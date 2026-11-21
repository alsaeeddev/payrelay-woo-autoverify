package com.alsaeeddev.payrelay

import com.alsaeeddev.payrelay.data.local.crypto.RequestSigner
import org.junit.Assert.assertEquals
import org.junit.Test

class RequestSignerTest {

    private val signer = RequestSigner()

    @Test
    fun signature_matches_plugin_test_vector() {
        val secret = "test_secret_0123456789abcdef0123456789abcdef"
            .toByteArray(Charsets.UTF_8)

        val rawBody = """{"idempotency_key":"k1","sender":"JazzCash","body":"TID 123456789 Rs 1500","received_at_ms":1763262000000,"sim_slot":0}"""
            .toByteArray(Charsets.UTF_8)

        val bodyHash = signer.sha256Hex(rawBody)
        assertEquals(
            "e175b9ce210029632748788b1785b1a75b0345656cd4a1f1c6a75d4947e86b3d",
            bodyHash
        )

        val signature = signer.computeSignature(
            secret = secret,
            method = "POST",
            path = "/wp-json/mpav/v1/sms",
            timestampSeconds = 1763262000L,
            nonce = "a1b2c3d4e5f60718293a4b5c6d7e8f90",
            rawBody = rawBody
        )

        assertEquals(
            "884b24a69651860e9bb5456c4aa5b71cee565e816a244fc562ee374b0c5fa0e2",
            signature
        )
    }
}