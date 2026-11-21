package com.alsaeeddev.payrelay

import android.app.Application
import android.content.ComponentName
import android.content.IntentFilter
import android.content.pm.ActivityInfo
import android.provider.Settings
import androidx.test.core.app.ApplicationProvider
import com.alsaeeddev.payrelay.ui.screens.reliability.LaunchOutcome
import com.alsaeeddev.payrelay.ui.screens.reliability.OemBrand
import com.alsaeeddev.payrelay.ui.screens.reliability.OemDetector
import com.alsaeeddev.payrelay.ui.screens.reliability.OemReliabilityCatalog
import com.alsaeeddev.payrelay.ui.screens.reliability.SafeSettingsLauncher
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class ReliabilityGuideTest {

    @Test
    fun `oem detector maps target manufacturer and brand names`() {
        assertEquals(OemBrand.XIAOMI, OemDetector.detectBrand("Xiaomi", "Redmi"))
        assertEquals(OemBrand.XIAOMI, OemDetector.detectBrand("Xiaomi", "POCO"))
        assertEquals(OemBrand.OPPO, OemDetector.detectBrand("OPPO", "CPH2411"))
        assertEquals(OemBrand.OPPO, OemDetector.detectBrand("realme", "realme"))
        assertEquals(OemBrand.VIVO, OemDetector.detectBrand("vivo", "iQOO"))
        assertEquals(OemBrand.SAMSUNG, OemDetector.detectBrand("samsung", "samsung"))
        assertEquals(OemBrand.TRANSSION, OemDetector.detectBrand("INFINIX", "Infinix"))
        assertEquals(OemBrand.TRANSSION, OemDetector.detectBrand("TECNO", "TECNO"))
        assertEquals(OemBrand.TRANSSION, OemDetector.detectBrand("itel", "itel"))
        assertEquals(OemBrand.STOCK, OemDetector.detectBrand("Google", "Pixel 9"))
    }

    @Test
    fun `ordered guides places detected brand first and covers all brands`() {
        val ordered = OemReliabilityCatalog.orderedGuides(OemBrand.TRANSSION)
        assertEquals(OemBrand.entries.size, ordered.size)
        assertEquals(OemBrand.TRANSSION, ordered.first().brand)
        assertTrue(ordered.all { it.steps.isNotEmpty() && it.batteryIntents.isNotEmpty() })
    }

    @Test
    fun `safe settings launcher prefers primary oem component when resolvable`() {
        val app = ApplicationProvider.getApplicationContext<Application>()
        val shadowPm = Shadows.shadowOf(app.packageManager)
        val guide = OemReliabilityCatalog.guideFor(OemBrand.XIAOMI)
        val primary = guide.autostartIntents.first()
        val component = ComponentName(primary.packageName!!, primary.className!!)
        shadowPm.addOrUpdateActivity(
            ActivityInfo().apply {
                packageName = component.packageName
                name = component.className
                exported = true
            }
        )

        val outcome = SafeSettingsLauncher.openOemAutostartOrFallback(app, guide)
        assertEquals(LaunchOutcome.OpenedPrimary, outcome)

        val launched = Shadows.shadowOf(app).nextStartedActivity
        assertEquals(component, launched.component)
    }

    @Test
    fun `safe settings launcher falls back to battery optimization then app details`() {
        val app = ApplicationProvider.getApplicationContext<Application>()
        val shadowPm = Shadows.shadowOf(app.packageManager)

        val batteryComponent = ComponentName("com.android.settings", "BatteryOptActivity")
        shadowPm.addOrUpdateActivity(
            ActivityInfo().apply {
                packageName = batteryComponent.packageName
                name = batteryComponent.className
                exported = true
            }
        )
        shadowPm.addIntentFilterForActivity(
            batteryComponent,
            IntentFilter(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS).apply {
                addCategory(android.content.Intent.CATEGORY_DEFAULT)
            }
        )

        val guide = OemReliabilityCatalog.guideFor(OemBrand.OPPO)
        val outcome = SafeSettingsLauncher.openOemAutostartOrFallback(app, guide)
        assertEquals(LaunchOutcome.OpenedBatterySettings, outcome)

        val launched = Shadows.shadowOf(app).nextStartedActivity
        assertEquals(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS, launched.action)
    }

    @Test
    fun `safe settings launcher falls back to app details when battery settings absent`() {
        val app = ApplicationProvider.getApplicationContext<Application>()
        val shadowPm = Shadows.shadowOf(app.packageManager)

        val appDetailsComponent = ComponentName("com.android.settings", "InstalledAppDetails")
        shadowPm.addOrUpdateActivity(
            ActivityInfo().apply {
                packageName = appDetailsComponent.packageName
                name = appDetailsComponent.className
                exported = true
            }
        )
        shadowPm.addIntentFilterForActivity(
            appDetailsComponent,
            IntentFilter(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                addCategory(android.content.Intent.CATEGORY_DEFAULT)
                addDataScheme("package")
            }
        )

        val guide = OemReliabilityCatalog.guideFor(OemBrand.VIVO)
        val outcome = SafeSettingsLauncher.openOemAutostartOrFallback(app, guide)
        assertEquals(LaunchOutcome.OpenedAppDetailsFallback, outcome)

        val launched = Shadows.shadowOf(app).nextStartedActivity
        assertEquals(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, launched.action)
        assertEquals("package:${app.packageName}", launched.dataString)
    }

    @Test
    fun `safe settings launcher returns Failed without crashing when no settings target exists`() {
        val app = ApplicationProvider.getApplicationContext<Application>()
        val guide = OemReliabilityCatalog.guideFor(OemBrand.SAMSUNG)
        val outcome = SafeSettingsLauncher.openOemAutostartOrFallback(app, guide)
        assertEquals(LaunchOutcome.Failed, outcome)
    }
}
