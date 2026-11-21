package com.alsaeeddev.payrelay.domain.repository

import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.domain.model.AppSettingsState
import com.alsaeeddev.payrelay.domain.model.AuditLogEntry
import com.alsaeeddev.payrelay.domain.model.OutboxItem
import com.alsaeeddev.payrelay.domain.model.PairingCredentials
import kotlinx.coroutines.flow.Flow

interface SettingsRepository {
    fun observeSettings(): Flow<AppSettingsState>
    suspend fun getSettingsSnapshot(): AppSettingsState
    suspend fun setConsentAccepted(accepted: Boolean)
    suspend fun setForwardingPaused(paused: Boolean)
    suspend fun setBiometricLockEnabled(enabled: Boolean)
    suspend fun setRetentionDays(days: Int)
    suspend fun updateSiteUrl(siteUrl: String)
    suspend fun addAllowedSender(sender: String)
    suspend fun removeAllowedSender(sender: String)
    suspend fun resetDefaultAllowlist()
    suspend fun recordLastSync(timestampMs: Long)
    suspend fun recordLastHeartbeat(timestampMs: Long)
    suspend fun setLastError(errorMessage: String?)
    suspend fun clearAll()
}

interface PairingRepository {
    suspend fun savePairing(siteUrl: String, deviceId: String, secretUtf8: String)
    suspend fun getDecryptedCredentials(): PairingCredentials?
    suspend fun clearPairing()
}

interface OutboxRepository {
    suspend fun enqueueIfNew(
        sender: String,
        body: String,
        receivedAtMs: Long,
        simSlot: Int?
    ): Long

    suspend fun getPendingInOrder(): List<OutboxItem>
    fun observePendingCount(): Flow<Int>
    suspend fun countPending(): Int
    fun observeRecent(limit: Int = 100): Flow<List<OutboxItem>>
    suspend fun markSent(id: Long, sentAtMs: Long, httpCode: Int)
    suspend fun recordRetryAttempt(
        id: Long,
        attemptCount: Int,
        httpCode: Int?,
        errorMessage: String?
    )
    suspend fun markFailedPermanent(
        id: Long,
        httpCode: Int?,
        errorMessage: String?
    )
    suspend fun pruneCompleted(retentionDays: Int): Int
    suspend fun clearAll()
}

interface AuditLogRepository {
    suspend fun log(
        level: LogLevel,
        category: String,
        sender: String?,
        message: String,
        httpCode: Int? = null
    )

    fun observeRecent(limit: Int = 200): Flow<List<AuditLogEntry>>
    suspend fun prune(retentionDays: Int): Int
    suspend fun clearAll()
}
