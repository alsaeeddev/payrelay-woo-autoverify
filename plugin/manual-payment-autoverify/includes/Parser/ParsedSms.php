<?php
/**
 * Immutable value object representing a parsed SMS.
 *
 * @package ManualPaymentAutoVerify\Parser
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Parser;

use InvalidArgumentException;

/**
 * ParsedSms value object.
 *
 * Holds the normalized extracted data from a payment provider SMS:
 * - Provider identifier (e.g. 'jazzcash', 'easypaisa')
 * - Normalized transaction ID (TID)
 * - Transaction amount in integer minor units (paisa)
 * - Masked sender identifier
 * - Received timestamp (Unix epoch seconds)
 */
final class ParsedSms {

	/** @var string */
	private string $provider;

	/** @var string Normalized TID. */
	private string $tid;

	/** @var int Amount in integer minor units (paisa). */
	private int $amount_minor;

	/** @var string Masked sender phone number or shortcode. */
	private string $sender_masked;

	/** @var int Unix timestamp in seconds. */
	private int $received_at_unix;

	/**
	 * @param string $provider         Provider slug ('jazzcash', 'easypaisa', etc.).
	 * @param string $tid              Normalized transaction ID.
	 * @param int    $amount_minor     Amount in integer minor units (e.g. 150000 for PKR 1,500.00).
	 * @param string $sender_masked    Masked sender number or alphanumeric shortcode.
	 * @param int    $received_at_unix Timestamp in seconds when SMS was received.
	 *
	 * @throws InvalidArgumentException When required values are invalid.
	 */
	public function __construct(
		string $provider,
		string $tid,
		int $amount_minor,
		string $sender_masked,
		int $received_at_unix
	) {
		$provider = trim( strtolower( $provider ) );
		$tid      = trim( $tid );

		if ( '' === $provider ) {
			throw new InvalidArgumentException( 'Provider must not be empty.' );
		}

		if ( '' === $tid ) {
			throw new InvalidArgumentException( 'Transaction ID must not be empty.' );
		}

		if ( $amount_minor < 0 ) {
			throw new InvalidArgumentException( 'Amount minor units must be non-negative.' );
		}

		if ( $received_at_unix <= 0 ) {
			throw new InvalidArgumentException( 'Received timestamp must be a positive integer.' );
		}

		$this->provider         = $provider;
		$this->tid              = $tid;
		$this->amount_minor     = $amount_minor;
		$this->sender_masked    = $sender_masked;
		$this->received_at_unix = $received_at_unix;
	}

	public function get_provider(): string {
		return $this->provider;
	}

	public function get_tid(): string {
		return $this->tid;
	}

	public function get_amount_minor(): int {
		return $this->amount_minor;
	}

	public function get_sender_masked(): string {
		return $this->sender_masked;
	}

	public function get_received_at_unix(): int {
		return $this->received_at_unix;
	}

	/**
	 * Magic getter for backward compatibility and property-style access.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		switch ( $name ) {
			case 'provider':
				return $this->provider;
			case 'tid':
				return $this->tid;
			case 'amount_minor':
				return $this->amount_minor;
			case 'sender_masked':
				return $this->sender_masked;
			case 'received_at_unix':
				return $this->received_at_unix;
			default:
				return null;
		}
	}

	/**
	 * Magic isset check.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function __isset( string $name ): bool {
		return in_array( $name, array( 'provider', 'tid', 'amount_minor', 'sender_masked', 'received_at_unix' ), true );
	}

	/**
	 * Convert to array for serialization or logging (no unmasked sensitive data).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'provider'         => $this->provider,
			'tid'              => $this->tid,
			'amount_minor'     => $this->amount_minor,
			'sender_masked'    => $this->sender_masked,
			'received_at_unix' => $this->received_at_unix,
		);
	}
}

