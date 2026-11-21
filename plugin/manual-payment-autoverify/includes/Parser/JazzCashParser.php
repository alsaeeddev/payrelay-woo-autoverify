<?php
/**
 * Parser for JazzCash payment SMS notifications.
 *
 * @package ManualPaymentAutoVerify\Parser
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Parser;

use ManualPaymentAutoVerify\Util\Money;
use ManualPaymentAutoVerify\Util\PhoneNumberMasker;
use ManualPaymentAutoVerify\Util\TidNormalizer;

/**
 * JazzCashParser.
 *
 * Extracts TID and transaction amount from JazzCash SMS messages sent by
 * official shortcodes (8558, JazzCash).
 *
 * ReDoS defense:
 * - Input length capped at 1024 bytes.
 * - Regexes use bounded quantifiers with no nested greedy repetitions.
 */
class JazzCashParser implements ProviderParserInterface {

	public const PROVIDER = 'jazzcash';

	private const MAX_BODY_LENGTH = 1024;

	/**
	 * Default known senders for JazzCash.
	 *
	 * @var string[]
	 */
	private array $known_senders = array(
		'jazzcash',
		'8558',
		'jazz cash',
		'+928558',
	);

	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return self::PROVIDER;
	}

	/**
	 * Matches sender against known JazzCash sender identifiers.
	 *
	 * @param string $sender Raw sender header.
	 * @return bool
	 */
	public function matches_sender( string $sender ): bool {
		$cleaned = strtolower( trim( $sender ) );

		if ( in_array( $cleaned, $this->known_senders, true ) ) {
			return true;
		}

		// Also check alphanumeric sender pattern.
		return (bool) preg_match( '/^(?:\+?92)?8558$|^jazz\s*cash$/i', $cleaned );
	}

	/**
	 * Returns default regex patterns.
	 *
	 * @return array<string, string>
	 */
	public function get_patterns(): array {
		$patterns = array(
			// Pattern 1: "You have received Rs. 1,500.00 from ... TID: 0123456789"
			'received_amount' => '/(?:received|credited|deposited)\s+(?:Rs\.?|PKR)\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)/i',
			// Pattern 2: "Rs. 1,500.00 received from ... TID 0123456789"
			'amount_received' => '/(?:Rs\.?|PKR)\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)\s+(?:received|credited|deposited)/i',
			// TID pattern: "TID: 0123456789" or "TID is 0123456789" or "Trx ID: 0123456789"
			'tid'             => '/(?:TID|Trx\s*ID|Transaction\s*ID|Trans\s*ID)\s*(?:is|[:.-])?\s*([A-Za-z0-9]{6,32})/i',
		);

		/**
		 * Filters the regex patterns used to parse JazzCash SMS.
		 *
		 * @param array<string, string> $patterns
		 */
		return (array) apply_filters( 'mpav_jazzcash_patterns', $patterns );
	}

	/**
	 * Parses SMS body for payment details.
	 *
	 * @param string $body           Raw SMS text.
	 * @param int    $received_at_ms Milliseconds timestamp.
	 * @return ParsedSms|null
	 */
	public function parse( string $body, int $received_at_ms ): ?ParsedSms {
		if ( strlen( $body ) > self::MAX_BODY_LENGTH ) {
			return null;
		}

		$patterns = $this->get_patterns();

		// 1. Extract amount.
		$amount_minor = null;

		if ( isset( $patterns['received_amount'] ) && preg_match( $patterns['received_amount'], $body, $matches ) ) {
			$amount_minor = $this->parse_amount( $matches[1] );
		} elseif ( isset( $patterns['amount_received'] ) && preg_match( $patterns['amount_received'], $body, $matches ) ) {
			$amount_minor = $this->parse_amount( $matches[1] );
		}

		if ( null === $amount_minor || $amount_minor <= 0 ) {
			return null;
		}

		// 2. Extract TID.
		if ( ! isset( $patterns['tid'] ) || ! preg_match( $patterns['tid'], $body, $matches ) ) {
			return null;
		}

		$tid_normalized = TidNormalizer::normalize( $matches[1] );
		if ( ! TidNormalizer::is_valid( $tid_normalized ) ) {
			return null;
		}

		$received_at_s = (int) round( $received_at_ms / 1000 );
		if ( $received_at_s <= 0 ) {
			$received_at_s = time();
		}

		$sender_masked = PhoneNumberMasker::mask( self::PROVIDER );

		return new ParsedSms(
			self::PROVIDER,
			$tid_normalized,
			$amount_minor,
			$sender_masked,
			$received_at_s
		);
	}

	/**
	 * Normalizes decimal amount string to integer minor units (paisa).
	 *
	 * @param string $raw_amount Amount string from regex, e.g. "1,500.00" or "500".
	 * @return int|null Minor units or null if invalid.
	 */
	private function parse_amount( string $raw_amount ): ?int {
		// Strip commas: "1,500.00" -> "1500.00"
		$cleaned = str_replace( ',', '', trim( $raw_amount ) );

		if ( ! is_numeric( $cleaned ) ) {
			return null;
		}

		try {
			return Money::from_decimal( $cleaned );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}
}

