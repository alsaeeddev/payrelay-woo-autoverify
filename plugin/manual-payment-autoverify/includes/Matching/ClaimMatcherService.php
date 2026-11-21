<?php
/**
 * Claim Matcher Service — matches parsed incoming SMS against pending customer claims.
 *
 * @package ManualPaymentAutoVerify\Matching
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Matching;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Core\ClaimStateMachine;
use ManualPaymentAutoVerify\Core\SmsRepository;
use ManualPaymentAutoVerify\Parser\ParsedSms;
use ManualPaymentAutoVerify\Util\Money;

/**
 * ClaimMatcherService.
 *
 * Pure matching logic:
 * - Queries ClaimRepository for a pending, non-expired claim with matching (provider, tid_normalized).
 * - Verifies that the SMS received timestamp falls within the allowed match window.
 * - Verifies that the amount matches exactly in integer minor units (paisa).
 * - Transitions the claim to 'auto_verified' (if both match) or 'flagged' (if TID matches but amount/window fails).
 * - Ignores SMS messages that do not correspond to any known pending claim.
 */
class ClaimMatcherService {

	public const RESULT_VERIFIED = 'verified';
	public const RESULT_FLAGGED  = 'flagged';
	public const RESULT_IGNORED  = 'ignored';

	/** @var ClaimRepository */
	private ClaimRepository $claim_repo;

	/** @var ClaimStateMachine */
	private ClaimStateMachine $state_machine;

	public function __construct(
		?ClaimRepository $claim_repo = null,
		?ClaimStateMachine $state_machine = null
	) {
		$this->claim_repo    = $claim_repo ?? new ClaimRepository();
		$this->state_machine = $state_machine ?? new ClaimStateMachine( $this->claim_repo );
	}

	/**
	 * Finds a matchable pending claim for this parsed SMS.
	 *
	 * @param ParsedSms $sms Parsed SMS details.
	 * @return object|null Claim DB row or null.
	 */
	public function match( ParsedSms $sms ): ?object {
		return $this->claim_repo->find_matchable(
			$sms->get_provider(),
			$sms->get_tid()
		);
	}

	/**
	 * Attempts to auto-verify a claim using the parsed SMS.
	 *
	 * @param int       $sms_id Database ID of the mpav_sms_messages record.
	 * @param ParsedSms $sms    Parsed SMS data object.
	 * @return string One of RESULT_VERIFIED, RESULT_FLAGGED, RESULT_IGNORED.
	 */
	public function try_auto_verify( int $sms_id, ParsedSms $sms ): string {
		$claim             = $this->match( $sms );
		$provider_mismatch = false;

		if ( null === $claim ) {
			// Same TID but a different provider than the customer selected
			// (wrong wallet picked, or unknown sender detected as generic 'bank').
			// Never auto-verified: flagged for manual review instead.
			$claim = $this->claim_repo->find_matchable_any_provider( $sms->get_tid() );
			if ( null === $claim ) {
				// No pending claim matches this TID.
				return self::RESULT_IGNORED;
			}
			$provider_mismatch = ( (string) $claim->provider !== $sms->get_provider() );
		}

		$claim_id      = (int) $claim->id;
		$claim_amount  = (int) $claim->amount_minor;
		$sms_amount    = $sms->get_amount_minor();
		$amount_match  = ( $claim_amount === $sms_amount );

		// Match window verification.
		$window_minutes = (int) get_option( 'mpav_sms_match_window_minutes', 60 );
		$window_seconds = max( 1, $window_minutes ) * 60;

		$claim_created_ts = strtotime( $claim->created_at . ' UTC' );
		if ( false === $claim_created_ts ) {
			$claim_created_ts = time();
		}
		$sms_received_ts = $sms->get_received_at_unix();
		$time_diff       = abs( $sms_received_ts - $claim_created_ts );

		// Direction-aware window:
		//  - SMS BEFORE the claim (customer pays first, enters TID later):
		//    must be no older than the match window, so an old unrelated
		//    transaction cannot be re-used.
		//  - SMS AFTER the claim (late/queued upload from the phone, delayed
		//    carrier SMS): accepted for as long as the claim is still pending,
		//    i.e. until the claim expiry time. The SMS timestamp is the real
		//    arrival time on the phone, not the upload time.
		$claim_expires_ts = strtotime( (string) ( $claim->expires_at ?? '' ) . ' UTC' );
		if ( false === $claim_expires_ts || $claim_expires_ts <= 0 ) {
			$claim_expires_ts = $claim_created_ts + $window_seconds;
		}
		$within_window = ( $sms_received_ts >= ( $claim_created_ts - $window_seconds ) )
			&& ( $sms_received_ts <= $claim_expires_ts );

		if ( $amount_match && $within_window && ! $provider_mismatch ) {
			// Successful match: transition to auto_verified.
			$transition_context = array(
				'actor'          => 'system',
				'matched_sms_id' => $sms_id,
				'amount_minor'   => $sms_amount,
				'provider'       => $sms->get_provider(),
				'tid_normalized' => $sms->get_tid(),
				'note'           => sprintf(
					'Auto-verified via %s SMS (#%d, TID: %s, Amount: %s).',
					ucfirst( $sms->get_provider() ),
					$sms_id,
					$sms->get_tid(),
					Money::format_display( $sms_amount )
				),
			);

			$success = $this->state_machine->transition( $claim_id, 'auto_verified', $transition_context );

			if ( $success ) {
				$this->claim_repo->update( $claim_id, array( 'matched_sms_id' => $sms_id ) );
				return self::RESULT_VERIFIED;
			}

			// If transition failed (e.g. concurrent state change), treat as ignored.
			return self::RESULT_IGNORED;
		}

		// TID matches, but amount or time window does not: flag for manual review.
		$reasons = array();
		if ( ! $amount_match ) {
			$reasons[] = sprintf(
				'Amount mismatch: expected %s, SMS received %s',
				Money::format_display( $claim_amount ),
				Money::format_display( $sms_amount )
			);
		}
		if ( $provider_mismatch ) {
			$reasons[] = sprintf(
				'Provider mismatch: customer selected %s, SMS looks like %s',
				(string) $claim->provider,
				$sms->get_provider()
			);
		}
		if ( ! $within_window ) {
			$reasons[] = sprintf(
				'Time window exceeded (SMS must be at most %d min before the claim and before claim expiry; diff was %d min)',
				$window_minutes,
				(int) round( $time_diff / 60 )
			);
		}

		$reason_str = implode( '; ', $reasons );

		$flag_context = array(
			'actor'          => 'system',
			'matched_sms_id' => $sms_id,
			'reason'         => $reason_str,
			'note'           => $reason_str,
			'amount_minor'   => $sms_amount,
			'provider'       => $sms->get_provider(),
			'tid_normalized' => $sms->get_tid(),
		);

		$transitioned = $this->state_machine->transition( $claim_id, 'flagged', $flag_context );

		if ( $transitioned ) {
			$this->claim_repo->update( $claim_id, array( 'matched_sms_id' => $sms_id ) );
		}

		return self::RESULT_FLAGGED;
	}

	/**
	 * Callback hooked to `mpav_claim_created` (reverse matching).
	 *
	 * The payment SMS usually reaches the merchant phone BEFORE the customer
	 * enters the TID at checkout. At that moment no claim exists, so the SMS was
	 * ignored. When the claim is created we therefore look for an already-stored
	 * SMS with the same provider + TID and run the normal auto-verify logic.
	 *
	 * @param int         $claim_id     New claim ID.
	 * @param mixed       $order        WC_Order (unused).
	 * @param string      $provider     Provider slug.
	 * @param string      $tid          Normalized TID.
	 * @param int         $amount_minor Claimed amount (unused; re-read from claim).
	 */
	public function on_claim_created( int $claim_id, $order = null, string $provider = '', string $tid = '', int $amount_minor = 0 ): void {
		if ( '' === $provider || '' === $tid ) {
			return;
		}

		$sms_repo = new SmsRepository();
		$sms_row  = $sms_repo->find_latest_by_parsed( $provider, $tid );
		if ( null === $sms_row ) {
			// Provider differs from the claim's: still look the TID up (will be flagged).
			$sms_row = $sms_repo->find_latest_by_tid( $tid );
		}
		if ( null === $sms_row ) {
			return;
		}

		$received_ts = strtotime( (string) $sms_row->received_at . ' UTC' );
		if ( false === $received_ts || $received_ts <= 0 ) {
			$received_ts = time();
		}

		try {
			$parsed = new ParsedSms(
				(string) $sms_row->parsed_provider,
				(string) $sms_row->parsed_tid,
				(int) $sms_row->parsed_amount,
				(string) $sms_row->sender_masked,
				$received_ts
			);
		} catch ( \InvalidArgumentException $e ) {
			return;
		}

		$result = $this->try_auto_verify( (int) $sms_row->id, $parsed );

		do_action( 'mpav_match_result', $result, (int) $sms_row->id, $parsed );
	}

	/**
	 * Safety net: re-runs matching for every still-pending claim against already
	 * stored SMS rows.
	 *
	 * Covers both arrival orders even if a hook was missed (fatal during the
	 * request, DB hiccup, race between the SMS webhook and checkout). Called by
	 * ExpiryJob before it expires anything, so a claim can never expire while a
	 * matching SMS is sitting in the database.
	 *
	 * @param int $limit Maximum claims to examine per run.
	 * @return int Number of claims that had a stored SMS evaluated.
	 */
	public function reconcile_pending( int $limit = 100 ): int {
		$processed = 0;

		foreach ( $this->claim_repo->find_pending_unexpired( $limit ) as $claim ) {
			$this->on_claim_created(
				(int) $claim->id,
				null,
				(string) $claim->provider,
				(string) $claim->tid_normalized,
				(int) $claim->amount_minor
			);
			++$processed;
		}

		return $processed;
	}

	/**
	 * Callback hooked to `mpav_sms_received`.
	 *
	 * @param int            $sms_id       Database record ID of the received SMS.
	 * @param ParsedSms|null $parse_result Parsed SMS object or null if unparsed.
	 * @param object|null    $device       Device record from DB.
	 */
	public function on_sms_received( int $sms_id, ?ParsedSms $parse_result, ?object $device = null ): void {
		if ( null === $parse_result ) {
			return;
		}

		$result = $this->try_auto_verify( $sms_id, $parse_result );

		/**
		 * Action triggered after an incoming SMS is evaluated against claims.
		 *
		 * @param string    $result       'verified' | 'flagged' | 'ignored'.
		 * @param int       $sms_id       Database ID of the SMS.
		 * @param ParsedSms $parse_result The parsed SMS data.
		 */
		do_action( 'mpav_match_result', $result, $sms_id, $parse_result );
	}
}

