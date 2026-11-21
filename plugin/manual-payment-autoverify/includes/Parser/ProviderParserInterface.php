<?php
/**
 * Interface for SMS provider parsers.
 *
 * @package ManualPaymentAutoVerify\Parser
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Parser;

/**
 * Contract for payment SMS parsers.
 *
 * Each mobile wallet provider (JazzCash, Easypaisa, etc.) has its own
 * SMS sender identifiers and message text formats.
 */
interface ProviderParserInterface {

	/**
	 * Returns the unique provider slug (e.g. 'jazzcash', 'easypaisa').
	 *
	 * @return string
	 */
	public function get_provider(): string;

	/**
	 * Determines whether the given sender matches this provider.
	 *
	 * @param string $sender Raw sender header (e.g. 'JazzCash', '8558', '3737').
	 * @return bool
	 */
	public function matches_sender( string $sender ): bool;

	/**
	 * Parses an SMS body and returns a ParsedSms object if valid transaction details
	 * are detected, or null if the message is not a matching payment confirmation.
	 *
	 * @param string $body           Full raw SMS text.
	 * @param int    $received_at_ms Epoch milliseconds when the SMS was received.
	 * @return ParsedSms|null
	 */
	public function parse( string $body, int $received_at_ms ): ?ParsedSms;

	/**
	 * Returns the configured regex patterns used by this parser.
	 *
	 * Useful for admin diagnostics and testing.
	 *
	 * @return array<string, string>
	 */
	public function get_patterns(): array;
}

