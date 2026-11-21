package com.alsaeeddev.payrelay.ui.screens.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.domain.usecase.HeartbeatOutcome
import com.alsaeeddev.payrelay.domain.usecase.SendHeartbeatUseCase
import com.alsaeeddev.payrelay.worker.BatteryOptimizationChecker
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import javax.inject.Inject

data class HomeUiState(
    val isPaired: Boolean = false,
    val siteUrl: String = "",
    val deviceId: String = "",
    val isPaused: Boolean = false,
    val pendingCount: Int = 0,
    val lastSyncAtMs: Long? = null,
    val lastHeartbeatAtMs: Long? = null,
    val lastErrorRedacted: String? = null,
    val isBatteryOptimized: Boolean = false,
    val isTestingConnection: Boolean = false,
    val testFeedback: String? = null
)

@HiltViewModel
class HomeViewModel @Inject constructor(
    private val settingsRepository: SettingsRepository,
    private val outboxRepository: OutboxRepository,
    private val auditLogRepository: AuditLogRepository,
    private val sendHeartbeatUseCase: SendHeartbeatUseCase,
    private val syncWorkScheduler: SyncWorkScheduler,
    private val batteryOptimizationChecker: BatteryOptimizationChecker
) : ViewModel() {

    private val isTesting = MutableStateFlow(false)
    private val testFeedback = MutableStateFlow<String?>(null)
    private val batteryOptimized = MutableStateFlow(batteryOptimizationChecker.isBatteryOptimized())

    val uiState: StateFlow<HomeUiState> = combine(
        settingsRepository.observeSettings(),
        outboxRepository.observePendingCount(),
        batteryOptimized,
        isTesting,
        testFeedback
    ) { settings, pending, isOptimized, testing, feedback ->
        HomeUiState(
            isPaired = settings.isPaired,
            siteUrl = settings.siteUrl.orEmpty(),
            deviceId = settings.deviceId.orEmpty(),
            isPaused = settings.forwardingPaused,
            pendingCount = pending,
            lastSyncAtMs = settings.lastSyncAtMs,
            lastHeartbeatAtMs = settings.lastHeartbeatAtMs,
            lastErrorRedacted = settings.lastErrorRedacted,
            isBatteryOptimized = isOptimized,
            isTestingConnection = testing,
            testFeedback = feedback
        )
    }.stateIn(
        scope = viewModelScope,
        started = SharingStarted.WhileSubscribed(5_000),
        initialValue = HomeUiState(isBatteryOptimized = batteryOptimizationChecker.isBatteryOptimized())
    )

    fun refreshBatteryStatus() {
        batteryOptimized.value = batteryOptimizationChecker.isBatteryOptimized()
    }

    fun togglePauseResume() {
        viewModelScope.launch {
            val current = settingsRepository.getSettingsSnapshot()
            val nextPaused = !current.forwardingPaused
            settingsRepository.setForwardingPaused(nextPaused)
            auditLogRepository.log(
                level = LogLevel.INFO,
                category = "SYSTEM",
                sender = null,
                message = if (nextPaused) "Forwarding paused by merchant" else "Forwarding resumed by merchant"
            )
            if (!nextPaused && outboxRepository.countPending() > 0) {
                syncWorkScheduler.enqueueExpeditedSmsSync()
            }
        }
    }

    fun syncNow() {
        syncWorkScheduler.enqueueExpeditedSmsSync()
        testFeedback.value = "Sync job queued"
    }

    fun testConnection() {
        if (isTesting.value) return
        viewModelScope.launch {
            isTesting.value = true
            testFeedback.value = null
            batteryOptimized.value = batteryOptimizationChecker.isBatteryOptimized()
            when (val outcome = sendHeartbeatUseCase(manualCheck = true)) {
                HeartbeatOutcome.Success -> {
                    testFeedback.value = "Connection healthy — signed heartbeat accepted"
                }
                HeartbeatOutcome.Skipped -> {
                    testFeedback.value = "Device is not paired"
                }
                is HeartbeatOutcome.AuthOrClockSkewError -> {
                    testFeedback.value = outcome.message
                }
                is HeartbeatOutcome.RetryableFailure -> {
                    testFeedback.value = outcome.reason
                }
            }
            isTesting.value = false
        }
    }

    fun clearFeedback() {
        testFeedback.value = null
    }
}
