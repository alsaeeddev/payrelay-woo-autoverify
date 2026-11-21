package com.alsaeeddev.payrelay.data.repository

import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.crypto.RequestSigner
import com.alsaeeddev.payrelay.data.local.db.OutboxDao
import com.alsaeeddev.payrelay.data.local.db.OutboxEntity
import com.alsaeeddev.payrelay.di.IoDispatcher
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.domain.model.OutboxItem
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class OutboxRepositoryImpl @Inject constructor(
    private val outboxDao: OutboxDao,
    private val requestSigner: RequestSigner,
    private val redactor: Redactor,
    private val timeProvider: TimeProvider,
    @IoDispatcher private val ioDispatcher: CoroutineDispatcher
) : OutboxRepository {

    override suspend fun enqueueIfNew(
        sender: String,
        body: String,
        receivedAtMs: Long,
        simSlot: Int?
    ): Long {
        return withContext(ioDispatcher) {
            val key = requestSigner.idempotencyKey(sender, body, receivedAtMs)
            val entity = OutboxEntity(
                idempotencyKey = key,
                sender = sender,
                body = body,
                receivedAtMs = receivedAtMs,
                simSlot = simSlot,
                createdAtMs = timeProvider.epochMillis()
            )
            outboxDao.insertIfNew(entity)
        }
    }

    override suspend fun getPendingInOrder(): List<OutboxItem> {
        return withContext(ioDispatcher) {
            outboxDao.getPendingInOrder().map { it.toDomain() }
        }
    }

    override fun observePendingCount(): Flow<Int> = outboxDao.observePendingCount()

    override suspend fun countPending(): Int = withContext(ioDispatcher) {
        outboxDao.countPending()
    }

    override fun observeRecent(limit: Int): Flow<List<OutboxItem>> =
        outboxDao.observeRecent(limit).map { list -> list.map { it.toDomain() } }

    override suspend fun markSent(id: Long, sentAtMs: Long, httpCode: Int) {
        withContext(ioDispatcher) {
            outboxDao.markSent(id = id, sentAtMs = sentAtMs, httpCode = httpCode)
        }
    }

    override suspend fun recordRetryAttempt(
        id: Long,
        attemptCount: Int,
        httpCode: Int?,
        errorMessage: String?
    ) {
        withContext(ioDispatcher) {
            outboxDao.recordRetryAttempt(
                id = id,
                attemptCount = attemptCount,
                httpCode = httpCode,
                errorRedacted = errorMessage?.let { redactor.redactMessage(it) }
            )
        }
    }

    override suspend fun markFailedPermanent(
        id: Long,
        httpCode: Int?,
        errorMessage: String?
    ) {
        withContext(ioDispatcher) {
            outboxDao.markFailedPermanent(
                id = id,
                httpCode = httpCode,
                errorRedacted = errorMessage?.let { redactor.redactMessage(it) }
            )
        }
    }

    override suspend fun pruneCompleted(retentionDays: Int): Int {
        return withContext(ioDispatcher) {
            val cutoffMs = timeProvider.epochMillis() - TimeUnit.DAYS.toMillis(retentionDays.toLong())
            outboxDao.pruneCompletedBefore(cutoffMs)
        }
    }

    override suspend fun clearAll() {
        withContext(ioDispatcher) {
            outboxDao.deleteAll()
        }
    }

    private fun OutboxEntity.toDomain(): OutboxItem = OutboxItem(
        id = id,
        idempotencyKey = idempotencyKey,
        sender = sender,
        body = body,
        receivedAtMs = receivedAtMs,
        simSlot = simSlot,
        status = status,
        attemptCount = attemptCount,
        lastHttpCode = lastHttpCode,
        lastErrorRedacted = lastErrorRedacted,
        createdAtMs = createdAtMs,
        sentAtMs = sentAtMs
    )
}
