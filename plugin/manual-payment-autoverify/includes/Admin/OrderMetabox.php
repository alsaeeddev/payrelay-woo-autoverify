<?php
/**
 * Order Metabox — displays manual payment claim info and action buttons on the order edit screen.
 *
 * Supports both traditional CPT (shop_order) and WooCommerce HPOS (wc-orders).
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Uploads\ProtectedFileServer;
use ManualPaymentAutoVerify\Util\Money;
use ManualPaymentAutoVerify\Util\PhoneNumberMasker;

/**
 * OrderMetabox registers and renders the payment claim details box on WooCommerce order screens.
 */
class OrderMetabox {

	/** @var ClaimRepository */
	private ClaimRepository $claim_repo;

	public function __construct( ?ClaimRepository $claim_repo = null ) {
		$this->claim_repo = $claim_repo ?? new ClaimRepository();
	}

	/**
	 * Registers the metabox on both CPT and HPOS screens.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ), 10, 2 );
	}

	/**
	 * Callback for add_meta_boxes hook.
	 *
	 * @param string   $post_type Post type or screen ID.
	 * @param mixed    $post_or_order Post or WC_Order object.
	 */
	public function add_order_metabox( string $post_type, $post_or_order ): void {
		$screens = array( 'shop_order' );

		// HPOS screen support.
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			if ( function_exists( 'wc_get_page_screen_id' ) ) {
				$screens[] = wc_get_page_screen_id( 'shop-order' );
			}
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'mpav_order_claims_metabox',
				__( 'Manual Payment Verification', 'manual-payment-autoverify' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders metabox content.
	 *
	 * @param \WP_Post|\WC_Order|object $post_or_order
	 */
	public function render( $post_or_order ): void {
		$order_id = 0;

		if ( $post_or_order instanceof \WC_Order ) {
			$order_id = $post_or_order->get_id();
		} elseif ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$order_id = (int) $post_or_order->ID;
		}

		if ( ! $order_id ) {
			echo '<p class="description">' . esc_html__( 'Unable to determine order ID.', 'manual-payment-autoverify' ) . '</p>';
			return;
		}

		$claims = $this->claim_repo->find_by_order_id( $order_id );

		if ( empty( $claims ) ) {
			echo '<p class="description">' . esc_html__( 'No manual payment claim submitted for this order.', 'manual-payment-autoverify' ) . '</p>';
			return;
		}

		$labels = array(
			'pending'       => __( 'Pending', 'manual-payment-autoverify' ),
			'auto_verified' => __( 'Auto-Verified', 'manual-payment-autoverify' ),
			'flagged'       => __( 'Flagged (Review)', 'manual-payment-autoverify' ),
			'rejected'      => __( 'Rejected', 'manual-payment-autoverify' ),
			'expired'       => __( 'Expired', 'manual-payment-autoverify' ),
		);

		echo '<div class="mpav-metabox-container">';

		foreach ( $claims as $claim ) {
			$status       = esc_attr( (string) $claim->status );
			$status_label = $labels[ $claim->status ] ?? ucfirst( (string) $claim->status );
			$provider     = ucfirst( (string) $claim->provider );
			$amount       = Money::format_display( (int) $claim->amount_minor );
			$tid          = esc_html( (string) $claim->tid_normalized );
			$sender       = ! empty( $claim->sender_number ) ? PhoneNumberMasker::mask( (string) $claim->sender_number ) : '&mdash;';
			$can_action   = in_array( $claim->status, array( 'pending', 'flagged' ), true );

			?>
			<div class="mpav-claim-card" data-claim-id="<?php echo (int) $claim->id; ?>">
				<div class="mpav-claim-header">
					<strong><?php printf( esc_html__( 'Claim #%d', 'manual-payment-autoverify' ), (int) $claim->id ); ?></strong>
					<span class="mpav-badge mpav-badge--<?php echo $status; ?>"><?php echo esc_html( $status_label ); ?></span>
				</div>

				<ul class="mpav-claim-meta">
					<li><strong><?php esc_html_e( 'Provider:', 'manual-payment-autoverify' ); ?></strong> <?php echo esc_html( $provider ); ?></li>
					<li><strong><?php esc_html_e( 'TID:', 'manual-payment-autoverify' ); ?></strong> <code><?php echo $tid; ?></code></li>
					<li><strong><?php esc_html_e( 'Amount:', 'manual-payment-autoverify' ); ?></strong> <?php echo esc_html( $amount ); ?></li>
					<li><strong><?php esc_html_e( 'Sender:', 'manual-payment-autoverify' ); ?></strong> <?php echo esc_html( $sender ); ?></li>
					<?php if ( ! empty( $claim->screenshot_path ) ) : ?>
						<li>
							<strong><?php esc_html_e( 'Screenshot:', 'manual-payment-autoverify' ); ?></strong>
							<a href="<?php echo esc_url( ProtectedFileServer::get_screenshot_url( (int) $claim->id ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small mpav-view-screenshot-link">
								<span class="dashicons dashicons-format-image" style="vertical-align: text-top; font-size: 15px;"></span>
								<?php esc_html_e( 'View Screenshot', 'manual-payment-autoverify' ); ?>
							</a>
						</li>
					<?php endif; ?>
					<?php if ( ! empty( $claim->matched_sms_id ) ) : ?>
						<li><strong><?php esc_html_e( 'Matched SMS:', 'manual-payment-autoverify' ); ?></strong> #<?php echo (int) $claim->matched_sms_id; ?></li>
					<?php endif; ?>
					<li><small class="description"><?php printf( esc_html__( 'Submitted: %s', 'manual-payment-autoverify' ), esc_html( (string) $claim->created_at ) ); ?></small></li>
				</ul>

				<?php if ( $can_action ) : ?>
					<div class="mpav-claim-actions" style="margin-top: 10px;">
						<button type="button" class="button button-primary button-small mpav-btn-approve" data-claim-id="<?php echo (int) $claim->id; ?>">
							<?php esc_html_e( 'Approve Payment', 'manual-payment-autoverify' ); ?>
						</button>
						<button type="button" class="button button-small mpav-btn-reject" data-claim-id="<?php echo (int) $claim->id; ?>">
							<?php esc_html_e( 'Reject', 'manual-payment-autoverify' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>
			<hr style="margin: 12px 0; border: 0; border-top: 1px solid #eee;" />
			<?php
		}

		echo '</div>';
	}
}

