package com.alsaeeddev.payrelay.ui.screens.reliability

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Launch
import androidx.compose.material.icons.filled.BatteryAlert
import androidx.compose.material.icons.filled.BatteryChargingFull
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.SettingsApplications
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.alsaeeddev.payrelay.R
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReliabilityGuideScreen(
    onBack: () -> Unit,
    viewModel: ReliabilityGuideViewModel = hiltViewModel()
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val snackbarHostState = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    val openedBatteryMsg = stringResource(R.string.reliability_feedback_opened_battery)
    val openedAppInfoMsg = stringResource(R.string.reliability_feedback_opened_app_info)
    val failedMsg = stringResource(R.string.reliability_feedback_failed)

    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                viewModel.refreshStatus()
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    fun showOutcomeFeedback(outcome: LaunchOutcome, primaryRequested: Boolean) {
        val message = when (outcome) {
            LaunchOutcome.OpenedPrimary -> null
            LaunchOutcome.OpenedBatterySettings -> if (primaryRequested) openedBatteryMsg else null
            LaunchOutcome.OpenedAppDetailsFallback -> if (primaryRequested) openedAppInfoMsg else null
            LaunchOutcome.Failed -> failedMsg
        }
        if (message != null) {
            scope.launch { snackbarHostState.showSnackbar(message) }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.reliability_title)) },
                navigationIcon = {
                    IconButton(
                        onClick = onBack,
                        modifier = Modifier.testTag("reliability_back_button")
                    ) {
                        Icon(
                            imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = stringResource(R.string.action_back)
                        )
                    }
                },
                actions = {
                    IconButton(
                        onClick = viewModel::refreshStatus,
                        modifier = Modifier.testTag("reliability_refresh_button")
                    ) {
                        Icon(
                            imageVector = Icons.Default.Refresh,
                            contentDescription = stringResource(R.string.reliability_refresh_status)
                        )
                    }
                }
            )
        },
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
                BatteryStatusBanner(
                    isBatteryOptimized = state.isBatteryOptimized,
                    onOpenBatterySettings = {
                        val outcome = SafeSettingsLauncher.openBatteryOptimizationSettings(context)
                        showOutcomeFeedback(outcome, primaryRequested = false)
                    }
                )

                LazyRow(
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    contentPadding = PaddingValues(vertical = 4.dp),
                    modifier = Modifier.testTag("reliability_brand_selector")
                ) {
                    items(state.orderedGuides, key = { it.brand.id }) { guide ->
                        val isSelected = guide.brand == state.selectedBrand
                        val isDetected = guide.brand == state.detectedBrand
                        val brandLabel = stringResource(guide.brand.displayNameRes)
                        val chipText = if (isDetected) {
                            "$brandLabel (${stringResource(R.string.reliability_your_phone_badge)})"
                        } else {
                            brandLabel
                        }
                        FilterChip(
                            selected = isSelected,
                            onClick = { viewModel.selectBrand(guide.brand) },
                            label = { Text(chipText) },
                            leadingIcon = if (isSelected) {
                                {
                                    Icon(
                                        imageVector = Icons.Default.Check,
                                        contentDescription = null,
                                        modifier = Modifier.size(16.dp)
                                    )
                                }
                            } else {
                                null
                            },
                            modifier = Modifier
                                .heightIn(min = 48.dp)
                                .testTag("brand_chip_${guide.brand.id}")
                        )
                    }
                }

                val activeGuide = state.activeGuide

                Card(
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceContainerLow
                    ),
                    modifier = Modifier
                        .fillMaxWidth()
                        .testTag("reliability_guide_card")
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Row(
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = stringResource(activeGuide.brand.displayNameRes),
                                    style = MaterialTheme.typography.titleLarge
                                )
                                Text(
                                    text = activeGuide.brand.osLabel,
                                    style = MaterialTheme.typography.labelLarge,
                                    color = MaterialTheme.colorScheme.primary
                                )
                            }
                            if (activeGuide.brand == state.detectedBrand) {
                                Surface(
                                    color = MaterialTheme.colorScheme.primaryContainer,
                                    shape = MaterialTheme.shapes.small
                                ) {
                                    Text(
                                        text = stringResource(R.string.reliability_your_phone_badge),
                                        style = MaterialTheme.typography.labelMedium,
                                        color = MaterialTheme.colorScheme.onPrimaryContainer,
                                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp)
                                    )
                                }
                            }
                        }

                        Text(
                            text = activeGuide.summary,
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )

                        activeGuide.steps.forEachIndexed { index, step ->
                            StepRow(
                                stepNumber = index + 1,
                                step = step
                            )
                        }

                        if (activeGuide.autostartIntents.isNotEmpty()) {
                            Button(
                                onClick = {
                                    val outcome = SafeSettingsLauncher.openOemAutostartOrFallback(
                                        context = context,
                                        guide = activeGuide
                                    )
                                    showOutcomeFeedback(outcome, primaryRequested = true)
                                },
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .heightIn(min = 50.dp)
                                    .testTag("open_oem_autostart_button")
                            ) {
                                Icon(Icons.Default.Tune, contentDescription = null)
                                Spacer(Modifier.padding(start = 8.dp))
                                Text(stringResource(R.string.reliability_open_autostart))
                            }
                        }

                        OutlinedButton(
                            onClick = {
                                val outcome = SafeSettingsLauncher.openBatteryOptimizationSettings(context)
                                showOutcomeFeedback(outcome, primaryRequested = false)
                            },
                            modifier = Modifier
                                .fillMaxWidth()
                                .heightIn(min = 48.dp)
                                .testTag("open_battery_list_button")
                        ) {
                            Icon(Icons.AutoMirrored.Filled.Launch, contentDescription = null)
                            Spacer(Modifier.padding(start = 8.dp))
                            Text(stringResource(R.string.reliability_open_battery_settings))
                        }

                        OutlinedButton(
                            onClick = {
                                val outcome = SafeSettingsLauncher.openAppDetails(context)
                                showOutcomeFeedback(outcome, primaryRequested = false)
                            },
                            modifier = Modifier
                                .fillMaxWidth()
                                .heightIn(min = 48.dp)
                                .testTag("open_app_info_button")
                        ) {
                            Icon(Icons.Default.SettingsApplications, contentDescription = null)
                            Spacer(Modifier.padding(start = 8.dp))
                            Text(stringResource(R.string.reliability_open_app_info))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun BatteryStatusBanner(
    isBatteryOptimized: Boolean,
    onOpenBatterySettings: () -> Unit
) {
    val containerColor = if (isBatteryOptimized) {
        MaterialTheme.colorScheme.tertiaryContainer
    } else {
        MaterialTheme.colorScheme.primaryContainer
    }
    val contentColor = if (isBatteryOptimized) {
        MaterialTheme.colorScheme.onTertiaryContainer
    } else {
        MaterialTheme.colorScheme.onPrimaryContainer
    }

    Card(
        colors = CardDefaults.cardColors(containerColor = containerColor),
        modifier = Modifier
            .fillMaxWidth()
            .testTag("reliability_battery_status_card")
    ) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            Row(
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(
                    imageVector = if (isBatteryOptimized) {
                        Icons.Default.BatteryAlert
                    } else {
                        Icons.Default.BatteryChargingFull
                    },
                    contentDescription = null,
                    tint = contentColor
                )
                Text(
                    text = if (isBatteryOptimized) {
                        stringResource(R.string.reliability_status_restricted_title)
                    } else {
                        stringResource(R.string.reliability_status_unrestricted_title)
                    },
                    style = MaterialTheme.typography.titleMedium,
                    color = contentColor
                )
            }
            Text(
                text = if (isBatteryOptimized) {
                    stringResource(R.string.reliability_status_restricted_desc)
                } else {
                    stringResource(R.string.reliability_status_unrestricted_desc)
                },
                style = MaterialTheme.typography.bodyMedium,
                color = contentColor
            )
            if (isBatteryOptimized) {
                Button(
                    onClick = onOpenBatterySettings,
                    modifier = Modifier
                        .fillMaxWidth()
                        .heightIn(min = 48.dp)
                        .testTag("open_battery_settings_button")
                ) {
                    Icon(Icons.AutoMirrored.Filled.Launch, contentDescription = null)
                    Spacer(Modifier.padding(start = 8.dp))
                    Text(stringResource(R.string.reliability_open_battery_settings))
                }
            }
        }
    }
}

@Composable
private fun StepRow(
    stepNumber: Int,
    step: GuideStep
) {
    Surface(
        color = MaterialTheme.colorScheme.surface,
        shape = MaterialTheme.shapes.medium,
        tonalElevation = 1.dp,
        modifier = Modifier.fillMaxWidth()
    ) {
        Row(
            modifier = Modifier.padding(12.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.Top
        ) {
            Box(
                modifier = Modifier
                    .size(28.dp)
                    .clip(CircleShape)
                    .background(MaterialTheme.colorScheme.primaryContainer),
                contentAlignment = Alignment.Center
            ) {
                Text(
                    text = stepNumber.toString(),
                    style = MaterialTheme.typography.labelLarge.copy(fontWeight = FontWeight.Bold),
                    color = MaterialTheme.colorScheme.onPrimaryContainer
                )
            }
            Column(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(4.dp)
            ) {
                Text(
                    text = step.title,
                    style = MaterialTheme.typography.titleSmall
                )
                Text(
                    text = step.detail,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
    }
}
