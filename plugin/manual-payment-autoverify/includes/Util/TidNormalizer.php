<?php
/**
 * Transaction ID normalization.
 *
 * Normalization rules (applied in order):
 *   1. Trim leading/trailing whitespace.
 *   2. Strip all internal whitespace (including Unicode whitespace).
 *   3. Convert to uppercase.
 *
 * This ensures "tid123456 ", "TID123456", and "T I D 1 2 3 4 5 6" all produce
 * the same normalized value "TID123456" and are therefore correctly deduplicated
 * by the UNIQUE KEY (provider, tid_normalized) in the DB.
 *
 * @package ManualPaymentAutoVerify\Util
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Util;

/**
 * TID normalization utilities.
 *
 * All methods are static and stateless.
 */
final class TidNormalizer {

    /**
     * Maximum allowed length for a normalized TID.
     * Matches the VARCHAR(128) column in mpav_claims.
     */
    private const MAX_LENGTH = 128;

    /**
     * Allowed character class for a valid normalized TID.
     * Anchored and bounded — not vulnerable to ReDoS.
     */
    private const VALID_PATTERN = '/^[A-Z0-9\-]{1,128}$/';

    /**
     * Returns the normalized form of a raw TID string.
     *
     * @param string $raw The raw TID as entered by the customer or parsed from SMS.
     *
     * @return string Normalized TID (may be empty if input was blank).
     */
    public static function normalize(string $raw): string {
        // 1. Trim.
        $normalized = trim($raw);

        // 2. Strip all whitespace, including Unicode non-breaking spaces.
        //    The /u flag enables Unicode mode; \s matches Unicode whitespace.
        $stripped = preg_replace('/\s+/u', '', $normalized);

        // preg_replace returns null only on regex error, which cannot happen
        // with a fixed, valid pattern. Fall back to empty string to satisfy
        // static analysis tools.
        $normalized = $stripped ?? '';

        // 3. Uppercase.
        $normalized = strtoupper($normalized);

        // 4. Enforce max length to prevent oversized inserts.
        if (strlen($normalized) > self::MAX_LENGTH) {
            $normalized = substr($normalized, 0, self::MAX_LENGTH);
        }

        return $normalized;
    }

    /**
     * Returns true if the value is a valid, non-empty normalized TID.
     *
     * "Valid" means 1–128 alphanumeric-or-dash characters. Hyphens are allowed
     * because some bank reference numbers include them (e.g. "FT24318-123456").
     *
     * @param string $tid Raw or already-normalized TID.
     */
    public static function is_valid(string $tid): bool {
        $normalized = static::normalize($tid);
        if ('' === $normalized) {
            return false;
        }
        // Anchored + bounded regex — safe against ReDoS.
        return (bool) preg_match(self::VALID_PATTERN, $normalized);
    }
}

