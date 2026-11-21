package com.alsaeeddev.payrelay.di

import android.content.Context
import androidx.room.Room
import com.alsaeeddev.payrelay.data.local.db.AuditLogDao
import com.alsaeeddev.payrelay.data.local.db.OutboxDao
import com.alsaeeddev.payrelay.data.local.db.PayRelayDatabase
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {

    @Provides
    @Singleton
    fun providePayRelayDatabase(@ApplicationContext context: Context): PayRelayDatabase {
        return Room.databaseBuilder(
            context,
            PayRelayDatabase::class.java,
            "payrelay.db"
        ).build()
    }

    @Provides
    fun provideOutboxDao(database: PayRelayDatabase): OutboxDao = database.outboxDao()

    @Provides
    fun provideAuditLogDao(database: PayRelayDatabase): AuditLogDao = database.auditLogDao()
}
