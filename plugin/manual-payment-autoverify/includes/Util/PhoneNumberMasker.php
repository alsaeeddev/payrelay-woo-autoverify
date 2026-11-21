<?php
/**
 * Phone number masking for log and DB storage.
 *
 * Replaces the middle portion of a phone number with asterisks so that logs
 * never contain a full, recoverable mobile number.
 *
 * Example (11-digit Pakistani number):
 *   03001234567  →  0300***567
 *
 * @package ManualPaymentAutoVerify\Util
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Util;

/**
 * Phone number masking utilities.
 *
 * All methods are static and stateless.
 */
final class PhoneNumberMasker
{

    /**

     *
     * @param string $phone Raw phone number, may contain +, spaces, dashes.
     *
     * @return string Masked phone number, digits only (no original formatting).
     */

    public static function mask(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }


}

