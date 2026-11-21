package com.alsaeeddev.payrelay.data.local.crypto

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class RedactorTest {

    private val redactor = Redactor()

    @Test
    fun redactMessage_masksPhoneNumbersAmountsAndLongTokens() {
        val raw = "Received Rs 1,500.00 from +923001234567 TID 1234567890123 via JazzCash"
        val redacted = redactor.redactMessage(raw)

        assertFalse(redacted.contains("1,500"))
        assertFalse(redacted.contains("3001234567"))
        assertFalse(redacted.contains("1234567890123"))
        assertTrue(redacted.contains("[REDACTED_AMT]"))
        assertTrue(redacted.contains("[REDACTED_PHONE]"))
        assertTrue(redacted.contains("[REDACTED_NUM]"))
    }

    @Test
    fun redactMessage_preservesHttpStatusCodes() {
        val raw = "Sync failed with HTTP 429 from server"
        assertEquals(raw, redactor.redactMessage(raw))
    }

    @Test
    fun maskSender_preservesShortcodesAndMasksPhoneNumbers() {
        assertEquals("JazzCash", redactor.maskSender("JazzCash"))
        assertEquals("8558", redactor.maskSender("8558"))
        assertEquals("+92***67", redactor.maskSender("+923001234567"))
        assertEquals("03***67", redactor.maskSender("03001234567"))
    }
}
