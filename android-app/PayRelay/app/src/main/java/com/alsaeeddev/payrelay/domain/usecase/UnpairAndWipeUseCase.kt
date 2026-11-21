package com.alsaeeddev.payrelay.domain.usecase

import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import javax.inject.Inject

class UnpairAndWipeUseCase @Inject constructor(
    private val syncWorkScheduler: SyncWorkScheduler,
    private val pairingRepository: PairingRepository,
    private val outboxRepository: OutboxRepository,
    private val auditLogRepository: AuditLogRepository,
    private val settingsRepository: SettingsRepository
) {

    suspend operator fun invoke() {
        syncWorkScheduler.cancelAllWork()
        pairingRepository.clearPairing()
        outboxRepository.clearAll()
        auditLogRepository.clearAll()
        settingsRepository.clearAll()
    }
}
