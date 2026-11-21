package com.alsaeeddev.payrelay.worker

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.alsaeeddev.payrelay.domain.usecase.HeartbeatOutcome
import com.alsaeeddev.payrelay.domain.usecase.SendHeartbeatUseCase
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

@HiltWorker
class HeartbeatWorker @AssistedInject constructor(
    @Assisted appContext: Context,
    @Assisted workerParams: WorkerParameters,
    private val sendHeartbeatUseCase: SendHeartbeatUseCase
) : CoroutineWorker(appContext, workerParams) {

    override suspend fun doWork(): Result {
        return when (sendHeartbeatUseCase(manualCheck = false)) {
            HeartbeatOutcome.Success,
            HeartbeatOutcome.Skipped,
            is HeartbeatOutcome.AuthOrClockSkewError -> Result.success()

            is HeartbeatOutcome.RetryableFailure -> Result.retry()
        }
    }
}
