package com.alsaeeddev.payrelay.ui.screens.onboarding

import android.util.Size
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import java.util.concurrent.Executors
import androidx.camera.view.PreviewView.StreamState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.ui.draw.alpha
import androidx.lifecycle.Observer
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

@Composable
fun QrScannerView(
    analyzer: QrCodeAnalyzer,
    modifier: Modifier = Modifier,
    onCameraError: (String) -> Unit
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnError by rememberUpdatedState(onCameraError)

    val previewView = remember {
        PreviewView(context).apply {
            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
    }

    // Placeholder, until first frame...
    var isStreaming by remember { mutableStateOf(false) }
    val previewAlpha by animateFloatAsState(
        targetValue = if (isStreaming) 1f else 0f,
        animationSpec = tween(durationMillis = 250),
        label = "previewAlpha"
    )

    DisposableEffect(previewView, lifecycleOwner) {
        val observer = Observer<PreviewView.StreamState> { state ->
            isStreaming = state == PreviewView.StreamState.STREAMING
        }
        previewView.previewStreamState.observe(lifecycleOwner, observer)
        onDispose { previewView.previewStreamState.removeObserver(observer) }
    }

    DisposableEffect(lifecycleOwner, analyzer) {
        val analysisExecutor = Executors.newSingleThreadExecutor()
        val cameraProviderFuture = ProcessCameraProvider.getInstance(context)
        val mainExecutor = ContextCompat.getMainExecutor(context)
        var cameraProvider: ProcessCameraProvider? = null
        var disposed = false

        cameraProviderFuture.addListener(
            {
                if (disposed) return@addListener
                try {
                    val provider = cameraProviderFuture.get()
                    cameraProvider = provider

                    val preview = Preview.Builder().build().also {
                        it.surfaceProvider = previewView.surfaceProvider
                    }

                    val resolutionSelector = ResolutionSelector.Builder()
                        .setResolutionStrategy(
                            ResolutionStrategy(
                                Size(1280, 720),
                                ResolutionStrategy.FALLBACK_RULE_CLOSEST_HIGHER_THEN_LOWER
                            )
                        )
                        .build()

                    val imageAnalysis = ImageAnalysis.Builder()
                        .setResolutionSelector(resolutionSelector)
                        .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                        .build()
                        .also { it.setAnalyzer(analysisExecutor, analyzer) }

                    provider.unbindAll()
                    provider.bindToLifecycle(
                        lifecycleOwner,
                        CameraSelector.DEFAULT_BACK_CAMERA,
                        preview,
                        imageAnalysis
                    )
                } catch (e: Exception) {
                    currentOnError(e.message ?: "Unable to start camera preview")
                }
            },
            mainExecutor
        )

        onDispose {
            disposed = true
            try {
                cameraProvider?.unbindAll()
            } catch (_: Exception) {
            }
            analysisExecutor.shutdown()
        }
    }

    Box(
        modifier = modifier.background(MaterialTheme.colorScheme.surfaceVariant)
    ) {
        AndroidView(
            factory = { previewView },
            modifier = Modifier
                .fillMaxSize()
                .alpha(previewAlpha)
        )

        if (!isStreaming) {
            CircularProgressIndicator(
                modifier = Modifier
                    .size(32.dp)
                    .align(Alignment.Center),
                strokeWidth = 3.dp
            )
        }

        Box(
            modifier = Modifier
                .size(220.dp)
                .align(Alignment.Center)
                .border(
                    width = 2.dp,
                    color = MaterialTheme.colorScheme.primary,
                    shape = RoundedCornerShape(16.dp)
                )
        )
    }
}