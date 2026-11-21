package com.alsaeeddev.payrelay.data.repository

import com.alsaeeddev.payrelay.data.local.crypto.EncryptedSecretBlob
import com.alsaeeddev.payrelay.data.local.crypto.SecretCipher
import com.alsaeeddev.payrelay.data.local.prefs.SettingsDataStore
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidationResult
import com.alsaeeddev.payrelay.data.remote.SiteUrlValidator
import com.alsaeeddev.payrelay.di.IoDispatcher
import com.alsaeeddev.payrelay.domain.model.PairingCredentials
import com.alsaeeddev.payrelay.domain.repository.PairingRepository
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.withContext
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PairingRepositoryImpl @Inject constructor(
    private val settingsDataStore: SettingsDataStore,
    private val secretCipher: SecretCipher,
    private val siteUrlValidator: SiteUrlValidator,
    @IoDispatcher private val ioDispatcher: CoroutineDispatcher
) : PairingRepository {

    override suspend fun savePairing(siteUrl: String, deviceId: String, secretUtf8: String) {
        withContext(ioDispatcher) {
            val validatedUrl = when (val validation = siteUrlValidator.validate(siteUrl)) {
                is SiteUrlValidationResult.Valid -> validation.normalizedBaseUrl
                is SiteUrlValidationResult.Invalid -> throw IllegalArgumentException(validation.reason)
            }
            require(deviceId.isNotBlank()) { "Device ID must not be blank" }
            require(secretUtf8.isNotBlank()) { "Pairing secret must not be blank" }

            val encryptedBlob = secretCipher.encrypt(secretUtf8.toByteArray(Charsets.UTF_8))
            settingsDataStore.savePairing(
                siteUrl = validatedUrl,
                deviceId = deviceId.trim(),
                encryptedSecret = encryptedBlob
            )
        }
    }

    override suspend fun getDecryptedCredentials(): PairingCredentials? {
        return withContext(ioDispatcher) {
            val prefs = settingsDataStore.snapshot()
            val siteUrl = prefs.siteUrl ?: return@withContext null
            val deviceId = prefs.deviceId ?: return@withContext null
            val ciphertext = prefs.secretCiphertext ?: return@withContext null
            val iv = prefs.secretIv ?: return@withContext null
            if (!prefs.isPaired) return@withContext null

            val plaintext = secretCipher.decrypt(
                EncryptedSecretBlob(
                    ciphertextBase64 = ciphertext,
                    ivBase64 = iv
                )
            )
            PairingCredentials(
                siteUrl = siteUrl,
                deviceId = deviceId,
                secret = plaintext
            )
        }
    }

    override suspend fun clearPairing() {
        withContext(ioDispatcher) {
            settingsDataStore.clearPairingOnly()
            secretCipher.deleteKey()
        }
    }
}
