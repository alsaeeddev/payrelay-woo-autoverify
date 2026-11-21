<?php
/**
 * OCR Helper Service (Pro Feature Stub).
 *
 * Provides optical character recognition helper functions for payment screenshot receipts.
 *
 * CRITICAL SECURITY INVARIANT (STRIDE Threat #8):
 * OCR output is STRICTLY COSMETIC/ADVISORY. It is NEVER authoritative proof of payment.
 * Under no circumstances may an OCR match trigger automatic verification of a claim.
 * Automated verification requires an authentic cryptographically signed SMS record
 * forwarded from a paired device.
 *
 * @package ManualPaymentAutoVerify\Uploads
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Uploads;

use ManualPaymentAutoVerify\Util\FeatureFlags;
use ManualPaymentAutoVerify\Util\TidNormalizer;

/**
 * OCR helper service for advisory TID detection.
 */
class OcrHelperService {

	/**
	 * Checks if OCR processing is available in the current installation/licence tier.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_available(): bool {
		return FeatureFlags::enabled( 'ocr_helper' );
	}

	/**
	 * Whether OCR results can be used as authoritative proof for automated verification.
	 *
	 * PERMANENT SECURITY GUARD: Always returns false.
	 *
	 * @return bool Always false.
	 */
	public static function is_authoritative(): bool {
		return false;
	}

	/**
	 * Extracts candidate Transaction ID (TID) from a screenshot image file.
	 *
	 * In the Lite edition, returns null. In Pro, hooks can integrate with local or cloud OCR engines.
	 * Any extracted candidate TID is sanitized and normalized, and provided solely for merchant advisory.
	 *
	 * @param string $file_path Path to image file.
	 * @return string|null Candidate normalized TID if detected, null otherwise.
	 */
	public function extract_tid( string $file_path ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return null;
		}

		/**
		 * Filter allows Pro engine or third-party OCR integrations to parse the screenshot.
		 *
		 * @param string|null $candidate_tid Extracted TID candidate.
		 * @param string      $file_path     Image file path.
		 */
		$raw_candidate = apply_filters( 'mpav_ocr_extract_candidate_tid', null, $file_path );

		if ( is_string( $raw_candidate ) && '' !== trim( $raw_candidate ) ) {
			$normalized = TidNormalizer::normalize( $raw_candidate );
			if ( TidNormalizer::is_valid( $normalized ) ) {
				return $normalized;
			}
		}

		return null;
	}
}

