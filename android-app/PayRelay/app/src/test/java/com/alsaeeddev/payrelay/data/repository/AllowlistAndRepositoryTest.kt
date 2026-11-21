package com.alsaeeddev.payrelay.data.repository

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.data.local.db.PayRelayDatabase
import com.alsaeeddev.payrelay.data.local.prefs.DefaultAllowlist
import com.alsaeeddev.payrelay.di.TimeProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class AllowlistAndRepositoryTest {

    private lateinit var db: PayRelayDatabase

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, PayRelayDatabase::class.java)
            .allowMainThreadQueries()
            .build()
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun defaultAllowlist_matchesWalletsAndBanksCaseInsensitively() {
        val allowlist = DefaultAllowlist.DEFAULT_SENDERS
        assertTrue(DefaultAllowlist.isAllowed("jazzcash", allowlist))
        assertTrue(DefaultAllowlist.isAllowed("  Easypaisa ", allowlist))
        assertTrue(DefaultAllowlist.isAllowed("8558", allowlist))
        assertTrue(DefaultAllowlist.isAllowed("3737", allowlist))
        assertTrue(DefaultAllowlist.isAllowed("HBL", allowlist))
        assertTrue(DefaultAllowlist.isAllowed("MeezanBank", allowlist))
        assertFalse(DefaultAllowlist.isAllowed("+923001112233", allowlist))
        assertFalse(DefaultAllowlist.isAllowed("RandomPromo", allowlist))
    }

    @Test
    fun auditLogRepository_redactsSensitiveDetailsBeforeInsert() = runTest {
        val repo = AuditLogRepositoryImpl(
            auditLogDao = db.auditLogDao(),
            redactor = Redactor(),
            timeProvider = object : TimeProvider {
                override fun epochMillis(): Long = 1_700_000_000_000L
            },
            ioDispatcher = Dispatchers.Unconfined
        )

        repo.log(
            level = LogLevel.INFO,
            category = "SYNC",
            sender = "+923001234567",
            message = "Failed Rs 4500 for +923001234567 TID 987654321",
            httpCode = 500
        )

        val entries = repo.observeRecent(10).first()
        assertFalse(entries[0].message.contains("4500"))
        assertFalse(entries[0].message.contains("3001234567"))
        assertFalse(entries[0].message.contains("987654321"))
        assertFalse(entries[0].senderMasked.orEmpty().contains("3001234567"))
    }
}
