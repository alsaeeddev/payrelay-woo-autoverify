<?php
/**
 * WooCommerce Blocks payment method integration for MPAV.
 *
 * Implements AbstractPaymentMethodType to register the React payment method
 * component with the block-based checkout and pass merchant account data to JS.
 *
 * Screenshots are no longer collected. The merchant's
 * accounts, instructions, and QR codes ARE passed to the React component via
 * get_payment_method_data() so the customer sees the correct payment details.
 *
 * Runtime payment data (provider, TID, sender number) entered by the customer
 * in the React form is bridged back to $_POST via
 * Plugin::register_blocks_payment_data_hook() before process_payment() runs.
 *
 * @package ManualPaymentAutoVerify\Blocks
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use ManualPaymentAutoVerify\Gateway\AccountConfig;
use ManualPaymentAutoVerify\Gateway\MpavGateway;

/**
 * MpavBlocksIntegration — Blocks API payment method type.
 */
final class MpavBlocksIntegration extends AbstractPaymentMethodType {

    /**
     * Payment method name — MUST match WC_Payment_Gateway::$id.
     *
     * @var string
     */
    protected $name = MpavGateway::GATEWAY_ID;

    // =========================================================================
    // AbstractPaymentMethodType implementation
    // =========================================================================

    /**
     * Initializes the integration.
     *
     * AbstractPaymentMethodType::initialize() is called by WC Blocks before any
     * other method. It must populate $this->settings from the WP options table.
     */
    public function initialize(): void {
        $this->settings = get_option(
            'woocommerce_' . MpavGateway::GATEWAY_ID . '_settings',
            []
        );
    }

    /**
     * Whether this payment method should be available on the blocks checkout.
     */
    public function is_active(): bool {
        return 'yes' === ($this->settings['enabled'] ?? 'no');
    }

    /**
     * Returns the script handle(s) that must be enqueued for this payment method.
     *
     * The compiled `block-checkout.js` is built from `assets/js/checkout/index.js`
     * via `npm run build`. Both files are part of this repository; run
     * `npm install && npm run build` once to generate the compiled asset.
     */
    public function get_payment_method_script_handles(): array {
        $handle  = 'mpav-block-checkout';
        $js_path = MPAV_DIR . 'assets/js/block-checkout.js';
        $version = file_exists($js_path) ? (string) filemtime($js_path) : MPAV_VERSION;

        wp_register_script(
            $handle,
            MPAV_URL . 'assets/js/block-checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            $version,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                $handle,
                'manual-payment-autoverify',
                MPAV_DIR . 'languages'
            );
        }

        return [$handle];
    }

    /**
     * Returns the data object passed to the React component via
     * `getSetting('mpav_data', {})` from `@woocommerce/settings`.
     *
     * All string values are escaped here so the React component can render them
     * safely without additional sanitization.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array {
        $gateway = $this->get_gateway_instance();

        $accounts_data = [];
        if ($gateway instanceof MpavGateway) {
            foreach ($gateway->get_accounts() as $account) {
                $accounts_data[] = [
                    'provider'      => esc_attr($account->provider()),
                    'providerLabel' => esc_html($account->provider_label()),
                    'displayName'   => esc_html($account->display_name()),
                    'accountNumber' => esc_html($account->account_number()),
                    'qrUrl'         => esc_url($account->qr_url() ?? ''),
                    'instructions'  => wp_kses_post($account->instructions()),
                ];
            }
        }

        return [
            'title'               => esc_html($this->get_setting('title', '')),
            'description'         => esc_html($this->get_setting('description', '')),
            'accounts'            => $accounts_data,
            'providers'           => array_map('esc_attr', AccountConfig::PROVIDERS),
            'i18n'                => [
                'selectProvider'   => esc_html__('Select payment method', 'manual-payment-autoverify'),
                'transactionId'    => esc_html__('Transaction ID (TID)', 'manual-payment-autoverify'),
                'tidPlaceholder'   => esc_html__('e.g. 123456789', 'manual-payment-autoverify'),
                'senderNumber'     => esc_html__('Sender Mobile Number', 'manual-payment-autoverify'),
                'senderPlaceholder'=> esc_html__('e.g. 03001234567', 'manual-payment-autoverify'),
                'sendTo'           => esc_html__('Send to:', 'manual-payment-autoverify'),
                'copy'             => esc_html__('Copy', 'manual-payment-autoverify'),
                'copied'           => esc_html__('Copied!', 'manual-payment-autoverify'),
                'required'         => esc_html__('required', 'manual-payment-autoverify'),
                'errProvider'      => esc_html__('Please select a payment method.', 'manual-payment-autoverify'),
                'errTid'           => esc_html__('Please enter your Transaction ID.', 'manual-payment-autoverify'),
                'errTidInvalid'    => esc_html__('Transaction ID contains invalid characters. Use letters and numbers only.', 'manual-payment-autoverify'),
                'errSender'        => esc_html__('Please enter the mobile number you sent payment from.', 'manual-payment-autoverify'),
                'errSenderInvalid' => esc_html__('Please enter a valid mobile number (10–15 digits).', 'manual-payment-autoverify'),
                'amountToSend'     => esc_html__('Amount to send', 'manual-payment-autoverify'),
                'amountHint'       => esc_html__('Send exactly this amount so your payment can be verified automatically.', 'manual-payment-autoverify'),
                'chooseProvider'   => esc_html__('Choose payment method', 'manual-payment-autoverify'),
                'sendPayment'      => esc_html__('Send your payment', 'manual-payment-autoverify'),
                'confirmPayment'   => esc_html__('Confirm your payment', 'manual-payment-autoverify'),
                'scanQr'           => esc_html__('Or scan the QR code to pay', 'manual-payment-autoverify'),
                'tidHint'          => esc_html__('The transaction or reference number from your payment confirmation message.', 'manual-payment-autoverify'),
                'senderHint'       => esc_html__('The mobile number you sent the payment from.', 'manual-payment-autoverify'),
                'autoConfirmNote'  => esc_html__('Your order is confirmed automatically as soon as we receive your payment confirmation.', 'manual-payment-autoverify'),
            ],
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Retrieves the live MpavGateway instance from WooCommerce's gateway registry.
     *
     * Returns null if WooCommerce hasn't loaded yet or the gateway is disabled.
     */
    private function get_gateway_instance(): ?MpavGateway {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways[ MpavGateway::GATEWAY_ID ] ?? null;
        return $gateway instanceof MpavGateway ? $gateway : null;
    }
}

