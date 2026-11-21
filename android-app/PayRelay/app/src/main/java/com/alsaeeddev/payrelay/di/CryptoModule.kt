package com.alsaeeddev.payrelay.di

import com.alsaeeddev.payrelay.data.local.crypto.KeystoreSecretCipher
import com.alsaeeddev.payrelay.data.local.crypto.SecretCipher
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class CryptoModule {

    @Binds
    @Singleton
    abstract fun bindSecretCipher(impl: KeystoreSecretCipher): SecretCipher
}
