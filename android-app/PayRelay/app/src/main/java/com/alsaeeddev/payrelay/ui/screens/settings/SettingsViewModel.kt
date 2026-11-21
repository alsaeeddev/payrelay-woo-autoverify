package com.alsaeeddev.payrelay.ui.screens.settings

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.domain.usecase.UnpairAndWipeUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import javax.inject.Inject

data class SettingsUiState(
    val siteUrl: String = "",
    val deviceId: String = "",
    val allowlist: List<String> = emptyList(),
    val retentionDays: Int = 14,
    val biometricLockEnabled: Boolean = false,
    val feedbackMessage: String? = null,
    val errorMessage: String? = null
)

@HiltViewModel
class SettingsViewModel @Inject constructor(
    private val settingsRepository: SettingsRepository,
    private val unpairAndWipeUseCase: UnpairAndWipeUseCase
) : ViewModel() {

    private val feedback = MutableStateFlow<String?>(null)
    private val error = MutableStateFlow<String?>(null)

    val uiState: StateFlow<SettingsUiState> = combine(
        settingsRepository.observeSettings(),
        feedback,
        error
    ) { settings, msg, err ->
        SettingsUiState(
            siteUrl = settings.siteUrl.orEmpty(),
            deviceId = settings.deviceId.orEmpty(),
            allowlist = settings.allowlist.sortedBy { it.lowercase() },
            retentionDays = settings.retentionDays,
            biometricLockEnabled = settings.biometricLockEnabled,
            feedbackMessage = msg,
            errorMessage = err
        )
    }.stateIn(
        scope = viewModelScope,
        started = SharingStarted.WhileSubscribed(5_000),
        initialValue = SettingsUiState()
    )

    fun updateSiteUrl(rawUrl: String) {
        viewModelScope.launch {
            error.value = null
            feedback.value = null
            try {
                settingsRepository.updateSiteUrl(rawUrl)
                feedback.value = "WordPress HTTPS URL updated"
            } catch (e: IllegalArgumentException) {
                error.value = e.message ?: "Invalid HTTPS URL"
            }
        }
    }

    fun addAllowedSender(sender: String) {
        viewModelScope.launch {
            error.value = null
            try {
                settingsRepository.addAllowedSender(sender)
                feedback.value = "Sender added to allowlist"
            } catch (e: IllegalArgumentException) {
                error.value = e.message ?: "Invalid sender ID"
            }
        }
    }

    fun removeAllowedSender(sender: String) {
        viewModelScope.launch {
            settingsRepository.removeAllowedSender(sender)
        }
    }

    fun resetDefaultAllowlist() {
        viewModelScope.launch {
            settingsRepository.resetDefaultAllowlist()
            feedback.value = "Default Pakistani wallet & bank senders restored"
        }
    }

    fun setRetentionDays(days: Int) {
        viewModelScope.launch {
            settingsRepository.setRetentionDays(days)
        }
    }

    fun setBiometricLockEnabled(enabled: Boolean) {
        viewModelScope.launch {
            settingsRepository.setBiometricLockEnabled(enabled)
        }
    }

    fun unpairAndWipe(onComplete: () -> Unit) {
        viewModelScope.launch {
            unpairAndWipeUseCase()
            onComplete()
        }
    }

    fun clearMessages() {
        feedback.value = null
        error.value = null
    }
}
