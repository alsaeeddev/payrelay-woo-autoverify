package com.alsaeeddev.payrelay.domain.model

import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.data.local.db.OutboxStatus

data class PairingCredentials(
    val siteUrl: String,
    val deviceId: String,
    val secret: ByteArray
) {
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is PairingCredentials) return false
        return siteUrl == other.siteUrl &&
            deviceId == other.deviceId &&
            secret.contentEquals(other.secret)
    }

    override fun hashCode(): Int {
        var result = siteUrl.hashCode()
        result = 31 * result + deviceId.hashCode()
        result = 31 * result + secret.contentHashCode()
        return result
    }

    override fun toString(): String {
        return "PairingCredentials(siteUrl='$siteUrl', deviceId='$deviceId', secret=[REDACTED])"
    }
}

data class AppSettingsState(
    val consentAccepted: Boolean,
    val isPaired: Boolean,
    val siteUrl: String?,
    val deviceId: String?,
    val forwardingPaused: Boolean,
    val biometricLockEnabled: Boolean,
    val retentionDays: Int,
    val allowlist: Set<String>,
    val lastSyncAtMs: Long?,
    val lastHeartbeatAtMs: Long?,
    val lastErrorRedacted: String?
)

data class OutboxItem(
    val id: Long,
    val idempotencyKey: String,
    val sender: String,
    val body: String,
    val receivedAtMs: Long,
    val simSlot: Int?,
    val status: OutboxStatus,
    val attemptCount: Int,
    val lastHttpCode: Int?,
    val lastErrorRedacted: String?,
    val createdAtMs: Long,
    val sentAtMs: Long?
)

data class AuditLogEntry(
    val id: Long,
    val timestampMs: Long,
    val level: LogLevel,
    val category: String,
    val senderMasked: String?,
    val message: String,
    val httpCode: Int?
)
