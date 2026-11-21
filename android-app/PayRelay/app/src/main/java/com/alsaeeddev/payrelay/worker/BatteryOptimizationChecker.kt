package com.alsaeeddev.payrelay.worker

import android.content.Context
import android.os.PowerManager
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton

interface BatteryOptimizationChecker {
    /**
     * Returns true when battery optimization IS restricting the app
     * (i.e., NOT ignoring battery optimizations).
     */
    fun isBatteryOptimized(): Boolean
}

@Singleton
class AndroidBatteryOptimizationChecker @Inject constructor(
    @ApplicationContext private val context: Context,
    private val powerManager: PowerManager
) : BatteryOptimizationChecker {

    override fun isBatteryOptimized(): Boolean {
        return !powerManager.isIgnoringBatteryOptimizations(context.packageName)
    }
}
