package com.alsaeeddev.payrelay.ui.screens.reliability

import androidx.lifecycle.ViewModel
import com.alsaeeddev.payrelay.worker.BatteryOptimizationChecker
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import javax.inject.Inject

data class ReliabilityGuideUiState(
    val detectedBrand: OemBrand = OemDetector.detectBrand(),
    val selectedBrand: OemBrand = OemDetector.detectBrand(),
    val orderedGuides: List<OemGuide> = OemReliabilityCatalog.orderedGuides(OemDetector.detectBrand()),
    val isBatteryOptimized: Boolean = true
) {
    val activeGuide: OemGuide
        get() = OemReliabilityCatalog.guideFor(selectedBrand)
}

@HiltViewModel
class ReliabilityGuideViewModel @Inject constructor(
    private val batteryOptimizationChecker: BatteryOptimizationChecker
) : ViewModel() {

    private val detected = OemDetector.detectBrand()

    private val _uiState = MutableStateFlow(
        ReliabilityGuideUiState(
            detectedBrand = detected,
            selectedBrand = detected,
            orderedGuides = OemReliabilityCatalog.orderedGuides(detected),
            isBatteryOptimized = batteryOptimizationChecker.isBatteryOptimized()
        )
    )
    val uiState: StateFlow<ReliabilityGuideUiState> = _uiState.asStateFlow()

    fun selectBrand(brand: OemBrand) {
        _uiState.update { it.copy(selectedBrand = brand) }
    }

    fun refreshStatus() {
        _uiState.update {
            it.copy(isBatteryOptimized = batteryOptimizationChecker.isBatteryOptimized())
        }
    }
}
