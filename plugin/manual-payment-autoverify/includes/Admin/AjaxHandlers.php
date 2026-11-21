<?php
/**
 * Admin AJAX Handlers — handles approval, rejection, bulk actions, pairing token generation, and device revocation.
 *
 * All endpoints require:
 * 1. manage_woocommerce capability check.
 * 2. Nonce verification via check_ajax_referer('mpav_admin_nonce', 'nonce').
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Core\ClaimStateMachine;
use ManualPaymentAutoVerify\Core\DeviceRepository;

/**
 * AjaxHandlers.
 */
class AjaxHandlers {

	/** @var ClaimRepository */
	private ClaimRepository $claim_repo;

	/** @var ClaimStateMachine */
	private ClaimStateMachine $state_machine;

	/** @var DeviceRepository */
	private DeviceRepository $device_repo;

	/** @var \wpdb */
	private \wpdb $wpdb;

	public function __construct(
		?ClaimRepository $claim_repo = null,
		?ClaimStateMachine $state_machine = null,
		?DeviceRepository $device_repo = null,
		?\wpdb $wpdb = null
	) {
		global $wpdb;
		$this->wpdb          = $wpdb ?? $GLOBALS['wpdb'];
		$this->claim_repo    = $claim_repo ?? new ClaimRepository();
		$this->state_machine = $state_machine ?? new ClaimStateMachine( $this->claim_repo );
		$this->device_repo   = $device_repo ?? new DeviceRepository( $this->wpdb );
	}

	/**
	 * Registers all WordPress AJAX hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_mpav_approve_claim', array( $this, 'handle_approve' ) );
		add_action( 'wp_ajax_mpav_reject_claim', array( $this, 'handle_reject' ) );
		add_action( 'wp_ajax_mpav_bulk_claims', array( $this, 'handle_bulk_action' ) );
		add_action( 'wp_ajax_mpav_generate_pairing_token', array( $this, 'handle_generate_pairing_token' ) );
		add_action( 'wp_ajax_mpav_check_pairing_status', array( $this, 'handle_check_pairing_status' ) );
		add_action( 'wp_ajax_mpav_revoke_device', array( $this, 'handle_revoke_device' ) );
		add_action( 'wp_ajax_mpav_clear_all_devices', array( $this, 'handle_clear_all_devices' ) );
	}

	/**
	 * Handles manual approval of a single claim.
	 */
	public function handle_approve(): void {
		$this->verify_admin_access();

		$claim_id = isset( $_POST['claim_id'] ) ? absint( $_POST['claim_id'] ) : 0;
		if ( ! $claim_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid claim ID provided.', 'manual-payment-autoverify' ) ), 400 );
		}

		$claim = $this->claim_repo->find_by_id( $claim_id );
		if ( ! $claim ) {
			wp_send_json_error( array( 'message' => __( 'Claim not found.', 'manual-payment-autoverify' ) ), 404 );
		}

		if ( ! $this->state_machine->can_transition( (string) $claim->status, 'auto_verified' ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Current claim status */
						__( 'Cannot approve claim from current status: %s.', 'manual-payment-autoverify' ),
						esc_html( (string) $claim->status )
					),
				),
				400
			);
		}

		$user_id = get_current_user_id();
		$context = array(
			'actor'          => 'wp:' . $user_id,
			'reviewed_by'    => $user_id,
			'amount_minor'   => (int) $claim->amount_minor,
			'provider'       => (string) $claim->provider,
			'tid_normalized' => (string) $claim->tid_normalized,
			'note'           => __( 'Manually approved by store manager.', 'manual-payment-autoverify' ),
		);

		$success = $this->state_machine->transition( $claim_id, 'auto_verified', $context );

		if ( ! $success ) {
			wp_send_json_error( array( 'message' => __( 'Failed to transition claim status.', 'manual-payment-autoverify' ) ), 500 );
		}

		$this->claim_repo->update( $claim_id, array( 'reviewed_by' => $user_id ) );

		wp_send_json_success(
			array(
				'message'  => sprintf( __( 'Claim #%d successfully approved.', 'manual-payment-autoverify' ), $claim_id ),
				'claim_id' => $claim_id,
				'status'   => 'auto_verified',
			)
		);
	}

	/**
	 * Handles manual rejection of a single claim.
	 */
	public function handle_reject(): void {
		$this->verify_admin_access();

		$claim_id = isset( $_POST['claim_id'] ) ? absint( $_POST['claim_id'] ) : 0;
		if ( ! $claim_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid claim ID provided.', 'manual-payment-autoverify' ) ), 400 );
		}

		$claim = $this->claim_repo->find_by_id( $claim_id );
		if ( ! $claim ) {
			wp_send_json_error( array( 'message' => __( 'Claim not found.', 'manual-payment-autoverify' ) ), 404 );
		}

		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( '' === $reason ) {
			$reason = __( 'Rejected by store manager.', 'manual-payment-autoverify' );
		}

		$user_id = get_current_user_id();

		// If pending, transition pending -> flagged first (state machine guard: pending -> rejected forbidden).
		if ( 'pending' === $claim->status ) {
			$flag_context = array(
				'actor'          => 'wp:' . $user_id,
				'reviewed_by'    => $user_id,
				'amount_minor'   => (int) $claim->amount_minor,
				'provider'       => (string) $claim->provider,
				'tid_normalized' => (string) $claim->tid_normalized,
				'note'           => __( 'Flagged prior to rejection by store manager.', 'manual-payment-autoverify' ),
			);
			$flagged = $this->state_machine->transition( $claim_id, 'flagged', $flag_context );
			if ( ! $flagged ) {
				wp_send_json_error( array( 'message' => __( 'Failed to flag claim for rejection.', 'manual-payment-autoverify' ) ), 500 );
			}
			$claim->status = 'flagged';
		}

		if ( ! $this->state_machine->can_transition( (string) $claim->status, 'rejected' ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Current claim status */
						__( 'Cannot reject claim from current status: %s.', 'manual-payment-autoverify' ),
						esc_html( (string) $claim->status )
					),
				),
				400
			);
		}

		$reject_context = array(
			'actor'          => 'wp:' . $user_id,
			'reviewed_by'    => $user_id,
			'amount_minor'   => (int) $claim->amount_minor,
			'provider'       => (string) $claim->provider,
			'tid_normalized' => (string) $claim->tid_normalized,
			'note'           => $reason,
		);

		$success = $this->state_machine->transition( $claim_id, 'rejected', $reject_context );

		if ( ! $success ) {
			wp_send_json_error( array( 'message' => __( 'Failed to transition claim status to rejected.', 'manual-payment-autoverify' ) ), 500 );
		}

		$this->claim_repo->update( $claim_id, array( 'reviewed_by' => $user_id ) );

		wp_send_json_success(
			array(
				'message'  => sprintf( __( 'Claim #%d has been rejected.', 'manual-payment-autoverify' ), $claim_id ),
				'claim_id' => $claim_id,
				'status'   => 'rejected',
			)
		);
	}

	/**
	 * Handles bulk approval and rejection of multiple claims.
	 */
	public function handle_bulk_action(): void {
		$this->verify_admin_access();

		$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
		if ( ! in_array( $action_type, array( 'approve', 'reject' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid bulk action specified.', 'manual-payment-autoverify' ) ), 400 );
		}

		$raw_ids   = isset( $_POST['claim_ids'] ) ? (array) $_POST['claim_ids'] : array();
		$claim_ids = array_filter( array_map( 'absint', $raw_ids ) );

		if ( empty( $claim_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid claims selected.', 'manual-payment-autoverify' ) ), 400 );
		}

		$reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$user_id = get_current_user_id();

		$success_count = 0;
		$failed_count  = 0;

		foreach ( $claim_ids as $claim_id ) {
			$claim = $this->claim_repo->find_by_id( $claim_id );
			if ( ! $claim ) {
				$failed_count++;
				continue;
			}

			if ( 'approve' === $action_type ) {
				if ( ! $this->state_machine->can_transition( (string) $claim->status, 'auto_verified' ) ) {
					$failed_count++;
					continue;
				}
				$context = array(
					'actor'          => 'wp:' . $user_id,
					'reviewed_by'    => $user_id,
					'amount_minor'   => (int) $claim->amount_minor,
					'provider'       => (string) $claim->provider,
					'tid_normalized' => (string) $claim->tid_normalized,
					'note'           => __( 'Bulk approved by store manager.', 'manual-payment-autoverify' ),
				);
				$ok = $this->state_machine->transition( $claim_id, 'auto_verified', $context );
				if ( $ok ) {
					$this->claim_repo->update( $claim_id, array( 'reviewed_by' => $user_id ) );
					$success_count++;
				} else {
					$failed_count++;
				}
			} elseif ( 'reject' === $action_type ) {
				if ( 'pending' === $claim->status ) {
					$flag_context = array(
						'actor'          => 'wp:' . $user_id,
						'reviewed_by'    => $user_id,
						'amount_minor'   => (int) $claim->amount_minor,
						'provider'       => (string) $claim->provider,
						'tid_normalized' => (string) $claim->tid_normalized,
						'note'           => __( 'Flagged prior to bulk rejection by store manager.', 'manual-payment-autoverify' ),
					);
					$this->state_machine->transition( $claim_id, 'flagged', $flag_context );
					$claim->status = 'flagged';
				}

				if ( ! $this->state_machine->can_transition( (string) $claim->status, 'rejected' ) ) {
					$failed_count++;
					continue;
				}

				$reject_context = array(
					'actor'          => 'wp:' . $user_id,
					'reviewed_by'    => $user_id,
					'amount_minor'   => (int) $claim->amount_minor,
					'provider'       => (string) $claim->provider,
					'tid_normalized' => (string) $claim->tid_normalized,
					'note'           => '' !== $reason ? $reason : __( 'Bulk rejected by store manager.', 'manual-payment-autoverify' ),
				);

				$ok = $this->state_machine->transition( $claim_id, 'rejected', $reject_context );
				if ( $ok ) {
					$this->claim_repo->update( $claim_id, array( 'reviewed_by' => $user_id ) );
					$success_count++;
				} else {
					$failed_count++;
				}
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: Count of successfully processed claims, 2: Count of failed claims */
					__( 'Bulk action completed: %1$d processed, %2$d skipped or failed.', 'manual-payment-autoverify' ),
					$success_count,
					$failed_count
				),
				'success_count' => $success_count,
				'failed_count'  => $failed_count,
			)
		);
	}

	/**
	 * Generates a one-time pairing token for the Android SMS forwarder app.
	 */
	public function handle_generate_pairing_token(): void {
		$this->verify_admin_access();

		try {
			$raw_token = bin2hex( random_bytes( 32 ) ); // 64-char hex string (256-bit entropy).
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Failed to generate secure random token.', 'manual-payment-autoverify' ) ), 500 );
		}

		$token_hash = hash( 'sha256', $raw_token );
		$table      = $this->wpdb->prefix . 'mpav_pairing_tokens';
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + 600 ); // 10 minutes TTL.

		$inserted = $this->wpdb->insert(
			$table,
			array(
				'token_hash' => $token_hash,
				'expires_at' => $expires_at,
				'used'       => 0,
			),
			array( '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to store pairing token in database.', 'manual-payment-autoverify' ) ), 500 );
		}

		$server_url = get_rest_url( null, 'mpav/v1' );

		$qr_payload = wp_json_encode(
			array(
				'v'         => 1,
				'server'    => $server_url,
				'token'     => $raw_token,
				'site_name' => get_bloginfo( 'name' ),
			)
		);

		wp_send_json_success(
			array(
				'token'        => $raw_token,
				'expires_in'   => 600,
				'expires_at'   => $expires_at,
				'server_url'   => $server_url,
				'qr_payload'   => $qr_payload,
				'is_ssl'       => is_ssl(),
				'site_name'    => get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * Reports whether a pairing token has been consumed by an Android device.
	 *
	 * Polled by the pairing modal. PairController sets used = 1 only when the
	 * device row was created and the transaction committed, so used = 1 means
	 * "a phone paired with this token". Read-only; no state is changed.
	 */
	public function handle_check_pairing_status(): void {
		$this->verify_admin_access();

		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_admin_access().
		if ( 64 !== strlen( $raw_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token.', 'manual-payment-autoverify' ) ), 400 );
		}

		$table = $this->wpdb->prefix . 'mpav_pairing_tokens';
		$used  = $this->wpdb->get_var(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT used FROM `{$table}` WHERE token_hash = %s LIMIT 1",
				hash( 'sha256', $raw_token )
			)
		);

		wp_send_json_success( array( 'paired' => ( null !== $used && 1 === (int) $used ) ) );
	}

	/**
	 * Revokes an existing Android device.
	 */
	public function handle_revoke_device(): void {
		$this->verify_admin_access();

		$device_id = isset( $_POST['device_id'] ) ? absint( $_POST['device_id'] ) : 0;
		if ( ! $device_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid device ID.', 'manual-payment-autoverify' ) ), 400 );
		}

		$device = $this->device_repo->find_by_id( $device_id );
		if ( ! $device ) {
			wp_send_json_error( array( 'message' => __( 'Device not found.', 'manual-payment-autoverify' ) ), 404 );
		}

		$revoked = $this->device_repo->revoke( $device_id );
		if ( ! $revoked ) {
			wp_send_json_error( array( 'message' => __( 'Failed to revoke device or device already revoked.', 'manual-payment-autoverify' ) ), 400 );
		}

		// Log revocation event.
		$audit_table = $this->wpdb->prefix . 'mpav_audit_log';
		$user_id     = get_current_user_id();
		$this->wpdb->insert(
			$audit_table,
			array(
				'claim_id'   => null,
				'order_id'   => null,
				'actor'      => 'wp:' . $user_id,
				'event'      => 'device:revoked',
				'context'    => wp_json_encode(
					array(
						'device_id'   => $device_id,
						'device_name' => $device->device_name ?? '',
					)
				),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		wp_send_json_success(
			array(
				'message'   => sprintf( __( 'Device "%s" successfully revoked.', 'manual-payment-autoverify' ), esc_html( (string) $device->device_name ) ),
				'device_id' => $device_id,
			)
		);
	}

	/**
	 * Disconnects and removes ALL paired Android devices.
	 *
	 * Same guard as the single revoke (capability + nonce). Every device row,
	 * including its encrypted secret, is deleted, so previously paired phones
	 * stop authenticating immediately and the list is emptied. An audit entry
	 * records who did it and which devices were removed.
	 */
	public function handle_clear_all_devices(): void {
		$this->verify_admin_access();

		$devices = $this->device_repo->get_all( 1000, 0 );
		if ( empty( $devices ) ) {
			wp_send_json_error( array( 'message' => __( 'There are no devices to clear.', 'manual-payment-autoverify' ) ), 400 );
		}

		$removed_list = array();
		foreach ( $devices as $device ) {
			$removed_list[] = array(
				'device_id'   => (int) $device->device_id,
				'device_name' => (string) ( $device->device_name ?? '' ),
			);
		}

		$deleted = $this->device_repo->delete_all();

		$this->wpdb->insert(
			$this->wpdb->prefix . 'mpav_audit_log',
			array(
				'claim_id'   => null,
				'order_id'   => null,
				'actor'      => 'wp:' . get_current_user_id(),
				'event'      => 'device:cleared_all',
				'context'    => wp_json_encode(
					array(
						'count'   => $deleted,
						'devices' => $removed_list,
					)
				),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		wp_send_json_success(
			array(
				/* translators: %d: number of devices removed */
				'message' => sprintf( _n( '%d device was disconnected and removed.', '%d devices were disconnected and removed.', $deleted, 'manual-payment-autoverify' ), $deleted ),
				'count'   => $deleted,
			)
		);
	}

	/**
	 * Strict capability and nonce guard.
	 */
	private function verify_admin_access(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied. Manage WooCommerce capability required.', 'manual-payment-autoverify' ) ),
				403
			);
		}

		// Check nonce.
		$nonce_valid = check_ajax_referer( 'mpav_admin_nonce', 'nonce', false );
		if ( ! $nonce_valid ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'manual-payment-autoverify' ) ),
				403
			);
		}
	}
}

