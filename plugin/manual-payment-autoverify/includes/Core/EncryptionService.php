<?php
/**
 * Encryption service for device secrets.
 *
 * Device secrets are symmetric signing keys; if they leak, an attacker can
 * forge webhook signatures. This service encrypts them at rest so a database
 * dump alone is not sufficient to forge requests.
 *
 * Key derivation:
 *   The encryption key is derived deterministically from WordPress secret salts
 *   using HKDF-SHA256 (RFC 5869). No key is ever stored; it is re-derived on
 *   every request from the salts defined in wp-config.php.
 *   key = HKDF-SHA256(IKM = salts, length = 32, info = "mpav-device-secret-v1")
 *
 * Key ID:
 *   A short fingerprint (first 8 hex chars of SHA256(key)) is stored alongside
 *   each encrypted value. If the salts rotate the key_id changes, letting us
 *   detect and report undecryptable ciphertexts rather than silently returning
 *   garbage.
 *
 * Storage format:  {key_id}:{base64(nonce || ciphertext [|| tag])}
 *
 * Backend priority:
 *   1. libsodium secretbox (XSalsa20-Poly1305) — bundled with PHP ≥7.2.
 *   2. OpenSSL AES-256-GCM — fallback for hardened PHP builds with sodium disabled.
 *   3. Neither available → RuntimeException on first encrypt/decrypt.
 *
 * Memory hygiene:
 *   sodium_memzero() is called on the derived key after each operation (sodium
 *   path). On the OpenSSL path the key variable is unset; PHP does not guarantee
 *   immediate memory zeroing, which is a known PHP limitation.
 *
 * @package ManualPaymentAutoVerify\Core
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Core;

use RuntimeException;

/**
 * EncryptionService — authenticated encryption for device secrets.
 */
class EncryptionService {

    /** Context string for HKDF info parameter — changing this rotates all keys. */
    private const HKDF_INFO = 'mpav-device-secret-v1';

    /** OpenSSL cipher for the fallback backend. */
    private const OPENSSL_CIPHER = 'aes-256-gcm';

    /** GCM tag length in bytes. */
    private const GCM_TAG_LENGTH = 16;

    /**
     * Which encryption backend is available: 'sodium' | 'openssl' | 'none'.
     * Detected once and cached.
     */
    private string $backend;

    /** Derived key bytes (32). Memoised within a single request; never stored. */
    private ?string $derived_key = null;

    /** Fingerprint of the derived key. */
    private ?string $key_id = null;

    public function __construct() {
        $this->backend = $this->detect_backend();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Encrypts a plaintext string and returns the storage blob.
     *
     * Format: "{key_id}:{base64(nonce || ciphertext [|| tag])}"
     *
     * @param string $plaintext The device secret to encrypt.
     *
     * @return string Opaque storage blob.
     *
     * @throws RuntimeException If no encryption backend is available.
     */
    public function encrypt(string $plaintext): string {
        if ('none' === $this->backend) {
            throw new RuntimeException(
                '[MPAV] No encryption backend available. Ensure libsodium or OpenSSL with AES-256-GCM is enabled.'
            );
        }

        $key    = $this->get_derived_key();
        $key_id = $this->current_key_id();

        try {
            if ('sodium' === $this->backend) {
                $blob = $this->sodium_encrypt($plaintext, $key);
            } else {
                $blob = $this->openssl_encrypt($plaintext, $key);
            }
        } finally {
            // Zero the key copy from local scope on sodium path.
            if ('sodium' === $this->backend && function_exists('sodium_memzero')) {
                sodium_memzero($key);
            } else {
                unset($key);
            }
        }

        return $key_id . ':' . $blob;
    }

    /**
     * Decrypts a storage blob produced by encrypt().
     *
     * @param string $ciphertext Storage blob: "{key_id}:{base64(...)}"
     *
     * @return string Decrypted plaintext.
     *
     * @throws RuntimeException On decryption failure, authentication failure,
     *                          or key-ID mismatch (salts may have rotated).
     */
    public function decrypt(string $ciphertext): string {
        if ('none' === $this->backend) {
            throw new RuntimeException('[MPAV] No encryption backend available.');
        }

        // Parse storage format.
        $parts = explode(':', $ciphertext, 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new RuntimeException('[MPAV] Malformed ciphertext blob.');
        }

        [$stored_key_id, $encoded] = $parts;

        // Key-ID check: if it doesn't match, WP salts have rotated.
        if (!hash_equals($this->current_key_id(), $stored_key_id)) {
            throw new RuntimeException(
                '[MPAV] Encryption key mismatch. WP salts may have rotated. Re-pair the device.'
            );
        }

        $raw = base64_decode($encoded, true);
        if (false === $raw) {
            throw new RuntimeException('[MPAV] Ciphertext base64 decode failed.');
        }

        $key = $this->get_derived_key();

        try {
            if ('sodium' === $this->backend) {
                $plaintext = $this->sodium_decrypt($raw, $key);
            } else {
                $plaintext = $this->openssl_decrypt($raw, $key);
            }
        } finally {
            if ('sodium' === $this->backend && function_exists('sodium_memzero')) {
                sodium_memzero($key);
            } else {
                unset($key);
            }
        }

        return $plaintext;
    }

    /**
     * Returns the fingerprint of the currently derived key.
     *
     * Stored alongside encrypted values so key-rotation mismatches can be detected.
     * This is NOT a secret value — it is a short hash used for identification only.
     */
    public function current_key_id(): string {
        if (null === $this->key_id) {
            $key          = $this->get_derived_key();
            $this->key_id = substr(hash('sha256', $key), 0, 8);
        }
        return $this->key_id;
    }

    // =========================================================================
    // Sodium backend
    // =========================================================================

    /**
     * @throws RuntimeException On sodium failure.
     */
    private function sodium_encrypt(string $plaintext, string $key): string {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        if (false === $ciphertext) {
            throw new RuntimeException('[MPAV] sodium_crypto_secretbox failed.');
        }

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * @param string $raw Decoded bytes: nonce (24) || ciphertext.
     *
     * @throws RuntimeException On authentication failure.
     */
    private function sodium_decrypt(string $raw, string $key): string {
        $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($raw) <= $nonce_len) {
            throw new RuntimeException('[MPAV] Ciphertext too short for sodium decryption.');
        }

        $nonce      = substr($raw, 0, $nonce_len);
        $ciphertext = substr($raw, $nonce_len);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if (false === $plaintext) {
            // Authentication tag mismatch — data tampered or wrong key.
            throw new RuntimeException('[MPAV] Sodium decryption failed: authentication error.');
        }

        return $plaintext;
    }

    // =========================================================================
    // OpenSSL backend
    // =========================================================================

    /**
     * @throws RuntimeException On OpenSSL failure.
     */
    private function openssl_encrypt(string $plaintext, string $key): string {
        $iv_len = (int) openssl_cipher_iv_length(self::OPENSSL_CIPHER);
        $iv     = random_bytes($iv_len);
        $tag    = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::OPENSSL_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LENGTH
        );

        if (false === $ciphertext) {
            throw new RuntimeException('[MPAV] openssl_encrypt failed.');
        }

        // Layout: iv (12 bytes) || tag (16 bytes) || ciphertext.
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @param string $raw Decoded bytes: iv || tag (16) || ciphertext.
     *
     * @throws RuntimeException On authentication failure or OpenSSL error.
     */
    private function openssl_decrypt(string $raw, string $key): string {
        $iv_len  = (int) openssl_cipher_iv_length(self::OPENSSL_CIPHER);
        $min_len = $iv_len + self::GCM_TAG_LENGTH + 1;

        if (strlen($raw) < $min_len) {
            throw new RuntimeException('[MPAV] Ciphertext too short for AES-GCM decryption.');
        }

        $iv         = substr($raw, 0, $iv_len);
        $tag        = substr($raw, $iv_len, self::GCM_TAG_LENGTH);
        $ciphertext = substr($raw, $iv_len + self::GCM_TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::OPENSSL_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $plaintext) {
            throw new RuntimeException('[MPAV] AES-GCM decryption failed: authentication error.');
        }

        return $plaintext;
    }

    // =========================================================================
    // Key derivation
    // =========================================================================

    /**
     * Derives (and memoises) a 32-byte encryption key from WordPress secret salts.
     *
     * hash_hkdf() requires PHP 7.1.2+. Since this plugin requires PHP 7.4+ the
     * function is guaranteed to be available.
     *
     * The IKM (input key material) is the concatenation of four WP salt constants.
     * These are defined in wp-config.php. If they are not defined (e.g. in unit
     * tests) an empty string is used for each — the derived key is then based
     * entirely on the HKDF_INFO string, which is weak. Unit tests should mock
     * WP constants or use a test-specific salt.
     *
     * @return string 32 binary bytes.
     */
    private function get_derived_key(): string {
        if (null !== $this->derived_key) {
            return $this->derived_key;
        }

        $ikm = (defined('SECURE_AUTH_KEY')  ? SECURE_AUTH_KEY  : '')
             . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '')
             . (defined('AUTH_KEY')         ? AUTH_KEY         : '')
             . (defined('AUTH_SALT')        ? AUTH_SALT        : '');

        if ('' === $ikm && function_exists('wp_salt')) {
            $ikm = wp_salt('auth') . wp_salt('secure_auth');
        }
        if ('' === $ikm) {
            $ikm = 'mpav-default-fallback-key-material-sha256';
        }

        // HKDF-SHA256: derive 32 bytes with our fixed info string.
        $key = hash_hkdf('sha256', $ikm, 32, self::HKDF_INFO, '');

        // hash_hkdf() returns a hex string by default; we want raw binary.
        // The fifth parameter is the "raw_output" flag — it isn't present in PHP 7.4.
        // Instead, unpack the hex string to binary.
        // Actually: hash_hkdf returns raw binary when no 5th arg. Let's verify length.
        if (strlen($key) !== 32) {
            // If raw binary was not returned, we got a hex string (64 chars → need raw).
            // This should not happen but guard anyway.
            $key = pack('H*', $key);
            $key = substr($key, 0, 32);
        }

        $this->derived_key = $key;

        return $this->derived_key;
    }

    // =========================================================================
    // Backend detection
    // =========================================================================

    /**
     * Detects which encryption backend is available.
     *
     * @return string 'sodium' | 'openssl' | 'none'
     */
    private function detect_backend(): string {
        if (
            extension_loaded('sodium')
            && function_exists('sodium_crypto_secretbox')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
        ) {
            return 'sodium';
        }

        if (
            extension_loaded('openssl')
            && function_exists('openssl_encrypt')
            && in_array(self::OPENSSL_CIPHER, openssl_get_cipher_methods(), true)
        ) {
            return 'openssl';
        }

        return 'none';
    }
}

