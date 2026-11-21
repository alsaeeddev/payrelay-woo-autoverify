<?php
/**
 * Logs Viewer Template.
 *
 * Variables available:
 * - $rows: object[] audit log rows
 * - $total_rows: int
 * - $total_pages: int
 * - $paged: int
 * - $claim_id: int
 * - $order_id: int
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap mpav-admin-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Manual Payment Audit Log', 'manual-payment-autoverify' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Immutable audit trail of all claim state transitions, SMS verifications, and administrative actions.', 'manual-payment-autoverify' ); ?>
	</p>
	<hr class="wp-header-end">

	<form method="get" class="mpav-logs-filter-form" style="margin-bottom: 15px;">
		<input type="hidden" name="page" value="mpav-logs" />
		<div class="alignleft actions">
			<label for="filter-claim-id" class="screen-reader-text"><?php esc_html_e( 'Filter by Claim ID', 'manual-payment-autoverify' ); ?></label>
			<input type="number" id="filter-claim-id" name="claim_id" value="<?php echo $claim_id > 0 ? esc_attr( (string) $claim_id ) : ''; ?>" placeholder="<?php esc_attr_e( 'Claim ID...', 'manual-payment-autoverify' ); ?>" style="width: 120px;" />

			<label for="filter-order-id" class="screen-reader-text"><?php esc_html_e( 'Filter by Order ID', 'manual-payment-autoverify' ); ?></label>
			<input type="number" id="filter-order-id" name="order_id" value="<?php echo $order_id > 0 ? esc_attr( (string) $order_id ) : ''; ?>" placeholder="<?php esc_attr_e( 'Order ID...', 'manual-payment-autoverify' ); ?>" style="width: 120px;" />

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter Logs', 'manual-payment-autoverify' ); ?>" />
			<?php if ( $claim_id > 0 || $order_id > 0 ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mpav-logs' ) ); ?>" class="button"><?php esc_html_e( 'Reset Filters', 'manual-payment-autoverify' ); ?></a>
			<?php endif; ?>
		</div>
		<div class="tablenav-pages alignright">
			<span class="displaying-num"><?php printf( esc_html( _n( '%s log item', '%s log items', $total_rows, 'manual-payment-autoverify' ) ), number_format_i18n( $total_rows ) ); ?></span>
		</div>
		<br class="clear" />
	</form>

	<?php if ( empty( $rows ) ) : ?>
		<div class="mpav-empty-state">
			<span class="dashicons dashicons-list-view mpav-empty-icon"></span>
			<h2><?php esc_html_e( 'No Audit Log Entries Found', 'manual-payment-autoverify' ); ?></h2>
			<p><?php esc_html_e( 'Audit records will be created automatically as claims are submitted, verified, or actioned.', 'manual-payment-autoverify' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped table-view-list mpav-logs-table">
			<thead>
				<tr>
					<th scope="col" style="width: 80px;"><?php esc_html_e( 'Log #', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 160px;"><?php esc_html_e( 'Timestamp (UTC)', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 200px;"><?php esc_html_e( 'Event', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 140px;"><?php esc_html_e( 'Actor', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 110px;"><?php esc_html_e( 'Claim', 'manual-payment-autoverify' ); ?></th>
					<th scope="col" style="width: 110px;"><?php esc_html_e( 'Order', 'manual-payment-autoverify' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Context Details', 'manual-payment-autoverify' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) :
					$log_id     = (int) $row->id;
					$timestamp  = (string) $row->created_at;
					$event      = esc_html( (string) $row->event );
					$actor      = esc_html( (string) $row->actor );
					$c_id       = ! empty( $row->claim_id ) ? (int) $row->claim_id : null;
					$o_id       = ! empty( $row->order_id ) ? (int) $row->order_id : null;
					$context    = (string) ( $row->context ?? '' );

					$context_decoded = json_decode( $context, true );
					$context_display = is_array( $context_decoded )
						? wp_json_encode( $context_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
						: $context;
				?>
				<tr>
					<td><code>#<?php echo $log_id; ?></code></td>
					<td><?php echo esc_html( $timestamp ); ?></td>
					<td><span class="mpav-event-badge"><?php echo $event; ?></span></td>
					<td><code><?php echo $actor; ?></code></td>
					<td>
						<?php if ( $c_id ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mpav-queue&status=all&s=' . $c_id ) ); ?>">
								<?php printf( esc_html__( 'Claim #%d', 'manual-payment-autoverify' ), $c_id ); ?>
							</a>
						<?php else : ?>
							<span class="description">&mdash;</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $o_id ) : ?>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $o_id . '&action=edit' ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php printf( esc_html__( 'Order #%d', 'manual-payment-autoverify' ), $o_id ); ?>
							</a>
						<?php else : ?>
							<span class="description">&mdash;</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( '' !== trim( $context ) ) : ?>
							<pre class="mpav-context-json"><code><?php echo esc_html( $context_display ); ?></code></pre>
						<?php else : ?>
							<span class="description">&mdash;</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

