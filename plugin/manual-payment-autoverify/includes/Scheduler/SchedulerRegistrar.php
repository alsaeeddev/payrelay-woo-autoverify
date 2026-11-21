<?php
/**
 * Scheduler Registrar — hooks and schedules recurring background jobs.
 *
 * Integrates with WooCommerce Action Scheduler (and falls back gracefully to
 * WP-Cron if Action Scheduler is not loaded).
 *
 * Registered Jobs:
 *   - `mpav_expire_claims`: Runs periodically (default 5 min) to expire stale claims.
 *   - `mpav_cleanup`: Runs daily (86400 s) for privacy retention and table maintenance.
 *
 * @package ManualPaymentAutoVerify\Scheduler
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Scheduler;

/**
 * SchedulerRegistrar handles lifecycle registration and scheduling.
 */
class SchedulerRegistrar {

	/**
	 * Action Scheduler action hook for claim expiration.
	 */
	public const HOOK_EXPIRE_CLAIMS = 'mpav_expire_claims';

	/**
	 * Action Scheduler action hook for housekeeping cleanup.
	 */
	public const HOOK_CLEANUP = 'mpav_cleanup';

	/**
	 * Action Scheduler action group name.
	 */
	public const GROUP = 'mpav';

	/**
	 * Registers action hooks and ensures recurring schedules are active.
	 */
	public function register(): void {
		// Attach job runners to action hooks.
		add_action( self::HOOK_EXPIRE_CLAIMS, array( $this, 'run_expiry_job' ) );
		add_action( self::HOOK_CLEANUP, array( $this, 'run_cleanup_job' ) );

		// Check and schedule recurring actions on WordPress init.
		add_action( 'init', array( $this, 'maybe_schedule_jobs' ) );
	}

	/**
	 * Handler for mpav_expire_claims hook.
	 */
	public function run_expiry_job(): void {
		( new ExpiryJob() )->run();
	}

	/**
	 * Handler for mpav_cleanup hook.
	 */
	public function run_cleanup_job(): void {
		( new CleanupJob() )->run();
	}

	/**
	 * Ensures both recurring background actions are scheduled.
	 */
	public function maybe_schedule_jobs(): void {
		$this->schedule_all();
	}

	/**
	 * Schedules all recurring background jobs if not already registered.
	 */
	public function schedule_all(): void {
		$this->schedule_expiry_job();
		$this->schedule_cleanup_job();
	}

	/**
	 * Schedules the recurring claim expiry job.
	 */
	public function schedule_expiry_job(): void {
		$interval = (int) apply_filters( 'mpav_expiry_job_interval', 300 ); // 5 minutes default.
		if ( $interval < 60 ) {
			$interval = 300;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_EXPIRE_CLAIMS, array(), self::GROUP ) ) {
				as_schedule_recurring_action(
					time() + $interval,
					$interval,
					self::HOOK_EXPIRE_CLAIMS,
					array(),
					self::GROUP,
					true
				);
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_EXPIRE_CLAIMS ) ) {
			wp_schedule_event( time() + $interval, 'hourly', self::HOOK_EXPIRE_CLAIMS );
		}
	}

	/**
	 * Schedules the recurring housekeeping and retention cleanup job.
	 */
	public function schedule_cleanup_job(): void {
		$interval = (int) apply_filters( 'mpav_cleanup_job_interval', DAY_IN_SECONDS ); // Daily default.
		if ( $interval < 3600 ) {
			$interval = DAY_IN_SECONDS;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_CLEANUP, array(), self::GROUP ) ) {
				as_schedule_recurring_action(
					time() + $interval,
					$interval,
					self::HOOK_CLEANUP,
					array(),
					self::GROUP,
					true
				);
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + $interval, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Unschedules all scheduled actions for both Action Scheduler and WP-Cron.
	 *
	 * Called during plugin deactivation and uninstallation.
	 */
	public function unschedule_all(): void {
		$hooks = array( self::HOOK_EXPIRE_CLAIMS, self::HOOK_CLEANUP );

		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}
}

