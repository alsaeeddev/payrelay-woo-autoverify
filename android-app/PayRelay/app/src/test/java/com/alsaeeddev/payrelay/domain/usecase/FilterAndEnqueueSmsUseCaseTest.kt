package com.alsaeeddev.payrelay.domain.usecase

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.crypto.RequestSigner
import com.alsaeeddev.payrelay.data.local.db.PayRelayDatabase
import com.alsaeeddev.payrelay.data.local.prefs.DefaultAllowlist
import com.alsaeeddev.payrelay.data.repository.AuditLogRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.OutboxRepositoryImpl
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.domain.model.AppSettingsState
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class FilterAndEnqueueSmsUseCaseTest {

    private lateinit var db: PayRelayDatabase
    private lateinit var outboxRepo: OutboxRepositoryImpl
    private lateinit var auditRepo: AuditLogRepositoryImpl

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, PayRelayDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val timeProvider = object : TimeProvider {
            override fun epochMillis(): Long = 1_763_262_000_000L
        }
        outboxRepo = OutboxRepositoryImpl(
            outboxDao = db.outboxDao(),
            requestSigner = RequestSigner(),
            redactor = Redactor(),
            timeProvider = timeProvider,
            ioDispatcher = Dispatchers.Unconfined
        )
        auditRepo = AuditLogRepositoryImpl(
            auditLogDao = db.auditLogDao(),
            redactor = Redactor(),
            timeProvider = timeProvider,
            ioDispatcher = Dispatchers.Unconfined
        )
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun invoke_dropsNonAllowlistedSenderWithoutStoringOrLogging() = runTest {
        val fakeScheduler = FakeWorkScheduler()
        val settingsRepo = FakeSettingsRepository(
            AppSettingsState(
                consentAccepted = true,
                isPaired = true,
                siteUrl = "https://store.example.com",
                deviceId = "dev_1",
                forwardingPaused = false,
                biometricLockEnabled = false,
                retentionDays = 14,
                allowlist = DefaultAllowlist.DEFAULT_SENDERS,
                lastSyncAtMs = null,
                lastHeartbeatAtMs = null,
                lastErrorRedacted = null
            )
        )
        val useCase = FilterAndEnqueueSmsUseCase(settingsRepo, outboxRepo, auditRepo, fakeScheduler)

        val outcome = useCase(
            sender = "+923001112233",
            body = "Your personal OTP is 998811",
            receivedAtMs = 1_763_262_000_000L,
            simSlot = 0
        )

        assertTrue(outcome is EnqueueOutcome.Dropped)
        assertEquals(0, outboxRepo.countPending())
        assertEquals(0, auditRepo.observeRecent(10).first().size)
        assertEquals(0, fakeScheduler.syncEnqueueCount)
    }

    @Test
    fun invoke_enqueuesAllowlistedSenderAndSchedulesExpeditedSync() = runTest {
        val fakeScheduler = FakeWorkScheduler()
        val settingsRepo = FakeSettingsRepository(
            AppSettingsState(
                consentAccepted = true,
                isPaired = true,
                siteUrl = "https://store.example.com",
                deviceId = "dev_1",
                forwardingPaused = false,
                biometricLockEnabled = false,
                retentionDays = 14,
                allowlist = DefaultAllowlist.DEFAULT_SENDERS,
                lastSyncAtMs = null,
                lastHeartbeatAtMs = null,
                lastErrorRedacted = null
            )
        )
        val useCase = FilterAndEnqueueSmsUseCase(settingsRepo, outboxRepo, auditRepo, fakeScheduler)

        val outcome = useCase(
            sender = "JazzCash",
            body = "TID 123456789 Rs 1500",
            receivedAtMs = 1_763_262_000_000L,
            simSlot = 1
        )

        assertTrue(outcome is EnqueueOutcome.Enqueued)
        assertEquals(1, outboxRepo.countPending())
        assertEquals(1, fakeScheduler.syncEnqueueCount)
        assertEquals(1, auditRepo.observeRecent(10).first().size)
    }

    @Test
    fun invoke_dropsAllowlistedSenderWhenPaused() = runTest {
        val fakeScheduler = FakeWorkScheduler()
        val settingsRepo = FakeSettingsRepository(
            AppSettingsState(
                consentAccepted = true,
                isPaired = true,
                siteUrl = "https://store.example.com",
                deviceId = "dev_1",
                forwardingPaused = true,
                biometricLockEnabled = false,
                retentionDays = 14,
                allowlist = DefaultAllowlist.DEFAULT_SENDERS,
                lastSyncAtMs = null,
                lastHeartbeatAtMs = null,
                lastErrorRedacted = null
            )
        )
        val useCase = FilterAndEnqueueSmsUseCase(settingsRepo, outboxRepo, auditRepo, fakeScheduler)

        val outcome = useCase("JazzCash", "TID 123456 Rs 500", 1_763_262_000_000L, 0)
        assertTrue(outcome is EnqueueOutcome.Dropped)
        assertEquals(0, outboxRepo.countPending())
        assertEquals(0, fakeScheduler.syncEnqueueCount)
    }

    private class FakeWorkScheduler : SyncWorkScheduler {
        var syncEnqueueCount = 0
        override fun enqueueExpeditedSmsSync() {
            syncEnqueueCount++
        }
        override fun schedulePeriodicHeartbeat() {}
        override fun cancelAllWork() {}
    }

    private class FakeSettingsRepository(initial: AppSettingsState) : SettingsRepository {
        private val flow = MutableStateFlow(initial)
        override fun observeSettings(): Flow<AppSettingsState> = flow
        override suspend fun getSettingsSnapshot(): AppSettingsState = flow.value
        override suspend fun setConsentAccepted(accepted: Boolean) {}
        override suspend fun setForwardingPaused(paused: Boolean) {}
        override suspend fun setBiometricLockEnabled(enabled: Boolean) {}
        override suspend fun setRetentionDays(days: Int) {}
        override suspend fun updateSiteUrl(siteUrl: String) {}
        override suspend fun addAllowedSender(sender: String) {}
        override suspend fun removeAllowedSender(sender: String) {}
        override suspend fun resetDefaultAllowlist() {}
        override suspend fun recordLastSync(timestampMs: Long) {
            flow.value = flow.value.copy(lastSyncAtMs = timestampMs, lastErrorRedacted = null)
        }
        override suspend fun recordLastHeartbeat(timestampMs: Long) {}
        override suspend fun setLastError(errorMessage: String?) {
            flow.value = flow.value.copy(lastErrorRedacted = errorMessage)
        }
        override suspend fun clearAll() {}
    }
}
