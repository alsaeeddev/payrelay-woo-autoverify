<?php
/**
 * Handles incoming SMS forwarding from the companion Android app.
 *
 * POST /wp-json/mpav/v1/sms
 *
 * @package ManualPaymentAutoVerify\Rest
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Rest;

use ManualPaymentAutoVerify\Core\DeviceRepository;
use ManualPaymentAutoVerify\Core\EncryptionService;
use ManualPaymentAutoVerify\Core\NonceStore;
use ManualPaymentAutoVerify\Core\SmsRepository;
use ManualPaymentAutoVerify\Util\PhoneNumberMasker;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Authenticates device HMAC signature then stores the forwarded SMS.
 *
 * Security posture
 * ----------------
 * - Dual rate limit: per-IP then per-device (device check is after DB lookup,
 *   so IP limit is the first line of defence).
 * - Body size capped at 64 KB before any deserialization.
 * - HMAC verified with hash_equals (constant-time).
 * - Nonce consumed AFTER signature verified (no TOCTOU window).
 * - SMS body is NEVER stored; only hex(SHA-256(body)) is persisted.
 * - Sender phone masked before storage.
 * - All 401 paths return the same WP_Error code to prevent oracle attacks.
 * - $authenticated_device is an instance property; each registered route
 *   gets its own SmsController instance, so there is no cross-request bleed.
 */
class SmsController {

	private const BODY_SIZE_LIMIT = 65536; // 64 KB.

	/** @var object|null Device row from DB, set by authenticate(). */
	private ?object $authenticated_device = null;

	/** @var DeviceRepository */
	private DeviceRepository $device_repo;

	/** @var EncryptionService */
	private EncryptionService $encryption;

	/** @var NonceStore */
	private NonceStore $nonce_store;

	/** @var SmsRepository */
	private SmsRepository $sms_repo;

	/** @var RateLimiter */
	private RateLimiter $rate_limiter;

	public function __construct(
		?DeviceRepository $device_repo = null,
		?EncryptionService $encryption = null,
		?NonceStore $nonce_store = null,
		?SmsRepository $sms_repo = null,
		?RateLimiter $rate_limiter = null
	) {
		$this->device_repo  = $device_repo ?? new DeviceRepository();
		$this->encryption   = $encryption ?? new EncryptionService();
		$this->nonce_store  = $nonce_store ?? new NonceStore();
		$this->sms_repo     = $sms_repo ?? new SmsRepository();
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
			'idempotency_key' => array(
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 128,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sender'          => array(
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 128,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'body'            => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 1024,
				// No sanitize_callback — we hash the raw body; sanitizing would corrupt the hash.
				// The raw body is never stored; only body_hash is persisted.
			),
			'received_at_ms'  => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
	'sim_slot' => array(
	'type'              => 'integer',
	'required'          => false,
	'default'           => 0,
	'minimum'           => 0,
	'maximum'           => 10,
	'sanitize_callback' => 'absint',
),
		);
	}

	// ------------------------------------------------------------------ //
	// permission_callback
	// ------------------------------------------------------------------ //

	/**
	 * Full HMAC authentication pipeline.
	 *
	 * Sets $this->authenticated_device on success so handle() can consume it
	 * without a second DB round-trip.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ) {
		// 1. IP-level rate limit (cheapest check first, no DB involved).
		$ip = $this->get_client_ip();
		if ( ! $this->rate_limiter->check_ip( $ip ) ) {
			return $this->rate_limited_error();
		}

		// 2. Extract device ID from header.
		$device_id = $this->extract_device_id( $request );
		if ( null === $device_id ) {
			return $this->auth_error();
		}

		// 3. Per-device rate limit.
		if ( ! $this->rate_limiter->check_device( (string) $device_id ) ) {
			return $this->rate_limited_error();
		}

		// 4. Body size guard before any parsing.
		if ( strlen( $request->get_body() ) > self::BODY_SIZE_LIMIT ) {
			return new WP_Error(
				'mpav_request_too_large',
				__( 'Request body too large.', 'manual-payment-autoverify' ),
				array( 'status' => 400 )
			);
		}

		// 5. Load device — single DB query.
		$device = $this->device_repo->find_by_id( $device_id );
		if ( null === $device || ! empty( $device->revoked_at ) ) {
			return $this->auth_error();
		}

		// 6. Decrypt secret — RuntimeException means corrupt/tampered ciphertext.
		try {
			$secret = $this->encryption->decrypt( $device->encrypted_secret );
		} catch ( \RuntimeException $e ) {
			// Log internally (no sensitive data exposed).
			error_log( sprintf( 'MPAV: failed to decrypt secret for device %d', $device_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $this->auth_error();
		}

		// 7. HMAC verification (also consumes nonce atomically on success).
		if ( ! HmacAuthenticator::verify( $request, $secret, (string) $device_id, $this->nonce_store ) ) {
			return $this->auth_error();
		}

		// 8. Update last-seen timestamp (best-effort; non-critical).
		$timestamp_header = (int) $request->get_header( 'x_mpav_timestamp' );
		$this->device_repo->update_last_seen( $device_id, $timestamp_header );

		// 9. Store device on instance for handle() — avoids second DB query.
		$this->authenticated_device = $device;

		return true;
	}

	// ------------------------------------------------------------------ //
	// callback
	// ------------------------------------------------------------------ //

	/**
	 * Store the forwarded SMS and trigger matching.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$device = $this->authenticated_device;
		if ( null === $device ) {
			// Should never happen (authenticate() must have succeeded), but be defensive.
			return $this->auth_error();
		}

		$idempotency_key = (string) $request->get_param( 'idempotency_key' );
		$sender          = (string) $request->get_param( 'sender' );
		$body            = (string) $request->get_param( 'body' );
		$received_at_ms  = (int) $request->get_param( 'received_at_ms' );
		$sim_slot        = (int) $request->get_param( 'sim_slot' );

		// Idempotency: return "duplicate" if we have already processed this key
		// for this device (SmsRepository stores device_id + idempotency_key with UNIQUE index).
		$existing = $this->sms_repo->find_by_idempotency_key( (string) $device->device_id, $idempotency_key );
		if ( null !== $existing ) {
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		// Invoke the SMS parser filter (Stage 5 wires real parsers here).
		// $parse_result is a ParsedSms object or null.
		$parse_result = apply_filters( 'mpav_parse_sms', null, $sender, $body, $received_at_ms );

		// Build insert row.
		// IMPORTANT: body is NEVER persisted — only its SHA-256 hash.
		$body_hash     = hash( 'sha256', $body );
		$sender_masked = PhoneNumberMasker::mask( $sender );
		$received_at_s = (int) round( $received_at_ms / 1000 );
		if ( $received_at_s <= 0 ) {
			$received_at_s = time();
		}
		$received_at_dt = gmdate( 'Y-m-d H:i:s', $received_at_s );

		$insert_data = array(
			'device_id'       => (string) $device->device_id,
			'idempotency_key' => $idempotency_key,
			'sender_raw'      => $sender,
			'sender_masked'   => $sender_masked,
			'body_hash'       => $body_hash,
			'received_at'     => $received_at_dt,
			'parsed_provider' => '',
			'parsed_tid'      => '',
			'parsed_amount'   => null,
		);

		// Merge in parsed fields if the filter produced a result.
		if ( null !== $parse_result && is_object( $parse_result ) ) {
			if ( method_exists( $parse_result, 'get_provider' ) ) {
				$insert_data['parsed_provider'] = (string) $parse_result->get_provider();
			} elseif ( isset( $parse_result->provider ) && is_string( $parse_result->provider ) ) {
				$insert_data['parsed_provider'] = $parse_result->provider;
			}

			if ( method_exists( $parse_result, 'get_tid' ) ) {
				$insert_data['parsed_tid'] = (string) $parse_result->get_tid();
			} elseif ( isset( $parse_result->tid ) && is_string( $parse_result->tid ) ) {
				$insert_data['parsed_tid'] = $parse_result->tid;
			}

			if ( method_exists( $parse_result, 'get_amount_minor' ) ) {
				$insert_data['parsed_amount'] = (int) $parse_result->get_amount_minor();
			} elseif ( isset( $parse_result->amount_minor ) && is_int( $parse_result->amount_minor ) ) {
				$insert_data['parsed_amount'] = $parse_result->amount_minor;
			}
		}

		$sms_id = $this->sms_repo->insert( $insert_data );

		if ( false === $sms_id ) {
			// Duplicate key means the idempotency_key+device_id pair already exists
			// (race condition between the find and insert). Treat as duplicate.
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		// Fire action for the matching engine (Stage 5).
		do_action( 'mpav_sms_received', $sms_id, $parse_result, $device );

		return new WP_REST_Response( array( 'status' => 'accepted' ), 200 );
	}

	// ------------------------------------------------------------------ //
	// Private helpers
	// ------------------------------------------------------------------ //

	/**
	 * Extract and validate the X-MPAV-Device header value.
	 *
	 * WP normalises headers: X-MPAV-Device -> x_mpav_device.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int|null Device ID or null if header absent/invalid.
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
	 * Uniform 401 to prevent oracle attacks.
	 *
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
	 * Uniform 429.
	 *
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
	 * REMOTE_ADDR only — see note in PairController.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	}
}

