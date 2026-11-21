<?php
/**
 * Handles device pairing requests.
 *
 * POST /wp-json/mpav/v1/pair
 *
 * @package ManualPaymentAutoVerify\Rest
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Rest;

use ManualPaymentAutoVerify\Core\DeviceRepository;
use ManualPaymentAutoVerify\Core\EncryptionService;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Exchanges a one-time pairing token for a device_id + secret.
 *
 * Security posture
 * ----------------
 * - Rate-limited to 5 req/min per IP before any DB work.
 * - Token consumed atomically (UPDATE WHERE used=0 + rowcount guard) to
 *   prevent race-condition double-use — no SELECT-then-UPDATE.
 * - Secret is returned ONCE and never stored in plaintext; only the
 *   EncryptionService-wrapped form goes to the DB.
 * - All error responses use the same HTTP status and generic message to
 *   prevent oracle/enumeration attacks.
 */
class PairController {

	/** @var \wpdb */
	private \wpdb $wpdb;

	/** @var DeviceRepository */
	private DeviceRepository $device_repo;

	/** @var EncryptionService */
	private EncryptionService $encryption;

	/** @var RateLimiter */
	private RateLimiter $rate_limiter;

	public function __construct(
		?\wpdb $wpdb = null,
		?DeviceRepository $device_repo = null,
		?EncryptionService $encryption = null,
		?RateLimiter $rate_limiter = null
	) {
		global $wpdb;
		$this->wpdb         = $wpdb ?? $GLOBALS['wpdb'];
		$this->device_repo  = $device_repo ?? new DeviceRepository();
		$this->encryption   = $encryption ?? new EncryptionService();
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
			'pairing_token' => array(
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 64,
				'maxLength'         => 64,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && 64 === strlen( $value );
				},
			),
			'device_name'   => array(
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 128,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && strlen( $value ) >= 1 && strlen( $value ) <= 128;
				},
			),
			// Optional: stable per-install UUID from the app. Older app versions omit it.
			'device_uuid'   => array(
				'type'              => 'string',
				'required'          => false,
				'maxLength'         => 64,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && ( '' === $value || 1 === preg_match( '/^[A-Za-z0-9\-]{16,64}$/', $value ) );
				},
			),
		);
	}

	// ------------------------------------------------------------------ //
	// permission_callback
	// ------------------------------------------------------------------ //

	/**
	 * Rate-limit by IP. Token validation happens inside handle().
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ) {
		$ip = $this->get_client_ip();

		if ( ! $this->rate_limiter->check_pair_ip( $ip ) ) {
			return new WP_Error(
				'mpav_rate_limited',
				__( 'Too many requests. Please try again later.', 'manual-payment-autoverify' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	// ------------------------------------------------------------------ //
	// callback
	// ------------------------------------------------------------------ //

	/**
	 * Exchange a pairing token for device credentials.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$raw_token   = $request->get_param( 'pairing_token' ); // already sanitized by args schema.
		$device_name = $request->get_param( 'device_name' );   // already sanitized.

		if ( ! is_string( $raw_token ) || 64 !== strlen( $raw_token ) ) {
			return $this->generic_error();
		}

		if ( ! is_string( $device_name ) || '' === trim( $device_name ) ) {
			return $this->generic_error();
		}

		$token_hash = hash( 'sha256', $raw_token );

		// Atomic consume: UPDATE WHERE used=0 returns 1 affected row on success,
		// 0 if already used or expired. No SELECT needed first.
		$table = $this->wpdb->prefix . 'mpav_pairing_tokens';

		$this->wpdb->query( 'START TRANSACTION' );

		$rows_updated = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}` SET used = 1
				 WHERE token_hash = %s
				   AND used       = 0
				   AND expires_at > UTC_TIMESTAMP()
				 LIMIT 1",
				$token_hash
			)
		);

		if ( ! $rows_updated ) {
			$this->wpdb->query( 'ROLLBACK' );
			// Generic: could be expired, already used, or non-existent token.
			return $this->generic_error();
		}

		// Generate a 64-char hex secret (32 raw bytes).
		try {
			$raw_secret       = bin2hex( random_bytes( 32 ) );
			$encrypted_secret = $this->encryption->encrypt( $raw_secret );
		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			return $this->generic_error();
		}

		// Same phone pairing again (matched by the app's install UUID): reuse its row
		// with the new secret instead of adding a duplicate device.
		$device_uuid = $request->get_param( 'device_uuid' );
		$device_uuid = ( is_string( $device_uuid ) && '' !== $device_uuid ) ? strtolower( $device_uuid ) : null;
		$clean_name  = sanitize_text_field( $device_name );

		$existing = null !== $device_uuid ? $this->device_repo->find_by_uuid( $device_uuid ) : null;

		if ( null !== $existing ) {
			$device_id = $this->device_repo->reactivate( (int) $existing->device_id, $clean_name, $encrypted_secret )
				? (int) $existing->device_id
				: 0;
		} else {
			// Persist the device inside the same transaction.
			$device_id = $this->device_repo->create( $clean_name, $encrypted_secret, $device_uuid );
		}

		if ( ! $device_id ) {
			$this->wpdb->query( 'ROLLBACK' );
			return $this->generic_error();
		}

		$this->wpdb->query( 'COMMIT' );

		// Secret returned ONCE — never stored in plaintext.
		// return new WP_REST_Response(
		// 	array(
		// 		'device_id' => $device_id,
		// 		'secret'    => $raw_secret,
		// 	),
		// 	200
		// );

					return new WP_REST_Response(
			array(
				'device_id' => (string) $device_id,
				'secret'    => $raw_secret,
			),
			200
		);
	}

	// ------------------------------------------------------------------ //
	// Private helpers
	// ------------------------------------------------------------------ //

	/**
	 * Returns a generic 400 error that leaks no information.
	 *
	 * @return WP_Error
	 */
	private function generic_error(): WP_Error {
		return new WP_Error(
			'mpav_pair_failed',
			__( 'Pairing failed. Please generate a new pairing code.', 'manual-payment-autoverify' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Best-effort client IP from REMOTE_ADDR.
	 *
	 * X-Forwarded-For is intentionally ignored — it is trivially spoofed
	 * without a trusted reverse-proxy layer. Configure the proxy to rewrite
	 * REMOTE_ADDR at the server level if needed.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	}
}

