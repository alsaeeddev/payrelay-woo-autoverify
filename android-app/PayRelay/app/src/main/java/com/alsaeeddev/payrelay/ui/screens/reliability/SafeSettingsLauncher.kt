package com.alsaeeddev.payrelay.ui.screens.reliability

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.Settings

sealed interface LaunchOutcome {
    data object OpenedPrimary : LaunchOutcome
    data object OpenedBatterySettings : LaunchOutcome
    data object OpenedAppDetailsFallback : LaunchOutcome
    data object Failed : LaunchOutcome
}

object SafeSettingsLauncher {

    fun openOemAutostartOrFallback(context: Context, guide: OemGuide): LaunchOutcome {
        if (tryLaunchAny(context, guide.autostartIntents)) {
            return LaunchOutcome.OpenedPrimary
        }
        if (tryLaunchCandidate(context, OemReliabilityCatalog.standardBatterySettingsCandidate)) {
            return LaunchOutcome.OpenedBatterySettings
        }
        if (tryLaunchCandidate(context, OemReliabilityCatalog.appDetailsFallbackCandidate)) {
            return LaunchOutcome.OpenedAppDetailsFallback
        }
        return LaunchOutcome.Failed
    }

    fun openBatteryOptimizationSettings(context: Context): LaunchOutcome {
        if (tryLaunchCandidate(context, OemReliabilityCatalog.standardBatterySettingsCandidate)) {
            return LaunchOutcome.OpenedBatterySettings
        }
        if (tryLaunchCandidate(context, OemReliabilityCatalog.appDetailsFallbackCandidate)) {
            return LaunchOutcome.OpenedAppDetailsFallback
        }
        return LaunchOutcome.Failed
    }

    fun openAppDetails(context: Context): LaunchOutcome {
        return if (tryLaunchCandidate(context, OemReliabilityCatalog.appDetailsFallbackCandidate)) {
            LaunchOutcome.OpenedAppDetailsFallback
        } else {
            LaunchOutcome.Failed
        }
    }

    fun buildIntent(context: Context, candidate: IntentCandidate): Intent {
        val intent = Intent()
        if (!candidate.action.isNullOrBlank()) {
            intent.action = candidate.action
        }
        if (!candidate.packageName.isNullOrBlank() && !candidate.className.isNullOrBlank()) {
            intent.component = ComponentName(candidate.packageName, candidate.className)
        }
        if (candidate.usePackageDataUri) {
            intent.data = Uri.fromParts("package", context.packageName, null)
        }
        if (context !is Activity) {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        return intent
    }

    fun canResolve(context: Context, intent: Intent): Boolean {
        return context.packageManager.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY) != null
    }

    private fun tryLaunchAny(context: Context, candidates: List<IntentCandidate>): Boolean {
        for (candidate in candidates) {
            if (tryLaunchCandidate(context, candidate)) {
                return true
            }
        }
        return false
    }

    private fun tryLaunchCandidate(context: Context, candidate: IntentCandidate): Boolean {
        val intent = buildIntent(context, candidate)
        if (!canResolve(context, intent)) {
            return false
        }
        return try {
            context.startActivity(intent)
            true
        } catch (_: ActivityNotFoundException) {
            false
        } catch (_: SecurityException) {
            false
        }
    }
}
