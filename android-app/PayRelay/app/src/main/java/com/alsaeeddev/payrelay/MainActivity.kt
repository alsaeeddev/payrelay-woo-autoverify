package com.alsaeeddev.payrelay

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.fragment.app.FragmentActivity
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.alsaeeddev.payrelay.ui.components.BiometricLockScreen
import com.alsaeeddev.payrelay.ui.navigation.AppEntryViewModel
import com.alsaeeddev.payrelay.ui.navigation.PayRelayNavGraph
import com.alsaeeddev.payrelay.ui.theme.PayRelayTheme
import dagger.hilt.android.AndroidEntryPoint

@AndroidEntryPoint
class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            PayRelayTheme {
                val entryViewModel: AppEntryViewModel = hiltViewModel()
                val entryState by entryViewModel.entryState.collectAsStateWithLifecycle()
                var sessionUnlocked by rememberSaveable { mutableStateOf(false) }

                when {
                    entryState.isLoading -> {
                        Box(
                            modifier = Modifier.fillMaxSize(),
                            contentAlignment = Alignment.Center
                        ) {
                            CircularProgressIndicator()
                        }
                    }

                    entryState.isPaired && entryState.biometricLockEnabled && !sessionUnlocked -> {
                        BiometricLockScreen(onUnlocked = { sessionUnlocked = true })
                    }

                    else -> {
                        PayRelayNavGraph(entryState = entryState)
                    }
                }
            }
        }
    }
}
