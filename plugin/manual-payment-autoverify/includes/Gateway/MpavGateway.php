<?php
/**
 * Manual Payment Auto-Verify payment gateway.
 *
 * Extends WC_Payment_Gateway to provide:
 *   - Classic checkout payment fields (provider, TID, sender number, screenshot)
 *   - Block checkout via MpavBlocksIntegration + store-API data hook
 *   - Claim creation on payment processing
 *   - On-hold order status while awaiting SMS verification
 *
 * HPOS compliance: ALL order data access uses WC_Order CRUD methods.
 *   get_post_meta() / update_post_meta() are NEVER called on order IDs.
 *
 * Screenshot upload in this stage uses wp_check_filetype_and_ext() + move_uploaded_file().
 * Stage 7 (ScreenshotUploader) replaces this with finfo MIME probing, getimagesize()
 * verification, GD/Imagick re-encoding and random filename generation.
 *
 * @package ManualPaymentAutoVerify\Gateway
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Gateway;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Util\Money;
use ManualPaymentAutoVerify\Util\TidNormalizer;
use ManualPaymentAutoVerify\Util\PhoneNumberMasker;

/**
 * MpavGateway — WooCommerce payment gateway.
 *
 * @property string $title
 * @property string $description
 */
class MpavGateway extends \WC_Payment_Gateway {

    /** Gateway ID — must match the gateway slug used in WC settings URLs. */
    public const GATEWAY_ID = 'mpav';

    /** @var AccountConfig[] Enabled accounts built from admin settings. */
    private array $accounts = [];

    // =========================================================================
    // Constructor
    // =========================================================================

    public function __construct() {
        $this->id                 = self::GATEWAY_ID;
        $this->icon               = (string) apply_filters('mpav_gateway_icon', '');
        $this->has_fields         = true;
        $this->method_title       = __('Manual Payment (JazzCash / Easypaisa / Bank)', 'manual-payment-autoverify');
        $this->method_description = __(
            'Allow customers to pay manually via JazzCash, Easypaisa, or bank transfer, then submit their Transaction ID for verification.',
            'manual-payment-autoverify'
        );
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        // Bind displayed title / description from settings.
        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');

        // Build the AccountConfig[] from saved settings.
        $this->accounts = $this->build_accounts();

        // Persist admin form settings.
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );

        // Enqueue checkout CSS.
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    /**
     * Defines the admin settings form fields rendered on the WC gateway page.
     */
    public function init_form_fields(): void {
        $account_slots = [];

        for ($slot = 1; $slot <= 3; $slot++) {
            $account_slots["account_{$slot}_section"] = [
                'title' => sprintf(
                    /* translators: %d: account slot number */
                    __('Payment Account %d', 'manual-payment-autoverify'),
                    $slot
                ),
                'type'  => 'title',
            ];
            $account_slots["account_{$slot}_enabled"] = [
                'title'   => __('Enable', 'manual-payment-autoverify'),
                'type'    => 'checkbox',
                'label'   => __('Enable this account', 'manual-payment-autoverify'),
                'default' => 'no',
            ];
            $account_slots["account_{$slot}_provider"] = [
                'title'   => __('Provider', 'manual-payment-autoverify'),
                'type'    => 'select',
                'options' => [
                    'jazzcash'  => __('JazzCash', 'manual-payment-autoverify'),
                    'easypaisa' => __('Easypaisa', 'manual-payment-autoverify'),
                    'bank'      => __('Bank Transfer', 'manual-payment-autoverify'),
                ],
                'default' => 1 === $slot ? 'jazzcash' : (2 === $slot ? 'easypaisa' : 'bank'),
            ];
            $account_slots["account_{$slot}_name"] = [
                'title'   => __('Display Name', 'manual-payment-autoverify'),
                'type'    => 'text',
                'default' => '',
            ];
            $account_slots["account_{$slot}_number"] = [
                'title'       => __('Account Number', 'manual-payment-autoverify'),
                'type'        => 'text',
                'description' => __('Mobile wallet or bank account number customers send money to.', 'manual-payment-autoverify'),
                'default'     => '',
                'desc_tip'    => true,
            ];
            $account_slots["account_{$slot}_instructions"] = [
                'title'       => __('Instructions', 'manual-payment-autoverify'),
                'type'        => 'textarea',
                'description' => __('Optional instructions shown when this account is selected. Basic HTML allowed.', 'manual-payment-autoverify'),
                'default'     => '',
                'desc_tip'    => true,
            ];
        }

        $this->form_fields = array_merge(
            [
                'enabled' => [
                    'title'   => __('Enable/Disable', 'manual-payment-autoverify'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Manual Payment Auto-Verify', 'manual-payment-autoverify'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title'    => __('Title', 'manual-payment-autoverify'),
                    'type'     => 'text',
                    'default'  => __('Manual Bank / Mobile Payment', 'manual-payment-autoverify'),
                    'desc_tip' => __('Payment method title shown to the customer at checkout.', 'manual-payment-autoverify'),
                ],
                'description' => [
                    'title'    => __('Description', 'manual-payment-autoverify'),
                    'type'     => 'textarea',
                    'default'  => __('Pay via JazzCash, Easypaisa, or bank transfer, then enter your Transaction ID below.', 'manual-payment-autoverify'),
                    'desc_tip' => __('Payment instructions shown to the customer during checkout.', 'manual-payment-autoverify'),
                ],
                'claim_expiry_minutes' => [
                    'title'             => __('Claim Expiry (minutes)', 'manual-payment-autoverify'),
                    'type'              => 'number',
                    'description'       => __('Unverified claims expire after this many minutes. Default: 1440 (24 hours).', 'manual-payment-autoverify'),
                    'default'           => '1440',
                    'desc_tip'          => true,
                    'custom_attributes' => ['min' => '30', 'max' => '10080', 'step' => '1'],
                ],
            ],
            $account_slots
        );
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Returns all enabled merchant accounts.
     *
     * @return AccountConfig[]
     */
    public function get_accounts(): array {
        return $this->accounts;
    }

    // =========================================================================
    // Checkout rendering
    // =========================================================================

    /**
     * Renders payment fields in the classic (non-block) checkout.
     */
    public function payment_fields(): void {
        $accounts    = $this->accounts;
        $description = $this->get_description();
        $total_html  = $this->get_checkout_total_html();

        $template = MPAV_DIR . 'templates/checkout/payment-fields.php';
        if (is_readable($template)) {
            include $template;
        }
    }

    /**
     * Icon shown next to the payment method title in the classic checkout list.
     *
     * A merchant-supplied icon (via the `mpav_gateway_icon` filter) is kept as-is.
     * Otherwise a small inline SVG (mobile payment) is used, so no image file or
     * remote request is needed and the colour follows the theme/accent.
     */
    public function get_icon(): string {
        if ('' !== (string) $this->icon) {
            return parent::get_icon();
        }

        $svg = '<span class="mpav-method-icon" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
            . '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg></span>';

        return (string) apply_filters('woocommerce_gateway_icon', $svg, $this->id);
    }

    /**
     * Formatted order total shown in the "Amount to send" banner (display only).
     *
     * Uses the order on the pay-for-order page, otherwise the cart. Returns an
     * empty string when no total is available (the banner is then skipped).
     */
    private function get_checkout_total_html(): string {
        if (function_exists('get_query_var')) {
            $pay_order_id = absint(get_query_var('order-pay'));
            if ($pay_order_id > 0) {
                $pay_order = wc_get_order($pay_order_id);
                if ($pay_order instanceof \WC_Order) {
                    return wc_price((float) $pay_order->get_total(), ['currency' => $pay_order->get_currency()]);
                }
            }
        }

        if (function_exists('WC') && WC()->cart) {
            return wc_price((float) WC()->cart->get_total('edit'));
        }

        return '';
    }

    /**
     * Enqueues checkout-specific CSS when on the checkout page.
     */
    public function enqueue_checkout_scripts(): void {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'mpav-checkout',
            MPAV_URL . 'assets/css/checkout.css',
            [],
            MPAV_VERSION
        );

        // Inline copy-to-clipboard script — no external dependency required.
        $inline_js = $this->get_copy_button_js();
        wp_add_inline_script('wc-checkout', $inline_js);
    }

    // =========================================================================
    // Validation (classic checkout)
    // =========================================================================

    /**
     * Server-side field validation for classic checkout.
     *
     * WooCommerce verifies the checkout nonce before calling this method, so we
     * must NOT add a second nonce check here (it would double-verify).
     *
     * Returns true to allow checkout to proceed; adds wc_add_notice() on error.
     */
    public function validate_fields(): bool {
        $fields = new CheckoutFields($this->accounts, false);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies checkout nonce.
        $post_data = [
            'mpav_provider'      => isset($_POST['mpav_provider'])      ? wp_unslash($_POST['mpav_provider'])      : '',
            'mpav_tid'           => isset($_POST['mpav_tid'])           ? wp_unslash($_POST['mpav_tid'])           : '',
            'mpav_sender_number' => isset($_POST['mpav_sender_number']) ? wp_unslash($_POST['mpav_sender_number']) : '',
        ];

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $errors = $fields->validate($post_data, false);

        foreach ($errors as $message) {
            wc_add_notice($message, 'error');
        }

        return empty($errors);
    }

    // =========================================================================
    // Payment processing
    // =========================================================================

    /**
     * Processes payment after checkout form submission.
     *
     * Called by WooCommerce for both classic and block checkout. For block
     * checkout, Plugin::register_blocks_payment_data_hook() populates $_POST
     * with the payment_data from the Store API request before this method runs.
     *
     * Flow:
     *   1. Load order and re-validate fields (defense in depth).
     *   2. Convert order total to integer minor units.
     *   3. Handle screenshot upload.
     *   4. Create claim row via ClaimRepository.
     *   5. Store claim metadata on order via HPOS-safe CRUD.
     *   6. Set order status to on-hold.
     *   7. Fire action hook for downstream subscribers (SMS matching, etc.).
     *   8. Empty cart and return redirect URL.
     *
     * @param int $order_id WooCommerce order ID.
     *
     * @return array{result: string, redirect: string}
     */
    public function process_payment($order_id): array {
        $failure = ['result' => 'failure', 'redirect' => ''];

        // ── Load order ────────────────────────────────────────────────────────
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wc_add_notice(__('Payment error: order not found.', 'manual-payment-autoverify'), 'error');
            return $failure;
        }

        // ── Collect & sanitize field data ──────────────────────────────────
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifies checkout nonce.
        $provider   = sanitize_key(wp_unslash($_POST['mpav_provider'] ?? ''));
        $tid_raw    = sanitize_text_field(wp_unslash($_POST['mpav_tid'] ?? ''));
        $sender_raw = sanitize_text_field(wp_unslash($_POST['mpav_sender_number'] ?? ''));

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // ── Re-validate (defense in depth — fields may have been tampered) ──
        $fields       = new CheckoutFields($this->accounts, false);
        $field_errors = $fields->validate(
            ['mpav_provider' => $provider, 'mpav_tid' => $tid_raw, 'mpav_sender_number' => $sender_raw],
            false
        );

        if (!empty($field_errors)) {
            foreach ($field_errors as $message) {
                wc_add_notice($message, 'error');
            }
            return $failure;
        }

        $tid_normalized = TidNormalizer::normalize($tid_raw);

        // ── Convert order total to minor units ─────────────────────────────
        $amount_minor = $this->order_amount_to_minor($order);
        if ($amount_minor <= 0) {
            wc_add_notice(__('Payment error: invalid order total.', 'manual-payment-autoverify'), 'error');
            return $failure;
        }

        // ── Screenshots are no longer collected ────────────────────────────
        // The proof of payment is the matched SMS, not an uploaded image.
        $screenshot_path = '';

        // ── Compute claim expiry timestamp (UTC) ───────────────────────────
        $expiry_minutes = max(30, (int) $this->get_option('claim_expiry_minutes', 1440));
        $expires_at     = gmdate('Y-m-d H:i:s', time() + $expiry_minutes * 60);

        // ── Create claim (UNIQUE KEY prevents duplicates at DB level) ──────
        $repository = new ClaimRepository();
        $claim_id   = $repository->create([
            'order_id'        => $order_id,
            'provider'        => $provider,
            'tid_raw'         => $tid_raw,
            'tid_normalized'  => $tid_normalized,
            'amount_minor'    => $amount_minor,
            'sender_number'   => PhoneNumberMasker::mask($sender_raw),
            'screenshot_path' => $screenshot_path,
            'expires_at'      => $expires_at,
        ]);

        if (false === $claim_id) {
            // MySQL 1062: TID already claimed by another order.
            wc_add_notice(
                __(
                    'This Transaction ID has already been submitted. If this is an error, please contact us.',
                    'manual-payment-autoverify'
                ),
                'error'
            );
            return $failure;
        }

        // ── Persist claim data on the order (HPOS-safe — no get_post_meta) ─
        $order->update_meta_data('_mpav_claim_id', $claim_id);
        $order->update_meta_data('_mpav_provider', $provider);
        $order->update_meta_data('_mpav_tid_normalized', $tid_normalized);
        $order->update_meta_data('_mpav_amount_minor', $amount_minor);
        $order->save_meta_data();

        // ── Move order to on-hold ──────────────────────────────────────────
        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %d: internal claim ID */
                __('Awaiting manual payment verification. Claim #%d created.', 'manual-payment-autoverify'),
                $claim_id
            )
        );

        // ── Notify downstream subscribers ─────────────────────────────────
        // Stage 5 (matcher) and Stage 6 (admin) hook here.
        do_action('mpav_claim_created', $claim_id, $order, $provider, $tid_normalized, $amount_minor);

        // ── Clear cart and return ──────────────────────────────────────────
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Builds AccountConfig[] from the saved gateway settings.
     *
     * Silently skips any slot with invalid or incomplete settings.
     *
     * @return AccountConfig[]
     */
    private function build_accounts(): array {
        $accounts = [];

        for ($slot = 1; $slot <= 3; $slot++) {
            if ('yes' !== $this->get_option("account_{$slot}_enabled", 'no')) {
                continue;
            }

            $provider = sanitize_key($this->get_option("account_{$slot}_provider", ''));
            $name     = sanitize_text_field($this->get_option("account_{$slot}_name", ''));
            $number   = sanitize_text_field($this->get_option("account_{$slot}_number", ''));

            if ('' === $provider || '' === $name || '' === $number) {
                continue;
            }

            try {
                $accounts[] = new AccountConfig(
                    $provider,
                    $name,
                    $number,
                    null,
                    $this->get_option("account_{$slot}_instructions", '')
                );
            } catch (\InvalidArgumentException $e) {
                // Skip misconfigured slot.
                continue;
            }
        }

        return $accounts;
    }

    /**
     * Converts the order total to integer paisa (minor units).
     *
     * Uses Money::from_decimal() to avoid float rounding.
     *
     * @param \WC_Order $order WooCommerce order.
     *
     * @return int Minor units, or 0 on conversion failure.
     */
    private function order_amount_to_minor(\WC_Order $order): int {
        try {
            return Money::from_decimal((string) $order->get_total('edit'), 2);
        } catch (\InvalidArgumentException $e) {
            return 0;
        }
    }

    /**
     * Returns the minimal inline JS needed for the copy-to-clipboard button.
     *
     * No external libraries required. Gracefully degrades if the Clipboard API
     * is unavailable (falls back to execCommand which is deprecated but widely
     * supported on Android WebView).
     */
    private function get_copy_button_js(): string {
        $copied = esc_js(__('Copied!', 'manual-payment-autoverify'));
        $copy   = esc_js(__('Copy', 'manual-payment-autoverify'));

        // Nowdoc: the JavaScript contains no PHP interpolation. Placeholders are
        // swapped for the translated labels below.
        $js = <<<'JS'
(function () {
    var COPIED = '__MPAV_COPIED__';
    var COPY   = '__MPAV_COPY__';
    var saved  = { provider: '', tid: '', sender: '' };

    function activeProvider() {
        var checked = document.querySelector('.mpav-provider-radio:checked');
        if (checked) { return checked.value; }
        var hidden = document.getElementById('mpav_provider');
        return hidden ? hidden.value : '';
    }

    // Show the account panel of the selected provider, hide the others.
    function sync() {
        var active = activeProvider();
        document.querySelectorAll('.mpav-account-details').forEach(function (el) {
            el.style.display = '';
            el.classList.toggle('mpav-account-details--active', el.dataset.provider === active);
        });
        document.querySelectorAll('.mpav-account-card').forEach(function (el) {
            el.classList.toggle('mpav-account-card--active', el.dataset.provider === active);
        });
    }

    // WooCommerce re-renders the payment box on every checkout refresh; put back
    // what the customer had already chosen/typed.
    function restore() {
        if (saved.provider) {
            document.querySelectorAll('.mpav-provider-radio').forEach(function (r) {
                if (r.value === saved.provider) { r.checked = true; }
            });
        }
        var tid = document.getElementById('mpav_tid');
        if (tid && saved.tid && !tid.value) { tid.value = saved.tid; }
        var sender = document.getElementById('mpav_sender_number');
        if (sender && saved.sender && !sender.value) { sender.value = saved.sender; }
        sync();
    }

    function flash(btn) {
        btn.textContent = btn.dataset.copiedLabel || COPIED;
        btn.classList.add('mpav-copy-btn--done');
        setTimeout(function () {
            btn.textContent = btn.dataset.copyLabel || COPY;
            btn.classList.remove('mpav-copy-btn--done');
        }, 2000);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.mpav-copy-btn');
        if (!btn) { return; }
        e.preventDefault();
        var target = btn.dataset.clipboardTarget ? document.querySelector(btn.dataset.clipboardTarget) : null;
        var text = target ? target.textContent.trim() : '';
        if (!text) { return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { flash(btn); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); flash(btn); } catch (err) {}
            document.body.removeChild(ta);
        }
    });

    document.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('mpav-provider-radio')) { return; }
        saved.provider = e.target.value;
        sync();
    });

    document.addEventListener('input', function (e) {
        if (e.target.id === 'mpav_tid') { saved.tid = e.target.value; }
        if (e.target.id === 'mpav_sender_number') { saved.sender = e.target.value; }
    });

    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', restore);
    }
    document.addEventListener('DOMContentLoaded', restore);
    if (document.readyState !== 'loading') { restore(); }
})();
JS;

        return str_replace(
            array('__MPAV_COPIED__', '__MPAV_COPY__'),
            array($copied, $copy),
            $js
        );
    }
}
