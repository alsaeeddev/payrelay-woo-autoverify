package com.alsaeeddev.payrelay.receiver

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.provider.Telephony
import android.telephony.SubscriptionManager
import javax.inject.Inject
import javax.inject.Singleton

data class ParsedSmsMessage(
    val sender: String,
    val body: String,
    val receivedAtMs: Long,
    val simSlot: Int?
)

@Singleton
class SmsIntentParser @Inject constructor() {

    fun parse(intent: Intent?): List<ParsedSmsMessage> {
        if (intent?.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
            return emptyList()
        }

        val smsMessages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (smsMessages.isNullOrEmpty()) {
            return emptyList()
        }

        val simSlot = extractSimSlot(intent.extras)

        // Group multipart SMS segments belonging to the same sender
        val grouped = LinkedHashMap<String, MutableList<Pair<String, Long>>>()
        for (sms in smsMessages) {
            val sender = (sms.displayOriginatingAddress ?: sms.originatingAddress).orEmpty().trim()
            val partBody = sms.messageBody.orEmpty()
            if (sender.isEmpty() || partBody.isEmpty()) continue
            grouped.getOrPut(sender) { mutableListOf() }
                .add(partBody to sms.timestampMillis)
        }

        return grouped.map { (sender, parts) ->
            val combinedBody = parts.joinToString(separator = "") { it.first }
            val timestamp = parts.minOf { it.second }.takeIf { it > 0L }
                ?: System.currentTimeMillis()
            ParsedSmsMessage(
                sender = sender,
                body = combinedBody,
                receivedAtMs = timestamp,
                simSlot = simSlot
            )
        }
    }

    internal fun extractSimSlot(extras: Bundle?): Int? {
        if (extras == null) return null

        for (key in SLOT_EXTRA_KEYS) {
            if (extras.containsKey(key)) {
                val value = extras.getInt(key, -1)
                if (value in 0..MAX_SIM_SLOTS) {
                    return value
                }
            }
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            for (subKey in SUBSCRIPTION_EXTRA_KEYS) {
                if (extras.containsKey(subKey)) {
                    val subId = extras.getInt(subKey, -1)
                    if (subId >= 0) {
                        val slot = SubscriptionManager.getSlotIndex(subId)
                        if (slot in 0..MAX_SIM_SLOTS) {
                            return slot
                        }
                    }
                }
            }
        }
        return null
    }

    companion object {
        private const val MAX_SIM_SLOTS = 3
        private val SLOT_EXTRA_KEYS = listOf(
            "android.telephony.extra.SLOT_INDEX",
            "slot",
            "simSlot",
            "simId",
            "phone"
        )
        private val SUBSCRIPTION_EXTRA_KEYS = listOf(
            "android.telephony.extra.SUBSCRIPTION_INDEX",
            "subscription"
        )
    }
}
