package com.alsaeeddev.payrelay.data.repository

import com.alsaeeddev.payrelay.data.local.crypto.Redactor
import com.alsaeeddev.payrelay.data.local.db.AuditLogDao
import com.alsaeeddev.payrelay.data.local.db.AuditLogEntity
import com.alsaeeddev.payrelay.data.local.db.LogLevel
import com.alsaeeddev.payrelay.di.IoDispatcher
import com.alsaeeddev.payrelay.di.TimeProvider
import com.alsaeeddev.payrelay.domain.model.AuditLogEntry
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class AuditLogRepositoryImpl @Inject constructor(
    private val auditLogDao: AuditLogDao,
    private val redactor: Redactor,
    private val timeProvider: TimeProvider,
    @IoDispatcher private val ioDispatcher: CoroutineDispatcher
) : AuditLogRepository {

    override suspend fun log(
        level: LogLevel,
        category: String,
        sender: String?,
        message: String,
        httpCode: Int?
    ) {
        withContext(ioDispatcher) {
            auditLogDao.insert(
                AuditLogEntity(
                    timestampMs = timeProvider.epochMillis(),
                    level = level,
                    category = category,
                    senderMasked = sender?.let { redactor.maskSender(it) },
                    message = redactor.redactMessage(message),
                    httpCode = httpCode
                )
            )
        }
    }

    override fun observeRecent(limit: Int): Flow<List<AuditLogEntry>> =
        auditLogDao.observeRecent(limit).map { entries ->
            entries.map {
                AuditLogEntry(
                    id = it.id,
                    timestampMs = it.timestampMs,
                    level = it.level,
                    category = it.category,
                    senderMasked = it.senderMasked,
                    message = it.message,
                    httpCode = it.httpCode
                )
            }
        }

    override suspend fun prune(retentionDays: Int): Int {
        return withContext(ioDispatcher) {
            val cutoffMs = timeProvider.epochMillis() - TimeUnit.DAYS.toMillis(retentionDays.toLong())
            auditLogDao.pruneBefore(cutoffMs)
        }
    }

    override suspend fun clearAll() {
        withContext(ioDispatcher) {
            auditLogDao.deleteAll()
        }
    }
}
