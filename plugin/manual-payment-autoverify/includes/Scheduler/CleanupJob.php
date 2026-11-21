<?php
/**
 * Cleanup Job — automated data retention and housekeeping maintenance.
 *
 * Runs via Action Scheduler recurring action `mpav_cleanup`.
 * Responsibilities:
 *   1. Purge expired webhook deduplication nonces (NonceStore).
 *   2. Purge expired or consumed pairing tokens (mpav_pairing_tokens).
 *   3. Enforce SMS retention policy (SmsRepository::delete_older_than).
 *   4. Clean up terminal claims and remove associated screenshot files from disk.
 *   5. Purge expired audit log rows (mpav_audit_log).
 *
 * @package ManualPaymentAutoVerify\Scheduler
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use ManualPaymentAutoVerify\Admin\SettingsPage;
use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Core\NonceStore;
use ManualPaymentAutoVerify\Core\SmsRepository;
use ManualPaymentAutoVerify\Uploads\ScreenshotUploader;

/**
 * CleanupJob background runner.
 */
class CleanupJob {

	private NonceStore $nonce_store;
	private SmsRepository $sms_repo;
	private ClaimRepository $claim_repo;
	private ScreenshotUploader $uploader;
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param NonceStore|null         $nonce_store Optional custom nonce store.
	 * @param SmsRepository|null      $sms_repo    Optional custom SMS repository.
	 * @param ClaimRepository|null    $claim_repo  Optional custom claim repository.
	 * @param ScreenshotUploader|null $uploader    Optional custom screenshot uploader.
	 * @param \wpdb|null              $wpdb        Optional custom wpdb instance.
	 */
	public function __construct(
		?NonceStore $nonce_store = null,
		?SmsRepository $sms_repo = null,
		?ClaimRepository $claim_repo = null,
		?ScreenshotUploader $uploader = null,
		?\wpdb $wpdb = null
	) {
		global $wpdb;

		$this->nonce_store = $nonce_store ?? new NonceStore();
		$this->sms_repo    = $sms_repo ?? new SmsRepository();
		$this->claim_repo  = $claim_repo ?? new ClaimRepository();
		$this->uploader    = $uploader ?? new ScreenshotUploader();
		$this->wpdb        = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Executes the cleanup and data retention job.
	 *
	 * @return array<string, int> Statistics of purged items.
	 */
	public function run(): array {
		$stats = array(
			'nonces_purged'      => 0,
			'tokens_purged'      => 0,
			'sms_purged'         => 0,
			'claims_purged'      => 0,
			'screenshots_purged' => 0,
			'audit_logs_purged'  => 0,
		);

		// 1. Purge expired webhook deduplication nonces.
		$stats['nonces_purged'] = $this->nonce_store->purge_expired();

		// 2. Purge expired or used pairing tokens.
		$tokens_table           = $this->wpdb->prefix . 'mpav_pairing_tokens';
		$now_gmt                = current_time( 'mysql', true );
		$tokens_result          = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM `{$tokens_table}` WHERE expires_at < %s OR used = 1",
				$now_gmt
			)
		);
		$stats['tokens_purged'] = ( false === $tokens_result ) ? 0 : (int) $tokens_result;

		// 3. Purge forwarded SMS messages beyond retention period.
		$sms_retention_days = (int) SettingsPage::get( 'mpav_sms_retention_days', 90 );
		if ( $sms_retention_days < 1 ) {
			$sms_retention_days = 90;
		}

		$sms_cutoff          = new DateTimeImmutable( "-{$sms_retention_days} days", new DateTimeZone( 'UTC' ) );
		$stats['sms_purged'] = $this->sms_repo->delete_older_than( $sms_cutoff );

		// 4. Purge terminal claims and their associated screenshots.
		$claim_retention_days = (int) apply_filters( 'mpav_claim_retention_days', $sms_retention_days );
		if ( $claim_retention_days < 1 ) {
			$claim_retention_days = $sms_retention_days;
		}

		$claim_cutoff = new DateTimeImmutable( "-{$claim_retention_days} days", new DateTimeZone( 'UTC' ) );

		// Clean up screenshots for claims that are about to be purged.
		$terminal_claims = $this->claim_repo->find_terminal_older_than( $claim_cutoff, 500 );
		foreach ( $terminal_claims as $claim ) {
			if ( ! empty( $claim->screenshot_path ) ) {
				if ( $this->uploader->delete_screenshot( (string) $claim->screenshot_path ) ) {
					$stats['screenshots_purged']++;
				}
			}
		}

		// Delete terminal claims.
		$stats['claims_purged'] = $this->claim_repo->delete_older_than( $claim_cutoff );

		// 5. Purge old audit logs beyond retention period.
		$audit_table                = $this->wpdb->prefix . 'mpav_audit_log';
		$audit_result               = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM `{$audit_table}` WHERE created_at < %s",
				$claim_cutoff->format( 'Y-m-d H:i:s' )
			)
		);
		$stats['audit_logs_purged'] = ( false === $audit_result ) ? 0 : (int) $audit_result;

		/**
		 * Fires after the cleanup job finishes running.
		 *
		 * @param array<string, int> $stats Summary of purged records.
		 */
		do_action( 'mpav_cleanup_job_completed', $stats );

		return $stats;
	}
}

