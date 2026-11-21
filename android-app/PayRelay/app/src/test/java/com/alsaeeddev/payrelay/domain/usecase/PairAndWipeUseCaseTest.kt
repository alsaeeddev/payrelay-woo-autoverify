package com.alsaeeddev.payrelay.domain.usecase

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.alsaeeddev.payrelay.data.local.crypto.EncryptedSecretBlob
import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.crypto.RequestSigner
import com.alsaeeddev.payrelay.data.local.crypto.SecretCipher
import com.alsaeeddev.payrelay.data.local.db.PayRelayDatabase
import com.alsaeeddev.payrelay.data.local.prefs.DeviceUuidProvider
import com.alsaeeddev.payrelay.data.local.prefs.SettingsDataStore
import com.alsaeeddev.payrelay.data.remote.MpavApi
import com.alsaeeddev.payrelay.data.remote.SignedRequestFactory
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidator
import com.alsaeeddev.payrelay.data.remote.dto.PairResponse
import com.alsaeeddev.payrelay.data.remote.dto.SmsAckResponse
import com.alsaeeddev.payrelay.data.repository.AuditLogRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.OutboxRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.PairingRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.SettingsRepositoryImpl
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody
import okhttp3.ResponseBody
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class PairAndWipeUseCaseTest {

    private lateinit var db: PayRelayDatabase
    private lateinit var dataStore: SettingsDataStore
    private lateinit var settingsRepo: SettingsRepositoryImpl
    private lateinit var pairingRepo: PairingRepositoryImpl
    private lateinit var outboxRepo: OutboxRepositoryImpl
    private lateinit var auditRepo: AuditLogRepositoryImpl
    private lateinit var fakeCipher: FakeSecretCipher
    private lateinit var fakeScheduler: FakeScheduler
    private lateinit var uuidProvider: DeviceUuidProvider

    private val fixedTime = object : TimeProvider {
        override fun epochMillis(): Long = 1_763_262_000_000L
    }

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, PayRelayDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        dataStore = SettingsDataStore(context)
        fakeCipher = FakeSecretCipher()
        fakeScheduler = FakeScheduler()
        uuidProvider = DeviceUuidProvider(context, Dispatchers.Unconfined)
        val validator = SiteUrlValidator()
        val redactor = Redactor()
        settingsRepo = SettingsRepositoryImpl(dataStore, validator, redactor)
        pairingRepo = PairingRepositoryImpl(dataStore, fakeCipher, validator, Dispatchers.Unconfined)
        outboxRepo = OutboxRepositoryImpl(db.outboxDao(), RequestSigner(), redactor, fixedTime, Dispatchers.Unconfined)
        auditRepo = AuditLogRepositoryImpl(db.auditLogDao(), redactor, fixedTime, Dispatchers.Unconfined)
    }

    @After
    fun tearDown() = runTest {
        dataStore.wipeAll()
        db.close()
    }

    @Test
    fun pairDevice_encryptsSecretAndSchedulesHeartbeat() = runTest {
        val api = FakeMpavApi(Response.success(PairResponse("dev_42", "super_secret_key")))
        val factory = SignedRequestFactory(
            Json { encodeDefaults = true; explicitNulls = false },
            RequestSigner(),
            SiteUrlValidator(),
            fixedTime
        )
        val useCase = PairDeviceUseCase(
            SiteUrlValidator(),
            factory,
            api,
            pairingRepo,
            auditRepo,
            fakeScheduler,
            uuidProvider
        )

        val outcome = useCase(
            siteUrl = "https://store.example.com/",
            pairingToken = "pair_tok_123",
            deviceName = "Merchant Phone"
        )

        assertTrue(outcome is PairOutcome.Success)
        assertEquals(1, fakeScheduler.heartbeatScheduleCount)
        val creds = pairingRepo.getDecryptedCredentials()
        assertNotNull(creds)
        assertEquals("dev_42", creds?.deviceId)
        assertEquals("super_secret_key", creds?.secret?.toString(Charsets.UTF_8))
    }

    @Test
    fun pairDevice_rejectsNonHttpsWithoutCallingNetwork() = runTest {
        var apiCalled = false
        val api = FakeMpavApi(Response.success(PairResponse("d", "s"))) { apiCalled = true }
        val factory = SignedRequestFactory(Json, RequestSigner(), SiteUrlValidator(), fixedTime)
        val useCase = PairDeviceUseCase(
            SiteUrlValidator(),
            factory,
            api,
            pairingRepo,
            auditRepo,
            fakeScheduler,
            uuidProvider
        )

        val outcome = useCase("http://store.example.com", "tok")
        assertTrue(outcome is PairOutcome.Failure)
        assertEquals(false, apiCalled)
    }

    @Test
    fun unpairAndWipe_cancelsWorkDeletesKeyAndWipesStorage() = runTest {
        settingsRepo.setConsentAccepted(true)
        pairingRepo.savePairing("https://store.example.com", "dev_1", "sec_123")
        outboxRepo.enqueueIfNew("JazzCash", "TID 123 Rs 100", 1_763_262_000_000L, 0)

        val wipeUseCase = UnpairAndWipeUseCase(
            fakeScheduler,
            pairingRepo,
            outboxRepo,
            auditRepo,
            settingsRepo
        )
        wipeUseCase()

        assertEquals(1, fakeScheduler.cancelCount)
        assertTrue(fakeCipher.keyDeleted)
        assertNull(pairingRepo.getDecryptedCredentials())
        assertEquals(0, outboxRepo.countPending())
        assertEquals(0, auditRepo.observeRecent(10).first().size)
        assertEquals(false, settingsRepo.getSettingsSnapshot().consentAccepted)
    }

    @Test
    fun pairDevice_sendsStableDeviceUuidAndReusesItWhenPairingAgain() = runTest {
        val api = FakeMpavApi(Response.success(PairResponse("dev_42", "super_secret_key")))
        val factory = SignedRequestFactory(
            Json { encodeDefaults = true; explicitNulls = false },
            RequestSigner(),
            SiteUrlValidator(),
            fixedTime
        )
        val useCase = PairDeviceUseCase(
            SiteUrlValidator(),
            factory,
            api,
            pairingRepo,
            auditRepo,
            fakeScheduler,
            uuidProvider
        )

        useCase("https://store.example.com/", "pair_tok_123", "Merchant Phone")
        val first = Json.parseToJsonElement(api.lastPairBody.orEmpty())
            .jsonObject["device_uuid"]?.jsonPrimitive?.content

        // Pairing again (e.g. after a revoke on the server): same phone, same UUID.
        useCase("https://store.example.com/", "pair_tok_456", "Merchant Phone")
        val second = Json.parseToJsonElement(api.lastPairBody.orEmpty())
            .jsonObject["device_uuid"]?.jsonPrimitive?.content

        assertNotNull(first)
        assertTrue(Regex("^[A-Za-z0-9-]{16,64}$").matches(first.orEmpty()))
        assertEquals(first, second)
        assertEquals(first, uuidProvider.get())
    }

    @Test
    fun deviceUuid_isStableAcrossProviderInstancesAndSurvivesUnpairAndWipe() = runTest {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val first = uuidProvider.get()

        // A fresh provider (e.g. after the app process restarts) reads the same UUID back.
        assertEquals(first, DeviceUuidProvider(context, Dispatchers.Unconfined).get())

        // "Unpair & wipe" clears settings/outbox/logs but keeps the install UUID, so
        // pairing the same phone again maps to the same server row.
        settingsRepo.setConsentAccepted(true)
        pairingRepo.savePairing("https://store.example.com", "dev_1", "sec_123")
        UnpairAndWipeUseCase(
            fakeScheduler,
            pairingRepo,
            outboxRepo,
            auditRepo,
            settingsRepo
        )()

        assertEquals(first, DeviceUuidProvider(context, Dispatchers.Unconfined).get())
    }

    private class FakeSecretCipher : SecretCipher {
        var keyDeleted = false
        override fun encrypt(plaintext: ByteArray): EncryptedSecretBlob {
            return EncryptedSecretBlob(
                ciphertextBase64 = plaintext.toString(Charsets.UTF_8),
                ivBase64 = "fake_iv"
            )
        }
        override fun decrypt(blob: EncryptedSecretBlob): ByteArray =
            blob.ciphertextBase64.toByteArray(Charsets.UTF_8)
        override fun deleteKey() {
            keyDeleted = true
        }
    }

    private class FakeScheduler : SyncWorkScheduler {
        var heartbeatScheduleCount = 0
        var cancelCount = 0
        override fun enqueueExpeditedSmsSync() {}
        override fun schedulePeriodicHeartbeat() {
            heartbeatScheduleCount++
        }
        override fun cancelAllWork() {
            cancelCount++
        }
    }

    private class FakeMpavApi(
        private val pairResponse: Response<PairResponse>,
        private val onCall: () -> Unit = {}
    ) : MpavApi {
        /** JSON body of the most recent pair request (for asserting what the app sends). */
        var lastPairBody: String? = null

        override suspend fun pair(url: String, body: RequestBody): Response<PairResponse> {
            onCall()
            val buffer = Buffer()
            body.writeTo(buffer)
            lastPairBody = buffer.readUtf8()
            return pairResponse
        }

        override suspend fun sendSms(
            url: String,
            deviceId: String,
            timestamp: String,
            nonce: String,
            signature: String,
            body: RequestBody
        ): Response<SmsAckResponse> = Response.error(500, "".toResponseBody("text/plain".toMediaType()))

        override suspend fun sendHeartbeat(
            url: String,
            deviceId: String,
            timestamp: String,
            nonce: String,
            signature: String,
            body: RequestBody
        ): Response<ResponseBody> = Response.error(500, "".toResponseBody("text/plain".toMediaType()))
    }
}