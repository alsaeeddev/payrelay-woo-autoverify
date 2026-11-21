package com.alsaeeddev.payrelay.worker

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.ForegroundInfo
import androidx.work.WorkerParameters
import com.alsaeeddev.payrelay.R
import com.alsaeeddev.payrelay.domain.usecase.SyncOutcome
import com.alsaeeddev.payrelay.domain.usecase.SyncOutboxUseCase
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

@HiltWorker
class SmsSyncWorker @AssistedInject constructor(
    @Assisted appContext: Context,
    @Assisted workerParams: WorkerParameters,
    private val syncOutboxUseCase: SyncOutboxUseCase
) : CoroutineWorker(appContext, workerParams) {

    override suspend fun doWork(): Result {
        return when (syncOutboxUseCase()) {
            is SyncOutcome.Completed, SyncOutcome.Skipped -> Result.success()
            SyncOutcome.RetryRecommended -> Result.retry()
        }
    }

    override suspend fun getForegroundInfo(): ForegroundInfo {
        ensureNotificationChannel()
        val notification = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setContentTitle(applicationContext.getString(R.string.app_name))
            .setContentText(applicationContext.getString(R.string.notification_syncing_payments))
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
        return ForegroundInfo(NOTIFICATION_ID, notification)
    }

    private fun ensureNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = applicationContext.getSystemService(NotificationManager::class.java)
            val existing = manager.getNotificationChannel(CHANNEL_ID)
            if (existing == null) {
                val channel = NotificationChannel(
                    CHANNEL_ID,
                    applicationContext.getString(R.string.notification_channel_sync_name),
                    NotificationManager.IMPORTANCE_LOW
                )
                manager.createNotificationChannel(channel)
            }
        }
    }

    companion object {
        private const val CHANNEL_ID = "payrelay_sync_channel"
        private const val NOTIFICATION_ID = 4101
    }
}
