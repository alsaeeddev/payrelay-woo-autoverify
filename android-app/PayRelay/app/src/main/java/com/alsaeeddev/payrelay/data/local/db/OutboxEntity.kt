package com.alsaeeddev.payrelay.data.local.db

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

enum class OutboxStatus {
    PENDING,
    SENT,
    FAILED_PERMANENT
}

@Entity(
    tableName = "outbox",
    indices = [
        Index(value = ["idempotency_key"], unique = true),
        Index(value = ["status", "received_at_ms"])
    ]
)
data class OutboxEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0L,
    @ColumnInfo(name = "idempotency_key")
    val idempotencyKey: String,
    @ColumnInfo(name = "sender")
    val sender: String,
    @ColumnInfo(name = "body")
    val body: String,
    @ColumnInfo(name = "received_at_ms")
    val receivedAtMs: Long,
    @ColumnInfo(name = "sim_slot")
    val simSlot: Int?,
    @ColumnInfo(name = "status")
    val status: OutboxStatus = OutboxStatus.PENDING,
    @ColumnInfo(name = "attempt_count")
    val attemptCount: Int = 0,
    @ColumnInfo(name = "last_http_code")
    val lastHttpCode: Int? = null,
    @ColumnInfo(name = "last_error_redacted")
    val lastErrorRedacted: String? = null,
    @ColumnInfo(name = "created_at_ms")
    val createdAtMs: Long,
    @ColumnInfo(name = "sent_at_ms")
    val sentAtMs: Long? = null
)
