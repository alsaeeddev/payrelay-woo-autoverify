package com.alsaeeddev.payrelay.domain.usecase

import com.alsaeeddev.payrelay.BuildConfig
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.data.remote.MpavApi
import com.alsaeeddev.payrelay.data.remote.SignedRequestFactory
import com.alsaeeddev.payrelay.data.remote.dto.HeartbeatPayload
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.worker.BatteryOptimizationChecker
import java.io.IOException
import javax.inject.Inject

sealed class HeartbeatOutcome {
    data object Success : HeartbeatOutcome()
    data object Skipped : HeartbeatOutcome()
    data class AuthOrClockSkewError(val httpCode: Int, val message: String) : HeartbeatOutcome()
    data class RetryableFailure(val reason: String) : HeartbeatOutcome()
}

class SendHeartbeatUseCase @Inject constructor(
    private val settingsRepository: SettingsRepository,
    private val pairingRepository: PairingRepository,
    private val auditLogRepository: AuditLogRepository,
    private val signedRequestFactory: SignedRequestFactory,
    private val mpavApi: MpavApi,
    private val batteryOptimizationChecker: BatteryOptimizationChecker,
    private val timeProvider: TimeProvider
) {

    suspend operator fun invoke(manualCheck: Boolean = false): HeartbeatOutcome {
        val settings = settingsRepository.getSettingsSnapshot()
        if (!settings.consentAccepted || !settings.isPaired) {
            return HeartbeatOutcome.Skipped
        }

        val credentials = pairingRepository.getDecryptedCredentials()
            ?: return HeartbeatOutcome.Skipped

        val batteryOptimized = batteryOptimizationChecker.isBatteryOptimized()
        val payload = HeartbeatPayload(
            appVersion = BuildConfig.VERSION_NAME,
            batteryOptimized = batteryOptimized
        )

        val prepared = signedRequestFactory.createSignedPost(
            normalizedSiteUrl = credentials.siteUrl,
            endpoint = "heartbeat",
            deviceId = credentials.deviceId,
            secret = credentials.secret,
            serializer = HeartbeatPayload.serializer(),
            payload = payload
        )

        return try {
            val response = mpavApi.sendHeartbeat(
                url = prepared.url.toString(),
                deviceId = prepared.headers.deviceId,
                timestamp = prepared.headers.timestampSeconds,
                nonce = prepared.headers.nonce,
                signature = prepared.headers.signature,
                body = prepared.requestBody
            )

            val code = response.code()
            when {
                response.isSuccessful -> {
                    val now = timeProvider.epochMillis()
                    settingsRepository.recordLastHeartbeat(now)
                    settingsRepository.setLastError(null)
                    if (manualCheck) {
                        auditLogRepository.log(
                            level = LogLevel.INFO,
                            category = "HEARTBEAT",
                            sender = null,
                            message = "Connection test succeeded (battery_optimized=$batteryOptimized)",
                            httpCode = code
                        )
                    }
                    HeartbeatOutcome.Success
                }

                code == 401 || code == 403 -> {
                    val msg =
                        "Connection rejected (HTTP $code). Check device automatic clock/timezone or re-pair."
                    settingsRepository.setLastError(msg)
                    auditLogRepository.log(
                        level = LogLevel.ERROR,
                        category = "SECURITY",
                        sender = null,
                        message = msg,
                        httpCode = code
                    )
                    HeartbeatOutcome.AuthOrClockSkewError(code, msg)
                }

                else -> {
                    val msg = "Heartbeat returned HTTP $code"
                    settingsRepository.setLastError(msg)
                    auditLogRepository.log(
                        level = LogLevel.WARN,
                        category = "HEARTBEAT",
                        sender = null,
                        message = msg,
                        httpCode = code
                    )
                    HeartbeatOutcome.RetryableFailure(msg)
                }
            }
        } catch (ioe: IOException) {
            val msg = "Connection failed (${ioe.javaClass.simpleName})"
            settingsRepository.setLastError(msg)
            if (manualCheck) {
                auditLogRepository.log(
                    level = LogLevel.WARN,
                    category = "HEARTBEAT",
                    sender = null,
                    message = msg
                )
            }
            HeartbeatOutcome.RetryableFailure(msg)
        }
    }
}
