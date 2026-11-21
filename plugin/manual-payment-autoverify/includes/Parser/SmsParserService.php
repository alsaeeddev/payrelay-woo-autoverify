<?php
/**
 * SMS Parser Service — dispatches incoming SMS to the appropriate provider parser.
 *
 * @package ManualPaymentAutoVerify\Parser
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Parser;

use ManualPaymentAutoVerify\Util\PhoneNumberMasker;
use ManualPaymentAutoVerify\Util\SmsExtractor;

/**
 * SmsParserService.
 *
 * Coordinates provider-specific parsers (JazzCash, Easypaisa, etc.) and exposes
 * the primary `parse()` method called by the `mpav_parse_sms` filter hook.
 */
class SmsParserService {

	/**
	 * Registered provider parsers.
	 *
	 * @var array<string, ProviderParserInterface>
	 */
	private array $parsers = array();

	public function __construct() {
		// Register default Pakistani wallet parsers.
		$this->register_parser( JazzCashParser::PROVIDER, new JazzCashParser() );
		$this->register_parser( EasypaisaParser::PROVIDER, new EasypaisaParser() );

		/**
		 * Allows custom or Pro parsers (e.g. Nayapay, Sadapay, Raast) to be registered.
		 *
		 * @param SmsParserService $this
		 */
		do_action( 'mpav_register_sms_parsers', $this );
	}

	/**
	 * Registers a provider parser instance.
	 *
	 * @param string                  $provider Provider slug.
	 * @param ProviderParserInterface $parser   Parser implementation.
	 */
	public function register_parser( string $provider, ProviderParserInterface $parser ): void {
		$this->parsers[ strtolower( $provider ) ] = $parser;
	}

	/**
	 * Retrieves a parser by provider slug.
	 *
	 * @param string $provider Provider slug.
	 * @return ProviderParserInterface|null
	 */
	public function get_parser( string $provider ): ?ProviderParserInterface {
		return $this->parsers[ strtolower( $provider ) ] ?? null;
	}

	/**
	 * Returns all registered parsers.
	 *
	 * @return array<string, ProviderParserInterface>
	 */
	public function get_parsers(): array {
		return $this->parsers;
	}

	/**
	 * Parses an incoming SMS by matching its sender or message content.
	 *
	 * @param string $sender         Raw sender identifier (phone number or shortcode).
	 * @param string $body           Full SMS text.
	 * @param int    $received_at_ms Timestamp in milliseconds.
	 * @return ParsedSms|null Parsed payment details or null if unparsed/not a payment SMS.
	 */
	public function parse( string $sender, string $body, int $received_at_ms ): ?ParsedSms {
		// 1. Direct sender match.
		foreach ( $this->parsers as $parser ) {
			if ( $parser->matches_sender( $sender ) ) {
				$result = $parser->parse( $body, $received_at_ms );
				if ( null !== $result ) {
					return $result;
				}
			}
		}

		// 2. Fallback heuristic: If sender is a generic phone number (forwarding device)
		// but the body explicitly names a supported provider, attempt that provider's parser.
		if ( false !== stripos( $body, 'jazzcash' ) && isset( $this->parsers[ JazzCashParser::PROVIDER ] ) ) {
			$result = $this->parsers[ JazzCashParser::PROVIDER ]->parse( $body, $received_at_ms );
			if ( null !== $result ) {
				return $result;
			}
		}

		if ( false !== stripos( $body, 'easypaisa' ) && isset( $this->parsers[ EasypaisaParser::PROVIDER ] ) ) {
			$result = $this->parsers[ EasypaisaParser::PROVIDER ]->parse( $body, $received_at_ms );
			if ( null !== $result ) {
				return $result;
			}
		}

		// 3. Universal fallback: any wallet/bank SMS format. Strict provider parsers
		// above win when they recognise the format; this catches everything else
		// (other banks, reworded wallet messages, different field order).
		return $this->parse_universal( $sender, $body, $received_at_ms );
	}

	/**
	 * Format-independent parse using SmsExtractor (amount scoring + keyword/fallback TID).
	 *
	 * Only CREDIT messages in PKR are accepted. Provider is detected from the
	 * sender/body; anything unrecognised is treated as a generic 'bank' SMS.
	 *
	 * @param string $sender         Raw sender.
	 * @param string $body           SMS text.
	 * @param int    $received_at_ms Epoch ms.
	 * @return ParsedSms|null
	 */
	public function parse_universal( string $sender, string $body, int $received_at_ms ): ?ParsedSms {
		/**
		 * Allows disabling the format-independent fallback (strict parsers only).
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'mpav_enable_universal_sms_parsing', true ) ) {
			return null;
		}

		$data = SmsExtractor::extract( $body );
		if ( null === $data ) {
			return null;
		}

		$received_at_s = (int) round( $received_at_ms / 1000 );
		if ( $received_at_s <= 0 ) {
			$received_at_s = time();
		}

		try {
			return new ParsedSms(
				$this->detect_provider( $sender, $body ),
				$data['tid'],
				$data['amount_minor'],
				PhoneNumberMasker::mask( $sender ),
				$received_at_s
			);
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Detects provider slug from sender/body. Unknown senders are generic 'bank'.
	 *
	 * @param string $sender Raw sender.
	 * @param string $body   SMS text.
	 * @return string jazzcash|easypaisa|bank
	 */
	private function detect_provider( string $sender, string $body ): string {
		$hay = strtolower( $sender . ' ' . $body );

		if ( preg_match( '/jazz\s*cash|\b8558\b/', $hay ) ) {
			return JazzCashParser::PROVIDER;
		}
		if ( preg_match( '/easy\s*paisa|\b3737\b/', $hay ) ) {
			return EasypaisaParser::PROVIDER;
		}
		return 'bank';
	}

	/**
	 * WordPress filter callback for `mpav_parse_sms`.
	 *
	 * @param ParsedSms|null $current_result Current filtered value.
	 * @param string         $sender         Sender string.
	 * @param string         $body           SMS body string.
	 * @param int            $received_at_ms Received timestamp in ms.
	 * @return ParsedSms|null
	 */
	public function filter_parse_sms( ?ParsedSms $current_result, string $sender, string $body, int $received_at_ms ): ?ParsedSms {
		if ( null !== $current_result ) {
			return $current_result;
		}

		return $this->parse( $sender, $body, $received_at_ms );
	}
}

