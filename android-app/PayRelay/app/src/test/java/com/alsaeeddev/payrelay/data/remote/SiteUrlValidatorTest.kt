package com.alsaeeddev.payrelay.data.remote

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SiteUrlValidatorTest {

    private val validator = SiteUrlValidator()

    @Test
    fun validate_acceptsHttpsAndNormalizesTrailingSlash() {
        val result = validator.validate("https://shop.example.com/store/")
        assertTrue(result is SiteUrlValidationResult.Valid)
        assertEquals(
            "https://shop.example.com/store",
            (result as SiteUrlValidationResult.Valid).normalizedBaseUrl
        )
    }

    @Test
    fun validate_rejectsHttpAndUnsafeUrls() {
        assertTrue(validator.validate("http://shop.example.com") is SiteUrlValidationResult.Invalid)
        assertTrue(validator.validate("https://user:pass@shop.example.com") is SiteUrlValidationResult.Invalid)
        assertTrue(validator.validate("https://shop.example.com?foo=bar") is SiteUrlValidationResult.Invalid)
        assertTrue(validator.validate("https://localhost") is SiteUrlValidationResult.Invalid)
        assertTrue(validator.validate("") is SiteUrlValidationResult.Invalid)
    }

    @Test
    fun buildEndpointUrl_preservesSubdirectoryPath() {
        val url = validator.buildEndpointUrl("https://shop.example.com/wc", "sms")
        assertEquals("/wc/wp-json/mpav/v1/sms", url.encodedPath)
    }
}
