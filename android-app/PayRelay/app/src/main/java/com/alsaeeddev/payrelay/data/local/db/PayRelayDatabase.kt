package com.alsaeeddev.payrelay.data.local.db

import androidx.room.Database
import androidx.room.RoomDatabase
import androidx.room.TypeConverters

@Database(
    entities = [OutboxEntity::class, AuditLogEntity::class],
    version = 1,
    exportSchema = false
)
@TypeConverters(Converters::class)
abstract class PayRelayDatabase : RoomDatabase() {
    abstract fun outboxDao(): OutboxDao
    abstract fun auditLogDao(): AuditLogDao
}
