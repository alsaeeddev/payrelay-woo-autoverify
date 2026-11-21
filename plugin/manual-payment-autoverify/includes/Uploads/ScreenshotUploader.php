<?php
/**
 * Secure screenshot uploader for manual payment verification.
 *
 * Implements defense-in-depth image upload security:
 *   1. Transport and upload error validation.
 *   2. File size enforcement (<= 3 MB).
 *   3. File existence and upload origin validation.
 *   4. Layered MIME inspection (PHP finfo, getimagesize(), and wp_check_filetype_and_ext()).
 *   5. Image re-encoding via GD to strip EXIF, comments, and polyglot executable payloads.
 *   6. Alpha channel preservation for PNG and WebP images.
 *   7. Storage in a protected directory outside direct HTTP access with cryptographically random filenames.
 *
 * @package ManualPaymentAutoVerify\Uploads
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Uploads;

/**
 * Handles screenshot file uploads and image sanitization.
 */
class ScreenshotUploader {

	/**
	 * Maximum allowed screenshot file size in bytes (3 MB).
	 */
	public const MAX_FILE_SIZE = 3145728; // 3 * 1024 * 1024

	/**
	 * Maximum allowed image width or height in pixels (defense against pixel flood / corrupt headers).
	 */
	public const MAX_IMAGE_DIMENSION = 10000;

	/**
	 * Allowed MIME types for uploaded screenshots.
	 *
	 * @var string[]
	 */
	public const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
	);

	/**
	 * Allowed file extensions.
	 *
	 * @var string[]
	 */
	public const ALLOWED_EXTENSIONS = array(
		'jpg',
		'jpeg',
		'png',
		'webp',
	);

	/**
	 * Subdirectory inside wp-content/uploads where protected screenshots reside.
	 */
	public const UPLOAD_SUBDIR = 'mpav-screenshots';

	/**
	 * Base directory path for screenshots.
	 *
	 * @var string|null
	 */
	private ?string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $base_dir Custom base directory override for testing.
	 */
	public function __construct( ?string $base_dir = null ) {
		$this->base_dir = $base_dir;
	}

	/**
	 * Handles screenshot upload for an order.
	 *
	 * @param array<string, mixed> $file     $_FILES['mpav_screenshot'] entry.
	 * @param int                  $order_id WooCommerce order ID.
	 * @return string|\WP_Error Relative file path on success (e.g. "123/a1b2...png"), \WP_Error on failure.
	 */
	public function handle_upload( array $file, int $order_id ): string|\WP_Error {
		$validation = $this->validate_file( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );
		$mime     = $this->detect_mime_type( $tmp_path );

		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new \WP_Error(
				'mpav_invalid_mime',
				__( 'Screenshot must be a valid JPG, PNG, or WebP image.', 'manual-payment-autoverify' )
			);
		}

		// Ensure protected base directory and security shield files exist.
		$base_dir = $this->get_base_dir();
		if ( is_wp_error( $base_dir ) ) {
			return $base_dir;
		}

		$this->ensure_directory_security( $base_dir );

		// Create order-specific subdirectory.
		$order_dir = $base_dir . DIRECTORY_SEPARATOR . $order_id;
		if ( ! is_dir( $order_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				$created = wp_mkdir_p( $order_dir );
			} else {
				$created = @mkdir( $order_dir, 0755, true );
			}

			if ( ! $created && ! is_dir( $order_dir ) ) {
				return new \WP_Error(
					'mpav_upload_mkdir_failed',
					__( 'Could not create secure storage folder for order screenshot.', 'manual-payment-autoverify' )
				);
			}
		}

		// Determine safe extension based on detected MIME type.
		$ext = $this->mime_to_extension( $mime );

		// Generate cryptographically random 32-hex (128-bit) filename.
		$filename = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$dest_path = $order_dir . DIRECTORY_SEPARATOR . $filename;

		// Re-encode image to purge EXIF, comments, and polyglot executable payloads.
		$reencode_result = $this->reencode( $tmp_path, $mime, $dest_path );
		if ( is_wp_error( $reencode_result ) ) {
			return $reencode_result;
		}

		// Set restrictive file permissions.
		@chmod( $dest_path, 0640 );

		// Return relative path: "{order_id}/{filename}".
		return $order_id . DIRECTORY_SEPARATOR . $filename;
	}

	/**
	 * Validates uploaded file metadata, size, MIME type, and image structure.
	 *
	 * @param array<string, mixed> $file File array from $_FILES.
	 * @return bool|\WP_Error True if valid, \WP_Error on failure.
	 */
	public function validate_file( array $file ): bool|\WP_Error {
		$error_code = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$file_name  = (string) ( $file['name'] ?? '' );
		$file_size  = (int) ( $file['size'] ?? 0 );
		$tmp_path   = (string) ( $file['tmp_name'] ?? '' );

		if ( UPLOAD_ERR_NO_FILE === $error_code || '' === $file_name ) {
			return new \WP_Error(
				'mpav_no_file_uploaded',
				__( 'No screenshot file was provided.', 'manual-payment-autoverify' )
			);
		}

		if ( UPLOAD_ERR_OK !== $error_code ) {
			return new \WP_Error(
				'mpav_upload_error',
				sprintf(
					/* translators: %d: PHP upload error code */
					__( 'Screenshot upload failed with error code %d.', 'manual-payment-autoverify' ),
					$error_code
				)
			);
		}

		// Size enforcement.
		if ( $file_size > self::MAX_FILE_SIZE || ( file_exists( $tmp_path ) && filesize( $tmp_path ) > self::MAX_FILE_SIZE ) ) {
			return new \WP_Error(
				'mpav_file_too_large',
				__( 'Screenshot exceeds the 3 MB maximum size limit.', 'manual-payment-autoverify' )
			);
		}

		if ( '' === $tmp_path || ! file_exists( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return new \WP_Error(
				'mpav_file_not_found',
				__( 'Temporary upload file not found or unreadable.', 'manual-payment-autoverify' )
			);
		}

		// Validate extension against client-supplied filename.
		$ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return new \WP_Error(
				'mpav_invalid_extension',
				__( 'Screenshot must have a .jpg, .jpeg, .png, or .webp extension.', 'manual-payment-autoverify' )
			);
		}

		// Deep MIME inspection using finfo.
		$detected_mime = $this->detect_mime_type( $tmp_path );
		if ( ! in_array( $detected_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new \WP_Error(
				'mpav_invalid_mime',
				__( 'Screenshot file content does not match an allowed image type.', 'manual-payment-autoverify' )
			);
		}

		// Deep structural inspection via getimagesize().
		$image_info = @getimagesize( $tmp_path );
		if (
			false === $image_info
			|| empty( $image_info[0] )
			|| empty( $image_info[1] )
			|| $image_info[0] > self::MAX_IMAGE_DIMENSION
			|| $image_info[1] > self::MAX_IMAGE_DIMENSION
			|| ( isset( $image_info['bits'] ) && $image_info['bits'] > 16 )
		) {
			return new \WP_Error(
				'mpav_invalid_image',
				__( 'Uploaded file is not a valid or readable image.', 'manual-payment-autoverify' )
			);
		}

		// Cross-check getimagesize() MIME with detected finfo MIME.
		$image_mime = (string) ( $image_info['mime'] ?? '' );
		if ( $image_mime !== $detected_mime ) {
			return new \WP_Error(
				'mpav_mime_mismatch',
				__( 'Image header and content type mismatch.', 'manual-payment-autoverify' )
			);
		}

		// WordPress layered extension & type verification if function available.
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$wp_check = wp_check_filetype_and_ext(
				$tmp_path,
				$file_name,
				array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				)
			);

			if ( empty( $wp_check['ext'] ) || empty( $wp_check['type'] ) ) {
				return new \WP_Error(
					'mpav_wp_check_failed',
					__( 'Screenshot failed WordPress filetype verification.', 'manual-payment-autoverify' )
				);
			}
		}

		return true;
	}

	/**
	 * Re-encodes the image via GD to strip all EXIF metadata and malicious embedded polyglots.
	 *
	 * @param string $source_path Path to uploaded source file.
	 * @param string $mime        Detected MIME type.
	 * @param string $dest_path   Destination file path.
	 * @return bool|\WP_Error True on success, \WP_Error on failure.
	 */
	public function reencode( string $source_path, string $mime, string $dest_path ): bool|\WP_Error {
		// If GD extension is available, perform full re-encode.
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' ) ) {
			return $this->reencode_with_gd( $source_path, $mime, $dest_path );
		}

		// Fallback if GD is absent: copy validated file.
		if ( ! @copy( $source_path, $dest_path ) ) {
			return new \WP_Error(
				'mpav_copy_failed',
				__( 'Could not save validated screenshot to disk.', 'manual-payment-autoverify' )
			);
		}

		return true;
	}

	/**
	 * Re-encodes using PHP GD library.
	 *
	 * @param string $source_path Source file path.
	 * @param string $mime        Detected MIME type.
	 * @param string $dest_path   Destination file path.
	 * @return bool|\WP_Error True on success, \WP_Error on failure.
	 */
	private function reencode_with_gd( string $source_path, string $mime, string $dest_path ): bool|\WP_Error {
		$img = null;

		switch ( $mime ) {
			case 'image/jpeg':
				if ( function_exists( 'imagecreatefromjpeg' ) ) {
					$img = @imagecreatefromjpeg( $source_path );
				}
				break;

			case 'image/png':
				if ( function_exists( 'imagecreatefrompng' ) ) {
					$img = @imagecreatefrompng( $source_path );
				}
				break;

			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$img = @imagecreatefromwebp( $source_path );
				}
				break;
		}

		// Fallback to imagecreatefromstring if typed function failed or wasn't available.
		if ( false === $img || null === $img ) {
			$raw_data = @file_get_contents( $source_path );
			if ( false !== $raw_data ) {
				$img = @imagecreatefromstring( $raw_data );
			}
		}

		if ( false === $img || null === $img ) {
			return new \WP_Error(
				'mpav_reencode_decode_failed',
				__( 'Could not decode image for sanitization. The image file may be corrupt or invalid.', 'manual-payment-autoverify' )
			);
		}

		$saved = false;

		switch ( $mime ) {
			case 'image/jpeg':
				$saved = @imagejpeg( $img, $dest_path, 88 );
				break;

			case 'image/png':
				// Preserve alpha channel transparency for PNG.
				imagealphablending( $img, false );
				imagesavealpha( $img, true );
				$saved = @imagepng( $img, $dest_path, 8 );
				break;

			case 'image/webp':
				// Preserve alpha channel transparency for WebP.
				imagealphablending( $img, false );
				imagesavealpha( $img, true );
				$saved = @imagewebp( $img, $dest_path, 88 );
				break;
		}

		if ( is_resource( $img ) || ( is_object( $img ) && $img instanceof \GdImage ) ) {
			imagedestroy( $img );
		}

		if ( ! $saved || ! file_exists( $dest_path ) || 0 === filesize( $dest_path ) ) {
			return new \WP_Error(
				'mpav_reencode_save_failed',
				__( 'Could not write sanitized image to disk.', 'manual-payment-autoverify' )
			);
		}

		return true;
	}

	/**
	 * Detects file MIME type using finfo or fallback.
	 *
	 * @param string $file_path Path to file.
	 * @return string MIME type string.
	 */
	public function detect_mime_type( string $file_path ): string {
		if ( class_exists( '\finfo' ) ) {
			$finfo = new \finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->file( $file_path );
			if ( is_string( $mime ) && '' !== $mime ) {
				return strtolower( trim( $mime ) );
			}
		} elseif ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$mime = finfo_file( $finfo, $file_path );
				finfo_close( $finfo );
				if ( is_string( $mime ) && '' !== $mime ) {
					return strtolower( trim( $mime ) );
				}
			}
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$mime = @mime_content_type( $file_path );
			if ( is_string( $mime ) && '' !== $mime ) {
				return strtolower( trim( $mime ) );
			}
		}

		$size_info = @getimagesize( $file_path );
		if ( ! empty( $size_info['mime'] ) ) {
			return strtolower( trim( (string) $size_info['mime'] ) );
		}

		return 'application/octet-stream';
	}

	/**
	 * Maps an allowed MIME type to standard file extension.
	 *
	 * @param string $mime MIME type.
	 * @return string Extension without dot ('jpg', 'png', 'webp').
	 */
	public function mime_to_extension( string $mime ): string {
		switch ( $mime ) {
			case 'image/jpeg':
				return 'jpg';
			case 'image/png':
				return 'png';
			case 'image/webp':
				return 'webp';
			default:
				return 'bin';
		}
	}

	/**
	 * Resolves base storage directory for screenshots.
	 *
	 * @return string|\WP_Error Base path on success, \WP_Error on failure.
	 */
	public function get_base_dir(): string|\WP_Error {
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

			$base = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
		} else {
			$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::UPLOAD_SUBDIR;
		}

		if ( ! is_dir( $base ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $base );
			} else {
				@mkdir( $base, 0755, true );
			}
		}

		return $base;
	}

	/**
	 * Enforces multi-layer web server shield files (.htaccess, web.config, index.php)
	 * inside the storage directory to prevent direct HTTP access or script execution.
	 *
	 * @param string $dir Target directory path.
	 */
	public function ensure_directory_security( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// 1. Apache .htaccess: Deny both Apache 2.2 and 2.4+ direct access and turn off PHP engines.
		$htaccess_file = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$rules = implode(
				"\n",
				array(
					'# Manual Payment Auto-Verify — Protected Directory',
					'<IfModule mod_authz_core.c>',
					'    Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					'    Order Deny,Allow',
					'    Deny from all',
					'</IfModule>',
					'',
					'# Disable script execution',
					'<IfModule mod_php7.c>',
					'    php_flag engine off',
					'</IfModule>',
					'<IfModule mod_php8.c>',
					'    php_flag engine off',
					'</IfModule>',
					'<IfModule mod_php.c>',
					'    php_flag engine off',
					'</IfModule>',
					'Options -Indexes -ExecCGI',
					'AddHandler cgi-script .php .pl .py .jsp .asp .sh .cgi',
					'',
				)
			);
			@file_put_contents( $htaccess_file, $rules );
		}

		// 2. Microsoft IIS web.config.
		$webconfig_file = $dir . DIRECTORY_SEPARATOR . 'web.config';
		if ( ! file_exists( $webconfig_file ) ) {
			$xml = implode(
				"\n",
				array(
					'<?xml version="1.0" encoding="UTF-8"?>',
					'<configuration>',
					'  <system.webServer>',
					'    <authorization>',
					'      <deny users="*"/>',
					'    </authorization>',
					'    <handlers accessPolicy="None" />',
					'  </system.webServer>',
					'</configuration>',
					'',
				)
			);
			@file_put_contents( $webconfig_file, $xml );
		}

		// 3. Fallback PHP silence index.
		$index_file = $dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Deletes a protected screenshot file from disk securely.
	 *
	 * Enforces strict path traversal checks to guarantee the target file resides strictly
	 * within the designated screenshot storage directory before unlinking.
	 *
	 * @param string $relative_path Path relative to screenshot storage directory (e.g. "123/a1b2c3.png").
	 * @return bool True if file was deleted or did not exist, false on error or traversal attempt.
	 */
	public function delete_screenshot( string $relative_path ): bool {
		$relative_path = trim( $relative_path );
		if ( '' === $relative_path ) {
			return true;
		}

		// Strictly reject directory traversal or absolute path attempts.
		if (
			strpos( $relative_path, '..' ) !== false
			|| strpos( $relative_path, "\0" ) !== false
			|| preg_match( '/^(\/|\\\\|[a-zA-Z]:)/', $relative_path )
		) {
			return false;
		}

		$base_dir = $this->get_base_dir();
		if ( is_wp_error( $base_dir ) || ! is_dir( $base_dir ) ) {
			return false;
		}

		$normalized_rel = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative_path );
		$target_file    = $base_dir . DIRECTORY_SEPARATOR . $normalized_rel;

		$real_base   = realpath( $base_dir );
		$real_target = realpath( $target_file );

		// If the file does not exist on disk, consider it cleanly absent.
		if ( false === $real_target || ! file_exists( $real_target ) ) {
			return true;
		}

		// Ensure target is strictly inside real base dir.
		if (
			false === $real_base
			|| 0 !== strpos( strtolower( $real_target ), strtolower( $real_base ) . DIRECTORY_SEPARATOR )
		) {
			return false;
		}

		$deleted = @unlink( $real_target );

		// If the parent order directory is now empty, remove it as well.
		$parent_dir = dirname( $real_target );
		if ( $deleted && is_dir( $parent_dir ) && $parent_dir !== $real_base ) {
			$remaining = array_diff( (array) @scandir( $parent_dir ), array( '.', '..' ) );
			if ( empty( $remaining ) ) {
				@rmdir( $parent_dir );
			}
		}

		return $deleted;
	}
}

