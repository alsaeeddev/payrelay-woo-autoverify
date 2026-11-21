package com.alsaeeddev.payrelay.data.remote

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import javax.inject.Inject
import javax.inject.Singleton

@Serializable
data class PairingQrPayload(
    @SerialName("v") val version: Int = 1,
    @SerialName("server") val server: String,
    @SerialName("token") val token: String,
    @SerialName("site_name") val siteName: String? = null
)

sealed class QrParseResult {
    data class Valid(
        val normalizedSiteUrl: String,
        val pairingToken: String,
        val siteName: String? = null
    ) : QrParseResult()

    data class Invalid(val reason: String) : QrParseResult()
}

@Singleton
class PairingQrParser @Inject constructor(
    private val siteUrlValidator: SiteUrlValidator
) {
    private val lenientJson = Json { ignoreUnknownKeys = true }

    companion object {
        private const val SUPPORTED_VERSION = 1
        private const val REST_SUFFIX = "/wp-json/mpav/v1"
    }

    fun parse(rawQrText: String): QrParseResult {
        val trimmed = rawQrText.trim()
        if (trimmed.isEmpty()) return QrParseResult.Invalid("QR code is empty")

        val payload = try {
            lenientJson.decodeFromString(PairingQrPayload.serializer(), trimmed)
        } catch (_: Exception) {
            return QrParseResult.Invalid("Invalid pairing QR format")
        }

        if (payload.version > SUPPORTED_VERSION) {
            return QrParseResult.Invalid("Please update the app to pair with this site")
        }

        val token = payload.token.trim()
        if (token.isEmpty()) return QrParseResult.Invalid("Pairing token is missing")

        // server = ".../wp-json/mpav/v1" -> sirf site base URL nikalo
        val siteBase = payload.server.trim().trimEnd('/').removeSuffix(REST_SUFFIX)

        return when (val v = siteUrlValidator.validate(siteBase)) {
            is SiteUrlValidationResult.Valid -> QrParseResult.Valid(
                normalizedSiteUrl = v.normalizedBaseUrl,
                pairingToken = token,
                siteName = payload.siteName
            )
            is SiteUrlValidationResult.Invalid -> QrParseResult.Invalid(v.reason)
        }
    }
}