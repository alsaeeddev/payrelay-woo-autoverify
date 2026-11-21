package com.alsaeeddev.payrelay.data.local.prefs

import java.util.Locale

object DefaultAllowlist {
    val DEFAULT_SENDERS: Set<String> = setOf(
        // JazzCash
        "JazzCash",
        "8558",
        // Easypaisa / Telenor Microfinance Bank
        "Easypaisa",
        "3737",
        // Digital wallets & Raast
        "SadaPay",
        "NayaPay",
        "Zindigi",
        "Raast",
        // Major Pakistani Banks
        "MeezanBank",
        "Meezan Bank",
        "8002",
        "HBL",
        "4250",
        "8255",
        "UBL",
        "8257",
        "MCB",
        "8222",
        "BankAlfalah",
        "Alfalah",
        "8287",
        "ABL",
        "AlliedBank",
        "9080",
        "AskariBank",
        "FaysalBank",
        "8116",
        "BankIslami",
        "8001",
        "SCBPL",
        "HabibMetro",
        "JSBank",
        "BOP"
    )

    fun normalize(sender: String): String = sender.trim().lowercase(Locale.ROOT)

    fun isAllowed(sender: String, allowlist: Set<String>): Boolean {
        val normalizedIncoming = normalize(sender)
        if (normalizedIncoming.isEmpty()) return false
        return allowlist.any { normalize(it) == normalizedIncoming }
    }
}
