package com.alsaeeddev.payrelay.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class PairRequest(
    @SerialName("pairing_token")
    val pairingToken: String,
    @SerialName("device_name")
    val deviceName: String,
    // Stable per-install ID. Lets the server reuse this phone's device row when it pairs
    // again. Optional: servers/plugin versions that do not know the field ignore it.
    @SerialName("device_uuid")
    val deviceUuid: String? = null
)

@Serializable
data class PairResponse(
    @SerialName("device_id")
    val deviceId: String,
    @SerialName("secret")
    val secret: String
)

@Serializable
data class SmsPayload(
    @SerialName("idempotency_key")
    val idempotencyKey: String,
    @SerialName("sender")
    val sender: String,
    @SerialName("body")
    val body: String,
    @SerialName("received_at_ms")
    val receivedAtMs: Long,
    @SerialName("sim_slot")
    val simSlot: Int? = null
)

@Serializable
data class SmsAckResponse(
    @SerialName("status")
    val status: String
)

@Serializable
data class HeartbeatPayload(
    @SerialName("app_version")
    val appVersion: String,
    @SerialName("battery_optimized")
    val batteryOptimized: Boolean
)
