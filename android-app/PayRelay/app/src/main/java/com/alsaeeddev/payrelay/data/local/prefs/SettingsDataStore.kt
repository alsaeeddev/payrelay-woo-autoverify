package com.alsaeeddev.payrelay.data.local.prefs

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.core.stringSetPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.alsaeeddev.payrelay.data.local.crypto.EncryptedSecretBlob
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

private val Context.payRelayDataStore: DataStore<Preferences> by preferencesDataStore(
    name = "payrelay_settings"
)

data class StoredPreferences(
    val consentAccepted: Boolean,
    val isPaired: Boolean,
    val siteUrl: String?,
    val deviceId: String?,
    val secretCiphertext: String?,
    val secretIv: String?,
    val allowlist: Set<String>,
    val forwardingPaused: Boolean,
    val biometricLockEnabled: Boolean,
    val retentionDays: Int,
    val lastSyncAtMs: Long?,
    val lastHeartbeatAtMs: Long?,
    val lastErrorRedacted: String?
)

@Singleton
class SettingsDataStore @Inject constructor(
    @ApplicationContext private val context: Context
) {
    private val dataStore: DataStore<Preferences> get() = context.payRelayDataStore

    val preferencesFlow: Flow<StoredPreferences> = dataStore.data.map { prefs ->
        val allowlistInitialized = prefs[KEY_ALLOWLIST_INITIALIZED] ?: false
        val storedAllowlist = if (allowlistInitialized) {
            prefs[KEY_ALLOWLIST].orEmpty()
        } else {
            DefaultAllowlist.DEFAULT_SENDERS
        }

        StoredPreferences(
            consentAccepted = prefs[KEY_CONSENT_ACCEPTED] ?: false,
            isPaired = prefs[KEY_IS_PAIRED] ?: false,
            siteUrl = prefs[KEY_SITE_URL],
            deviceId = prefs[KEY_DEVICE_ID],
            secretCiphertext = prefs[KEY_SECRET_CIPHERTEXT],
            secretIv = prefs[KEY_SECRET_IV],
            allowlist = storedAllowlist,
            forwardingPaused = prefs[KEY_FORWARDING_PAUSED] ?: false,
            biometricLockEnabled = prefs[KEY_BIOMETRIC_LOCK_ENABLED] ?: false,
            retentionDays = (prefs[KEY_RETENTION_DAYS] ?: DEFAULT_RETENTION_DAYS).coerceIn(1, 90),
            lastSyncAtMs = prefs[KEY_LAST_SYNC_AT_MS],
            lastHeartbeatAtMs = prefs[KEY_LAST_HEARTBEAT_AT_MS],
            lastErrorRedacted = prefs[KEY_LAST_ERROR_REDACTED]
        )
    }

    suspend fun snapshot(): StoredPreferences = preferencesFlow.first()

    suspend fun setConsentAccepted(accepted: Boolean) {
        dataStore.edit { it[KEY_CONSENT_ACCEPTED] = accepted }
    }

    suspend fun savePairing(
        siteUrl: String,
        deviceId: String,
        encryptedSecret: EncryptedSecretBlob
    ) {
        dataStore.edit { prefs ->
            prefs[KEY_IS_PAIRED] = true
            prefs[KEY_SITE_URL] = siteUrl
            prefs[KEY_DEVICE_ID] = deviceId
            prefs[KEY_SECRET_CIPHERTEXT] = encryptedSecret.ciphertextBase64
            prefs[KEY_SECRET_IV] = encryptedSecret.ivBase64
            prefs.remove(KEY_LAST_ERROR_REDACTED)
        }
    }

    suspend fun clearPairingOnly() {
        dataStore.edit { prefs ->
            prefs[KEY_IS_PAIRED] = false
            prefs.remove(KEY_SITE_URL)
            prefs.remove(KEY_DEVICE_ID)
            prefs.remove(KEY_SECRET_CIPHERTEXT)
            prefs.remove(KEY_SECRET_IV)
            prefs.remove(KEY_LAST_SYNC_AT_MS)
            prefs.remove(KEY_LAST_HEARTBEAT_AT_MS)
            prefs.remove(KEY_LAST_ERROR_REDACTED)
        }
    }

    suspend fun updateSiteUrl(siteUrl: String) {
        dataStore.edit { it[KEY_SITE_URL] = siteUrl }
    }

    suspend fun setForwardingPaused(paused: Boolean) {
        dataStore.edit { it[KEY_FORWARDING_PAUSED] = paused }
    }

    suspend fun setBiometricLockEnabled(enabled: Boolean) {
        dataStore.edit { it[KEY_BIOMETRIC_LOCK_ENABLED] = enabled }
    }

    suspend fun setRetentionDays(days: Int) {
        dataStore.edit { it[KEY_RETENTION_DAYS] = days.coerceIn(1, 90) }
    }

    suspend fun setAllowlist(senders: Set<String>) {
        dataStore.edit { prefs ->
            prefs[KEY_ALLOWLIST_INITIALIZED] = true
            prefs[KEY_ALLOWLIST] = senders
        }
    }

    suspend fun resetDefaultAllowlist() {
        dataStore.edit { prefs ->
            prefs[KEY_ALLOWLIST_INITIALIZED] = true
            prefs[KEY_ALLOWLIST] = DefaultAllowlist.DEFAULT_SENDERS
        }
    }

    suspend fun setLastSyncAtMs(timestampMs: Long) {
        dataStore.edit { prefs ->
            prefs[KEY_LAST_SYNC_AT_MS] = timestampMs
            prefs.remove(KEY_LAST_ERROR_REDACTED)
        }
    }

    suspend fun setLastHeartbeatAtMs(timestampMs: Long) {
        dataStore.edit { it[KEY_LAST_HEARTBEAT_AT_MS] = timestampMs }
    }

    suspend fun setLastErrorRedacted(error: String?) {
        dataStore.edit { prefs ->
            if (error == null) {
                prefs.remove(KEY_LAST_ERROR_REDACTED)
            } else {
                prefs[KEY_LAST_ERROR_REDACTED] = error
            }
        }
    }

    suspend fun wipeAll() {
        dataStore.edit { it.clear() }
    }

    companion object {
        const val DEFAULT_RETENTION_DAYS = 14

        private val KEY_CONSENT_ACCEPTED = booleanPreferencesKey("consent_accepted")
        private val KEY_IS_PAIRED = booleanPreferencesKey("is_paired")
        private val KEY_SITE_URL = stringPreferencesKey("site_url")
        private val KEY_DEVICE_ID = stringPreferencesKey("device_id")
        private val KEY_SECRET_CIPHERTEXT = stringPreferencesKey("secret_ciphertext")
        private val KEY_SECRET_IV = stringPreferencesKey("secret_iv")
        private val KEY_ALLOWLIST_INITIALIZED = booleanPreferencesKey("allowlist_initialized")
        private val KEY_ALLOWLIST = stringSetPreferencesKey("sender_allowlist")
        private val KEY_FORWARDING_PAUSED = booleanPreferencesKey("forwarding_paused")
        private val KEY_BIOMETRIC_LOCK_ENABLED = booleanPreferencesKey("biometric_lock_enabled")
        private val KEY_RETENTION_DAYS = intPreferencesKey("retention_days")
        private val KEY_LAST_SYNC_AT_MS = longPreferencesKey("last_sync_at_ms")
        private val KEY_LAST_HEARTBEAT_AT_MS = longPreferencesKey("last_heartbeat_at_ms")
        private val KEY_LAST_ERROR_REDACTED = stringPreferencesKey("last_error_redacted")
    }
}
