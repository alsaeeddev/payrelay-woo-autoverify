package com.alsaeeddev.payrelay.data.remote

import com.alsaeeddev.payrelay.BuildConfig
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import javax.inject.Inject
import javax.inject.Singleton

sealed class SiteUrlValidationResult {
    data class Valid(val normalizedBaseUrl: String) : SiteUrlValidationResult()
    data class Invalid(val reason: String) : SiteUrlValidationResult()
}

@Singleton
class SiteUrlValidator @Inject constructor() {

    private val allowInsecureForDev: Boolean = BuildConfig.DEBUG

    fun validate(rawUrl: String): SiteUrlValidationResult {
        val trimmed = rawUrl.trim()
        if (trimmed.isEmpty()) {
            return SiteUrlValidationResult.Invalid("Site URL cannot be empty")
        }

        val isHttps = trimmed.startsWith("https://", ignoreCase = true)
        val isHttp = trimmed.startsWith("http://", ignoreCase = true)
        if (!isHttps && !(allowInsecureForDev && isHttp)) {
            return SiteUrlValidationResult.Invalid("Only HTTPS site URLs are allowed")
        }

        val parsed = trimmed.toHttpUrlOrNull()
            ?: return SiteUrlValidationResult.Invalid("Malformed site URL")

        if (parsed.username.isNotEmpty() || parsed.password.isNotEmpty()) {
            return SiteUrlValidationResult.Invalid("Site URL must not contain embedded credentials")
        }
        if (parsed.encodedQuery != null || parsed.fragment != null) {
            return SiteUrlValidationResult.Invalid("Site URL must not contain query parameters or fragments")
        }

        val host = parsed.host
        val isLocalhost = host.equals("localhost", ignoreCase = true)
        if (host.isBlank() || (isLocalhost && !allowInsecureForDev)) {
            return SiteUrlValidationResult.Invalid("Site host is invalid")
        }

        val scheme = parsed.scheme
        val cleanPath = parsed.encodedPath.trimEnd('/')
        val portSuffix = if (parsed.port != HttpUrl.defaultPort(scheme)) ":${parsed.port}" else ""
        return SiteUrlValidationResult.Valid("$scheme://$host$portSuffix$cleanPath")
    }

    fun buildEndpointUrl(normalizedBaseUrl: String, relativeEndpoint: String): HttpUrl {
        val cleanBase = normalizedBaseUrl.trimEnd('/')
        val cleanEndpoint = relativeEndpoint.trimStart('/')
        return "$cleanBase/wp-json/mpav/v1/$cleanEndpoint".toHttpUrlOrNull()
            ?: throw IllegalArgumentException("Invalid MPAV endpoint URL")
    }
}