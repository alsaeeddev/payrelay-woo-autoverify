package com.alsaeeddev.payrelay.ui.screens.logs

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.domain.model.AuditLogEntry
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import javax.inject.Inject

enum class LogFilter {
    ALL,
    ISSUES,
    SYNC
}

data class LogsUiState(
    val entries: List<AuditLogEntry> = emptyList(),
    val filter: LogFilter = LogFilter.ALL
)

@HiltViewModel
class LogsViewModel @Inject constructor(
    private val auditLogRepository: AuditLogRepository
) : ViewModel() {

    private val selectedFilter = MutableStateFlow(LogFilter.ALL)

    val uiState: StateFlow<LogsUiState> = combine(
        auditLogRepository.observeRecent(200),
        selectedFilter
    ) { entries, filter ->
        val filtered = when (filter) {
            LogFilter.ALL -> entries
            LogFilter.ISSUES -> entries.filter { it.level == LogLevel.WARN || it.level == LogLevel.ERROR }
            LogFilter.SYNC -> entries.filter { it.category == "SYNC" || it.category == "SMS_FILTER" }
        }
        LogsUiState(entries = filtered, filter = filter)
    }.stateIn(
        scope = viewModelScope,
        started = SharingStarted.WhileSubscribed(5_000),
        initialValue = LogsUiState()
    )

    fun setFilter(filter: LogFilter) {
        selectedFilter.value = filter
    }

    fun clearLogs() {
        viewModelScope.launch {
            auditLogRepository.clearAll()
        }
    }
}
