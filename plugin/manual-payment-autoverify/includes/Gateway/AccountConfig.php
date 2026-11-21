<?php
/**
 * Immutable value object for a single merchant payment account.
 *
 * A merchant may configure multiple accounts (e.g. JazzCash + Easypaisa).
 * Each account has a provider ID, a human-readable display name, an account
 * number for the customer to send money to, and an optional QR code image URL.
 *
 * All string values are sanitized in the constructor so that callers can render
 * them with esc_html() / esc_attr() without further processing.
 *
 * @package ManualPaymentAutoVerify\Gateway
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Gateway;

use InvalidArgumentException;

/**
 * AccountConfig value object.
 */
final class AccountConfig {

    /**
     * Allowed provider slugs.
     * Keep in sync with the SMS parser provider IDs (Stage 5).
     *
     * @var string[]
     */
    public const PROVIDERS = ['jazzcash', 'easypaisa', 'bank'];

    private string  $provider;
    private string  $display_name;
    private string  $account_number;
    private ?string $qr_url;
    private string  $instructions;

    /**
     * @param string      $provider       One of PROVIDERS.
     * @param string      $display_name   Human-readable name shown at checkout.
     * @param string      $account_number Mobile wallet or bank account number.
     * @param string|null $qr_url         Absolute URL to the QR image, or null.
     * @param string      $instructions   HTML instructions; run through wp_kses_post.
     *
     * @throws InvalidArgumentException On invalid provider or empty required fields.
     */
    public function __construct(
        string $provider,
        string $display_name,
        string $account_number,
        ?string $qr_url = null,
        string $instructions = ''
    ) {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException(
                'Invalid provider. Allowed: ' . implode(', ', self::PROVIDERS) . '.'
            );
        }
        if ('' === trim($display_name)) {
            throw new InvalidArgumentException('Account display_name cannot be empty.');
        }
        if ('' === trim($account_number)) {
            throw new InvalidArgumentException('Account account_number cannot be empty.');
        }

        $this->provider       = $provider;
        $this->display_name   = sanitize_text_field($display_name);
        $this->account_number = sanitize_text_field($account_number);
        $this->qr_url         = null !== $qr_url ? esc_url_raw($qr_url) : null;
        $this->instructions   = wp_kses_post($instructions);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function provider(): string {
        return $this->provider;
    }

    public function display_name(): string {
        return $this->display_name;
    }

    public function account_number(): string {
        return $this->account_number;
    }

    public function qr_url(): ?string {
        return $this->qr_url;
    }

    public function instructions(): string {
        return $this->instructions;
    }

    /**
     * Returns the translatable human-readable label for the provider.
     */
    public function provider_label(): string {
        $labels = [
            'jazzcash'  => __('JazzCash', 'manual-payment-autoverify'),
            'easypaisa' => __('Easypaisa', 'manual-payment-autoverify'),
            'bank'      => __('Bank Transfer', 'manual-payment-autoverify'),
        ];

        return $labels[$this->provider] ?? ucfirst($this->provider);
    }
}

