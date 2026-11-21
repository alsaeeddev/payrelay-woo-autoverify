package com.alsaeeddev.payrelay.domain.usecase

import android.os.Build
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.data.local.prefs.DeviceUuidProvider
import com.alsaeeddev.payrelay.data.remote.MpavApi
import com.alsaeeddev.payrelay.data.remote.SignedRequestFactory
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidationResult
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidator
import com.alsaeeddev.payrelay.data.remote.dto.PairRequest
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import java.io.IOException
import javax.inject.Inject

sealed class PairOutcome {
    data class Success(val deviceId: String, val siteUrl: String) : PairOutcome()
    data class Failure(val reason: String) : PairOutcome()
}

class PairDeviceUseCase @Inject constructor(
    private val siteUrlValidator: SiteUrlValidator,
    private val signedRequestFactory: SignedRequestFactory,
    private val mpavApi: MpavApi,
    private val pairingRepository: PairingRepository,
    private val auditLogRepository: AuditLogRepository,
    private val syncWorkScheduler: SyncWorkScheduler,
    private val deviceUuidProvider: DeviceUuidProvider
) {

    suspend operator fun invoke(
        siteUrl: String,
        pairingToken: String,
        deviceName: String = defaultDeviceName()
    ): PairOutcome {
        val cleanToken = pairingToken.trim()
        if (cleanToken.isEmpty()) {
            return PairOutcome.Failure("Pairing token cannot be empty")
        }

        val normalizedSite = when (val validation = siteUrlValidator.validate(siteUrl)) {
            is SiteUrlValidationResult.Valid -> validation.normalizedBaseUrl
            is SiteUrlValidationResult.Invalid -> return PairOutcome.Failure(validation.reason)
        }

        val endpointUrl = siteUrlValidator.buildEndpointUrl(normalizedSite, "pair").toString()
        val body = signedRequestFactory.createUnsignedJsonBody(
            serializer = PairRequest.serializer(),
            payload = PairRequest(
                pairingToken = cleanToken,
                deviceName = deviceName.trim().ifEmpty { "Android Device" },
                deviceUuid = deviceUuidProvider.get()
            )
        )

        return try {
            val response = mpavApi.pair(url = endpointUrl, body = body)
            val code = response.code()
            val pairResponse = response.body()
            if (response.isSuccessful && pairResponse != null &&
                pairResponse.deviceId.isNotBlank() && pairResponse.secret.isNotBlank()
            ) {
                pairingRepository.savePairing(
                    siteUrl = normalizedSite,
                    deviceId = pairResponse.deviceId,
                    secretUtf8 = pairResponse.secret
                )
                syncWorkScheduler.schedulePeriodicHeartbeat()
                auditLogRepository.log(
                    level = LogLevel.INFO,
                    category = "PAIRING",
                    sender = null,
                    message = "Device paired successfully",
                    httpCode = code
                )
                PairOutcome.Success(
                    deviceId = pairResponse.deviceId,
                    siteUrl = normalizedSite
                )
            } else {
                val err = "Pairing rejected by server (HTTP $code)"
                auditLogRepository.log(
                    level = LogLevel.ERROR,
                    category = "PAIRING",
                    sender = null,
                    message = err,
                    httpCode = code
                )
                PairOutcome.Failure(err)
            }
        } catch (ioe: IOException) {
        android.util.Log.e("PairDevice", "Pair failed, url=$endpointUrl", ioe)
        val err = "Network error while pairing (${ioe.javaClass.simpleName}: ${ioe.message})"
        auditLogRepository.log(
            level = LogLevel.WARN,
            category = "PAIRING",
            sender = null,
            message = err
        )
        PairOutcome.Failure(err)
    } catch (e: Exception) {
            val err = "Pairing failed: ${e.message ?: "Unexpected error"}"
            auditLogRepository.log(
                level = LogLevel.ERROR,
                category = "PAIRING",
                sender = null,
                message = err
            )
            PairOutcome.Failure(err)
        }
    }

    companion object {
        fun defaultDeviceName(): String {
            val manufacturer = Build.MANUFACTURER.orEmpty().trim()
            val model = Build.MODEL.orEmpty().trim()
            return listOf(manufacturer, model)
                .filter { it.isNotEmpty() }
                .distinct()
                .joinToString(" ")
                .ifEmpty { "Android Device" }
        }
    }
}
