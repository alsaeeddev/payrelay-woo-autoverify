<?php
/**
 * Protected File Server for Manual Payment Screenshots.
 *
 * Serves uploaded screenshots exclusively to authenticated administrators with
 * 'manage_woocommerce' capability. Prevents direct HTTP access, directory traversal,
 * MIME confusion, and caching of sensitive financial records.
 *
 * @package ManualPaymentAutoVerify\Uploads
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Uploads;

use ManualPaymentAutoVerify\Core\ClaimRepository;

/**
 * Streams protected payment verification screenshots.
 */
class ProtectedFileServer {

	/**
	 * Nonce action prefix.
	 */
	public const NONCE_ACTION_PREFIX = 'mpav_view_screenshot_';

	/**
	 * Subdirectory inside wp-content/uploads where protected screenshots reside.
	 */
	public const UPLOAD_SUBDIR = 'mpav-screenshots';

	/**
	 * Base directory override for tests.
	 *
	 * @var string|null
	 */
	private ?string $base_dir;

	/**
	 * Claim repository instance.
	 *
	 * @var ClaimRepository
	 */
	private ClaimRepository $claim_repo;

	/**
	 * Constructor.
	 *
	 * @param ClaimRepository|null $claim_repo Custom claim repository.
	 * @param string|null          $base_dir   Custom base directory override for tests.
	 */
	public function __construct( ?ClaimRepository $claim_repo = null, ?string $base_dir = null ) {
		$this->claim_repo = $claim_repo ?? new ClaimRepository();
		$this->base_dir   = $base_dir;
	}

	/**
	 * Registers the admin post action hook.
	 */
	public function register(): void {
		add_action( 'admin_post_mpav_view_screenshot', array( $this, 'handle_request' ) );
	}

	/**
	 * Generates a signed, nonce-protected URL to stream a claim's screenshot.
	 *
	 * @param int $claim_id Claim ID.
	 * @return string Secure URL.
	 */
	public static function get_screenshot_url( int $claim_id ): string {
		$action = 'mpav_view_screenshot';
		$nonce  = wp_create_nonce( self::NONCE_ACTION_PREFIX . $claim_id );

		return admin_url(
			sprintf(
				'admin-post.php?action=%s&claim_id=%d&_wpnonce=%s',
				rawurlencode( $action ),
				$claim_id,
				rawurlencode( $nonce )
			)
		);
	}

	/**
	 * Handles HTTP GET request for admin_post_mpav_view_screenshot.
	 */
	public function handle_request(): void {
		// Capability check: user must have WooCommerce management permission.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this screenshot.', 'manual-payment-autoverify' ),
				esc_html__( 'Forbidden', 'manual-payment-autoverify' ),
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$claim_id = isset( $_GET['claim_id'] ) ? absint( $_GET['claim_id'] ) : 0;
		if ( ! $claim_id ) {
			wp_die(
				esc_html__( 'Invalid claim ID specified.', 'manual-payment-autoverify' ),
				esc_html__( 'Bad Request', 'manual-payment-autoverify' ),
				array( 'response' => 400 )
			);
		}

		// Nonce check.
		check_admin_referer( self::NONCE_ACTION_PREFIX . $claim_id );

		// Load claim row.
		$claim = $this->claim_repo->find_by_id( $claim_id );
		if ( ! $claim || empty( $claim->screenshot_path ) ) {
			wp_die(
				esc_html__( 'Payment screenshot not found for this claim.', 'manual-payment-autoverify' ),
				esc_html__( 'Not Found', 'manual-payment-autoverify' ),
				array( 'response' => 404 )
			);
		}

		$this->serve( (string) $claim->screenshot_path, (int) $claim->order_id );
	}

	/**
	 * Validates path traversal and securely streams the file with anti-sniffing headers.
	 *
	 * @param string $relative_path Path relative to mpav-screenshots directory (e.g. "123/abc.png").
	 * @param int    $order_id      WooCommerce order ID.
	 */
	public function serve( string $relative_path, int $order_id ): void {
		// Strict input validation to prevent traversal attacks.
		if (
			'' === trim( $relative_path )
			|| strpos( $relative_path, '..' ) !== false
			|| strpos( $relative_path, "\0" ) !== false
			|| preg_match( '/^(\/|\\\\|[a-zA-Z]:)/', $relative_path )
		) {
			wp_die(
				esc_html__( 'Invalid screenshot path provided.', 'manual-payment-autoverify' ),
				esc_html__( 'Forbidden', 'manual-payment-autoverify' ),
				array( 'response' => 403 )
			);
		}

		$base_dir = $this->get_base_dir();
		if ( is_wp_error( $base_dir ) || ! is_dir( $base_dir ) ) {
			wp_die(
				esc_html__( 'Screenshot storage directory unavailable.', 'manual-payment-autoverify' ),
				esc_html__( 'Internal Server Error', 'manual-payment-autoverify' ),
				array( 'response' => 500 )
			);
		}

		// Normalize paths and resolve canonical real paths.
		$normalized_rel = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative_path );
		$target_file    = $base_dir . DIRECTORY_SEPARATOR . $normalized_rel;

		$real_base   = realpath( $base_dir );
		$real_target = realpath( $target_file );

		// Confirm file exists, is inside the real base directory, and is readable.
		if (
			false === $real_base
			|| false === $real_target
			|| ! file_exists( $real_target )
			|| ! is_readable( $real_target )
			|| ! is_file( $real_target )
			|| 0 !== strpos( strtolower( $real_target ), strtolower( $real_base ) . DIRECTORY_SEPARATOR )
		) {
			wp_die(
				esc_html__( 'The requested screenshot does not exist on disk.', 'manual-payment-autoverify' ),
				esc_html__( 'Not Found', 'manual-payment-autoverify' ),
				array( 'response' => 404 )
			);
		}

		// Verify file MIME type before streaming.
		$mime = $this->detect_mime_type( $real_target );
		if ( ! in_array( $mime, ScreenshotUploader::ALLOWED_MIME_TYPES, true ) ) {
			wp_die(
				esc_html__( 'File type not supported for display.', 'manual-payment-autoverify' ),
				esc_html__( 'Forbidden', 'manual-payment-autoverify' ),
				array( 'response' => 403 )
			);
		}

		$size     = (int) filesize( $real_target );
		$filename = basename( $real_target );

		// Clean any previous output buffer to avoid corrupting image stream.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Set strict security headers.
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: inline; filename="' . esc_attr( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Cache-Control: private, no-transform, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Stream content.
		readfile( $real_target );
		exit;
	}

	/**
	 * Detects file MIME type using finfo or getimagesize().
	 *
	 * @param string $file_path Path to file.
	 * @return string MIME type string.
	 */
	private function detect_mime_type( string $file_path ): string {
		if ( class_exists( '\finfo' ) ) {
			$finfo = new \finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->file( $file_path );
			if ( is_string( $mime ) && '' !== $mime ) {
				return strtolower( trim( $mime ) );
			}
		}

		$info = @getimagesize( $file_path );
		if ( ! empty( $info['mime'] ) ) {
			return strtolower( trim( (string) $info['mime'] ) );
		}

		return 'application/octet-stream';
	}

	/**
	 * Resolves base storage directory for screenshots.
	 *
	 * @return string|\WP_Error Base path on success, \WP_Error on failure.
	 */
	private function get_base_dir(): string|\WP_Error {
		if ( null !== $this->base_dir ) {
			return $this->base_dir;
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			if ( ! empty( $upload_dir['error'] ) ) {
				return new \WP_Error(
					'mpav_upload_dir_error',
					__( 'WordPress upload directory is not writable.', 'manual-payment-autoverify' )
				);
			}

			return trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		}

		return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::UPLOAD_SUBDIR;
	}
}

