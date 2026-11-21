<?php
/**
 * Registers all REST API routes for the MPAV plugin.
 *
 * @package ManualPaymentAutoVerify\Rest
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Rest;

/**
 * Wires each controller to its REST route.
 *
 * Each route gets a fresh controller instance so that the
 * per-request $authenticated_device property is isolated
 * to exactly one HTTP request / permission_callback + callback pair.
 */
class RestRegistrar {

	private const NAMESPACE = 'mpav/v1';

	/**
	 * Hook into rest_api_init and register all routes.
	 */
	public function register(): void {
		$this->register_pair_route();
		$this->register_sms_route();
		$this->register_heartbeat_route();
	}

	// ------------------------------------------------------------------ //
	// Private helpers
	// ------------------------------------------------------------------ //

	private function register_pair_route(): void {
		// A new instance per registration ensures no cross-request state.
		$controller = new PairController();

		register_rest_route(
			self::NAMESPACE,
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'handle' ),
				'permission_callback' => array( $controller, 'authenticate' ),
				'args'                => PairController::get_args(),
			)
		);
	}

	private function register_sms_route(): void {
		$controller = new SmsController();

		register_rest_route(
			self::NAMESPACE,
			'/sms',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'handle' ),
				'permission_callback' => array( $controller, 'authenticate' ),
				'args'                => SmsController::get_args(),
			)
		);
	}

	private function register_heartbeat_route(): void {
		$controller = new HeartbeatController();

		register_rest_route(
			self::NAMESPACE,
			'/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'handle' ),
				'permission_callback' => array( $controller, 'authenticate' ),
				'args'                => HeartbeatController::get_args(),
			)
		);
	}
}

