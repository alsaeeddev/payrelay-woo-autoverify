package com.alsaeeddev.payrelay.data.local.db

import androidx.room.TypeConverter

class Converters {

    @TypeConverter
    fun fromOutboxStatus(status: OutboxStatus): String = status.name

    @TypeConverter
    fun toOutboxStatus(raw: String): OutboxStatus = OutboxStatus.valueOf(raw)

    @TypeConverter
    fun fromLogLevel(level: LogLevel): String = level.name

    @TypeConverter
    fun toLogLevel(raw: String): LogLevel = LogLevel.valueOf(raw)
}
