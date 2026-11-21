package com.alsaeeddev.payrelay.data.remote

import com.alsaeeddev.payrelay.data.local.crypto.RequestSigner
import com.alsaeeddev.payrelay.data.local.crypto.SignedHeaders
import com.alsaeeddev.payrelay.di.TimeProvider
import kotlinx.serialization.SerializationStrategy
import kotlinx.serialization.json.Json
import okhttp3.HttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import javax.inject.Inject
import javax.inject.Singleton

data class PreparedSignedRequest(
    val url: HttpUrl,
    val rawBytes: ByteArray,
    val headers: SignedHeaders,
    val requestBody: RequestBody
) {
    fun toOkHttpRequest(): Request {
        return Request.Builder()
            .url(url)
            .post(requestBody)
            .header(HEADER_DEVICE, headers.deviceId)
            .header(HEADER_TIMESTAMP, headers.timestampSeconds)
            .header(HEADER_NONCE, headers.nonce)
            .header(HEADER_SIGNATURE, headers.signature)
            .header("Accept", "application/json")
            .build()
    }

    companion object {
        const val HEADER_DEVICE = "X-MPAV-Device"
        const val HEADER_TIMESTAMP = "X-MPAV-Timestamp"
        const val HEADER_NONCE = "X-MPAV-Nonce"
        const val HEADER_SIGNATURE = "X-MPAV-Signature"
    }
}

@Singleton
class SignedRequestFactory @Inject constructor(
    private val json: Json,
    private val requestSigner: RequestSigner,
    private val siteUrlValidator: SiteUrlValidator,
    private val timeProvider: TimeProvider
) {

    fun <T> createSignedPost(
        normalizedSiteUrl: String,
        endpoint: String,
        deviceId: String,
        secret: ByteArray,
        serializer: SerializationStrategy<T>,
        payload: T,
        nonceOverride: String? = null
    ): PreparedSignedRequest {
        val url = siteUrlValidator.buildEndpointUrl(normalizedSiteUrl, endpoint)
        val rawJsonString = json.encodeToString(serializer, payload)
        val rawBytes = rawJsonString.toByteArray(Charsets.UTF_8)
        val timestampSeconds = timeProvider.epochSeconds()
        val nonce = nonceOverride ?: requestSigner.generateNonce()

        val headers = requestSigner.signHeaders(
            deviceId = deviceId,
            secret = secret,
            method = "POST",
           path = url.encodedPath,
            timestampSeconds = timestampSeconds,
            rawBody = rawBytes,
            nonce = nonce
        )

        val requestBody = rawBytes.toRequestBody(JSON_MEDIA_TYPE)
        return PreparedSignedRequest(
            url = url,
            rawBytes = rawBytes,
            headers = headers,
            requestBody = requestBody
        )
    }

    fun <T> createUnsignedJsonBody(
        serializer: SerializationStrategy<T>,
        payload: T
    ): RequestBody {
        val rawBytes = json.encodeToString(serializer, payload).toByteArray(Charsets.UTF_8)
        return rawBytes.toRequestBody(JSON_MEDIA_TYPE)
    }

    companion object {
        private val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
