package com.alsaeeddev.payrelay.data.repository

import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.prefs.DefaultAllowlist
import com.alsaeeddev.payrelay.data.local.prefs.SettingsDataStore
import com.alsaeeddev.payrelay.data.local.prefs.StoredPreferences
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidationResult
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidator
import com.alsaeeddev.payrelay.domain.model.AppSettingsState
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class SettingsRepositoryImpl @Inject constructor(
    private val settingsDataStore: SettingsDataStore,
    private val siteUrlValidator: SiteUrlValidator,
    private val redactor: Redactor
) : SettingsRepository {

    override fun observeSettings(): Flow<AppSettingsState> =
        settingsDataStore.preferencesFlow.map { it.toDomain() }

    override suspend fun getSettingsSnapshot(): AppSettingsState =
        settingsDataStore.snapshot().toDomain()

    override suspend fun setConsentAccepted(accepted: Boolean) {
        settingsDataStore.setConsentAccepted(accepted)
    }

    override suspend fun setForwardingPaused(paused: Boolean) {
        settingsDataStore.setForwardingPaused(paused)
    }

    override suspend fun setBiometricLockEnabled(enabled: Boolean) {
        settingsDataStore.setBiometricLockEnabled(enabled)
    }

    override suspend fun setRetentionDays(days: Int) {
        settingsDataStore.setRetentionDays(days)
    }

    override suspend fun updateSiteUrl(siteUrl: String) {
        when (val result = siteUrlValidator.validate(siteUrl)) {
            is SiteUrlValidationResult.Valid -> {
                settingsDataStore.updateSiteUrl(result.normalizedBaseUrl)
            }
            is SiteUrlValidationResult.Invalid -> {
                throw IllegalArgumentException(result.reason)
            }
        }
    }

    override suspend fun addAllowedSender(sender: String) {
        val cleaned = sender.trim()
        require(cleaned.isNotEmpty()) { "Sender ID cannot be blank" }
        val current = settingsDataStore.snapshot().allowlist
        val deduplicated = current.filterNot {
            DefaultAllowlist.normalize(it) == DefaultAllowlist.normalize(cleaned)
        }.toMutableSet()
        deduplicated.add(cleaned)
        settingsDataStore.setAllowlist(deduplicated)
    }

    override suspend fun removeAllowedSender(sender: String) {
        val targetNorm = DefaultAllowlist.normalize(sender)
        val current = settingsDataStore.snapshot().allowlist
        val updated = current.filterNot {
            DefaultAllowlist.normalize(it) == targetNorm
        }.toSet()
        settingsDataStore.setAllowlist(updated)
    }

    override suspend fun resetDefaultAllowlist() {
        settingsDataStore.resetDefaultAllowlist()
    }

    override suspend fun recordLastSync(timestampMs: Long) {
        settingsDataStore.setLastSyncAtMs(timestampMs)
    }

    override suspend fun recordLastHeartbeat(timestampMs: Long) {
        settingsDataStore.setLastHeartbeatAtMs(timestampMs)
    }

    override suspend fun setLastError(errorMessage: String?) {
        settingsDataStore.setLastErrorRedacted(errorMessage?.let { redactor.redactMessage(it) })
    }

    override suspend fun clearAll() {
        settingsDataStore.wipeAll()
    }

    private fun StoredPreferences.toDomain(): AppSettingsState = AppSettingsState(
        consentAccepted = consentAccepted,
        isPaired = isPaired && !siteUrl.isNullOrBlank() && !deviceId.isNullOrBlank() && !secretCiphertext.isNullOrBlank(),
        siteUrl = siteUrl,
        deviceId = deviceId,
        forwardingPaused = forwardingPaused,
        biometricLockEnabled = biometricLockEnabled,
        retentionDays = retentionDays,
        allowlist = allowlist,
        lastSyncAtMs = lastSyncAtMs,
        lastHeartbeatAtMs = lastHeartbeatAtMs,
        lastErrorRedacted = lastErrorRedacted
    )
}
