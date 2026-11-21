package com.alsaeeddev.payrelay.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext

private val DarkColorScheme = darkColorScheme(
    primary = DarkEmeraldPrimary,
    onPrimary = DarkEmeraldOnPrimary,
    primaryContainer = DarkEmeraldPrimaryContainer,
    onPrimaryContainer = DarkEmeraldOnPrimaryContainer,
    secondary = SlateSecondaryContainer,
    onSecondary = SlateOnSecondaryContainer,
    tertiary = TealTertiaryContainer,
    onTertiary = TealOnTertiaryContainer,
    background = DarkBackground,
    surface = DarkSurface,
    onSurface = DarkOnSurface
)

private val LightColorScheme = lightColorScheme(
    primary = EmeraldPrimary,
    onPrimary = EmeraldOnPrimary,
    primaryContainer = EmeraldPrimaryContainer,
    onPrimaryContainer = EmeraldOnPrimaryContainer,
    secondary = SlateSecondary,
    onSecondary = SlateOnSecondary,
    secondaryContainer = SlateSecondaryContainer,
    onSecondaryContainer = SlateOnSecondaryContainer,
    tertiary = TealTertiary,
    onTertiary = TealOnTertiary,
    tertiaryContainer = TealTertiaryContainer,
    onTertiaryContainer = TealOnTertiaryContainer,
    error = ErrorRed,
    errorContainer = ErrorContainer,
    onErrorContainer = OnErrorContainer,
    background = LightBackground,
    surface = LightSurface,
    onSurface = LightOnSurface
)

@Composable
fun PayRelayTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = false,
    content: @Composable () -> Unit
) {
    val colorScheme = when {
        dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S -> {
            val context = LocalContext.current
            if (darkTheme) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
        }
        darkTheme -> DarkColorScheme
        else -> LightColorScheme
    }

    MaterialTheme(
        colorScheme = colorScheme,
        typography = PayRelayTypography,
        content = content
    )
}
