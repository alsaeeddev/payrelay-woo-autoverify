package com.alsaeeddev.payrelay.data.local.crypto

import java.security.MessageDigest
import java.security.SecureRandom
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec
import javax.inject.Inject
import javax.inject.Singleton

data class SignedHeaders(
    val deviceId: String,
    val timestampSeconds: String,
    val nonce: String,
    val signature: String
)

@Singleton
class RequestSigner @Inject constructor() {

    private val secureRandom = SecureRandom()

    fun generateNonce(byteLength: Int = DEFAULT_NONCE_BYTES): String {
        require(byteLength >= MIN_NONCE_BYTES) {
            "Nonce must be at least $MIN_NONCE_BYTES bytes"
        }
        val bytes = ByteArray(byteLength)
        secureRandom.nextBytes(bytes)
        return bytes.toLowerHex()
    }

    fun sha256Hex(bytes: ByteArray): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(bytes)
        return digest.toLowerHex()
    }

    fun idempotencyKey(sender: String, body: String, receivedAtMs: Long): String {
        val canonical = "$sender|$body|$receivedAtMs"
        return sha256Hex(canonical.toByteArray(Charsets.UTF_8))
    }

    fun computeSignature(
        secret: ByteArray,
        method: String,
        path: String,
        timestampSeconds: Long,
        nonce: String,
        rawBody: ByteArray
    ): String {
        require(secret.isNotEmpty()) { "Signing secret must not be empty" }
        require(nonce.length >= MIN_NONCE_BYTES * 2) { "Nonce hex length must be >= ${MIN_NONCE_BYTES * 2}" }

        val bodySha256 = sha256Hex(rawBody)
        val canonical = buildString {
            append(method.uppercase())
            append('\n')
            append(path)
            append('\n')
            append(timestampSeconds)
            append('\n')
            append(nonce)
            append('\n')
            append(bodySha256)
        }

        val mac = Mac.getInstance(HMAC_ALGORITHM)
        mac.init(SecretKeySpec(secret, HMAC_ALGORITHM))
        val hmacBytes = mac.doFinal(canonical.toByteArray(Charsets.UTF_8))
        return hmacBytes.toLowerHex()
    }

    fun signHeaders(
        deviceId: String,
        secret: ByteArray,
        method: String,
        path: String,
        timestampSeconds: Long,
        rawBody: ByteArray,
        nonce: String = generateNonce()
    ): SignedHeaders {
        val signature = computeSignature(
            secret = secret,
            method = method,
            path = path,
            timestampSeconds = timestampSeconds,
            nonce = nonce,
            rawBody = rawBody
        )
        return SignedHeaders(
            deviceId = deviceId,
            timestampSeconds = timestampSeconds.toString(),
            nonce = nonce,
            signature = signature
        )
    }

    private fun ByteArray.toLowerHex(): String {
        val out = CharArray(size * 2)
        for (i in indices) {
            val v = this[i].toInt() and 0xFF
            out[i * 2] = HEX_CHARS[v ushr 4]
            out[i * 2 + 1] = HEX_CHARS[v and 0x0F]
        }
        return String(out)
    }

    companion object {
        private const val HMAC_ALGORITHM = "HmacSHA256"
        const val MIN_NONCE_BYTES = 16
        const val DEFAULT_NONCE_BYTES = 16
        private val HEX_CHARS = "0123456789abcdef".toCharArray()
    }
}
