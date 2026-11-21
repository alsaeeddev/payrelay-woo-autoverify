<?php
/**
 * Device Manager Template.
 *
 * Variables available:
 * - $devices: object[] list of paired devices
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap mpav-admin-wrap">
	<div class="mpav-page-header">
		<div>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Android SMS Sync Devices', 'manual-payment-autoverify' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Manage Android devices running the MPAV companion app. Paired devices securely forward wallet SMS receipts using HMAC-SHA256 signatures.', 'manual-payment-autoverify' ); ?>
			</p>
		</div>
		<div class="mpav-page-header__actions">
			<?php if ( ! empty( $devices ) ) : ?>
				<button type="button" class="button button-hero mpav-btn-clear-all-devices" id="mpav-btn-clear-all-devices">
					<span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
					<?php esc_html_e( 'Clear All Devices', 'manual-payment-autoverify' ); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="button button-primary button-hero" id="mpav-btn-open-pair-modal">
				<span class="dashicons dashicons-plus-alt2" style="margin-top:4px;"></span>
				<?php esc_html_e( 'Pair New Device', 'manual-payment-autoverify' ); ?>
			</button>
		</div>
	</div>

	<hr class="wp-header-end">

	<div id="mpav-devices-notice-area"></div>

	<?php if ( empty( $devices ) ) : ?>
		<div class="mpav-empty-state">
			<span class="dashicons dashicons-smartphone mpav-empty-icon"></span>
			<h2><?php esc_html_e( 'No Devices Paired Yet', 'manual-payment-autoverify' ); ?></h2>
			<p><?php esc_html_e( 'To enable automatic payment verification, pair an Android phone running the MPAV forwarder app.', 'manual-payment-autoverify' ); ?></p>
			<button type="button" class="button button-primary" id="mpav-btn-empty-pair">
				<?php esc_html_e( 'Pair Your First Device', 'manual-payment-autoverify' ); ?>
			</button>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped table-view-list mpav-devices-table">
			<thead>
				<tr>
					<th scope="col" style="width: 25%;"><?php esc_html_e( 'Device Name', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 15%;"><?php esc_html_e( 'Status', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 20%;"><?php esc_html_e( 'Last Activity', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 25%;"><?php esc_html_e( 'App Diagnostics', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 15%; text-align: right;"><?php esc_html_e( 'Actions', 'manual-payment-autoverify' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $devices as $device ) :
					$device_id   = (int) $device->device_id;
					$is_revoked  = ! empty( $device->revoked_at );
					$meta_raw    = (string) ( $device->device_meta ?? '' );
					$meta        = json_decode( $meta_raw, true ) ?: array();
					$app_version = isset( $meta['app_version'] ) ? sanitize_text_field( (string) $meta['app_version'] ) : 'N/A';
					$battery_opt = isset( $meta['battery_optimized'] ) ? (bool) $meta['battery_optimized'] : null;

					// Compute last seen indicator.
					$last_seen_str = esc_html__( 'Never', 'manual-payment-autoverify' );
					$is_active_now = false;

					if ( ! empty( $device->last_seen_at ) ) {
						$last_seen_ts = strtotime( $device->last_seen_at . ' UTC' );
						if ( false !== $last_seen_ts ) {
							$diff = time() - $last_seen_ts;
							if ( $diff < 300 ) {
								$is_active_now = true;
								$last_seen_str = __( 'Active now', 'manual-payment-autoverify' );
							} else {
								$last_seen_str = sprintf(
									/* translators: %s: Human-readable time difference */
									__( '%s ago', 'manual-payment-autoverify' ),
									human_time_diff( $last_seen_ts, time() )
								);
							}
						}
					}
				?>
				<tr id="mpav-device-row-<?php echo $device_id; ?>" class="<?php echo $is_revoked ? 'mpav-row--revoked' : ''; ?>">
					<td>
						<strong><?php echo esc_html( (string) $device->device_name ); ?></strong>
						<div class="description"><small>ID: #<?php echo $device_id; ?> &bull; Paired: <?php echo esc_html( (string) ( $device->created_at ?? $device->paired_at ?? '' ) ); ?></small></div>
					</td>
					<td>
						<?php if ( $is_revoked ) : ?>
							<span class="mpav-badge mpav-badge--rejected"><?php esc_html_e( 'Revoked', 'manual-payment-autoverify' ); ?></span>
							<div class="description"><small><?php echo esc_html( (string) $device->revoked_at ); ?></small></div>
						<?php else : ?>
							<span class="mpav-badge mpav-badge--auto_verified"><?php esc_html_e( 'Active', 'manual-payment-autoverify' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! $is_revoked && $is_active_now ) : ?>
							<span class="mpav-heartbeat-dot mpav-heartbeat-dot--online" title="<?php esc_attr_e( 'Device is online', 'manual-payment-autoverify' ); ?>"></span>
						<?php elseif ( ! $is_revoked ) : ?>
							<span class="mpav-heartbeat-dot mpav-heartbeat-dot--offline" title="<?php esc_attr_e( 'Device is idle or offline', 'manual-payment-autoverify' ); ?>"></span>
						<?php endif; ?>
						<span><?php echo esc_html( $last_seen_str ); ?></span>
					</td>
					<td>
						<div><strong><?php esc_html_e( 'Version:', 'manual-payment-autoverify' ); ?></strong> <code><?php echo esc_html( $app_version ); ?></code></div>
						<?php if ( null !== $battery_opt ) : ?>
							<div>
								<strong><?php esc_html_e( 'Battery Saver:', 'manual-payment-autoverify' ); ?></strong>
								<?php if ( $battery_opt ) : ?>
									<span class="mpav-text-warning" title="<?php esc_attr_e( 'Battery optimization is active; background SMS forwarding may be delayed by Android OS.', 'manual-payment-autoverify' ); ?>">
										<?php esc_html_e( 'Enabled (Warning)', 'manual-payment-autoverify' ); ?>
									</span>
								<?php else : ?>
									<span class="mpav-text-success"><?php esc_html_e( 'Disabled (Optimal)', 'manual-payment-autoverify' ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</td>
					<td style="text-align: right;">
						<?php if ( ! $is_revoked ) : ?>
							<button type="button" class="button button-small mpav-btn-revoke-device" data-device-id="<?php echo $device_id; ?>">
								<?php esc_html_e( 'Revoke', 'manual-payment-autoverify' ); ?>
							</button>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'No actions', 'manual-payment-autoverify' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

