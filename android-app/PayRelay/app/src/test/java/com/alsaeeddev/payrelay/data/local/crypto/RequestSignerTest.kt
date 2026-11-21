package com.alsaeeddev.payrelay.data.local.crypto

import com.alsaeeddev.payrelay.data.remote.SignedRequestFactory
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidator
import com.alsaeeddev.payrelay.data.remote.dto.SmsPayload
import com.alsaeeddev.payrelay.di.TimeProvider
import kotlinx.serialization.json.Json
import okio.Buffer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RequestSignerTest {

    private val signer = RequestSigner()

    @Test
    fun computeSignature_matchesExactContractTestVector() {
        val secret = "test_secret_0123456789abcdef0123456789abcdef".toByteArray(Charsets.UTF_8)
        val method = "POST"
        val path = "/wp-json/mpav/v1/sms"
        val timestamp = 1763262000L
        val nonce = "a1b2c3d4e5f60718293a4b5c6d7e8f90"
        val rawBody = """{"idempotency_key":"k1","sender":"JazzCash","body":"TID 123456789 Rs 1500","received_at_ms":1763262000000,"sim_slot":0}"""
            .toByteArray(Charsets.UTF_8)

        val actualBodySha256 = signer.sha256Hex(rawBody)
        assertEquals(
            "e175b9ce210029632748788b1785b1a75b0345656cd4a1f1c6a75d4947e86b3d",
            actualBodySha256
        )

        val actualSignature = signer.computeSignature(
            secret = secret,
            method = method,
            path = path,
            timestampSeconds = timestamp,
            nonce = nonce,
            rawBody = rawBody
        )
        assertEquals(
            "884b24a69651860e9bb5456c4aa5b71cee565e816a244fc562ee374b0c5fa0e2",
            actualSignature
        )
    }

    @Test
    fun signedRequestFactory_serializesOnceAndMatchesExactWireBytes() {
        val json = Json {
            ignoreUnknownKeys = true
            encodeDefaults = true
            explicitNulls = false
        }
        val fixedTime = object : TimeProvider {
            override fun epochMillis(): Long = 1763262000_000L
        }
        val factory = SignedRequestFactory(
            json = json,
            requestSigner = signer,
            siteUrlValidator = SiteUrlValidator(),
            timeProvider = fixedTime
        )

        val payload = SmsPayload(
            idempotencyKey = "k1",
            sender = "JazzCash",
            body = "TID 123456789 Rs 1500",
            receivedAtMs = 1763262000000L,
            simSlot = 0
        )

        val prepared = factory.createSignedPost(
            normalizedSiteUrl = "https://merchant.example.com",
            endpoint = "sms",
            deviceId = "dev_123",
            secret = "test_secret_0123456789abcdef0123456789abcdef".toByteArray(Charsets.UTF_8),
            serializer = SmsPayload.serializer(),
            payload = payload,
            nonceOverride = "a1b2c3d4e5f60718293a4b5c6d7e8f90"
        )

        val buffer = Buffer()
        prepared.requestBody.writeTo(buffer)
        val wireString = buffer.readUtf8()

        assertEquals(
            """{"idempotency_key":"k1","sender":"JazzCash","body":"TID 123456789 Rs 1500","received_at_ms":1763262000000,"sim_slot":0}""",
            wireString
        )
        assertEquals(
            "884b24a69651860e9bb5456c4aa5b71cee565e816a244fc562ee374b0c5fa0e2",
            prepared.headers.signature
        )
        assertEquals("/wp-json/mpav/v1/sms", prepared.url.encodedPath)
        assertEquals("dev_123", prepared.headers.deviceId)
        assertEquals("1763262000", prepared.headers.timestampSeconds)
    }

    @Test
    fun idempotencyKey_isDeterministicSha256Hex() {
        val key1 = signer.idempotencyKey("JazzCash", "TID 123", 1763262000000L)
        val key2 = signer.idempotencyKey("JazzCash", "TID 123", 1763262000000L)
        val key3 = signer.idempotencyKey("JazzCash", "TID 124", 1763262000000L)

        assertEquals(64, key1.length)
        assertEquals(key1, key2)
        assertNotEquals(key1, key3)
    }

    @Test
    fun generateNonce_returnsAtLeast32UniqueHexChars() {
        val nonces = (1..1000).map { signer.generateNonce() }
        assertTrue(nonces.all { it.length >= 32 && it.all { ch -> ch in '0'..'9' || ch in 'a'..'f' } })
        assertEquals(1000, nonces.toSet().size)
    }
}
