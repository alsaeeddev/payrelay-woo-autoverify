package com.alsaeeddev.payrelay.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import com.alsaeeddev.payrelay.di.IoDispatcher
import com.alsaeeddev.payrelay.domain.usecase.FilterAndEnqueueSmsUseCase
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import javax.inject.Inject

@AndroidEntryPoint
class SmsBroadcastReceiver : BroadcastReceiver() {

    @Inject
    lateinit var smsIntentParser: SmsIntentParser

    @Inject
    lateinit var filterAndEnqueueSmsUseCase: FilterAndEnqueueSmsUseCase

    @Inject
    @IoDispatcher
    lateinit var ioDispatcher: CoroutineDispatcher

    override fun onReceive(context: Context?, intent: Intent?) {
        if (context == null || intent?.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
            return
        }

        val messages = smsIntentParser.parse(intent)
        if (messages.isEmpty()) {
            return
        }

        val pendingResult = goAsync()
        val scope = CoroutineScope(SupervisorJob() + ioDispatcher)
        scope.launch {
            try {
                withTimeoutOrNull(BROADCAST_TIMEOUT_MS) {
                    for (sms in messages) {
                        filterAndEnqueueSmsUseCase(
                            sender = sms.sender,
                            body = sms.body,
                            receivedAtMs = sms.receivedAtMs,
                            simSlot = sms.simSlot
                        )
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
