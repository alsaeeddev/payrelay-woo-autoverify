package com.alsaeeddev.payrelay.worker

import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit
import javax.inject.Inject
import javax.inject.Singleton

interface SyncWorkScheduler {
    fun enqueueExpeditedSmsSync()
    fun schedulePeriodicHeartbeat()
    fun cancelAllWork()
}

@Singleton
class WorkScheduler @Inject constructor(
    private val workManager: WorkManager
) : SyncWorkScheduler {

    override fun enqueueExpeditedSmsSync() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val request = OneTimeWorkRequestBuilder<SmsSyncWorker>()
            .setConstraints(constraints)
            .setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()

        workManager.enqueueUniqueWork(
            UNIQUE_SMS_SYNC,
            ExistingWorkPolicy.APPEND_OR_REPLACE,
            request
        )
    }

    override fun schedulePeriodicHeartbeat() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val request = PeriodicWorkRequestBuilder<HeartbeatWorker>(15, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 1, TimeUnit.MINUTES)
            .build()

        workManager.enqueueUniquePeriodicWork(
            UNIQUE_HEARTBEAT,
            ExistingPeriodicWorkPolicy.KEEP,
            request
        )
    }

    override fun cancelAllWork() {
        workManager.cancelUniqueWork(UNIQUE_SMS_SYNC)
        workManager.cancelUniqueWork(UNIQUE_HEARTBEAT)
    }

    companion object {
        const val UNIQUE_SMS_SYNC = "payrelay_sms_sync"
        const val UNIQUE_HEARTBEAT = "payrelay_heartbeat"
    }
}
