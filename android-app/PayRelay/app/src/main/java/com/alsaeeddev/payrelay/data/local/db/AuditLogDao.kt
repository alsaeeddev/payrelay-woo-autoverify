package com.alsaeeddev.payrelay.data.local.db

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface AuditLogDao {
    @Insert
    suspend fun insert(entry: AuditLogEntity)

    @Query("SELECT * FROM audit_logs ORDER BY timestamp_ms DESC, id DESC LIMIT :limit")
    fun observeRecent(limit: Int = 200): Flow<List<AuditLogEntity>>

    @Query("DELETE FROM audit_logs WHERE timestamp_ms < :cutoffMs")
    suspend fun pruneBefore(cutoffMs: Long): Int

    @Query("DELETE FROM audit_logs")
    suspend fun deleteAll()
}
