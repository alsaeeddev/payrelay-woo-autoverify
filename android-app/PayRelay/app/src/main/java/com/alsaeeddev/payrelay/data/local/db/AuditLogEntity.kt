package com.alsaeeddev.payrelay.data.local.db

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

enum class LogLevel {
    INFO,
    WARN,
    ERROR
}

@Entity(
    tableName = "audit_logs",
    indices = [Index(value = ["timestamp_ms"])]
)
data class AuditLogEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0L,
    @ColumnInfo(name = "timestamp_ms")
    val timestampMs: Long,
    @ColumnInfo(name = "level")
    val level: LogLevel,
    @ColumnInfo(name = "category")
    val category: String,
    @ColumnInfo(name = "sender_masked")
    val senderMasked: String? = null,
    @ColumnInfo(name = "message")
    val message: String,
    @ColumnInfo(name = "http_code")
    val httpCode: Int? = null
)
