<?php
/**
 * Main plugin bootstrap — wires all subsystems together.
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify;

use ManualPaymentAutoVerify\Admin\AdminMenu;
use ManualPaymentAutoVerify\Admin\AjaxHandlers;
use ManualPaymentAutoVerify\Admin\OrderMetabox;
use ManualPaymentAutoVerify\Admin\SettingsPage;
use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Matching\ClaimMatcherService;
use ManualPaymentAutoVerify\Parser\SmsParserService;
use ManualPaymentAutoVerify\Privacy\PrivacyEraser;
use ManualPaymentAutoVerify\Privacy\PrivacyExporter;
use ManualPaymentAutoVerify\Rest\RestRegistrar;
use ManualPaymentAutoVerify\Scheduler\SchedulerRegistrar;
use ManualPaymentAutoVerify\Uploads\ProtectedFileServer;

/**
 * Singleton bootstrap class.
 *
 * Responsibilities
 * ----------------
 * - Register the MPAV payment gateway with WooCommerce.
 * - Declare HPOS (High-Performance Order Storage) compatibility.
 * - Bridge WooCommerce Blocks checkout context into $_POST so the classic
 *   gateway process_payment() can read the submitted claim fields.
 * - Register REST API routes (Stage 4).
 * - Load frontend assets (CSS) only on the checkout page.
 *
 * Everything that touches the DB (Installer, repositories) is intentionally
 * NOT instantiated here — Plugin.php only registers hooks.
 */
class Plugin {

	private static ?self $instance = null;

	/** @var string Absolute path to the main plugin file. */
	private string $plugin_file;

	/** @var string Plugin version string. */
	private string $version;

	private function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $version     Plugin version string.
	 * @return self
	 */
	public static function get_instance( string $plugin_file = '', string $version = '' ): self {
		if ( null === self::$instance ) {
			// The bootstrap calls instance() without arguments: fall back to the plugin constants.
			if ( '' === $plugin_file && defined( 'MPAV_FILE' ) ) {
				$plugin_file = (string) MPAV_FILE;
			}
			if ( '' === $version && defined( 'MPAV_VERSION' ) ) {
				$version = (string) MPAV_VERSION;
			}
			self::$instance = new self( $plugin_file, $version );
		}
		return self::$instance;
	}

	/**
	 * Returns the singleton instance (alias for get_instance).
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $version     Plugin version string.
	 * @return self
	 */
	public static function instance( string $plugin_file = '', string $version = '' ): self {
		return self::get_instance( $plugin_file, $version );
	}

	/**
	 * Attach all WordPress hooks.
	 */
	public function run(): void {
		$this->declare_hpos_compatibility();
		$this->register_gateway();
		$this->register_blocks_integration();
		$this->register_blocks_payment_data_hook();
		$this->register_rest_routes();
		$this->register_parser();
		$this->register_matching();
		$this->register_reverse_matching();
		$this->register_claim_transitions();
		$this->register_admin();
		$this->register_assets();
		$this->register_scheduler();
		$this->register_privacy();
	}

	// ------------------------------------------------------------------ //
	// HPOS compatibility declaration
	// ------------------------------------------------------------------ //

	/**
	 * Declare compatibility with WooCommerce HPOS (COT).
	 *
	 * Must fire on before_woocommerce_init, before WC checks for declarations.
	 *
	 * @see https://developer.woocommerce.com/docs/high-performance-order-storage/
	 */
	private function declare_hpos_compatibility(): void {
		add_action(
			'before_woocommerce_init',
			function (): void {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						$this->plugin_file,
						true
					);
				}
			}
		);
	}

	// ------------------------------------------------------------------ //
	// Gateway registration
	// ------------------------------------------------------------------ //

	/**
	 * Register the MPAV gateway with WooCommerce's payment gateway list.
	 */
	private function register_gateway(): void {
		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = \ManualPaymentAutoVerify\Gateway\MpavGateway::class;
				return $gateways;
			}
		);
	}

	// ------------------------------------------------------------------ //
	// Blocks checkout payment method registration
	// ------------------------------------------------------------------ //

	/**
	 * Register the MPAV payment method type with WooCommerce Blocks.
	 *
	 * Without this, the Blocks checkout never learns about the gateway, so it shows
	 * "There are no payment methods available" even though classic checkout works.
	 */
	private function register_blocks_integration(): void {
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $payment_method_registry ): void {
				if ( ! is_object( $payment_method_registry ) || ! method_exists( $payment_method_registry, 'register' ) ) {
					return;
				}
				if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
					return;
				}
				$payment_method_registry->register( new \ManualPaymentAutoVerify\Blocks\MpavBlocksIntegration() );
			}
		);
	}

	// ------------------------------------------------------------------ //
	// Blocks checkout bridge
	// ------------------------------------------------------------------ //

	/**
	 * When a Blocks checkout order is processed, inject the MPAV claim fields
	 * from the payment context's payment_data array into $_POST so that the
	 * classic MpavGateway::process_payment() can read them transparently.
	 *
	 * This approach is used because WooCommerce Blocks currently passes
	 * payment_data to the gateway via a context object; classic gateways
	 * read from $_POST. The injection is intentional and scoped to this hook.
	 *
	 * Note: PaymentContext class path was verified against WC 8.x source.
	 * If a future WC release moves it, duck-typing on $context is sufficient.
	 *
	 * @param mixed $context Checkout payment context (WC Blocks internal type).
	 * @param mixed $result  Checkout result object (unused here).
	 */
	private function register_blocks_payment_data_hook(): void {
		add_action(
			'woocommerce_rest_checkout_process_payment_with_context',
			static function ( $context, $result ): void {
				// Guard: only act for our gateway.
				if ( ! isset( $context->payment_method ) || 'mpav' !== $context->payment_method ) {
					return;
				}

				// Flag the request as Blocks checkout: Blocks cannot upload files, so the
				// gateway must not demand a screenshot for these orders.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$_POST['mpav_checkout_context'] = 'blocks';

				$payment_data = $context->payment_data ?? array();

				// Whitelist of fields we bridge from Blocks to $_POST.
				$bridged_fields = array(
					'mpav_provider',
					'mpav_tid',
					'mpav_sender_number',
				);

				foreach ( $bridged_fields as $field ) {
					if ( isset( $payment_data[ $field ] ) && is_string( $payment_data[ $field ] ) ) {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$_POST[ $field ] = $payment_data[ $field ];
					}
				}
			},
			10,
			2
		);
	}

	// ------------------------------------------------------------------ //
	// REST API
	// ------------------------------------------------------------------ //

	/**
	 * Hook RestRegistrar into rest_api_init.
	 *
	 * RestRegistrar is instantiated inside the closure so it is only created
	 * when the REST API initialises (i.e., on REST requests), not on every
	 * page load.
	 */
	private function register_rest_routes(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new RestRegistrar() )->register();
			}
		);
	}

	// ------------------------------------------------------------------ //
	// SMS Parser & Matching Engine
	// ------------------------------------------------------------------ //

	/**
	 * Hook the SMS parser service into the mpav_parse_sms filter.
	 */
	private function register_parser(): void {
		add_filter(
			'mpav_parse_sms',
			static function ( $current_result, string $sender, string $body, int $received_at_ms ) {
				static $parser_service = null;
				if ( null === $parser_service ) {
					$parser_service = new SmsParserService();
				}
				return $parser_service->filter_parse_sms( $current_result, $sender, $body, $received_at_ms );
			},
			10,
			4
		);
	}

	/**
	 * Hook the claim matcher service into the mpav_sms_received action.
	 */
	private function register_matching(): void {
		add_action(
			'mpav_sms_received',
			static function ( int $sms_id, $parse_result, $device = null ): void {
				static $matcher_service = null;
				if ( null === $matcher_service ) {
					$matcher_service = new ClaimMatcherService();
				}
				$matcher_service->on_sms_received( $sms_id, $parse_result, $device );
			},
			10,
			3
		);
	}

	/**
	 * Reverse matching: when a claim is created, check SMS already received.
	 */
	private function register_reverse_matching(): void {
		add_action(
			'mpav_claim_created',
			static function ( $claim_id, $order = null, $provider = '', $tid = '', $amount_minor = 0 ): void {
				( new ClaimMatcherService() )->on_claim_created(
					(int) $claim_id,
					$order,
					(string) $provider,
					(string) $tid,
					(int) $amount_minor
				);
			},
			20,
			5
		);
	}

	/**
	 * Hook into claim transitions to trigger WooCommerce order side-effects.
	 *
	 * - auto_verified: marks order payment complete, adds order note.
	 * - flagged:       adds warning order note for merchant review.
	 * - rejected:      fails order with reason.
	 * - expired:       cancels order due to expiration.
	 */
	private function register_claim_transitions(): void {
		add_action(
			'mpav_claim_transitioned',
			array( $this, 'on_claim_transitioned' ),
			10,
			5
		);
	}

	/**
	 * Handles WooCommerce order side effects for claim state transitions.
	 *
	 * @param int         $claim_id   Claim ID.
	 * @param string      $from_state Previous claim state.
	 * @param string      $to_state   New claim state.
	 * @param array       $context    Metadata passed to state machine transition.
	 * @param object|null $claim      Claim row object (if already loaded).
	 */
	public function on_claim_transitioned( int $claim_id, string $from_state, string $to_state, array $context, ?object $claim = null ): void {
		if ( null === $claim || ! isset( $claim->order_id ) ) {
			$repo  = new ClaimRepository();
			$claim = $repo->find_by_id( $claim_id );
		}

		if ( null === $claim || empty( $claim->order_id ) ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) $claim->order_id );
		if ( ! $order ) {
			return;
		}

		$tid      = (string) ( $claim->tid_normalized ?? '' );
		$provider = (string) ( $claim->provider ?? '' );

		switch ( $to_state ) {
			case 'auto_verified':
				// Complete payment and record transaction ID.
				$order->payment_complete( $tid );
				$order->add_order_note(
					sprintf(
						/* translators: 1: Provider name, 2: Transaction ID */
						__( 'Manual Payment verified via %1$s (TID: %2$s).', 'manual-payment-autoverify' ),
						esc_html( ucfirst( $provider ) ),
						esc_html( $tid )
					)
				);
				break;

			case 'flagged':
				$reason = (string) ( $context['reason'] ?? $context['note'] ?? __( 'Claim flagged for manual review.', 'manual-payment-autoverify' ) );
				$order->add_order_note(
					sprintf(
						/* translators: 1: Reason string, 2: Transaction ID */
						__( 'Manual Payment Flagged: %1$s (TID: %2$s).', 'manual-payment-autoverify' ),
						esc_html( $reason ),
						esc_html( $tid )
					)
				);
				break;

			case 'rejected':
				$reason = (string) ( $context['note'] ?? __( 'Manual payment verification rejected by administrator.', 'manual-payment-autoverify' ) );
				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: Rejection reason, 2: Transaction ID */
						__( 'Manual Payment Rejected: %1$s (TID: %2$s).', 'manual-payment-autoverify' ),
						esc_html( $reason ),
						esc_html( $tid )
					)
				);
				break;

			case 'expired':
				$order->update_status(
					'cancelled',
					__( 'Manual Payment Expired: no matching payment received within time window.', 'manual-payment-autoverify' )
				);
				break;
		}
	}

	// ------------------------------------------------------------------ //
	// Admin subsystem
	// ------------------------------------------------------------------ //

	/**
	 * Register Admin UI, settings, order metabox, AJAX handlers, and protected file server.
	 */
	private function register_admin(): void {
		( new AdminMenu() )->register();
		( new SettingsPage() )->register();
		( new OrderMetabox() )->register();
		( new AjaxHandlers() )->register();
		( new ProtectedFileServer() )->register();
	}

	// ------------------------------------------------------------------ //
	// Frontend assets
	// ------------------------------------------------------------------ //

	/**
	 * Enqueue checkout CSS on the checkout page only.
	 *
	 * The Blocks integration registers its own script handle via
	 * MpavBlocksIntegration::get_script_handles().
	 */
	private function register_assets(): void {
		add_action(
			'wp_enqueue_scripts',
			function (): void {
				if ( ! is_checkout() ) {
					return;
				}

				$plugin_url = defined( 'MPAV_URL' ) ? (string) MPAV_URL : plugin_dir_url( $this->plugin_file );
				$css_path   = ( defined( 'MPAV_DIR' ) ? (string) MPAV_DIR : plugin_dir_path( $this->plugin_file ) ) . 'assets/css/checkout.css';

				// File mtime as version: browsers/caches always fetch the latest stylesheet.
				wp_enqueue_style(
					'mpav-checkout',
					$plugin_url . 'assets/css/checkout.css',
					array(),
					file_exists( $css_path ) ? (string) filemtime( $css_path ) : $this->version
				);
			}
		);
	}

	// ------------------------------------------------------------------ //
	// Background Jobs & Scheduler
	// ------------------------------------------------------------------ //

	/**
	 * Register recurring Action Scheduler background jobs.
	 */
	private function register_scheduler(): void {
		( new SchedulerRegistrar() )->register();
	}

	// ------------------------------------------------------------------ //
	// Privacy & GDPR Compliance
	// ------------------------------------------------------------------ //

	/**
	 * Register WordPress Personal Data Exporter and Eraser.
	 */
	private function register_privacy(): void {
		( new PrivacyExporter() )->register();
		( new PrivacyEraser() )->register();
	}
}