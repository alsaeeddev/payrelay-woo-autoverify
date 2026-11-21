<?php
/**
 * Webhook nonce deduplication store.
 *
 * Prevents replay attacks against the /sms and /heartbeat endpoints.
 *
 * Protocol:
 *   1. The Android app generates a cryptographically random nonce (≥16 hex bytes)
 *      and includes it in every signed request.
 *   2. The server calls consume() after verifying the HMAC signature.
 *   3. consume() attempts to INSERT the nonce into mpav_used_nonces.
 *   4. INSERT success  → fresh nonce, return true (allow).
 *   5. MySQL 1062      → nonce seen before, return false (replay blocked).
 *   6. Any other error → return false (conservative: block).
 *
 * Nonce lifetime:
 *   The HMAC signing window is ±300 seconds. A nonce is valid from
 *   (request_timestamp - 300) to (request_timestamp + 300) relative to the
 *   server clock. We store the nonce with:
 *     expires_at = request_timestamp + 300 + 60   (60s cleanup buffer)
 *   The CleanupJob calls purge_expired() periodically to discard old rows.
 *
 * @package ManualPaymentAutoVerify\Core
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Core;

/**
 * NonceStore — single-use webhook nonce tracking.
 */
class NonceStore {

    private \wpdb $wpdb;

    /** @var string Fully-qualified table name. */
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'mpav_used_nonces';
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Attempts to consume a nonce. Returns true on first use; false on replay.
     *
     * MUST be called only after a valid HMAC signature has been confirmed.
     * Calling it before signature verification leaks information about nonce
     * state to unauthenticated callers.
     *
     * @param string $nonce     The nonce value from the X-MPAV-Nonce header.
     * @param string $device_id The device UUID (stored for audit purposes).
     * @param int    $timestamp The request timestamp from X-MPAV-Timestamp header.
     *
     * @return bool True = fresh (allow request); false = replayed (block request).
     */
    public function consume(string $nonce, string $device_id, int $timestamp): bool {
        // Nonce must stay alive until the signing window is fully closed.
        // expires_at = request_timestamp + window_end (300s) + cleanup_grace (60s).
        $expires_unix = $timestamp + 300 + 60;
        $expires_at   = gmdate('Y-m-d H:i:s', $expires_unix);

        $result = $this->wpdb->insert(
            $this->table,
            [
                'nonce'     => $nonce,
                'device_id' => $device_id,
                'expires_at' => $expires_at,
            ],
            ['%s', '%s', '%s']
        );

        if (false === $result) {
            if ($this->is_duplicate_key_error()) {
                // Nonce was seen before — replay attack.
                return false;
            }
            // Any other DB error: fail closed (safe default = block the request).
            $this->log_db_error('consume');
            return false;
        }

        return true;
    }

    /**
     * Deletes expired nonce rows.
     *
     * Called by CleanupJob on a recurring schedule so the table stays bounded.
     *
     * @return int Number of rows deleted.
     */
    public function purge_expired(): int {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->table}` WHERE expires_at < %s",
                current_time('mysql', true)
            )
        );

        return (false === $result) ? 0 : (int) $result;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Whether the last wpdb error was a duplicate key violation.
     * WP 6.0 compat: falls back to string matching when last_error_no absent.
     */
    private function is_duplicate_key_error(): bool {
        if (isset($this->wpdb->last_error_no) && is_int($this->wpdb->last_error_no)) {
            return 1062 === $this->wpdb->last_error_no;
        }
        return false !== strpos($this->wpdb->last_error, 'Duplicate entry');
    }

    /**
     * @param string $method Calling method name for context.
     */
    private function log_db_error(string $method): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf('[MPAV] NonceStore::%s() DB error.', $method));
    }
}

