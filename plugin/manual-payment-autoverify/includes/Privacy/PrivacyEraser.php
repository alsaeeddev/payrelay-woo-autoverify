<?php
/**
 * Privacy Eraser — integrates with WordPress Personal Data Erasure API (GDPR / "Right to Be Forgotten").
 *
 * Anonymizes customer-associated payment claims and permanently unlinks/deletes
 * payment screenshots from disk for requested email addresses. Active/pending claims
 * are safely retained with an audit notice until order resolution.
 *
 * @package ManualPaymentAutoVerify\Privacy
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Privacy;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Uploads\ScreenshotUploader;

/**
 * Handles WordPress personal data erasure requests for MPAV.
 */
class PrivacyEraser {

	/**
	 * Number of orders to process per erasure page.
	 */
	public const BATCH_SIZE = 50;

	private ClaimRepository $claim_repo;
	private ScreenshotUploader $uploader;
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param ClaimRepository|null    $claim_repo Optional custom claim repository.
	 * @param ScreenshotUploader|null $uploader   Optional custom screenshot uploader.
	 * @param \wpdb|null              $wpdb       Optional custom wpdb instance.
	 */
	public function __construct(
		?ClaimRepository $claim_repo = null,
		?ScreenshotUploader $uploader = null,
		?\wpdb $wpdb = null
	) {
		global $wpdb;

		$this->claim_repo = $claim_repo ?? new ClaimRepository();
		$this->uploader   = $uploader ?? new ScreenshotUploader();
		$this->wpdb       = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Registers the privacy eraser filter.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Adds the MPAV customer claim data eraser to WordPress.
	 *
	 * @param array<string, array<string, mixed>> $erasers Array of registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['mpav-claims'] = array(
			'eraser_friendly_name' => __( 'Manual Payment Claims', 'manual-payment-autoverify' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Erases or anonymizes personal data associated with the given email address.
	 *
	 * @param string $email_address Customer email address to erase.
	 * @param int    $page          Pagination page number (1-based).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} Standard WP Eraser response.
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$items_removed  = false;
		$items_retained = false;
		$messages       = array();
		$page           = max( 1, $page );

		$orders = $this->get_orders_for_email( $email_address, $page, self::BATCH_SIZE );
		if ( empty( $orders ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		foreach ( $orders as $order ) {
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' )
				? (int) $order->get_id()
				: (int) ( $order->ID ?? $order->id ?? 0 );

			if ( $order_id <= 0 ) {
				continue;
			}

			$claims = $this->claim_repo->find_by_order_id( $order_id );
			if ( empty( $claims ) ) {
				continue;
			}

			foreach ( $claims as $claim ) {
				$status   = (string) ( $claim->status ?? 'pending' );
				$claim_id = (int) $claim->id;

				// In-progress claims cannot be erased until finalized.
				if ( in_array( $status, array( 'pending', 'flagged' ), true ) ) {
					$items_retained = true;
					$messages[]     = sprintf(
						/* translators: 1: Claim ID, 2: Order ID, 3: Claim status */
						__( 'Manual payment claim #%1$d for order #%2$d was retained because its verification is currently active (%3$s).', 'manual-payment-autoverify' ),
						$claim_id,
						$order_id,
						$status
					);
					continue;
				}

				// Terminal claims: securely delete screenshot and anonymize database row.
				if ( ! empty( $claim->screenshot_path ) ) {
					$this->uploader->delete_screenshot( (string) $claim->screenshot_path );
				}

				$anonymized = $this->claim_repo->anonymize_claim( $claim_id );
				if ( $anonymized ) {
					$this->anonymize_audit_logs( $claim_id, $order_id );
					$items_removed = true;
					$messages[]    = sprintf(
						/* translators: 1: Claim ID, 2: Order ID */
						__( 'Personal data (sender account/number and payment screenshot) for manual payment claim #%1$d (Order #%2$d) was successfully anonymized.', 'manual-payment-autoverify' ),
						$claim_id,
						$order_id
					);
				}
			}
		}

		$done = count( $orders ) < self::BATCH_SIZE;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Anonymizes any context strings in the audit log for a given claim.
	 *
	 * @param int $claim_id Claim ID.
	 * @param int $order_id Order ID.
	 */
	protected function anonymize_audit_logs( int $claim_id, int $order_id ): void {
		$table = $this->wpdb->prefix . 'mpav_audit_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE `{$table}`
				 SET context = '{\"anonymized\":true}'
				 WHERE claim_id = %d OR (order_id = %d AND claim_id = %d)",
				$claim_id,
				$order_id,
				$claim_id
			)
		);
	}

	/**
	 * Retrieves WooCommerce orders associated with the customer email.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Current page.
	 * @param int    $limit         Page size.
	 * @return array<int, mixed> List of order objects.
	 */
	protected function get_orders_for_email( string $email_address, int $page, int $limit ): array {
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $email_address,
					'limit'         => $limit,
					'page'          => $page,
					'orderby'       => 'date',
					'order'         => 'DESC',
				)
			);
			if ( is_array( $orders ) ) {
				return $orders;
			}
		}

		return array();
	}
}

