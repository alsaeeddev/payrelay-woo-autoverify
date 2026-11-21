<?php
/**
 * Integer minor-unit money helpers.
 *
 * All monetary values MUST be stored and compared as integer minor units.
 * For PKR: 1 PKR = 100 paisa. Rs 1,500 is stored as 150000.
 *
 * NEVER use float arithmetic for money comparisons or storage.
 * NEVER cast currency strings directly to (float) before converting.
 *
 * @package ManualPaymentAutoVerify\Util
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Util;

use InvalidArgumentException;

/**
 * Money value utilities.
 *
 * All methods are static and stateless so they can be called anywhere without
 * dependency injection.
 */
final class Money {

    /**
     * Converts a decimal string to integer minor units.
     *
     * Handles comma-separated thousands (common in SMS: "Rs 1,500.00").
     * Uses bcmath when available to avoid any float rounding on large amounts.
     *
     * @param string $decimal  Decimal string, e.g. "1500", "1500.00", "1,500.00".
     * @param int    $decimals Decimal places for the currency (2 for PKR).
     *
     * @return int Minor units, e.g. 150000 for Rs 1,500.
     *
     * @throws InvalidArgumentException If the input is not a valid non-negative number.
     */
    public static function from_decimal(string $decimal, int $decimals = 2): int {
        // Strip thousands separators before validation.
        $cleaned = str_replace(',', '', trim($decimal));

        if (!is_numeric($cleaned)) {
            throw new InvalidArgumentException(
                'Invalid monetary value: non-numeric input.'
            );
        }

        if (bccomp($cleaned, '0', $decimals) < 0 && function_exists('bccomp')) {
            throw new InvalidArgumentException('Monetary values must be non-negative.');
        }
        if (!function_exists('bccomp') && (float) $cleaned < 0.0) {
            throw new InvalidArgumentException('Monetary values must be non-negative.');
        }

        // Preferred path: bcmath — avoids all float rounding issues.
        if (function_exists('bcmul') && function_exists('bcadd')) {
            $normalized = bcadd($cleaned, '0', $decimals);
            $multiplier = (string) (10 ** $decimals);
            $result     = bcmul($normalized, $multiplier, 0);
            return (int) $result;
        }

        // Fallback: round() is safe for PKR amounts (up to ~92 trillion paisa).
        return (int) round((float) $cleaned * (10 ** $decimals));
    }

    /**
     * Converts integer minor units to a decimal string.
     *
     * @param int $minor    Minor units, e.g. 150000.
     * @param int $decimals Decimal places for the currency.
     *
     * @return string e.g. "1500.00".
     *
     * @throws InvalidArgumentException If minor units are negative.
     */
    public static function to_decimal(int $minor, int $decimals = 2): string {
        if ($minor < 0) {
            throw new InvalidArgumentException('Minor units must be non-negative.');
        }

        $divisor = (string) (10 ** $decimals);

        if (function_exists('bcdiv')) {
            return bcdiv((string) $minor, $divisor, $decimals);
        }

        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    /**
     * Returns a human-readable display string with currency code.
     *
     * Output is escaped and safe for direct use in HTML after wrapping in
     * esc_html() at the call site.
     *
     * @param int    $minor    Minor units.
     * @param string $currency ISO 4217 code, e.g. "PKR".
     *
     * @return string e.g. "PKR 1,500.00".
     */
    public static function format_display(int $minor, string $currency = 'PKR'): string {
        $decimal = static::to_decimal($minor);
        return sprintf('%s %s', sanitize_text_field($currency), number_format((float) $decimal, 2, '.', ','));
    }
}

