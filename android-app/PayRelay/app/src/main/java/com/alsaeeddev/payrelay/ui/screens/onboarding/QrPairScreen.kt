package com.alsaeeddev.payrelay.ui.screens.onboarding

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.alsaeeddev.payrelay.R
import com.alsaeeddev.payrelay.ui.components.SecureScreenEffect

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ExitTransition
import androidx.compose.animation.SizeTransform
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.layout.navigationBarsPadding

import androidx.compose.foundation.BorderStroke

import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.rememberUpdatedState
import kotlinx.coroutines.channels.BufferOverflow
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.onStart
import androidx.compose.animation.slideOutVertically
import androidx.compose.ui.graphics.compositeOver
import androidx.compose.foundation.layout.BoxScope
import kotlin.time.Duration.Companion.milliseconds


@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun QrPairScreen(
    onPaired: () -> Unit,
    onBack: (() -> Unit)? = null,
    viewModel: OnboardingViewModel = hiltViewModel()
) {
    SecureScreenEffect(enabled = true)

    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current

    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                    PackageManager.PERMISSION_GRANTED
        )
    }
    var cameraError by remember { mutableStateOf<String?>(null) }

    val cameraPermissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission()
    ) { granted ->
        hasCameraPermission = granted
    }

    // Event for every scan. There is no state, so the screen is not recomposed from scan frames.
    val scanEvents = remember {
        MutableSharedFlow<Unit>(
            extraBufferCapacity = 1,
            onBufferOverflow = BufferOverflow.DROP_OLDEST
        )
    }

    val analyzer = remember {
        lateinit var self: QrCodeAnalyzer
        self = QrCodeAnalyzer { rawQr ->
            scanEvents.tryEmit(Unit)
            viewModel.onQrCodeScanned(rawQr) { self.resumeScanning() }
        }
        self
    }

    LaunchedEffect(uiState.pairedDeviceId) {
        if (uiState.pairedDeviceId != null) {
            onPaired()
        }
    }

    val errorMessage = uiState.errorMessage

    val currentError by rememberUpdatedState(errorMessage)
    var shownError by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(errorMessage) {
        if (errorMessage != null) {
            shownError = errorMessage
        } else {
            delay(800.milliseconds)
            shownError = null
        }
    }

    LaunchedEffect(shownError) {
        if (shownError != null) {
            scanEvents
                .onStart { emit(Unit) }
                .collectLatest {
                    delay(5_000.milliseconds)
                    shownError = null
                }
        }
    }

    LaunchedEffect(Unit) {
        scanEvents.collect {
            if (shownError == null && currentError != null) shownError = currentError
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.pair_screen_title)) },
                navigationIcon = {
                    if (onBack != null) {
                        IconButton(
                            onClick = onBack,
                            modifier = Modifier.testTag("pair_back_button")
                        ) {
                            Icon(
                                imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                                contentDescription = stringResource(R.string.action_back)
                            )
                        }
                    }
                }
            )
        }
    ) { innerPadding ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
        ) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(rememberScrollState())
                    .padding(
                        start = 20.dp,
                        end = 20.dp,
                        top = 16.dp,
                        bottom = 120.dp
                    ),
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
                            containerColor = MaterialTheme.colorScheme.primaryContainer
                        )
                    ) {
                        Row(
                            modifier = Modifier.padding(16.dp),
                            horizontalArrangement = Arrangement.spacedBy(12.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Icon(
                                imageVector = Icons.Default.Lock,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onPrimaryContainer
                            )
                            Text(
                                text = stringResource(R.string.pair_security_notice),
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onPrimaryContainer
                            )
                        }
                    }

                    if (!uiState.showManualEntry) {
                        if (hasCameraPermission && cameraError == null) {
                            Box(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .height(300.dp)
                                    .clip(RoundedCornerShape(16.dp))
                                    .testTag("qr_scanner_view")
                            ) {
                                QrScannerView(
                                    analyzer = analyzer,
                                    modifier = Modifier.fillMaxSize(),
                                    onCameraError = { err -> cameraError = err }
                                )
                            }
                        } else {
                            Card(modifier = Modifier.fillMaxWidth()) {
                                Column(
                                    modifier = Modifier.padding(20.dp),
                                    verticalArrangement = Arrangement.spacedBy(12.dp),
                                    horizontalAlignment = Alignment.CenterHorizontally
                                ) {
                                    Icon(
                                        imageVector = Icons.Default.CameraAlt,
                                        contentDescription = null,
                                        tint = MaterialTheme.colorScheme.primary
                                    )
                                    Text(
                                        text = cameraError
                                            ?: stringResource(R.string.pair_camera_permission_rationale),
                                        style = MaterialTheme.typography.bodyMedium
                                    )
                                    Button(
                                        onClick = {
                                            cameraError = null
                                            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
                                        },
                                        modifier = Modifier
                                            .heightIn(min = 48.dp)
                                            .testTag("grant_camera_button")
                                    ) {
                                        Icon(Icons.Default.QrCodeScanner, contentDescription = null)
                                        Spacer(Modifier.padding(start = 8.dp))
                                        Text(stringResource(R.string.pair_enable_camera_button))
                                    }
                                }
                            }
                        }

                        TextButton(
                            onClick = { viewModel.setManualEntryVisible(true) },
                            modifier = Modifier
                                .align(Alignment.CenterHorizontally)
                                .heightIn(min = 48.dp)
                                .testTag("switch_to_manual_pair_button")
                        ) {
                            Icon(Icons.Default.Edit, contentDescription = null)
                            Spacer(Modifier.padding(start = 6.dp))
                            Text(stringResource(R.string.pair_manual_entry_toggle))
                        }
                    } else {
                        OutlinedTextField(
                            value = uiState.manualSiteUrl,
                            onValueChange = viewModel::onManualSiteUrlChanged,
                            label = { Text(stringResource(R.string.pair_site_url_label)) },
                            placeholder = { Text("https://yourstore.com") },
                            singleLine = true,
                            modifier = Modifier
                                .fillMaxWidth()
                                .testTag("manual_site_url_input")
                        )

                        OutlinedTextField(
                            value = uiState.manualToken,
                            onValueChange = viewModel::onManualTokenChanged,
                            label = { Text(stringResource(R.string.pair_token_label)) },
                            singleLine = true,
                            visualTransformation = PasswordVisualTransformation(),
                            modifier = Modifier
                                .fillMaxWidth()
                                .testTag("manual_token_input")
                        )

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(12.dp)
                        ) {
                            OutlinedButton(
                                onClick = { viewModel.setManualEntryVisible(false) },
                                modifier = Modifier
                                    .weight(1f)
                                    .heightIn(min = 48.dp)
                                    .testTag("switch_to_qr_scan_button")
                            ) {
                                Text(stringResource(R.string.pair_scan_qr_toggle))
                            }

                            Button(
                                onClick = viewModel::submitManualPairing,
                                enabled = !uiState.isPairing,
                                modifier = Modifier
                                    .weight(1f)
                                    .heightIn(min = 48.dp)
                                    .testTag("submit_manual_pair_button")
                            ) {
                                Text(stringResource(R.string.pair_connect_button))
                            }
                        }
                    }

                    // Error card visible,pairing status hide
                    PairingStatus(
                        isPairing = { uiState.isPairing },
                        errorVisible = { shownError != null }
                    )
                }
            }

            // Error banner composable
            ErrorBanner(errorProvider = { shownError })
        }
    }
}

/**
 * Bottom error banner.
 */
@Composable
private fun BoxScope.ErrorBanner(errorProvider: () -> String?) {
    val shown = errorProvider()

    // Slide-out, text hide
    var last by remember { mutableStateOf("") }
    SideEffect { shown?.let { last = it } }

    val borderColor = MaterialTheme.colorScheme.error
        .copy(alpha = 0.4f)
        .compositeOver(MaterialTheme.colorScheme.errorContainer)

    AnimatedVisibility(
        visible = shown != null,
        enter = slideInVertically(tween(220)) { it },
        exit = slideOutVertically(tween(180)) { it },
        modifier = Modifier
            .align(Alignment.BottomCenter)
            .fillMaxWidth()
            .navigationBarsPadding()
            .padding(horizontal = 20.dp, vertical = 16.dp)
    ) {
        Card(
            colors = CardDefaults.cardColors(
                containerColor = MaterialTheme.colorScheme.errorContainer
            ),
            elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
            border = BorderStroke(1.dp, borderColor),
            modifier = Modifier
                .widthIn(max = 600.dp)
                .fillMaxWidth()
                .testTag("pair_error_card")
        ) {
            AnimatedContent(
                targetState = shown ?: last,
                transitionSpec = {
                    fadeIn(tween(200)) togetherWith fadeOut(tween(200)) using
                            SizeTransform(clip = false)
                },
                label = "errorText"
            ) { text ->
                Text(
                    text = text,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.padding(16.dp)
                )
            }
        }
    }
}

/**
 * "Pairing with WordPress" status.
 * - Fixed-height slot: showing/hiding it causes no layout shift.
 * - Hidden when the error card is visible.
 * - 400ms debounce: a quick true->false blip of isPairing during a scan is never shown.
 */
@Composable
private fun PairingStatus(
    isPairing: () -> Boolean,
    errorVisible: () -> Boolean
) {
    val pairing = isPairing()
    var debouncedPairing by remember { mutableStateOf(false) }
    LaunchedEffect(pairing) {
        if (pairing) {
            delay(400.milliseconds)
            debouncedPairing = true
        } else {
            debouncedPairing = false
        }
    }

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(48.dp),
        contentAlignment = Alignment.Center
    ) {
        if (debouncedPairing && !errorVisible()) {
            Row(
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically
            ) {
                CircularProgressIndicator()
                Spacer(Modifier.padding(start = 12.dp))
                Text(stringResource(R.string.pair_connecting_status))
            }
        }
    }
}