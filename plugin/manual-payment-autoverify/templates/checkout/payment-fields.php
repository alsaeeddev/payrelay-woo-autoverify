<?php
/**
 * Classic checkout payment fields template.
 *
 * Loaded by MpavGateway::payment_fields() via include.
 *
 * Available variables (set by the calling method):
 *   AccountConfig[] $accounts    Configured merchant accounts.
 *   string          $description Gateway description text (may contain HTML).
 *   string          $total_html  Formatted order total (HTML, may be empty).
 *
 * Layout (steps are numbered by CSS counters, so a skipped step renumbers itself):
 *   1. Choose payment method   (only when more than one account is configured)
 *   2. Send payment            (account number, copy button, QR, instructions)
 *   3. Confirm your payment    (TID + sender number)
 *
 * Markup:
 *   - Semantic HTML with ARIA labels for screen readers.
 *   - dir attribute is set from is_rtl() for RTL locale support.
 *   - All dynamic values are escaped at the point of output.
 *   - Interaction (copy, show/hide account panels) is handled by
 *     MpavGateway::get_copy_button_js(); field names/IDs are unchanged.
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$total_html = isset($total_html) ? (string) $total_html : '';
?>

<div
    class="mpav-payment-fields"
    id="mpav-payment-fields"
    dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>"
>

    <?php if (!empty($description)) : ?>
        <p class="mpav-description">
            <?php echo wp_kses_post($description); ?>
        </p>
    <?php endif; ?>

    <?php if (empty($accounts)) : ?>

        <p class="mpav-no-accounts">
            <?php esc_html_e(
                'No payment accounts are currently configured. Please contact the store owner.',
                'manual-payment-autoverify'
            ); ?>
        </p>

    <?php else : ?>

        <?php
        // With a single account the provider is submitted via a hidden input and
        // the "choose method" step is skipped entirely.
        $single_account = (1 === count($accounts)) ? $accounts[0] : null;
        ?>

        <?php if ('' !== $total_html) : ?>
            <div class="mpav-amount" role="note">
                <span class="mpav-amount__label">
                    <?php esc_html_e('Amount to send', 'manual-payment-autoverify'); ?>
                </span>
                <strong class="mpav-amount__value"><?php echo wp_kses_post($total_html); ?></strong>
                <span class="mpav-amount__hint">
                    <?php esc_html_e(
                        'Send exactly this amount so your payment can be verified automatically.',
                        'manual-payment-autoverify'
                    ); ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="mpav-steps">

            <?php // ── Step: choose provider ─────────────────────────────── ?>
            <?php if ($single_account) : ?>

                <input
                    type="hidden"
                    id="mpav_provider"
                    name="mpav_provider"
                    value="<?php echo esc_attr($single_account->provider()); ?>"
                />

            <?php else : ?>

                <section class="mpav-step mpav-field mpav-field--provider">
                    <span class="mpav-step__title mpav-label" id="mpav-provider-label">
                        <?php esc_html_e('Choose payment method', 'manual-payment-autoverify'); ?>
                        <abbr
                            class="required"
                            title="<?php esc_attr_e('required', 'manual-payment-autoverify'); ?>"
                            aria-hidden="true"
                        >*</abbr>
                    </span>

                    <fieldset
                        class="mpav-provider-tabs"
                        aria-labelledby="mpav-provider-label"
                        role="radiogroup"
                    >
                        <legend class="screen-reader-text">
                            <?php esc_html_e('Select payment method', 'manual-payment-autoverify'); ?>
                        </legend>

                        <?php foreach ($accounts as $index => $account) : ?>
                            <label
                                class="mpav-account-card<?php echo 0 === $index ? ' mpav-account-card--active' : ''; ?>"
                                data-provider="<?php echo esc_attr($account->provider()); ?>"
                                for="mpav_provider_<?php echo esc_attr((string) ($index + 1)); ?>"
                            >
                                <input
                                    type="radio"
                                    class="mpav-provider-radio screen-reader-text"
                                    id="mpav_provider_<?php echo esc_attr((string) ($index + 1)); ?>"
                                    name="mpav_provider"
                                    value="<?php echo esc_attr($account->provider()); ?>"
                                    <?php checked(0, $index); ?>
                                    aria-required="true"
                                />
                                <span class="mpav-account-card__text">
                                    <span class="mpav-account-card__label">
                                        <?php echo esc_html($account->provider_label()); ?>
                                    </span>
                                    <span class="mpav-account-card__name">
                                        <?php echo esc_html($account->display_name()); ?>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </section><!-- /.mpav-field--provider -->

            <?php endif; ?>

            <?php // ── Step: send payment (per-account panels) ───────────── ?>
            <section class="mpav-step mpav-step--send">
                <span class="mpav-step__title">
                    <?php esc_html_e('Send your payment', 'manual-payment-autoverify'); ?>
                </span>

                <?php foreach ($accounts as $index => $account) : ?>
                    <div
                        class="mpav-account-details<?php echo 0 === $index ? ' mpav-account-details--active' : ''; ?>"
                        id="mpav-account-details-<?php echo esc_attr($account->provider()); ?>"
                        data-provider="<?php echo esc_attr($account->provider()); ?>"
                        aria-live="polite"
                    >
                        <div class="mpav-account-details__head">
                            <span class="mpav-chip">
                                <?php echo esc_html($account->provider_label()); ?>
                            </span>
                            <span class="mpav-account-details__name">
                                <?php echo esc_html($account->display_name()); ?>
                            </span>
                        </div>

                        <div class="mpav-account-info">
                            <span class="mpav-account-info__label">
                                <?php esc_html_e('Send to:', 'manual-payment-autoverify'); ?>
                            </span>
                            <strong
                                class="mpav-account-info__number"
                                id="mpav-account-number-<?php echo esc_attr($account->provider()); ?>"
                            >
                                <?php echo esc_html($account->account_number()); ?>
                            </strong>
                            <button
                                type="button"
                                class="mpav-copy-btn"
                                data-clipboard-target="#mpav-account-number-<?php echo esc_attr($account->provider()); ?>"
                                data-copy-label="<?php esc_attr_e('Copy', 'manual-payment-autoverify'); ?>"
                                data-copied-label="<?php esc_attr_e('Copied!', 'manual-payment-autoverify'); ?>"
                                aria-label="<?php echo esc_attr(sprintf(
                                    /* translators: %s: account number */
                                    __('Copy account number %s to clipboard', 'manual-payment-autoverify'),
                                    $account->account_number()
                                )); ?>"
                            >
                                <?php esc_html_e('Copy', 'manual-payment-autoverify'); ?>
                            </button>
                        </div>

                        <?php if ($account->qr_url()) : ?>
                            <div class="mpav-qr-wrap">
                                <img
                                    src="<?php echo esc_url($account->qr_url()); ?>"
                                    alt="<?php echo esc_attr(sprintf(
                                        /* translators: %s: provider label */
                                        __('%s QR code — scan to pay', 'manual-payment-autoverify'),
                                        $account->provider_label()
                                    )); ?>"
                                    class="mpav-qr-image"
                                    width="200"
                                    height="200"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <span class="mpav-qr-caption">
                                    <?php esc_html_e('Or scan the QR code to pay', 'manual-payment-autoverify'); ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ('' !== $account->instructions()) : ?>
                            <div class="mpav-instructions">
                                <?php echo wp_kses_post($account->instructions()); ?>
                            </div>
                        <?php endif; ?>

                    </div><!-- /.mpav-account-details -->
                <?php endforeach; ?>
            </section>

            <?php // ── Step: confirm payment (TID + sender) ──────────────── ?>
            <section class="mpav-step mpav-step--confirm">
                <span class="mpav-step__title">
                    <?php esc_html_e('Confirm your payment', 'manual-payment-autoverify'); ?>
                </span>

                <div class="mpav-field mpav-field--tid">
                    <label for="mpav_tid" class="mpav-label">
                        <?php esc_html_e('Transaction ID (TID)', 'manual-payment-autoverify'); ?>
                        <abbr
                            class="required"
                            title="<?php esc_attr_e('required', 'manual-payment-autoverify'); ?>"
                            aria-hidden="true"
                        >*</abbr>
                    </label>
                    <input
                        type="text"
                        id="mpav_tid"
                        name="mpav_tid"
                        class="input-text mpav-input"
                        placeholder="<?php esc_attr_e('e.g. 123456789', 'manual-payment-autoverify'); ?>"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        maxlength="128"
                        inputmode="text"
                        aria-required="true"
                        aria-describedby="mpav-tid-desc"
                    />
                    <span id="mpav-tid-desc" class="mpav-field-hint">
                        <?php esc_html_e(
                            'The transaction or reference number from your payment confirmation message.',
                            'manual-payment-autoverify'
                        ); ?>
                    </span>
                </div><!-- /.mpav-field--tid -->

                <div class="mpav-field mpav-field--sender">
                    <label for="mpav_sender_number" class="mpav-label">
                        <?php esc_html_e('Sender Mobile Number', 'manual-payment-autoverify'); ?>
                        <abbr
                            class="required"
                            title="<?php esc_attr_e('required', 'manual-payment-autoverify'); ?>"
                            aria-hidden="true"
                        >*</abbr>
                    </label>
                    <input
                        type="tel"
                        id="mpav_sender_number"
                        name="mpav_sender_number"
                        class="input-text mpav-input"
                        placeholder="<?php esc_attr_e('e.g. 03001234567', 'manual-payment-autoverify'); ?>"
                        autocomplete="tel"
                        maxlength="20"
                        inputmode="tel"
                        pattern="[0-9+\-\s]{10,20}"
                        aria-required="true"
                        aria-describedby="mpav-sender-desc"
                    />
                    <span id="mpav-sender-desc" class="mpav-field-hint">
                        <?php esc_html_e(
                            'The mobile number you sent the payment from.',
                            'manual-payment-autoverify'
                        ); ?>
                    </span>
                </div><!-- /.mpav-field--sender -->

                <p class="mpav-note">
                    <?php esc_html_e(
                        'Your order is confirmed automatically as soon as we receive your payment confirmation.',
                        'manual-payment-autoverify'
                    ); ?>
                </p>
            </section>

        </div><!-- /.mpav-steps -->

    <?php endif; ?>

</div><!-- /#mpav-payment-fields -->
