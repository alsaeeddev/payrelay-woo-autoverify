package com.alsaeeddev.payrelay.data.local.prefs

import android.content.Context
import com.alsaeeddev.payrelay.di.IoDispatcher
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.io.File
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Stable, random per-install ID sent to WordPress when pairing.
 *
 * Why: the server uses it to recognise "this is the same phone pairing again" and reuses
 * its device row instead of listing a duplicate device after every revoke/re-pair.
 *
 * - Random UUID v4, NOT derived from IMEI / serial / Android ID (no hardware identifier).
 * - Stored in [Context.getNoBackupFilesDir]: excluded from Auto Backup / device transfer,
 *   so a restored backup on another phone never shares this phone's ID.
 * - Kept in its own file, so "Unpair & wipe" (which clears the DataStore) does not remove it
 *   and re-pairing the same phone still maps to the same server row.
 * - Uninstall / "Clear storage" removes it -> next pairing counts as a new device.
 */
@Singleton
class DeviceUuidProvider @Inject constructor(
    @ApplicationContext private val context: Context,
    @IoDispatcher private val ioDispatcher: CoroutineDispatcher
) {

    private val mutex = Mutex()

    @Volatile
    private var cached: String? = null

    suspend fun get(): String {
        cached?.let { return it }
        return withContext(ioDispatcher) {
            mutex.withLock {
                cached ?: loadOrCreate().also { cached = it }
            }
        }
    }

    private fun loadOrCreate(): String {
        val file = File(context.noBackupFilesDir, FILE_NAME)

        val existing = runCatching {
            if (file.exists()) file.readText().trim() else null
        }.getOrNull()
        if (existing != null && VALID_UUID.matches(existing)) return existing

        val fresh = UUID.randomUUID().toString()
        // If persisting fails the ID still works for this pairing; the next pairing just
        // behaves like before (new device row).
        runCatching { file.writeText(fresh) }
        return fresh
    }

    private companion object {
        const val FILE_NAME = "payrelay_device_uuid"
        // Same rule the WordPress plugin validates: letters, digits, '-' (16..64 chars).
        val VALID_UUID = Regex("^[A-Za-z0-9-]{16,64}$")
    }
}
