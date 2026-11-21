package com.alsaeeddev.payrelay.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.alsaeeddev.payrelay.di.IoDispatcher
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import javax.inject.Inject

@AndroidEntryPoint
class BootAndUpdateReceiver : BroadcastReceiver() {

    @Inject
    lateinit var settingsRepository: SettingsRepository

    @Inject
    lateinit var outboxRepository: OutboxRepository

    @Inject
    lateinit var syncWorkScheduler: SyncWorkScheduler

    @Inject
    @IoDispatcher
    lateinit var ioDispatcher: CoroutineDispatcher

    override fun onReceive(context: Context?, intent: Intent?) {
        val action = intent?.action ?: return
        if (action != Intent.ACTION_BOOT_COMPLETED && action != Intent.ACTION_MY_PACKAGE_REPLACED) {
            return
        }

        val pendingResult = goAsync()
        val scope = CoroutineScope(SupervisorJob() + ioDispatcher)
        scope.launch {
            try {
                withTimeoutOrNull(BROADCAST_TIMEOUT_MS) {
                    val settings = settingsRepository.getSettingsSnapshot()
                    if (settings.consentAccepted && settings.isPaired) {
                        syncWorkScheduler.schedulePeriodicHeartbeat()
                        if (!settings.forwardingPaused && outboxRepository.countPending() > 0) {
                            syncWorkScheduler.enqueueExpeditedSmsSync()
                        }
                    }
                }
            } finally {
                pendingResult.finish()
                scope.cancel()
            }
        }
    }

    companion object {
        private const val BROADCAST_TIMEOUT_MS = 8_500L
    }
}
