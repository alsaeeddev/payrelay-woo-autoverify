package com.alsaeeddev.payrelay.data.local.crypto

import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class Redactor @Inject constructor() {

    fun redactMessage(input: String): String {
        if (input.isBlank()) return input
        var sanitized = input
        sanitized = AMOUNT_REGEX.replace(sanitized) { match ->
            "${match.groupValues[1]} [REDACTED_AMT]"
        }
        sanitized = PHONE_REGEX.replace(sanitized, "[REDACTED_PHONE]")
        sanitized = LONG_NUMBER_REGEX.replace(sanitized, "[REDACTED_NUM]")
        return sanitized
    }

    fun maskSender(sender: String): String {
        val trimmed = sender.trim()
        val digitCount = trimmed.count { it.isDigit() }
        if (digitCount < 7) {
            return trimmed
        }
        if (trimmed.length <= 4) {
            return "****"
        }
        val prefixLen = if (trimmed.startsWith("+")) 3 else 2
        val suffixLen = 2
        if (trimmed.length <= prefixLen + suffixLen) {
            return "****"
        }
        return trimmed.take(prefixLen) + "***" + trimmed.takeLast(suffixLen)
    }

    companion object {
        private val AMOUNT_REGEX = Regex(
            pattern = """\b(PKR|Rs\.?|USD|\$)\s*:?\s*\d[\d,]*(?:\.\d{1,2})?\b""",
            option = RegexOption.IGNORE_CASE
        )
        private val PHONE_REGEX = Regex(
            pattern = """(?<!\w)(?:\+?92|0)?3\d{2}[-\s]?\d{7}(?!\w)"""
        )
        private val LONG_NUMBER_REGEX = Regex(
            pattern = """\b\d{6,}\b"""
        )
    }
}
