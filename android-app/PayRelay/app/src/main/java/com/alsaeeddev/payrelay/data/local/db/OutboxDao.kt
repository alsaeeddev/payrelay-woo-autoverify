package com.alsaeeddev.payrelay.data.local.db

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface OutboxDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insertIfNew(item: OutboxEntity): Long

    @Query("SELECT * FROM outbox WHERE status = 'PENDING' ORDER BY received_at_ms ASC, id ASC")
    suspend fun getPendingInOrder(): List<OutboxEntity>

    @Query("SELECT COUNT(*) FROM outbox WHERE status = 'PENDING'")
    suspend fun countPending(): Int

    @Query("SELECT COUNT(*) FROM outbox WHERE status = 'PENDING'")
    fun observePendingCount(): Flow<Int>

    @Query("SELECT * FROM outbox ORDER BY received_at_ms DESC, id DESC LIMIT :limit")
    fun observeRecent(limit: Int = 100): Flow<List<OutboxEntity>>

    @Query(
        """
        UPDATE outbox
        SET status = 'SENT',
            body = '',
            sent_at_ms = :sentAtMs,
            last_http_code = :httpCode,
            last_error_redacted = NULL
        WHERE id = :id
        """
    )
    suspend fun markSent(id: Long, sentAtMs: Long, httpCode: Int)

    @Query(
        """
        UPDATE outbox
        SET attempt_count = :attemptCount,
            last_http_code = :httpCode,
            last_error_redacted = :errorRedacted
        WHERE id = :id
        """
    )
    suspend fun recordRetryAttempt(
        id: Long,
        attemptCount: Int,
        httpCode: Int?,
        errorRedacted: String?
    )

    @Query(
        """
        UPDATE outbox
        SET status = 'FAILED_PERMANENT',
            body = '',
            last_http_code = :httpCode,
            last_error_redacted = :errorRedacted
        WHERE id = :id
        """
    )
    suspend fun markFailedPermanent(
        id: Long,
        httpCode: Int?,
        errorRedacted: String?
    )

    @Query(
        """
        DELETE FROM outbox
        WHERE status IN ('SENT', 'FAILED_PERMANENT')
          AND created_at_ms < :cutoffMs
        """
    )
    suspend fun pruneCompletedBefore(cutoffMs: Long): Int

    @Query("DELETE FROM outbox")
    suspend fun deleteAll()
}
