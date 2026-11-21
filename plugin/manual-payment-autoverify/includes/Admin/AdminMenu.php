<?php
/**
 * Admin Menu — registers top-level and submenu pages for MPAV.
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

use ManualPaymentAutoVerify\Core\ClaimRepository;

/**
 * AdminMenu handles top-level menu registration, badge counts, and admin asset loading.
 */
class AdminMenu {

	/** @var ClaimRepository */
	private ClaimRepository $claim_repo;

	/** @var SettingsPage */
	private SettingsPage $settings_page;

	/** @var DeviceManagerPage */
	private DeviceManagerPage $devices_page;

	/** @var LogsViewerPage */
	private LogsViewerPage $logs_page;

	public function __construct(
		?ClaimRepository $claim_repo = null,
		?SettingsPage $settings_page = null,
		?DeviceManagerPage $devices_page = null,
		?LogsViewerPage $logs_page = null
	) {
		$this->claim_repo    = $claim_repo ?? new ClaimRepository();
		$this->settings_page = $settings_page ?? new SettingsPage();
		$this->devices_page  = $devices_page ?? new DeviceManagerPage();
		$this->logs_page     = $logs_page ?? new LogsViewerPage();
	}

	/**
	 * Registers WordPress admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Registers top-level menu and submenus under "Manual Payments".
	 */
	public function register_menu_pages(): void {
		$pending_count = $this->get_pending_and_flagged_count();
		$badge_html    = '';

		if ( $pending_count > 0 ) {
			$badge_html = sprintf(
				' <span class="update-plugins count-%1$d" style="background-color:#d63638;border-radius:10px;color:#fff;display:inline-block;font-size:9px;font-weight:600;line-height:17px;margin-left:5px;min-width:17px;padding:0 5px;text-align:center;"><span class="plugin-count">%1$d</span></span>',
				$pending_count
			);
		}

		$main_title = __( 'Manual Payments', 'manual-payment-autoverify' ) . $badge_html;

		// Top-level menu: Verification Queue.
		add_menu_page(
			__( 'Manual Payment Verification Queue', 'manual-payment-autoverify' ),
			$main_title,
			'manage_woocommerce',
			'mpav-queue',
			array( $this, 'render_queue_page' ),
			'dashicons-money-alt',
			56
		);

		// Submenu 1: Verification Queue (matches top-level).
		add_submenu_page(
			'mpav-queue',
			__( 'Verification Queue', 'manual-payment-autoverify' ),
			__( 'Verification Queue', 'manual-payment-autoverify' ),
			'manage_woocommerce',
			'mpav-queue',
			array( $this, 'render_queue_page' )
		);

		// Submenu 2: Devices.
		add_submenu_page(
			'mpav-queue',
			__( 'Android Sync Devices', 'manual-payment-autoverify' ),
			__( 'Devices', 'manual-payment-autoverify' ),
			'manage_woocommerce',
			'mpav-devices',
			array( $this, 'render_devices_page' )
		);

		// Submenu 3: Settings.
		add_submenu_page(
			'mpav-queue',
			__( 'Manual Payment Settings', 'manual-payment-autoverify' ),
			__( 'Settings', 'manual-payment-autoverify' ),
			'manage_woocommerce',
			'mpav-settings',
			array( $this, 'render_settings_page' )
		);

		// Submenu 4: Audit Logs.
		add_submenu_page(
			'mpav-queue',
			__( 'Manual Payment Audit Log', 'manual-payment-autoverify' ),
			__( 'Audit Log', 'manual-payment-autoverify' ),
			'manage_woocommerce',
			'mpav-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Returns count of claims awaiting attention (pending + flagged).
	 *
	 * @return int
	 */
	public function get_pending_and_flagged_count(): int {
		return $this->claim_repo->count_queue(
			array(
				'status' => array( 'pending', 'flagged' ),
			)
		);
	}

	/**
	 * Enqueue styles and scripts only on MPAV admin screens and order edit screens.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$is_mpav_page = false !== strpos( $hook_suffix, 'mpav-' );
		$is_order_cpt = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
			&& 'shop_order' === ( get_post_type() ?? '' );
		$is_order_hpos = false !== strpos( $hook_suffix, 'wc-orders' );

		if ( ! $is_mpav_page && ! $is_order_cpt && ! $is_order_hpos ) {
			return;
		}

		$plugin_url = defined( 'MPAV_URL' ) ? MPAV_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/manual-payment-autoverify.php' );
		$version    = defined( 'MPAV_VERSION' ) ? MPAV_VERSION : '1.0.0';
		$base_dir   = defined( 'MPAV_DIR' ) ? (string) MPAV_DIR : dirname( __DIR__, 2 ) . '/';
		$css_file   = $base_dir . 'assets/css/admin.css';
		$js_file    = $base_dir . 'assets/js/admin.js';

		wp_enqueue_style(
			'mpav-admin-css',
			$plugin_url . 'assets/css/admin.css',
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : $version
		);

			wp_enqueue_script(
			'mpav-qrcode',
			$plugin_url . 'assets/js/qrcode.js',
			array(),
			'1.4.4',
			true
		);

		wp_enqueue_script(
			'mpav-admin-js',
			$plugin_url . 'assets/js/admin.js',
			array( 'jquery' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : $version,
			true
		);

		wp_localize_script(
			'mpav-admin-js',
			'mpav_admin',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'mpav_admin_nonce' ),
				'is_ssl'     => is_ssl(),
				'i18n'       => array(
					'confirm_approve'     => __( 'Are you sure you want to approve this payment claim?', 'manual-payment-autoverify' ),
					'confirm_reject'      => __( 'Are you sure you want to reject this payment claim?', 'manual-payment-autoverify' ),
					'prompt_reason'       => __( 'Please enter a reason for rejecting this claim:', 'manual-payment-autoverify' ),
					'confirm_revoke'      => __( 'Are you sure you want to revoke this device? It will immediately lose access to forward SMS.', 'manual-payment-autoverify' ),
					'confirm_clear_all'   => __( 'Disconnect and remove ALL paired devices? Every phone will immediately stop forwarding SMS and must be paired again. This cannot be undone.', 'manual-payment-autoverify' ),
					'copied'              => __( 'Token copied to clipboard!', 'manual-payment-autoverify' ),
					'copy_failed'         => __( 'Failed to copy token. Please select and copy manually.', 'manual-payment-autoverify' ),
					'processing'          => __( 'Processing...', 'manual-payment-autoverify' ),
					'error_generic'       => __( 'An error occurred while processing the request. Please try again.', 'manual-payment-autoverify' ),
					'bulk_confirm'        => __( 'Are you sure you want to perform this bulk action?', 'manual-payment-autoverify' ),
					'select_claims_first' => __( 'Please select at least one claim from the list.', 'manual-payment-autoverify' ),
				),
			)
		);
	}

	/**
	 * Renders the Verification Queue page with WP_List_Table.
	 */
	public function render_queue_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'manual-payment-autoverify' ) );
		}

		$table = new VerificationQueueTable( $this->claim_repo );
		$table->prepare_items();

		echo '<div class="wrap mpav-admin-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Manual Payment Verification Queue', 'manual-payment-autoverify' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		// Display any query notices or alerts.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mpav_notice'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = sanitize_text_field( wp_unslash( $_GET['mpav_notice'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<form id="mpav-queue-form" method="get">';
		echo '<input type="hidden" name="page" value="mpav-queue" />';
		// Keep the active status tab when a search is submitted.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		if ( '' !== $current_status && 'all' !== $current_status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $current_status ) . '" />';
		}

		$table->views();
		$table->render_search_summary();
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the Device Manager page.
	 */
	public function render_devices_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'manual-payment-autoverify' ) );
		}

		$this->devices_page->render();
	}

	/**
	 * Renders the Settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'manual-payment-autoverify' ) );
		}

		$this->settings_page->render();
	}

	/**
	 * Renders the Logs Viewer page.
	 */
	public function render_logs_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'manual-payment-autoverify' ) );
		}

		$this->logs_page->render();
	}
}