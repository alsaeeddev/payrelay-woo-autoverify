package com.alsaeeddev.payrelay.domain.usecase

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.crypto.RequestSigner
import com.alsaeeddev.payrelay.data.local.db.OutboxStatus
import com.alsaeeddev.payrelay.data.local.db.PayRelayDatabase
import com.alsaeeddev.payrelay.data.local.prefs.DefaultAllowlist
import com.alsaeeddev.payrelay.data.remote.MpavApi
import com.alsaeeddev.payrelay.data.remote.SignedRequestFactory
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidator
import com.alsaeeddev.payrelay.data.remote.dto.PairResponse
import com.alsaeeddev.payrelay.data.remote.dto.SmsAckResponse
import com.alsaeeddev.payrelay.data.repository.AuditLogRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.OutboxRepositoryImpl
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.domain.model.AppSettingsState
import com.alsaeeddev.payrelay.domain.model.PairingCredentials
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody
import okhttp3.ResponseBody
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class SyncOutboxUseCaseTest {

    private lateinit var db: PayRelayDatabase
    private lateinit var outboxRepo: OutboxRepositoryImpl
    private lateinit var auditRepo: AuditLogRepositoryImpl
    private lateinit var signedRequestFactory: SignedRequestFactory
    private val fixedTime = object : TimeProvider {
        override fun epochMillis(): Long = 1_763_262_000_000L
    }

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, PayRelayDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val signer = RequestSigner()
        val redactor = Redactor()
        outboxRepo = OutboxRepositoryImpl(db.outboxDao(), signer, redactor, fixedTime, Dispatchers.Unconfined)
        auditRepo = AuditLogRepositoryImpl(db.auditLogDao(), redactor, fixedTime, Dispatchers.Unconfined)
        val json = Json {
            ignoreUnknownKeys = true
            encodeDefaults = true
            explicitNulls = false
        }
        signedRequestFactory = SignedRequestFactory(json, signer, SiteUrlValidator(), fixedTime)
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun invoke_marksSentAndWipesBodyOn200() = runTest {
        outboxRepo.enqueueIfNew("JazzCash", "TID 123456 Rs 1500", 1_763_262_000_000L, 0)
        val settingsRepo = FakeSettingsRepository()
        val api = FakeMpavApi { Response.success(SmsAckResponse("accepted")) }
        val useCase = SyncOutboxUseCase(
            settingsRepo,
            FakePairingRepository(),
            outboxRepo,
            auditRepo,
            signedRequestFactory,
            api,
            fixedTime
        )

        val outcome = useCase()
        assertEquals(SyncOutcome.Completed(deliveredCount = 1, droppedCount = 0), outcome)
        assertEquals(0, outboxRepo.countPending())
        val recent = outboxRepo.observeRecent(10).first()
        assertEquals(OutboxStatus.SENT, recent[0].status)
        assertEquals("", recent[0].body)
        assertNotNull(settingsRepo.getSettingsSnapshot().lastSyncAtMs)
    }

    @Test
    fun invoke_recommendsRetryOn429AndPreservesBody() = runTest {
        outboxRepo.enqueueIfNew("Easypaisa", "TID 999888 Rs 2000", 1_763_262_000_000L, 1)
        val settingsRepo = FakeSettingsRepository()
        val api = FakeMpavApi {
            Response.error(429, "rate limited".toResponseBody("text/plain".toMediaType()))
        }
        val useCase = SyncOutboxUseCase(
            settingsRepo,
            FakePairingRepository(),
            outboxRepo,
            auditRepo,
            signedRequestFactory,
            api,
            fixedTime
        )

        val outcome = useCase()
        assertEquals(SyncOutcome.RetryRecommended, outcome)
        val pending = outboxRepo.getPendingInOrder()
        assertEquals(1, pending.size)
        assertEquals("TID 999888 Rs 2000", pending[0].body)
        assertEquals(1, pending[0].attemptCount)
    }

    @Test
    fun invoke_surfacesClockSkewOn401() = runTest {
        outboxRepo.enqueueIfNew("JazzCash", "TID 111222 Rs 500", 1_763_262_000_000L, 0)
        val settingsRepo = FakeSettingsRepository()
        val api = FakeMpavApi {
            Response.error(401, "timestamp expired".toResponseBody("text/plain".toMediaType()))
        }
        val useCase = SyncOutboxUseCase(
            settingsRepo,
            FakePairingRepository(),
            outboxRepo,
            auditRepo,
            signedRequestFactory,
            api,
            fixedTime
        )

        val outcome = useCase()
        assertEquals(SyncOutcome.RetryRecommended, outcome)
        val err = settingsRepo.getSettingsSnapshot().lastErrorRedacted.orEmpty()
        assertTrue(err.contains("clock", ignoreCase = true))
    }

    @Test
    fun invoke_dropsPermanentlyOn400AndWipesBody() = runTest {
        outboxRepo.enqueueIfNew("JazzCash", "Bad payload", 1_763_262_000_000L, 0)
        val settingsRepo = FakeSettingsRepository()
        val api = FakeMpavApi {
            Response.error(400, "bad request".toResponseBody("text/plain".toMediaType()))
        }
        val useCase = SyncOutboxUseCase(
            settingsRepo,
            FakePairingRepository(),
            outboxRepo,
            auditRepo,
            signedRequestFactory,
            api,
            fixedTime
        )

        val outcome = useCase()
        assertEquals(SyncOutcome.Completed(deliveredCount = 0, droppedCount = 1), outcome)
        assertEquals(0, outboxRepo.countPending())
        val recent = outboxRepo.observeRecent(10).first()
        assertEquals(OutboxStatus.FAILED_PERMANENT, recent[0].status)
        assertEquals("", recent[0].body)
    }

    private class FakePairingRepository : PairingRepository {
        override suspend fun savePairing(siteUrl: String, deviceId: String, secretUtf8: String) {}
        override suspend fun getDecryptedCredentials(): PairingCredentials =
            PairingCredentials(
                siteUrl = "https://merchant.example.com",
                deviceId = "dev_1",
                secret = "test_secret_0123456789abcdef0123456789abcdef".toByteArray(Charsets.UTF_8)
            )
        override suspend fun clearPairing() {}
    }

    private class FakeSettingsRepository : SettingsRepository {
        private val flow = MutableStateFlow(
            AppSettingsState(
                consentAccepted = true,
                isPaired = true,
                siteUrl = "https://merchant.example.com",
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
        override suspend fun recordLastHeartbeat(timestampMs: Long) {
            flow.value = flow.value.copy(lastHeartbeatAtMs = timestampMs)
        }
        override suspend fun setLastError(errorMessage: String?) {
            flow.value = flow.value.copy(lastErrorRedacted = errorMessage)
        }
        override suspend fun clearAll() {}
    }

    private class FakeMpavApi(
        private val smsResponder: () -> Response<SmsAckResponse>
    ) : MpavApi {
        override suspend fun pair(url: String, body: RequestBody): Response<PairResponse> {
            error("Not called")
        }

        override suspend fun sendSms(
            url: String,
            deviceId: String,
            timestamp: String,
            nonce: String,
            signature: String,
            body: RequestBody
        ): Response<SmsAckResponse> = smsResponder()

        override suspend fun sendHeartbeat(
            url: String,
            deviceId: String,
            timestamp: String,
            nonce: String,
            signature: String,
            body: RequestBody
        ): Response<ResponseBody> {
            error("Not called")
        }
    }
}
