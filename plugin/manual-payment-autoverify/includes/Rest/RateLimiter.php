<?php
/**
 * Fixed-window rate limiter backed by WordPress transients.
 *
 * Provides soft rate limiting for the MPAV REST endpoints. "Soft" means a
 * burst of simultaneous requests may exceed the limit by a small amount due to
 * the TOCTOU race inherent in transient-based counters. This is acceptable
 * because:
 *   1. The HMAC signature is the actual security boundary.
 *   2. Rate limiting here protects compute resources, not secrets.
 *   3. Per-device limits prevent one compromised device flooding the system.
 *   4. Per-IP limits prevent unauthenticated enumeration.
 *
 * Client IP is derived from REMOTE_ADDR only by default. Sites behind a trusted
 * reverse proxy can filter `mpav_client_ip` to use X-Forwarded-For. Never trust
 * proxy headers unless the proxy is verified.
 *
 * @package ManualPaymentAutoVerify\Rest
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Rest;

/**
 * RateLimiter — fixed-window counter using transients.
 */
final class RateLimiter {

    /** Default requests-per-minute for /sms and /heartbeat per device. */
    private const DEVICE_LIMIT_DEFAULT = 60;

    /** Default requests-per-minute for /sms and /heartbeat per IP. */
    private const IP_LIMIT_DEFAULT = 120;

    /** Requests-per-minute for /pair per IP. */
    private const PAIR_IP_LIMIT = 5;

    /** Window size in seconds. */
    private const WINDOW_SECONDS = 60;

    /**
     * Checks and increments the per-device counter.
     *
     * @param string $device_id Device UUID from the X-MPAV-Device header.
     * @param int    $limit     Requests allowed per WINDOW_SECONDS.
     *
     * @return bool True = within limit; false = limit exceeded.
     */
    public static function check_device(string $device_id, int $limit = self::DEVICE_LIMIT_DEFAULT): bool {
        // Hash device_id so the transient key never contains the raw UUID.
        $key = 'mpav_rl_d_' . substr(hash('sha256', $device_id), 0, 24);
        return self::increment($key, $limit);
    }

    /**
     * Checks and increments the per-IP counter for /sms and /heartbeat.
     *
     * @param string $ip    Client IP address.
     * @param int    $limit Requests allowed per WINDOW_SECONDS.
     *
     * @return bool True = within limit; false = limit exceeded.
     */
    public static function check_ip(string $ip, int $limit = self::IP_LIMIT_DEFAULT): bool {
        $key = 'mpav_rl_i_' . substr(hash('sha256', $ip), 0, 24);
        return self::increment($key, $limit);
    }

    /**
     * Stricter per-IP check for the /pair endpoint (5 req/min).
     *
     * @param string $ip Client IP address.
     *
     * @return bool True = within limit; false = limit exceeded.
     */
    public static function check_pair_ip(string $ip): bool {
        $key = 'mpav_rl_p_' . substr(hash('sha256', $ip), 0, 24);
        return self::increment($key, self::PAIR_IP_LIMIT);
    }

    /**
     * Returns the best available client IP.
     *
     * Filterable via `mpav_client_ip` for proxy-aware deployments.
     *
     * @return string IP address string, or '0.0.0.0' if unavailable.
     */
    public static function client_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';

        /**
         * Filter the client IP used for rate limiting.
         *
         * @param string $ip Default IP from REMOTE_ADDR.
         */
        return (string) apply_filters('mpav_client_ip', $ip);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Increments the counter for the given transient key.
     * Returns false if the counter has reached or exceeded $limit.
     *
     * @param string $key   Transient key (must be ≤172 chars after WP prefix).
     * @param int    $limit Maximum allowed hits per WINDOW_SECONDS.
     *
     * @return bool True = within limit; false = exceeded.
     */
    private static function increment(string $key, int $limit): bool {
        $data = get_transient($key);

        if (false === $data || !is_array($data)) {
            // First hit in this window.
            set_transient($key, ['count' => 1, 'start' => time()], self::WINDOW_SECONDS + 5);
            return true;
        }

        $elapsed = time() - (int) $data['start'];

        if ($elapsed >= self::WINDOW_SECONDS) {
            // Window has expired; reset counter.
            set_transient($key, ['count' => 1, 'start' => time()], self::WINDOW_SECONDS + 5);
            return true;
        }

        $count = (int) $data['count'];
        if ($count >= $limit) {
            // Limit exceeded; do not increment (avoid int overflow on sustained flood).
            return false;
        }

        // Increment within the existing window.
        $data['count'] = $count + 1;
        // Remaining TTL to preserve window boundary.
        $remaining_ttl = max(1, self::WINDOW_SECONDS - $elapsed + 5);
        set_transient($key, $data, $remaining_ttl);

        return true;
    }
}

