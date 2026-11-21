<?php
/**
 * Privacy Exporter — integrates with WordPress Personal Data Export API (GDPR).
 *
 * Exports customer-associated payment claims, transaction references, masked sender
 * phone numbers, and verification statuses linked to a requested email address.
 *
 * @package ManualPaymentAutoVerify\Privacy
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Privacy;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Util\Money;
use ManualPaymentAutoVerify\Util\PhoneNumberMasker;

/**
 * Handles WordPress personal data exports for MPAV.
 */
class PrivacyExporter {

	/**
	 * Number of orders to process per export page.
	 */
	public const BATCH_SIZE = 50;

	private ClaimRepository $claim_repo;

	/**
	 * Constructor.
	 *
	 * @param ClaimRepository|null $claim_repo Optional custom claim repository.
	 */
	public function __construct( ?ClaimRepository $claim_repo = null ) {
		$this->claim_repo = $claim_repo ?? new ClaimRepository();
	}

	/**
	 * Registers the privacy exporter callback and privacy policy content.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Adds the MPAV customer claim data exporter to WordPress.
	 *
	 * @param array<string, array<string, mixed>> $exporters Array of registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['mpav-claims'] = array(
			'exporter_friendly_name' => __( 'Manual Payment Claims', 'manual-payment-autoverify' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Exports personal data associated with the given email address.
	 *
	 * @param string $email_address Customer email address to query.
	 * @param int    $page          Pagination page number (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Standard WP Privacy exporter array.
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$data = array();
		$page = max( 1, $page );

		$orders = $this->get_orders_for_email( $email_address, $page, self::BATCH_SIZE );
		if ( empty( $orders ) ) {
			return array(
				'data' => array(),
				'done' => true,
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
				$amount_minor = (int) ( $claim->amount_minor ?? 0 );
				$amount_text  = Money::format_display( $amount_minor );
				$sender_phone = (string) ( $claim->sender_number ?? '' );
				$masked_phone = '' !== $sender_phone ? PhoneNumberMasker::mask( $sender_phone ) : __( 'None provided', 'manual-payment-autoverify' );

				$data[] = array(
					'group_id'    => 'mpav_claims',
					'group_label' => __( 'Manual Payment Claims', 'manual-payment-autoverify' ),
					'item_id'     => 'mpav-claim-' . (int) $claim->id,
					'data'        => array(
						array(
							'name'  => __( 'Claim ID', 'manual-payment-autoverify' ),
							'value' => (int) $claim->id,
						),
						array(
							'name'  => __( 'Order ID', 'manual-payment-autoverify' ),
							'value' => (int) $claim->order_id,
						),
						array(
							'name'  => __( 'Payment Provider', 'manual-payment-autoverify' ),
							'value' => ucfirst( (string) ( $claim->provider ?? '' ) ),
						),
						array(
							'name'  => __( 'Transaction ID (TID)', 'manual-payment-autoverify' ),
							'value' => (string) ( $claim->tid_normalized ?? '' ),
						),
						array(
							'name'  => __( 'Claimed Amount', 'manual-payment-autoverify' ),
							'value' => $amount_text,
						),
						array(
							'name'  => __( 'Sender Account / Phone Number', 'manual-payment-autoverify' ),
							'value' => $masked_phone,
						),
						array(
							'name'  => __( 'Verification Status', 'manual-payment-autoverify' ),
							'value' => (string) ( $claim->status ?? 'pending' ),
						),
						array(
							'name'  => __( 'Submission Timestamp', 'manual-payment-autoverify' ),
							'value' => (string) ( $claim->created_at ?? '' ),
						),
					),
				);
			}
		}

		$done = count( $orders ) < self::BATCH_SIZE;

		return array(
			'data' => $data,
			'done' => $done,
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

	/**
	 * Registers suggested privacy policy text in WordPress Core Privacy Guide.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2>' .
			'<p>%s</p>' .
			'<p>%s</p>' .
			'<p>%s</p>',
			esc_html__( 'Manual Payment Auto-Verify for WooCommerce', 'manual-payment-autoverify' ),
			esc_html__(
				'When you make a payment using manual payment gateways (JazzCash, Easypaisa, or Direct Bank Transfer), we collect your payment transaction ID (TID), your sender mobile/account number, and optionally an uploaded proof-of-payment screenshot to verify receipt of funds.',
				'manual-payment-autoverify'
			),
			esc_html__(
				'Uploaded payment screenshots are stored in a protected, access-controlled directory shielded from public indexing and direct URL retrieval; they can only be viewed by authenticated store administrators. Inbound SMS notifications forwarded by the store merchant’s companion Android device are stored only as one-way SHA-256 cryptographic hashes—raw SMS text is never persisted.',
				'manual-payment-autoverify'
			),
			esc_html__(
				'Transaction records and logs are automatically purged according to the site’s configured retention policy. Customers may request an export or anonymization of their manual payment records at any time using WordPress Privacy tools.',
				'manual-payment-autoverify'
			)
		);

		wp_add_privacy_policy_content(
			__( 'Manual Payment Auto-Verify', 'manual-payment-autoverify' ),
			wp_kses_post( $content )
		);
	}
}

