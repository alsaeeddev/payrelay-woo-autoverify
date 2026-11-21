<?php
/**
 * Pairing QR Modal Template.
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="mpav-pairing-modal" class="mpav-modal" style="display: none;">
	<div class="mpav-modal-backdrop"></div>
	<div class="mpav-modal-content">
		<div class="mpav-modal-header">
			<h2><?php esc_html_e( 'Pair Android Device', 'manual-payment-autoverify' ); ?></h2>
			<button type="button" class="mpav-modal-close" aria-label="<?php esc_attr_e( 'Close', 'manual-payment-autoverify' ); ?>">&times;</button>
		</div>

		<div class="mpav-modal-body">
			<?php if ( ! is_ssl() ) : ?>
				<div class="notice notice-warning inline mpav-ssl-warning">
					<p>
						<strong><?php esc_html_e( 'Security Warning:', 'manual-payment-autoverify' ); ?></strong>
						<?php esc_html_e( 'Your site is not using HTTPS. While tokens are one-time and short-lived, pairing over plain HTTP is vulnerable to network interception. We strongly recommend configuring SSL/TLS before pairing devices.', 'manual-payment-autoverify' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="mpav-pairing-layout">
				<div class="mpav-qr-section">
					<div id="mpav-qr-container" class="mpav-qr-box">
						<div class="mpav-loading-spinner"></div>
						<div class="description mpav-loading-text"><?php esc_html_e( 'Generating secure pairing token...', 'manual-payment-autoverify' ); ?></div>
					</div>
					<div id="mpav-token-timer" class="mpav-timer-text" style="display: none;">
						<?php esc_html_e( 'Token expires in:', 'manual-payment-autoverify' ); ?>
						<strong id="mpav-timer-countdown">10:00</strong>
					</div>
				</div>

				<div class="mpav-instructions-section">
					<h3><?php esc_html_e( 'Pairing Instructions', 'manual-payment-autoverify' ); ?></h3>
					<ol class="mpav-steps-list">
						<li><?php esc_html_e( 'Open the MPAV Forwarder companion app on your Android device.', 'manual-payment-autoverify' ); ?></li>
						<li><?php esc_html_e( 'Tap "Scan QR Code" in the app and point your phone at the code on the left.', 'manual-payment-autoverify' ); ?></li>
						<li><?php esc_html_e( 'Alternatively, enter the manual pairing token below if your device cannot scan the code:', 'manual-payment-autoverify' ); ?></li>
					</ol>

					<div class="mpav-manual-token-box" style="margin-top: 15px;">
						<label for="mpav-raw-token-input"><strong><?php esc_html_e( 'Manual Pairing Token', 'manual-payment-autoverify' ); ?></strong></label>
						<div class="mpav-input-copy-group">
							<input type="text" id="mpav-raw-token-input" readonly class="code" placeholder="<?php esc_attr_e( 'Waiting for token...', 'manual-payment-autoverify' ); ?>" />
							<button type="button" class="button" id="mpav-btn-copy-token">
								<?php esc_html_e( 'Copy', 'manual-payment-autoverify' ); ?>
							</button>
						</div>
					</div>

					<div class="description" style="margin-top: 12px;">
						<?php esc_html_e( 'The pairing token is valid for 10 minutes and can only be used once. Once paired, the secret key is securely stored inside Android Keystore.', 'manual-payment-autoverify' ); ?>
					</div>
				</div>
			</div>
		</div>

		<div class="mpav-modal-footer">
			<button type="button" class="button button-secondary mpav-modal-close">
				<?php esc_html_e( 'Done', 'manual-payment-autoverify' ); ?>
			</button>
		</div>
	</div>
</div>

