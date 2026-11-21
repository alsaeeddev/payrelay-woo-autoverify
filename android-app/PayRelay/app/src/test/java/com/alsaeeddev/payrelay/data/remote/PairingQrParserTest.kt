package com.alsaeeddev.payrelay.data.remote

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class PairingQrParserTest {

    private val parser = PairingQrParser(
        json = Json { ignoreUnknownKeys = true },
        siteUrlValidator = SiteUrlValidator()
    )

    @Test
    fun parse_acceptsValidHttpsPairingQr() {
        val result = parser.parse("""{"site":"https://shop.example.com/","token":"tok_abc123"}""")
        assertTrue(result is QrParseResult.Valid)
        val valid = result as QrParseResult.Valid
        assertEquals("https://shop.example.com", valid.normalizedSiteUrl)
        assertEquals("tok_abc123", valid.pairingToken)
    }

    @Test
    fun parse_rejectsHttpSiteInQr() {
        val result = parser.parse("""{"site":"http://shop.example.com","token":"tok_abc123"}""")
        assertTrue(result is QrParseResult.Invalid)
    }

    @Test
    fun parse_rejectsMalformedJsonOrMissingToken() {
        assertTrue(parser.parse("not-json") is QrParseResult.Invalid)
        assertTrue(parser.parse("""{"site":"https://shop.example.com","token":"  "}""") is QrParseResult.Invalid)
    }
}
