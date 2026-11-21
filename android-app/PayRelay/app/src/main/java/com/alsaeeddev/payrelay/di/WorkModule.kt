package com.alsaeeddev.payrelay.di

import com.alsaeeddev.payrelay.worker.AndroidBatteryOptimizationChecker
import com.alsaeeddev.payrelay.worker.BatteryOptimizationChecker
import com.alsaeeddev.payrelay.worker.SyncWorkScheduler
import com.alsaeeddev.payrelay.worker.WorkScheduler
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class WorkModule {

    @Binds
    @Singleton
    abstract fun bindSyncWorkScheduler(impl: WorkScheduler): SyncWorkScheduler

    @Binds
    @Singleton
    abstract fun bindBatteryOptimizationChecker(
        impl: AndroidBatteryOptimizationChecker
    ): BatteryOptimizationChecker
}
