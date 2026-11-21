package com.alsaeeddev.payrelay.ui.screens.reliability

import android.os.Build
import android.provider.Settings
import androidx.annotation.StringRes
import com.alsaeeddev.payrelay.R

enum class OemBrand(
    val id: String,
    @param:StringRes val displayNameRes: Int,
    val osLabel: String
) {
    XIAOMI("xiaomi", R.string.reliability_brand_xiaomi, "HyperOS / MIUI"),
    OPPO("oppo", R.string.reliability_brand_oppo, "ColorOS / Realme UI / OxygenOS"),
    VIVO("vivo", R.string.reliability_brand_vivo, "Funtouch OS / OriginOS"),
    SAMSUNG("samsung", R.string.reliability_brand_samsung, "One UI"),
    TRANSSION("transsion", R.string.reliability_brand_transsion, "XOS / HiOS / itel OS"),
    STOCK("stock", R.string.reliability_brand_stock, "Stock / AOSP Android")
}

data class IntentCandidate(
    val packageName: String? = null,
    val className: String? = null,
    val action: String? = null,
    val usePackageDataUri: Boolean = false
)

data class GuideStep(
    val title: String,
    val detail: String
)

data class OemGuide(
    val brand: OemBrand,
    val summary: String,
    val steps: List<GuideStep>,
    val autostartIntents: List<IntentCandidate>,
    val batteryIntents: List<IntentCandidate>
)

object OemDetector {

    fun detectBrand(
        manufacturer: String = Build.MANUFACTURER.orEmpty(),
        brand: String = Build.BRAND.orEmpty()
    ): OemBrand {
        val combined = "$manufacturer $brand".lowercase()
        return when {
            combined.contains("xiaomi") ||
                combined.contains("redmi") ||
                combined.contains("poco") -> OemBrand.XIAOMI

            combined.contains("oppo") ||
                combined.contains("realme") ||
                combined.contains("oneplus") -> OemBrand.OPPO

            combined.contains("vivo") ||
                combined.contains("iqoo") -> OemBrand.VIVO

            combined.contains("samsung") -> OemBrand.SAMSUNG

            combined.contains("infinix") ||
                combined.contains("tecno") ||
                combined.contains("itel") ||
                combined.contains("transsion") -> OemBrand.TRANSSION

            else -> OemBrand.STOCK
        }
    }
}

object OemReliabilityCatalog {

    val standardBatterySettingsCandidate = IntentCandidate(
        action = Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS
    )

    val appDetailsFallbackCandidate = IntentCandidate(
        action = Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
        usePackageDataUri = true
    )

    fun orderedGuides(detectedBrand: OemBrand): List<OemGuide> {
        val all = listOf(
            xiaomiGuide,
            oppoGuide,
            vivoGuide,
            samsungGuide,
            transsionGuide,
            stockGuide
        )
        return listOfNotNull(all.find { it.brand == detectedBrand }) +
            all.filterNot { it.brand == detectedBrand }
    }

    fun guideFor(brand: OemBrand): OemGuide = when (brand) {
        OemBrand.XIAOMI -> xiaomiGuide
        OemBrand.OPPO -> oppoGuide
        OemBrand.VIVO -> vivoGuide
        OemBrand.SAMSUNG -> samsungGuide
        OemBrand.TRANSSION -> transsionGuide
        OemBrand.STOCK -> stockGuide
    }

    private val xiaomiGuide = OemGuide(
        brand = OemBrand.XIAOMI,
        summary = "HyperOS and MIUI block SMS broadcast receivers unless Autostart is turned on and Battery Saver is set to No restrictions.",
        steps = listOf(
            GuideStep(
                title = "1. Enable Autostart",
                detail = "Open Security -> Permissions -> Autostart (or Background autostart) and toggle PayRelay ON."
            ),
            GuideStep(
                title = "2. Set Battery saver to No restrictions",
                detail = "Open PayRelay App Info -> Battery saver -> select \"No restrictions\" so WorkManager can sync immediately."
            ),
            GuideStep(
                title = "3. Lock PayRelay in Recents",
                detail = "Open Recent Apps, press and hold the PayRelay card, and tap the Lock icon so system cleaner does not kill it."
            )
        ),
        autostartIntents = listOf(
            IntentCandidate(
                packageName = "com.miui.securitycenter",
                className = "com.miui.permcenter.autostart.AutoStartManagementActivity"
            ),
            IntentCandidate(
                packageName = "com.miui.securitycenter",
                className = "com.miui.permcenter.permissions.PermissionsEditorActivity"
            )
        ),
        batteryIntents = listOf(
            standardBatterySettingsCandidate,
            appDetailsFallbackCandidate
        )
    )

    private val oppoGuide = OemGuide(
        brand = OemBrand.OPPO,
        summary = "ColorOS, Realme UI, and OxygenOS stop background apps after screen-off unless Auto-launch and Background activity are permitted.",
        steps = listOf(
            GuideStep(
                title = "1. Allow Auto-launch",
                detail = "Open Phone Manager -> App Management -> Startup manager (or App Info -> Battery usage -> Allow auto-launch) and enable PayRelay."
            ),
            GuideStep(
                title = "2. Allow Background activity",
                detail = "In PayRelay App Info -> Battery usage, enable \"Allow background activity\" and \"Allow foreground activity\"."
            ),
            GuideStep(
                title = "3. Lock in Recent tasks",
                detail = "Open Recent Apps, tap the menu icon on PayRelay, and choose \"Lock\"."
            )
        ),
        autostartIntents = listOf(
            IntentCandidate(
                packageName = "com.coloros.safecenter",
                className = "com.coloros.safecenter.permission.startup.StartupAppListActivity"
            ),
            IntentCandidate(
                packageName = "com.coloros.safecenter",
                className = "com.coloros.safecenter.startupapp.StartupAppListActivity"
            ),
            IntentCandidate(
                packageName = "com.oplus.safecenter",
                className = "com.oplus.safecenter.startupapp.StartupAppListActivity"
            ),
            IntentCandidate(
                packageName = "com.oppo.safe",
                className = "com.oppo.safe.permission.startup.StartupAppListActivity"
            )
        ),
        batteryIntents = listOf(
            standardBatterySettingsCandidate,
            appDetailsFallbackCandidate
        )
    )

    private val vivoGuide = OemGuide(
        brand = OemBrand.VIVO,
        summary = "Funtouch OS and OriginOS require both Autostart and High background power consumption for reliable payment relays.",
        steps = listOf(
            GuideStep(
                title = "1. Enable Autostart in iManager",
                detail = "Open iManager -> App Management -> Autostart (or Permission Management -> Autostart) and turn PayRelay ON."
            ),
            GuideStep(
                title = "2. Allow High background power consumption",
                detail = "Open Settings -> Battery -> Background power consumption management -> select PayRelay -> choose \"High background power usage\"."
            ),
            GuideStep(
                title = "3. Disable standard Battery optimization",
                detail = "Open Battery Optimization Settings, switch to \"All apps\", find PayRelay, and select \"Don't optimize\"."
            )
        ),
        autostartIntents = listOf(
            IntentCandidate(
                packageName = "com.vivo.permissionmanager",
                className = "com.vivo.permissionmanager.activity.BgStartUpManagerActivity"
            ),
            IntentCandidate(
                packageName = "com.iqoo.secure",
                className = "com.iqoo.secure.ui.phoneoptimize.BgStartUpManagerActivity"
            ),
            IntentCandidate(
                packageName = "com.iqoo.secure",
                className = "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity"
            )
        ),
        batteryIntents = listOf(
            standardBatterySettingsCandidate,
            appDetailsFallbackCandidate
        )
    )

    private val samsungGuide = OemGuide(
        brand = OemBrand.SAMSUNG,
        summary = "Samsung One UI puts apps into Deep Sleep automatically if battery usage is not set to Unrestricted.",
        steps = listOf(
            GuideStep(
                title = "1. Set Battery to Unrestricted",
                detail = "Open PayRelay App Info -> Battery -> select \"Unrestricted\"."
            ),
            GuideStep(
                title = "2. Add to Never sleeping apps",
                detail = "Open Settings -> Device care -> Battery -> Background usage limits -> \"Never auto sleeping apps\" -> tap + and add PayRelay."
            ),
            GuideStep(
                title = "3. Verify Unused app hibernation",
                detail = "In PayRelay App Info, ensure \"Remove permissions if app is unused\" is turned OFF."
            )
        ),
        autostartIntents = listOf(
            IntentCandidate(
                packageName = "com.samsung.android.lool",
                className = "com.samsung.android.sm.battery.ui.BatteryActivity"
            ),
            IntentCandidate(
                packageName = "com.samsung.android.sm",
                className = "com.samsung.android.sm.battery.ui.BatteryActivity"
            )
        ),
        batteryIntents = listOf(
            standardBatterySettingsCandidate,
            appDetailsFallbackCandidate
        )
    )

    private val transsionGuide = OemGuide(
        brand = OemBrand.TRANSSION,
        summary = "Infinix (XOS), Tecno (HiOS), and itel devices use Phone Master to pause background apps unless Auto-start is enabled.",
        steps = listOf(
            GuideStep(
                title = "1. Enable Auto-start in Phone Master",
                detail = "Open Phone Master -> Toolbox (or App Management) -> Auto-start Management -> toggle PayRelay ON."
            ),
            GuideStep(
                title = "2. Set Battery to Unrestricted",
                detail = "Open PayRelay App Info -> App battery usage -> select \"Unrestricted\" (or Don't optimize)."
            ),
            GuideStep(
                title = "3. Allow Background Activity",
                detail = "Open Phone Master -> Battery Lab -> App Power Saving Management -> PayRelay -> Allow background running."
            )
        ),
        autostartIntents = listOf(
            IntentCandidate(
                packageName = "com.transsion.phonemaster",
                className = "com.cyin.himgr.autostart.AutoStartActivity"
            ),
            IntentCandidate(
                packageName = "com.transsion.phonemaster",
                className = "com.transsion.phonemaster.autostart.AutoStartActivity"
            ),
            IntentCandidate(
                packageName = "com.itel.phonemaster",
                className = "com.cyin.himgr.autostart.AutoStartActivity"
            )
        ),
        batteryIntents = listOf(
            standardBatterySettingsCandidate,
            appDetailsFallbackCandidate
        )
    )

    private val stockGuide = OemGuide(
        brand = OemBrand.STOCK,
        summary = "Standard Android throttles background sync via Doze unless PayRelay is set to Unrestricted battery usage.",
        steps = listOf(
            GuideStep(
                title = "1. Disable Battery Optimization",
                detail = "Open Battery Optimization Settings (or App Info -> App battery usage), locate PayRelay, and select \"Unrestricted\" / \"Don't optimize\"."
            ),
            GuideStep(
                title = "2. Keep App Activity Active",
                detail = "In PayRelay App Info, turn OFF \"Pause app activity if unused\" so SMS permission stays active."
            ),
            GuideStep(
                title = "3. Keep Notifications Enabled",
                detail = "Allow notifications so PayRelay can warn you immediately if clock skew or WordPress pairing needs attention."
            )
        ),
        autostartIntents = emptyList(),
        batteryIntents = listOf(
            standardBatterySettingsCandidate,
            appDetailsFallbackCandidate
        )
    )
}
