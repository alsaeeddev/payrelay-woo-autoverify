package com.alsaeeddev.payrelay.di

import com.alsaeeddev.payrelay.data.repository.AuditLogRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.OutboxRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.PairingRepositoryImpl
import com.alsaeeddev.payrelay.data.repository.SettingsRepositoryImpl
import com.alsaeeddev.payrelay.domain.repository.AuditLogRepository
import com.alsaeeddev.payrelay.domain.repository.OutboxRepository
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import com.alsaeeddev.payrelay.domain.repository.SettingsRepository
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class RepositoryModule {

    @Binds
    @Singleton
    abstract fun bindSettingsRepository(impl: SettingsRepositoryImpl): SettingsRepository

    @Binds
    @Singleton
    abstract fun bindPairingRepository(impl: PairingRepositoryImpl): PairingRepository

    @Binds
    @Singleton
    abstract fun bindOutboxRepository(impl: OutboxRepositoryImpl): OutboxRepository

    @Binds
    @Singleton
    abstract fun bindAuditLogRepository(impl: AuditLogRepositoryImpl): AuditLogRepository
}
