package com.alsaeeddev.payrelay.ui.screens.onboarding

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.alsaeeddev.payrelay.data.remote.PairingQrParser
import com.alsaeeddev.payrelay.data.remote.QrParseResult
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.domain.usecase.PairDeviceUseCase
import com.alsaeeddev.payrelay.domain.usecase.PairOutcome
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class QrPairUiState(
    val isPairing: Boolean = false,
    val manualSiteUrl: String = "",
    val manualToken: String = "",
    val showManualEntry: Boolean = false,
    val errorMessage: String? = null,
    val pairedDeviceId: String? = null
)

@HiltViewModel
class OnboardingViewModel @Inject constructor(
    private val settingsRepository: SettingsRepository,
    private val pairingQrParser: PairingQrParser,
    private val pairDeviceUseCase: PairDeviceUseCase
) : ViewModel() {

    private val _uiState = MutableStateFlow(QrPairUiState())
    val uiState: StateFlow<QrPairUiState> = _uiState.asStateFlow()

    fun acceptConsent(onComplete: () -> Unit) {
        viewModelScope.launch {
            settingsRepository.setConsentAccepted(true)
            onComplete()
        }
    }

    fun onManualSiteUrlChanged(value: String) {
        _uiState.update { it.copy(manualSiteUrl = value, errorMessage = null) }
    }

    fun onManualTokenChanged(value: String) {
        _uiState.update { it.copy(manualToken = value, errorMessage = null) }
    }

    fun setManualEntryVisible(visible: Boolean) {
        _uiState.update { it.copy(showManualEntry = visible, errorMessage = null) }
    }

    fun clearError() {
        _uiState.update { it.copy(errorMessage = null) }
    }

    fun onQrCodeScanned(rawQrText: String, onResumeScanner: () -> Unit) {
        if (_uiState.value.isPairing) return

        when (val parsed = pairingQrParser.parse(rawQrText)) {
            is QrParseResult.Invalid -> {
                _uiState.update { it.copy(errorMessage = parsed.reason) }
                onResumeScanner()
            }
            is QrParseResult.Valid -> {
                submitPairing(
                    siteUrl = parsed.normalizedSiteUrl,
                    token = parsed.pairingToken,
                    onFailure = onResumeScanner
                )
            }
        }
    }

    fun submitManualPairing() {
        val state = _uiState.value
        if (state.isPairing) return
        submitPairing(
            siteUrl = state.manualSiteUrl,
            token = state.manualToken,
            onFailure = {}
        )
    }

    private fun submitPairing(
        siteUrl: String,
        token: String,
        onFailure: () -> Unit
    ) {
        viewModelScope.launch {
            _uiState.update { it.copy(isPairing = true, errorMessage = null) }
            when (val outcome = pairDeviceUseCase(siteUrl = siteUrl, pairingToken = token)) {
                is PairOutcome.Success -> {
                    _uiState.update {
                        it.copy(
                            isPairing = false,
                            pairedDeviceId = outcome.deviceId,
                            errorMessage = null
                        )
                    }
                }
                is PairOutcome.Failure -> {
                    _uiState.update {
                        it.copy(
                            isPairing = false,
                            errorMessage = outcome.reason
                        )
                    }
                    onFailure()
                }
            }
        }
    }
}
