package com.alsaeeddev.payrelay.ui.screens.logs

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.DeleteOutline
import androidx.compose.material.icons.filled.History
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.alsaeeddev.payrelay.R
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.domain.model.AuditLogEntry
import java.text.DateFormat
import java.util.Date

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LogsScreen(
    bottomBar: @Composable () -> Unit,
    viewModel: LogsViewModel = hiltViewModel()
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.logs_title)) },
                actions = {
                    if (state.entries.isNotEmpty()) {
                        IconButton(
                            onClick = viewModel::clearLogs,
                            modifier = Modifier.testTag("clear_logs_button")
                        ) {
                            Icon(
                                imageVector = Icons.Default.DeleteOutline,
                                contentDescription = stringResource(R.string.logs_clear_desc)
                            )
                        }
                    }
                }
            )
        },
        bottomBar = bottomBar
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Column(
                modifier = Modifier
                    .widthIn(max = 600.dp)
                    .fillMaxSize()
            ) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    FilterChip(
                        selected = state.filter == LogFilter.ALL,
                        onClick = { viewModel.setFilter(LogFilter.ALL) },
                        label = { Text(stringResource(R.string.logs_filter_all)) },
                        modifier = Modifier.testTag("logs_filter_all")
                    )
                    FilterChip(
                        selected = state.filter == LogFilter.SYNC,
                        onClick = { viewModel.setFilter(LogFilter.SYNC) },
                        label = { Text(stringResource(R.string.logs_filter_sync)) },
                        modifier = Modifier.testTag("logs_filter_sync")
                    )
                    FilterChip(
                        selected = state.filter == LogFilter.ISSUES,
                        onClick = { viewModel.setFilter(LogFilter.ISSUES) },
                        label = { Text(stringResource(R.string.logs_filter_issues)) },
                        modifier = Modifier.testTag("logs_filter_issues")
                    )
                }

                if (state.entries.isEmpty()) {
                    Column(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(32.dp)
                            .testTag("logs_empty_state"),
                        verticalArrangement = Arrangement.Center,
                        horizontalAlignment = Alignment.CenterHorizontally
                    ) {
                        Icon(
                            imageVector = Icons.Default.History,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.size(48.dp)
                        )
                        Text(
                            text = stringResource(R.string.logs_empty_title),
                            style = MaterialTheme.typography.titleMedium,
                            modifier = Modifier.padding(top = 12.dp)
                        )
                        Text(
                            text = stringResource(R.string.logs_empty_subtitle),
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.padding(top = 4.dp)
                        )
                    }
                } else {
                    LazyColumn(
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                        modifier = Modifier.testTag("logs_list")
                    ) {
                        items(state.entries, key = { it.id }) { entry ->
                            AuditLogCard(entry = entry)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun AuditLogCard(entry: AuditLogEntry) {
    val containerColor = when (entry.level) {
        LogLevel.ERROR -> MaterialTheme.colorScheme.errorContainer.copy(alpha = 0.55f)
        LogLevel.WARN -> MaterialTheme.colorScheme.tertiaryContainer.copy(alpha = 0.55f)
        LogLevel.INFO -> MaterialTheme.colorScheme.surfaceContainerLow
    }

    Card(
        colors = CardDefaults.cardColors(containerColor = containerColor),
        modifier = Modifier.fillMaxWidth()
    ) {
        Column(
            modifier = Modifier.padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AssistChip(
                        onClick = {},
                        label = {
                            Text(
                                text = entry.category,
                                style = MaterialTheme.typography.labelSmall
                            )
                        }
                    )
                    entry.senderMasked?.let { sender ->
                        AssistChip(
                            onClick = {},
                            label = {
                                Text(
                                    text = sender,
                                    style = MaterialTheme.typography.labelSmall
                                )
                            }
                        )
                    }
                }
                entry.httpCode?.let { code ->
                    Text(
                        text = "HTTP $code",
                        style = MaterialTheme.typography.labelMedium
                    )
                }
            }
            Text(
                text = entry.message,
                style = MaterialTheme.typography.bodyMedium
            )
            Text(
                text = DateFormat.getDateTimeInstance(DateFormat.SHORT, DateFormat.MEDIUM)
                    .format(Date(entry.timestampMs)),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}
