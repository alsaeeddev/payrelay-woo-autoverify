package com.alsaeeddev.payrelay.ui.screens.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.DeleteForever
import androidx.compose.material.icons.filled.Fingerprint
import androidx.compose.material.icons.filled.Restore
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.InputChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.alsaeeddev.payrelay.R
import com.alsaeeddev.payrelay.ui.components.BiometricCapability
import com.alsaeeddev.payrelay.ui.components.findActivity
import kotlin.math.roundToInt

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun SettingsScreen(
    bottomBar: @Composable () -> Unit,
    onOpenReliabilityGuide: () -> Unit = {},
    onWiped: () -> Unit,
    viewModel: SettingsViewModel = hiltViewModel()
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val context = LocalContext.current
    val activity = context.findActivity() as? FragmentActivity
    val canUseBiometric = remember(activity) {
        activity != null && BiometricCapability.canAuthenticate(activity)
    }

    var siteUrlInput by remember(state.siteUrl) { mutableStateOf(state.siteUrl) }
    var newSenderInput by remember { mutableStateOf("") }
    var showWipeDialog by remember { mutableStateOf(false) }

    val canAdd = newSenderInput.isNotBlank()
    val addSender = {
        val sender = newSenderInput.trim()
        if (sender.isNotEmpty()) {
            viewModel.addAllowedSender(sender)
            newSenderInput = ""
        }
    }

    LaunchedEffect(state.feedbackMessage, state.errorMessage) {
        val msg = state.errorMessage ?: state.feedbackMessage
        if (msg != null) {
            snackbarHostState.showSnackbar(msg)
            viewModel.clearMessages()
        }
    }

    if (showWipeDialog) {
        AlertDialog(
            onDismissRequest = { showWipeDialog = false },
            title = { Text(stringResource(R.string.settings_wipe_dialog_title)) },
            text = { Text(stringResource(R.string.settings_wipe_dialog_body)) },
            confirmButton = {
                Button(
                    onClick = {
                        showWipeDialog = false
                        viewModel.unpairAndWipe(onWiped)
                    },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = MaterialTheme.colorScheme.error
                    ),
                    modifier = Modifier.testTag("confirm_wipe_button")
                ) {
                    Text(stringResource(R.string.settings_wipe_confirm))
                }
            },
            dismissButton = {
                TextButton(
                    onClick = { showWipeDialog = false },
                    modifier = Modifier.testTag("cancel_wipe_button")
                ) {
                    Text(stringResource(R.string.action_cancel))
                }
            }
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.settings_title)) }
            )
        },
        bottomBar = bottomBar,
        snackbarHost = { SnackbarHost(snackbarHostState) }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Column(
                modifier = Modifier
                    .widthIn(max = 600.dp)
                    .fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(16.dp)
            ) {
                Card(
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceContainerLow
                    )
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Text(
                            text = stringResource(R.string.settings_site_section),
                            style = MaterialTheme.typography.titleMedium
                        )
                        OutlinedTextField(
                            value = siteUrlInput,
                            onValueChange = { siteUrlInput = it },
                            label = { Text(stringResource(R.string.pair_site_url_label)) },
                            singleLine = true,
                            modifier = Modifier
                                .fillMaxWidth()
                                .testTag("settings_site_url_input")
                        )
                        Button(
                            onClick = { viewModel.updateSiteUrl(siteUrlInput) },
                            modifier = Modifier
                                .heightIn(min = 48.dp)
                                .testTag("save_site_url_button")
                        ) {
                            Text(stringResource(R.string.settings_save_url))
                        }
                    }
                }

                Card(
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceContainerLow
                    )
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text(
                                text = stringResource(R.string.settings_allowlist_section),
                                style = MaterialTheme.typography.titleMedium
                            )
                            TextButton(
                                onClick = viewModel::resetDefaultAllowlist,
                                modifier = Modifier
                                    .heightIn(min = 48.dp)
                                    .testTag("reset_allowlist_button")
                            ) {
                                Icon(Icons.Default.Restore, contentDescription = null)
                                Spacer(Modifier.padding(start = 4.dp))
                                Text(stringResource(R.string.settings_reset_defaults))
                            }
                        }

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            OutlinedTextField(
                                value = newSenderInput,
                                onValueChange = { newSenderInput = it },
                                label = {
                                    Text(
                                        text = stringResource(R.string.settings_add_sender_hint),
                                        maxLines = 1,
                                        softWrap = false,
                                        overflow = TextOverflow.Ellipsis
                                    )
                                },
                                singleLine = true,
                                modifier = Modifier
                                    .weight(1f)
                                    .testTag("add_sender_input")
                            )

                            IconButton(
                                onClick = addSender,
                                enabled = canAdd,
                                modifier = Modifier
                                    .size(48.dp)
                                    .testTag("add_sender_button"),
                                colors = IconButtonDefaults.iconButtonColors(
                                    containerColor = MaterialTheme.colorScheme.primary,
                                    contentColor = MaterialTheme.colorScheme.onPrimary,
                                    disabledContainerColor = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.12f),
                                    disabledContentColor = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.38f)
                                )
                            ) {
                                Icon(
                                    imageVector = Icons.Default.Add,
                                    contentDescription = stringResource(R.string.settings_add_sender_desc)
                                )
                            }
/*                            IconButton(
                                onClick = {
                                    if (newSenderInput.isNotBlank()) {
                                        viewModel.addAllowedSender(newSenderInput)
                                        newSenderInput = ""
                                    }
                                },
                                modifier = Modifier.testTag("add_sender_button")
                            ) {
                                Icon(
                                    imageVector = Icons.Default.Add,
                                    contentDescription = stringResource(R.string.settings_add_sender_desc)
                                )
                            }*/
                        }

                        FlowRow(
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                            verticalArrangement = Arrangement.spacedBy(4.dp)
                        ) {
                            state.allowlist.forEach { sender ->
                                InputChip(
                                    selected = true,
                                    onClick = { viewModel.removeAllowedSender(sender) },
                                    label = { Text(sender) },
                                    trailingIcon = {
                                        Icon(
                                            imageVector = Icons.Default.Close,
                                            contentDescription = "Remove $sender"
                                        )
                                    }
                                )
                            }
                        }
                    }
                }

                Card(
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceContainerLow
                    )
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        Text(
                            text = stringResource(
                                R.string.settings_retention_section,
                                state.retentionDays
                            ),
                            style = MaterialTheme.typography.titleMedium
                        )
                        Slider(
                            value = state.retentionDays.toFloat(),
                            onValueChange = { viewModel.setRetentionDays(it.roundToInt()) },
                            valueRange = 1f..90f,
                            modifier = Modifier.testTag("retention_slider")
                        )

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Row(
                                horizontalArrangement = Arrangement.spacedBy(10.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                modifier = Modifier.weight(1f)
                            ) {
                                Icon(Icons.Default.Fingerprint, contentDescription = null)
                                Column {
                                    Text(
                                        text = stringResource(R.string.settings_biometric_title),
                                        style = MaterialTheme.typography.bodyLarge
                                    )
                                    Text(
                                        text = if (canUseBiometric) {
                                            stringResource(R.string.settings_biometric_desc)
                                        } else {
                                            stringResource(R.string.settings_biometric_unavailable)
                                        },
                                        style = MaterialTheme.typography.bodyMedium,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                            }
                            Switch(
                                checked = state.biometricLockEnabled,
                                onCheckedChange = viewModel::setBiometricLockEnabled,
                                enabled = canUseBiometric,
                                modifier = Modifier.testTag("biometric_lock_switch")
                            )
                        }
                    }
                }

                Card(
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceContainerLow
                    )
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        Text(
                            text = stringResource(R.string.settings_reliability_section),
                            style = MaterialTheme.typography.titleMedium
                        )
                        Text(
                            text = stringResource(R.string.home_reliability_card_desc),
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                        OutlinedButton(
                            onClick = onOpenReliabilityGuide,
                            modifier = Modifier
                                .fillMaxWidth()
                                .heightIn(min = 48.dp)
                                .testTag("settings_reliability_guide_button")
                        ) {
                            Icon(Icons.Default.Tune, contentDescription = null)
                            Spacer(Modifier.padding(start = 8.dp))
                            Text(stringResource(R.string.settings_open_reliability_guide))
                        }
                    }
                }

                OutlinedButton(
                    onClick = { showWipeDialog = true },
                    colors = ButtonDefaults.outlinedButtonColors(
                        contentColor = MaterialTheme.colorScheme.error
                    ),
                    modifier = Modifier
                        .fillMaxWidth()
                        .heightIn(min = 50.dp)
                        .testTag("unpair_wipe_button")
                ) {
                    Icon(Icons.Default.DeleteForever, contentDescription = null)
                    Spacer(Modifier.padding(start = 8.dp))
                    Text(stringResource(R.string.settings_unpair_wipe))
                }
            }
        }
    }
}
