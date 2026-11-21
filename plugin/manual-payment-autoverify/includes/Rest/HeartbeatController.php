<?php
/**
 * Handles device heartbeat pings.
 *
 * POST /wp-json/mpav/v1/heartbeat
 *
 * @package ManualPaymentAutoVerify\Rest
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Rest;

use ManualPaymentAutoVerify\Core\DeviceRepository;
use ManualPaymentAutoVerify\Core\EncryptionService;
use ManualPaymentAutoVerify\Core\NonceStore;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Accepts a signed heartbeat, updates device last-seen, and returns 200 OK.
 *
 * Authentication is identical to SmsController so the same security
 * guarantees apply (HMAC + nonce + rate limit + body size cap).
 *
 * The heartbeat body carries {app_version, battery_optimized}; we store
 * app_version in device metadata for diagnostics (no PII involved).
 */
class HeartbeatController {

	private const BODY_SIZE_LIMIT = 1024; // 1 KB is plenty for heartbeat.

	/** @var object|null Set by authenticate(), consumed by handle(). */
	private ?object $authenticated_device = null;

	/** @var DeviceRepository */
	private DeviceRepository $device_repo;

	/** @var EncryptionService */
	private EncryptionService $encryption;

	/** @var NonceStore */
	private NonceStore $nonce_store;

	/** @var RateLimiter */
	private RateLimiter $rate_limiter;

	public function __construct(
		?DeviceRepository $device_repo = null,
		?EncryptionService $encryption = null,
		?NonceStore $nonce_store = null,
		?RateLimiter $rate_limiter = null
	) {
		$this->device_repo  = $device_repo ?? new DeviceRepository();
		$this->encryption   = $encryption ?? new EncryptionService();
		$this->nonce_store  = $nonce_store ?? new NonceStore();
		$this->rate_limiter = $rate_limiter ?? new RateLimiter();
	}

	// ------------------------------------------------------------------ //
	// Route schema
	// ------------------------------------------------------------------ //

	/**
	 * Returns the WP REST args schema for this route.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_args(): array {
		return array(
			'app_version'       => array(
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 32,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'battery_optimized' => array(
				'type'     => 'boolean',
				'required' => true,
			),
		);
	}

	// ------------------------------------------------------------------ //
	// permission_callback
	// ------------------------------------------------------------------ //

	/**
	 * Full HMAC authentication pipeline — identical to SmsController.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ) {
		$ip = $this->get_client_ip();
		if ( ! $this->rate_limiter->check_ip( $ip ) ) {
			return $this->rate_limited_error();
		}

		$device_id = $this->extract_device_id( $request );
		if ( null === $device_id ) {
			return $this->auth_error();
		}

		if ( ! $this->rate_limiter->check_device( (string) $device_id ) ) {
			return $this->rate_limited_error();
		}

		if ( strlen( $request->get_body() ) > self::BODY_SIZE_LIMIT ) {
			return new WP_Error(
				'mpav_request_too_large',
				__( 'Request body too large.', 'manual-payment-autoverify' ),
				array( 'status' => 400 )
			);
		}

		$device = $this->device_repo->find_by_id( $device_id );
		if ( null === $device || ! empty( $device->revoked_at ) ) {
			return $this->auth_error();
		}

		try {
			$secret = $this->encryption->decrypt( $device->encrypted_secret );
		} catch ( \RuntimeException $e ) {
			error_log( sprintf( 'MPAV: failed to decrypt secret for device %d', $device_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $this->auth_error();
		}

		if ( ! HmacAuthenticator::verify( $request, $secret, (string) $device_id, $this->nonce_store ) ) {
			return $this->auth_error();
		}

		$timestamp_header = (int) $request->get_header( 'x_mpav_timestamp' );
		$this->device_repo->update_last_seen( $device_id, $timestamp_header );

		$this->authenticated_device = $device;

		return true;
	}

	// ------------------------------------------------------------------ //
	// callback
	// ------------------------------------------------------------------ //

	/**
	 * Process the heartbeat and return OK.
	 *
	 * Stores app_version for diagnostics; ignores battery_optimized
	 * (advisory only — Android may send it for display in the device manager).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$device = $this->authenticated_device;
		if ( null === $device ) {
			return $this->auth_error();
		}

		$app_version       = sanitize_text_field( (string) $request->get_param( 'app_version' ) );
		$battery_optimized = (bool) $request->get_param( 'battery_optimized' );

		// Persist app_version in device row for the device-manager UI.
		$this->device_repo->update_app_version(
			(int) $device->device_id,
			$app_version,
			$battery_optimized
		);

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	// ------------------------------------------------------------------ //
	// Private helpers
	// ------------------------------------------------------------------ //

	/**
	 * Extract and validate the X-MPAV-Device header value.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int|null
	 */
	private function extract_device_id( WP_REST_Request $request ): ?int {
		$raw = $request->get_header( 'x_mpav_device' );
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$id = (int) $raw;
		return $id > 0 ? $id : null;
	}

	/**
	 * @return WP_Error
	 */
	private function auth_error(): WP_Error {
		return new WP_Error(
			'mpav_unauthorized',
			__( 'Authentication failed.', 'manual-payment-autoverify' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * @return WP_Error
	 */
	private function rate_limited_error(): WP_Error {
		return new WP_Error(
			'mpav_rate_limited',
			__( 'Too many requests. Please try again later.', 'manual-payment-autoverify' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * @return string
	 */
	private function get_client_ip(): string {
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	}
}

