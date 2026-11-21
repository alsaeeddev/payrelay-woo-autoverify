package com.alsaeeddev.payrelay.ui.navigation

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ReceiptLong
import androidx.compose.material.icons.automirrored.outlined.ReceiptLong
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.navigation.NavDestination.Companion.hasRoute
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.alsaeeddev.payrelay.R
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.ui.screens.home.HomeScreen
import com.alsaeeddev.payrelay.ui.screens.logs.LogsScreen
import com.alsaeeddev.payrelay.ui.screens.onboarding.ConsentScreen
import com.alsaeeddev.payrelay.ui.screens.onboarding.PermissionsScreen
import com.alsaeeddev.payrelay.ui.screens.onboarding.QrPairScreen
import com.alsaeeddev.payrelay.ui.screens.reliability.ReliabilityGuideScreen
import com.alsaeeddev.payrelay.ui.screens.settings.SettingsScreen
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import javax.inject.Inject

data class AppEntryState(
    val isLoading: Boolean = true,
    val consentAccepted: Boolean = false,
    val isPaired: Boolean = false,
    val biometricLockEnabled: Boolean = false
)

@HiltViewModel
class AppEntryViewModel @Inject constructor(
    settingsRepository: SettingsRepository
) : ViewModel() {

    val entryState: StateFlow<AppEntryState> = settingsRepository.observeSettings()
        .map { settings ->
            AppEntryState(
                isLoading = false,
                consentAccepted = settings.consentAccepted,
                isPaired = settings.isPaired,
                biometricLockEnabled = settings.biometricLockEnabled
            )
        }
        .stateIn(
            scope = viewModelScope,
            started = SharingStarted.WhileSubscribed(5_000),
            initialValue = AppEntryState()
        )
}

@Composable
fun PayRelayNavGraph(entryState: AppEntryState) {
    val navController = rememberNavController()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = backStackEntry?.destination

    val startDestination: Any = when {
        !entryState.consentAccepted -> ConsentRoute
        !entryState.isPaired -> PermissionsRoute
        else -> HomeRoute
    }

    val bottomBar: @Composable () -> Unit = {
        NavigationBar {
            val isHome = currentDestination?.hasRoute<HomeRoute>() == true
            val isLogs = currentDestination?.hasRoute<LogsRoute>() == true
            val isSettings = currentDestination?.hasRoute<SettingsRoute>() == true

            NavigationBarItem(
                selected = isHome,
                onClick = {
                    navController.navigate(HomeRoute) {
                        popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                        launchSingleTop = true
                        restoreState = true
                    }
                },
                icon = {
                    Icon(
                        imageVector = if (isHome) Icons.Filled.Home else Icons.Outlined.Home,
                        contentDescription = null
                    )
                },
                label = { Text(stringResource(R.string.nav_home)) },
                modifier = Modifier.testTag("nav_home_tab")
            )
            NavigationBarItem(
                selected = isLogs,
                onClick = {
                    navController.navigate(LogsRoute) {
                        popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                        launchSingleTop = true
                        restoreState = true
                    }
                },
                icon = {
                    Icon(
                        imageVector = if (isLogs) Icons.AutoMirrored.Filled.ReceiptLong else Icons.AutoMirrored.Outlined.ReceiptLong,
                        contentDescription = null
                    )
                },
                label = { Text(stringResource(R.string.nav_logs)) },
                modifier = Modifier.testTag("nav_logs_tab")
            )
            NavigationBarItem(
                selected = isSettings,
                onClick = {
                    navController.navigate(SettingsRoute) {
                        popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                        launchSingleTop = true
                        restoreState = true
                    }
                },
                icon = {
                    Icon(
                        imageVector = if (isSettings) Icons.Filled.Settings else Icons.Outlined.Settings,
                        contentDescription = null
                    )
                },
                label = { Text(stringResource(R.string.nav_settings)) },
                modifier = Modifier.testTag("nav_settings_tab")
            )
        }
    }

    NavHost(
        navController = navController,
        startDestination = startDestination
    ) {
        composable<ConsentRoute> {
            ConsentScreen(
                onConsentAccepted = {
                    navController.navigate(PermissionsRoute)
                }
            )
        }

        composable<PermissionsRoute> {
            PermissionsScreen(
                onContinueToPair = {
                    navController.navigate(QrPairRoute)
                },
                onBack = {
                    if (!navController.popBackStack()) {
                        navController.navigate(ConsentRoute) {
                            popUpTo(0) { inclusive = true }
                        }
                    }
                }
            )
        }

        composable<QrPairRoute> {
            QrPairScreen(
                onPaired = {
                    navController.navigate(HomeRoute) {
                        popUpTo(0) { inclusive = true }
                    }
                },
                onBack = { navController.popBackStack() }
            )
        }

        composable<HomeRoute> {
            HomeScreen(
                bottomBar = bottomBar,
                onOpenReliabilityGuide = {
                    navController.navigate(ReliabilityGuideRoute) {
                        launchSingleTop = true
                    }
                }
            )
        }

        composable<LogsRoute> {
            LogsScreen(bottomBar = bottomBar)
        }

        composable<SettingsRoute> {
            SettingsScreen(
                bottomBar = bottomBar,
                onOpenReliabilityGuide = {
                    navController.navigate(ReliabilityGuideRoute) {
                        launchSingleTop = true
                    }
                },
                onWiped = {
                    navController.navigate(ConsentRoute) {
                        popUpTo(0) { inclusive = true }
                    }
                }
            )
        }

        composable<ReliabilityGuideRoute> {
            ReliabilityGuideScreen(
                onBack = { navController.popBackStack() }
            )
        }
    }
}
