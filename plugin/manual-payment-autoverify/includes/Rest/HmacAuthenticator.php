<?php
/**
 * HMAC-SHA256 request signing and verification.
 *
 * Signing string (fields joined by "\n"):
 *   UPPERCASE(METHOD)
 *   PATH   (full path as sent, e.g. /wp-json/mpav/v1/sms)
 *   timestamp  (unix seconds, as string)
 *   nonce      (lowercase hex)
 *   hex(SHA-256(raw_body_bytes))
 *
 * Test vector (must pass in PHPUnit):
 *   secret    = test_secret_0123456789abcdef0123456789abcdef
 *   method    = POST
 *   path      = /wp-json/mpav/v1/sms
 *   timestamp = 1763262000
 *   nonce     = a1b2c3d4e5f60718293a4b5c6d7e8f90
 *   raw_body  = {"idempotency_key":"k1","sender":"JazzCash","body":"TID 123456789 Rs 1500","received_at_ms":1763262000000,"sim_slot":0}
 *   body_sha256 = e175b9ce210029632748788b1785b1a75b0345656cd4a1f1c6a75d4947e86b3d
 *   expected sig = 884b24a69651860e9bb5456c4aa5b71cee565e816a244fc562ee374b0c5fa0e2
 *
 * Security requirements:
 *   • Signature comparison MUST use hash_equals() (constant-time).
 *   • Nonce is consumed AFTER signature verification, never before.
 *   • All failures return false — no detailed error messages (information leakage).
 *   • Timestamp window ±300 s — prevents both replay and future-dated requests.
 *   • Nonce format: lowercase hex, 32–128 chars (≥16 bytes of entropy).
 *
 * @package ManualPaymentAutoVerify\Rest
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Rest;

use ManualPaymentAutoVerify\Core\NonceStore;

/**
 * HmacAuthenticator — stateless signing + stateful replay-prevention.
 */
class HmacAuthenticator {

    /** Allowed clock skew in seconds for timestamp validation. */
    private const TIMESTAMP_WINDOW = 300;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Verifies a signed REST request.
     *
     * Returns true only when ALL of the following pass:
     *   1. Required headers are present.
     *   2. Timestamp is an integer within ±300 s of server time.
     *   3. Nonce is a lowercase hex string ≥ 32 chars.
     *   4. Signature is a 64-char hex string.
     *   5. Computed signature matches the provided signature (hash_equals).
     *   6. Nonce has not been seen before (NonceStore::consume).
     *
     * Always returns false on any failure — no information is leaked about
     * which check failed.
     *
     * IMPORTANT: `$secret` must already be decrypted by the caller.
     * This method never touches the database to look up secrets.
     *
     * @param \WP_REST_Request $request    The incoming REST request.
     * @param string           $secret     Plaintext device secret (pre-decrypted).
     * @param string           $device_id  Device UUID (passed to NonceStore for audit).
     * @param NonceStore       $nonce_store Replay-prevention store.
     *
     * @return bool True = authenticated; false = rejected.
     */
  public static function verify(
    \WP_REST_Request $request,
    string $secret,
    string $device_id,
    NonceStore $nonce_store
): bool {
        // ── Extract & validate header presence ────────────────────────────────
        // WP_REST_Request normalises header names: lowercased, hyphens → underscores.
        $timestamp = $request->get_header('x_mpav_timestamp');
        $nonce     = $request->get_header('x_mpav_nonce');
        $signature = $request->get_header('x_mpav_signature');

        if (null === $timestamp || '' === $timestamp) {
            return false;
        }
        if (null === $nonce || '' === $nonce) {
            return false;
        }
        if (null === $signature || '' === $signature) {
            return false;
        }

        // ── Timestamp: positive integer string, within ±300 s ─────────────────
        // ctype_digit rejects negatives, floats, leading spaces, signs.
        if (!ctype_digit($timestamp)) {
            return false;
        }
        $ts_int = (int) $timestamp;
        if (abs(time() - $ts_int) > self::TIMESTAMP_WINDOW) {
            return false;
        }

        // ── Nonce: lowercase hex, ≥32 chars (≥16 bytes), ≤128 chars ──────────
        // Anchored + bounded — not vulnerable to ReDoS.
        if (!preg_match('/^[0-9a-f]{32,128}$/', $nonce)) {
            return false;
        }

        // ── Signature: exactly 64 lowercase hex chars (SHA-256 output) ────────
        if (!preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }

        // ── Reconstruct the signing path ──────────────────────────────────────
        // PATH = '/' + rest_get_url_prefix() + route
        //      = '/wp-json' + '/mpav/v1/sms'
        //      = '/wp-json/mpav/v1/sms'
        // This matches what the Android app constructs from the site URL.
        // Include the site's sub-directory (e.g. https://host/shop -> /shop) so the
        // path matches the one the Android app signs (derived from the same
        // home URL that is embedded in the pairing QR code).
        $home_path = (string) wp_parse_url(home_url(), PHP_URL_PATH);
        $home_path = '' === trim($home_path, '/') ? '' : '/' . trim($home_path, '/');
        $path      = $home_path . '/' . rest_get_url_prefix() . $request->get_route();

        // ── Compute expected signature ─────────────────────────────────────────
        // Use the RAW body bytes — never a re-encoded version.
        $expected = static::compute_signature(
            $secret,
            strtoupper($request->get_method()),
            $path,
            $timestamp,
            $nonce,
            $request->get_body()
        );

        // ── Constant-time comparison ───────────────────────────────────────────
        if (!hash_equals($expected, strtolower($signature))) {
            return false;
        }

        // ── Consume nonce (AFTER signature is verified — never before) ─────────
        // If consume() returns false, the nonce was already used: replay attack.
        if (!$nonce_store->consume($nonce, $device_id, $ts_int)) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // Static signing (testable without HTTP context)
    // =========================================================================

    /**
     * Computes the HMAC-SHA256 signature for the given signing components.
     *
     * This is the canonical implementation; it must reproduce the test vector
     * exactly. It is static and pure so it can be called in unit tests without
     * any WordPress or WooCommerce context.
     *
     * Signing string:
     *   METHOD + "\n" + PATH + "\n" + timestamp + "\n" + nonce + "\n" + hex(SHA256(raw_body))
     *
     * @param string $secret    Plaintext device secret.
     * @param string $method    HTTP method, e.g. "POST" (will be uppercased).
     * @param string $path      Full request path, e.g. "/wp-json/mpav/v1/sms".
     * @param string $timestamp Unix timestamp as a string of digits.
     * @param string $nonce     Lowercase hex nonce.
     * @param string $raw_body  Raw request body bytes (UTF-8 encoded JSON).
     *
     * @return string 64-char lowercase hex HMAC-SHA256 digest.
     */
    public static function compute_signature(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $raw_body
    ): string {
        $body_hash = hash('sha256', $raw_body);  // 64-char lowercase hex.

        $signing_string = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $body_hash,
        ]);

        return hash_hmac('sha256', $signing_string, $secret);  // 64-char lowercase hex.
    }
}
