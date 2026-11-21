<?php
/**
 * Expiry Job — automatically expires stale pending payment claims.
 *
 * Runs via Action Scheduler recurring action `mpav_expire_claims`.
 * Identifies pending claims whose `expires_at` timestamp has passed and
 * transitions them to 'expired' through the ClaimStateMachine.
 * The state transition atomically writes an audit log entry and fires
 * `mpav_claim_transitioned` to cancel the associated WooCommerce order.
 *
 * @package ManualPaymentAutoVerify\Scheduler
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Scheduler;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Core\ClaimStateMachine;
use ManualPaymentAutoVerify\Matching\ClaimMatcherService;

/**
 * ExpiryJob background runner.
 */
class ExpiryJob {

	/**
	 * Default maximum number of expired claims to process per execution batch.
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	private ClaimRepository $claim_repo;
	private ClaimStateMachine $state_machine;

	/**
	 * Constructor.
	 *
	 * @param ClaimRepository|null   $claim_repo    Optional custom repository.
	 * @param ClaimStateMachine|null $state_machine Optional custom state machine.
	 */
	public function __construct( ?ClaimRepository $claim_repo = null, ?ClaimStateMachine $state_machine = null ) {
		$this->claim_repo    = $claim_repo ?? new ClaimRepository();
		$this->state_machine = $state_machine ?? new ClaimStateMachine( $this->claim_repo );
	}

	/**
	 * Executes the expiry job.
	 *
	 * Finds all pending claims where `expires_at <= NOW()` (UTC) and transitions
	 * each to 'expired'.
	 *
	 * @param int $batch_size Maximum claims to process in this run.
	 * @return int Number of claims transitioned to expired.
	 */
	public function run( int $batch_size = self::DEFAULT_BATCH_SIZE ): int {
		$batch_size = (int) apply_filters( 'mpav_expiry_job_batch_size', $batch_size );
		if ( $batch_size < 1 ) {
			$batch_size = self::DEFAULT_BATCH_SIZE;
		}

		// Last-chance reconciliation: verify/flag any pending claim whose SMS is
		// already stored, so it is never expired by mistake.
		try {
			( new ClaimMatcherService( $this->claim_repo, $this->state_machine ) )->reconcile_pending();
		} catch ( \Throwable $e ) {
			// Never let reconciliation block expiry.
			unset( $e );
		}

		$expired_claims = $this->claim_repo->find_expired_pending( $batch_size );
		if ( empty( $expired_claims ) ) {
			return 0;
		}

		$transitioned_count = 0;

		foreach ( $expired_claims as $claim ) {
			$claim_id = (int) ( $claim->id ?? 0 );
			if ( $claim_id <= 0 ) {
				continue;
			}

			$context = array(
				'actor'  => 'system',
				'reason' => __( 'Claim expired: payment window closed without matching SMS.', 'manual-payment-autoverify' ),
			);

			$success = $this->state_machine->transition( $claim_id, 'expired', $context );
			if ( $success ) {
				$transitioned_count++;
			}
		}

		/**
		 * Fires after an expiry job execution run completes.
		 *
		 * @param int      $transitioned_count Number of claims transitioned.
		 * @param object[] $expired_claims     Original candidate claim rows.
		 */
		do_action( 'mpav_expiry_job_completed', $transitioned_count, $expired_claims );

		return $transitioned_count;
	}
}

