package com.alsaeeddev.payrelay.domain.usecase

import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.data.local.prefs.DefaultAllowlist
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import javax.inject.Inject

sealed class EnqueueOutcome {
    data object Dropped : EnqueueOutcome()
    data object Duplicate : EnqueueOutcome()
    data class Enqueued(val outboxId: Long) : EnqueueOutcome()
}

class FilterAndEnqueueSmsUseCase @Inject constructor(
    private val settingsRepository: SettingsRepository,
    private val outboxRepository: OutboxRepository,
    private val auditLogRepository: AuditLogRepository,
    private val syncWorkScheduler: SyncWorkScheduler
) {

    suspend operator fun invoke(
        sender: String,
        body: String,
        receivedAtMs: Long,
        simSlot: Int?
    ): EnqueueOutcome {
        val cleanSender = sender.trim()
        if (cleanSender.isEmpty() || body.isBlank()) {
            return EnqueueOutcome.Dropped
        }

        val settings = settingsRepository.getSettingsSnapshot()
        if (!settings.consentAccepted || !settings.isPaired || settings.forwardingPaused) {
            return EnqueueOutcome.Dropped
        }

        // Strict allowlist check: non-allowlisted SMS are dropped immediately — never stored, logged, or sent.
        if (!DefaultAllowlist.isAllowed(cleanSender, settings.allowlist)) {
            return EnqueueOutcome.Dropped
        }

        val insertedId = outboxRepository.enqueueIfNew(
            sender = cleanSender,
            body = body,
            receivedAtMs = receivedAtMs,
            simSlot = simSlot
        )

        if (insertedId == -1L) {
            return EnqueueOutcome.Duplicate
        }

        val simLabel = simSlot?.let { "SIM ${it + 1}" } ?: "SIM ?"
        auditLogRepository.log(
            level = LogLevel.INFO,
            category = "SMS_FILTER",
            sender = cleanSender,
            message = "Queued payment SMS ($simLabel)"
        )
        syncWorkScheduler.enqueueExpeditedSmsSync()
        return EnqueueOutcome.Enqueued(insertedId)
    }
}
