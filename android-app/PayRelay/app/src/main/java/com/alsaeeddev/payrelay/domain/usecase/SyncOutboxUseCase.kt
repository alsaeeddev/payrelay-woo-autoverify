package com.alsaeeddev.payrelay.domain.usecase

import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.data.remote.MpavApi
import com.alsaeeddev.payrelay.data.remote.SignedRequestFactory
import com.alsaeeddev.payrelay.data.remote.dto.SmsPayload
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import java.io.IOException
import javax.inject.Inject

sealed class SyncOutcome {
    data class Completed(val deliveredCount: Int, val droppedCount: Int) : SyncOutcome()
    data object RetryRecommended : SyncOutcome()
    data object Skipped : SyncOutcome()
}

class SyncOutboxUseCase @Inject constructor(
    private val settingsRepository: SettingsRepository,
    private val pairingRepository: PairingRepository,
    private val outboxRepository: OutboxRepository,
    private val auditLogRepository: AuditLogRepository,
    private val signedRequestFactory: SignedRequestFactory,
    private val mpavApi: MpavApi,
    private val timeProvider: TimeProvider
) {

    suspend operator fun invoke(): SyncOutcome {
        val settings = settingsRepository.getSettingsSnapshot()
        if (!settings.consentAccepted || !settings.isPaired || settings.forwardingPaused) {
            return SyncOutcome.Skipped
        }

        val credentials = pairingRepository.getDecryptedCredentials()
            ?: return SyncOutcome.Skipped

        val pendingItems = outboxRepository.getPendingInOrder()
        if (pendingItems.isEmpty()) {
            pruneOldRecords(settings.retentionDays)
            return SyncOutcome.Completed(deliveredCount = 0, droppedCount = 0)
        }

        var delivered = 0
        var dropped = 0
        var shouldRetry = false

        for (item in pendingItems) {
            val payload = SmsPayload(
                idempotencyKey = item.idempotencyKey,
                sender = item.sender,
                body = item.body,
                receivedAtMs = item.receivedAtMs,
                simSlot = item.simSlot
            )

            val prepared = signedRequestFactory.createSignedPost(
                normalizedSiteUrl = credentials.siteUrl,
                endpoint = "sms",
                deviceId = credentials.deviceId,
                secret = credentials.secret,
                serializer = SmsPayload.serializer(),
                payload = payload
            )

            try {
                val response = mpavApi.sendSms(
                    url = prepared.url.toString(),
                    deviceId = prepared.headers.deviceId,
                    timestamp = prepared.headers.timestampSeconds,
                    nonce = prepared.headers.nonce,
                    signature = prepared.headers.signature,
                    body = prepared.requestBody
                )

                val code = response.code()
                when {
                    response.isSuccessful -> {
                        val ackStatus = response.body()?.status ?: "accepted"
                        val now = timeProvider.epochMillis()
                        outboxRepository.markSent(item.id, sentAtMs = now, httpCode = code)
                        settingsRepository.recordLastSync(now)
                        auditLogRepository.log(
                            level = LogLevel.INFO,
                            category = "SYNC",
                            sender = item.sender,
                            message = "Delivered SMS (status: $ackStatus)",
                            httpCode = code
                        )
                        delivered++
                    }

                    code == 429 || code >= 500 -> {
                        val nextAttempt = item.attemptCount + 1
                        val message = "Transient HTTP $code; scheduled retry #$nextAttempt"
                        outboxRepository.recordRetryAttempt(item.id, nextAttempt, code, message)
                        auditLogRepository.log(
                            level = LogLevel.WARN,
                            category = "SYNC",
                            sender = item.sender,
                            message = message,
                            httpCode = code
                        )
                        settingsRepository.setLastError("Server busy or unavailable (HTTP $code). Retrying.")
                        shouldRetry = true
                        break
                    }

                    code == 401 || code == 403 -> {
                        val nextAttempt = item.attemptCount + 1
                        val skewMessage =
                            "Auth/signature rejected (HTTP $code). Verify device clock uses automatic network time or re-pair."
                        outboxRepository.recordRetryAttempt(item.id, nextAttempt, code, skewMessage)
                        auditLogRepository.log(
                            level = LogLevel.ERROR,
                            category = "SECURITY",
                            sender = item.sender,
                            message = skewMessage,
                            httpCode = code
                        )
                        settingsRepository.setLastError(skewMessage)
                        shouldRetry = true
                        break
                    }

                    else -> {
                        val dropMessage = "Dropped permanently after HTTP $code"
                        outboxRepository.markFailedPermanent(item.id, code, dropMessage)
                        auditLogRepository.log(
                            level = LogLevel.ERROR,
                            category = "SYNC",
                            sender = item.sender,
                            message = dropMessage,
                            httpCode = code
                        )
                        dropped++
                    }
                }
            } catch (ioe: IOException) {
                val nextAttempt = item.attemptCount + 1
                val networkMsg = "Network error (${ioe.javaClass.simpleName}); retry #$nextAttempt"
                outboxRepository.recordRetryAttempt(item.id, nextAttempt, null, networkMsg)
                auditLogRepository.log(
                    level = LogLevel.WARN,
                    category = "SYNC",
                    sender = item.sender,
                    message = networkMsg
                )
                settingsRepository.setLastError("Network unavailable during sync. Retrying.")
                shouldRetry = true
                break
            }
        }

        pruneOldRecords(settings.retentionDays)
        return if (shouldRetry) {
            SyncOutcome.RetryRecommended
        } else {
            SyncOutcome.Completed(deliveredCount = delivered, droppedCount = dropped)
        }
    }

    private suspend fun pruneOldRecords(retentionDays: Int) {
        outboxRepository.pruneCompleted(retentionDays)
        auditLogRepository.prune(retentionDays)
    }
}
