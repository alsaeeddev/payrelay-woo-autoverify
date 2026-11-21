<?php
/**
 * Checkout field definitions and server-side validation.
 *
 * This class is intentionally decoupled from the gateway so it can be unit-
 * tested without a WC instance. It has no DB dependencies.
 *
 * validate() is called twice:
 *   1. From MpavGateway::validate_fields() — WC classic checkout.
 *   2. Inside MpavGateway::process_payment() — defense in depth.
 *
 * File presence for screenshots is checked here; MIME validation and re-encoding
 * happen in ScreenshotUploader (Stage 7).
 *
 * @package ManualPaymentAutoVerify\Gateway
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Gateway;

use ManualPaymentAutoVerify\Util\TidNormalizer;

/**
 * Checkout field definitions and validation.
 */
class CheckoutFields {

    /** @var AccountConfig[] */
    private array $accounts;

    private bool $screenshot_required;

    /**
     * @param AccountConfig[] $accounts            Configured merchant accounts.
     * @param bool            $screenshot_required Whether screenshot upload is mandatory.
     */
    public function __construct(array $accounts, bool $screenshot_required = true) {
        $this->accounts            = $accounts;
        $this->screenshot_required = $screenshot_required;
    }

    // =========================================================================
    // Field definitions
    // =========================================================================

    /**
     * Returns static field definitions used by the checkout template and blocks
     * component to build their UIs.
     *
     * @return array<string, array{label: string, type: string, required: bool, description: string}>
     */
    public static function field_definitions(): array {
        return [
            'mpav_provider' => [
                'label'       => __('Payment Method', 'manual-payment-autoverify'),
                'type'        => 'select',
                'required'    => true,
                'description' => __('Select the method you used to send payment.', 'manual-payment-autoverify'),
            ],
            'mpav_tid' => [
                'label'       => __('Transaction ID (TID)', 'manual-payment-autoverify'),
                'type'        => 'text',
                'required'    => true,
                'description' => __('The transaction or reference number from your payment confirmation.', 'manual-payment-autoverify'),
            ],
            'mpav_sender_number' => [
                'label'       => __('Sender Mobile Number', 'manual-payment-autoverify'),
                'type'        => 'tel',
                'required'    => true,
                'description' => __('The mobile number you sent payment from (e.g. 03001234567).', 'manual-payment-autoverify'),
            ],
        ];
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validates submitted checkout field data.
     *
     * Does NOT touch $_POST or $_FILES directly — the caller is responsible for
     * passing sanitized data, keeping this method testable without HTTP globals.
     *
     * @param array<string, string> $post_data   Sanitized POST values keyed by field name.
     * @param bool                  $has_file    True if $_FILES['mpav_screenshot'] has a file.
     *
     * @return array<string, string> Empty on success; field_name => error_message on failure.
     */
    public function validate(array $post_data, bool $has_file = false): array {
        $errors = [];

        // ── Provider ──────────────────────────────────────────────────────────
        $provider = sanitize_key($post_data['mpav_provider'] ?? '');

        if ('' === $provider) {
            $errors['mpav_provider'] = __('Please select a payment method.', 'manual-payment-autoverify');
        } elseif (!in_array($provider, AccountConfig::PROVIDERS, true)) {
            $errors['mpav_provider'] = __('Invalid payment method selected.', 'manual-payment-autoverify');
        } elseif (!$this->provider_is_configured($provider)) {
            $errors['mpav_provider'] = __('Selected payment method is not currently available.', 'manual-payment-autoverify');
        }

        // ── TID ───────────────────────────────────────────────────────────────
        $tid_raw = sanitize_text_field($post_data['mpav_tid'] ?? '');

        if ('' === $tid_raw) {
            $errors['mpav_tid'] = __('Please enter your Transaction ID.', 'manual-payment-autoverify');
        } elseif (!TidNormalizer::is_valid($tid_raw)) {
            $errors['mpav_tid'] = __(
                'Transaction ID contains invalid characters. Use letters and numbers only.',
                'manual-payment-autoverify'
            );
        }

        // ── Sender number ─────────────────────────────────────────────────────
        $sender       = sanitize_text_field($post_data['mpav_sender_number'] ?? '');
        $sender_digits = preg_replace('/\D/', '', $sender) ?? '';

        if ('' === $sender) {
            $errors['mpav_sender_number'] = __('Please enter the mobile number you sent payment from.', 'manual-payment-autoverify');
        } elseif (strlen($sender_digits) < 10 || strlen($sender_digits) > 15) {
            $errors['mpav_sender_number'] = __('Please enter a valid mobile number (10–15 digits).', 'manual-payment-autoverify');
        }

        // ── Screenshot (file presence only — MIME checked by ScreenshotUploader) ──
        if ($this->screenshot_required && !$has_file) {
            $errors['mpav_screenshot'] = __('Please upload a screenshot of your payment confirmation.', 'manual-payment-autoverify');
        }

        return $errors;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Whether the given provider slug has at least one active configured account.
     */
    private function provider_is_configured(string $provider): bool {
        foreach ($this->accounts as $account) {
            if ($account->provider() === $provider) {
                return true;
            }
        }
        return false;
    }
}

