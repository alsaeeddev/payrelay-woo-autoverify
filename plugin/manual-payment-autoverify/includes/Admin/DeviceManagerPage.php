<?php
/**
 * Device Manager Page — controller for viewing, pairing, and revoking Android forwarder devices.
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

use ManualPaymentAutoVerify\Core\DeviceRepository;

/**
 * DeviceManagerPage.
 */
class DeviceManagerPage {

	/** @var DeviceRepository */
	private DeviceRepository $device_repo;

	public function __construct( ?DeviceRepository $device_repo = null ) {
		$this->device_repo = $device_repo ?? new DeviceRepository();
	}

	/**
	 * Renders the device management page and modal templates.
	 */
	public function render(): void {
		$devices = $this->device_repo->get_all( 100, 0 );

		$template_path = dirname( __DIR__, 2 ) . '/templates/admin/device-manager.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}

		$modal_path = dirname( __DIR__, 2 ) . '/templates/admin/pairing-qr-modal.php';
		if ( file_exists( $modal_path ) ) {
			include $modal_path;
		}
	}
}

