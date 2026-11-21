<?php
/**
 * Repository for mpav_devices table.
 *
 * @package ManualPaymentAutoVerify\Core
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Core;

/**
 * Encapsulates all DB access for paired Android devices.
 *
 * All queries go through $wpdb->prepare(). Column names are whitelisted
 * constants — never interpolated from user input.
 */
class DeviceRepository {

	/** @var \wpdb */
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	// ------------------------------------------------------------------ //
	// Write
	// ------------------------------------------------------------------ //

	/**
	 * Insert a newly paired device row.
	 *
	 * @param string      $device_name      Human-readable label (already sanitized).
	 * @param string      $encrypted_secret Opaque ciphertext from EncryptionService.
	 * @param string|null $device_uuid      Stable per-install ID from the app (optional).
	 * @return int|false New device_id or false on failure.
	 */
	public function create( string $device_name, string $encrypted_secret, ?string $device_uuid = null ) {
		$table = $this->wpdb->prefix . 'mpav_devices';

		$result = $this->wpdb->insert(
			$table,
			array(
				'device_name'      => $device_name,
				'device_uuid'      => $device_uuid,
				'encrypted_secret' => $encrypted_secret,
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		// Safety net: if the device_uuid column is not there yet (schema upgrade still
		// pending), pair the device without it rather than failing the pairing.
		if ( false === $result && null !== $device_uuid ) {
			$result = $this->wpdb->insert(
				$table,
				array(
					'device_name'      => $device_name,
					'encrypted_secret' => $encrypted_secret,
					'created_at'       => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s' )
			);
		}

		if ( false === $result ) {
			return false;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update the last-seen timestamp from the request's X-MPAV-Timestamp header.
	 *
	 * @param int $device_id      Device primary key.
	 * @param int $unix_timestamp Unix timestamp from signed header.
	 */
	public function update_last_seen( int $device_id, int $unix_timestamp ): void {
		$table = $this->wpdb->prefix . 'mpav_devices';

		// Convert unix timestamp to UTC datetime string.
		$utc_datetime = gmdate( 'Y-m-d H:i:s', $unix_timestamp );

		$this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}`
				 SET last_seen_at = %s, updated_at = NOW()
				 WHERE device_id = %d
				   AND revoked_at IS NULL",
				$utc_datetime,
				$device_id
			)
		);
	}

	/**
	 * Store app_version and battery_optimized flag received from a heartbeat.
	 *
	 * Values are stored as a JSON blob in device_meta TEXT column so we do not
	 * need extra columns for every diagnostic field. Only whitelisted scalar
	 * values are encoded — no user-controlled keys.
	 *
	 * @param int    $device_id         Device primary key.
	 * @param string $app_version       Sanitized version string e.g. "1.2.3".
	 * @param bool   $battery_optimized Whether battery optimisation is active.
	 */
	public function update_app_version( int $device_id, string $app_version, bool $battery_optimized ): void {
		$table = $this->wpdb->prefix . 'mpav_devices';

		$meta = wp_json_encode(
			array(
				'app_version'       => substr( $app_version, 0, 32 ),
				'battery_optimized' => $battery_optimized,
				'last_heartbeat'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}`
				 SET device_meta = %s, updated_at = NOW()
				 WHERE device_id = %d
				   AND revoked_at IS NULL",
				$meta,
				$device_id
			)
		);
	}

	/**
	 * Re-activates an existing device row for a phone that is pairing again.
	 *
	 * Issues the new secret (the old one stops working), clears the revoked flag and
	 * the stale diagnostics, and restarts the "Paired" date. The device_id stays the
	 * same, so the list does not grow every time the same phone is re-paired.
	 *
	 * @param int    $device_id        Existing device primary key.
	 * @param string $device_name      Current device name.
	 * @param string $encrypted_secret New encrypted secret.
	 * @return bool True when the row was updated.
	 */
	public function reactivate( int $device_id, string $device_name, string $encrypted_secret ): bool {
		$table = $this->wpdb->prefix . 'mpav_devices';
		$now   = current_time( 'mysql', true );

		$rows = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}`
				 SET device_name = %s, encrypted_secret = %s, device_meta = NULL,
				     last_seen_at = NULL, revoked_at = NULL, created_at = %s, updated_at = %s
				 WHERE device_id = %d",
				$device_name,
				$encrypted_secret,
				$now,
				$now,
				$device_id
			)
		);

		return false !== $rows && $rows > 0;
	}

	/**
	 * Finds a device by the app's stable install UUID (any status, incl. revoked).
	 *
	 * @param string $device_uuid UUID sent by the app at pairing.
	 * @return object|null
	 */
	public function find_by_uuid( string $device_uuid ): ?object {
		$table = $this->wpdb->prefix . 'mpav_devices';

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE device_uuid = %s LIMIT 1",
				$device_uuid
			)
		);

		return $row ?: null;
	}

	/**
	 * Mark a device as revoked (soft delete).
	 *
	 * @param int $device_id Device primary key.
	 * @return bool True if a row was updated.
	 */
	public function revoke( int $device_id ): bool {
		$table = $this->wpdb->prefix . 'mpav_devices';

		$rows = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}`
				 SET revoked_at = NOW(), updated_at = NOW()
				 WHERE device_id = %d
				   AND revoked_at IS NULL",
				$device_id
			)
		);

		return $rows > 0;
	}

	/**
	 * Permanently removes ALL paired devices (active and revoked).
	 *
	 * Deleting the row also deletes the encrypted HMAC secret, so any phone that
	 * was paired can no longer authenticate: find_by_id() returns null for it and
	 * both REST controllers reject the request. Stored SMS rows are not touched.
	 *
	 * @return int Number of device rows removed.
	 */
	public function delete_all(): int {
		$table = $this->wpdb->prefix . 'mpav_devices';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->query( "DELETE FROM `{$table}`" );

		return is_int( $rows ) ? $rows : 0;
	}

	// ------------------------------------------------------------------ //
	// Read
	// ------------------------------------------------------------------ //

	/**
	 * Load a device by primary key.
	 *
	 * Returns null for missing or revoked devices.
	 * Callers must also check revoked_at themselves (defence in depth).
	 *
	 * @param int $device_id Device primary key.
	 * @return object|null DB row as stdClass or null.
	 */
	public function find_by_id( int $device_id ): ?object {
		$table = $this->wpdb->prefix . 'mpav_devices';

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE device_id = %d LIMIT 1",
				$device_id
			)
		);

		return $row ?: null;
	}

	/**
	 * List all non-revoked devices, newest first.
	 *
	 * @return object[] Array of stdClass rows.
	 */
	public function get_active_devices(): array {
		$table = $this->wpdb->prefix . 'mpav_devices';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			"SELECT * FROM `{$table}` WHERE revoked_at IS NULL ORDER BY created_at DESC"
		);

		return $rows ?: array();
	}

	/**
	 * List all devices (including revoked) for the device manager.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Pagination offset.
	 * @return object[]
	 */
	public function get_all( int $limit = 50, int $offset = 0 ): array {
		$table = $this->wpdb->prefix . 'mpav_devices';

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return $rows ?: array();
	}
}
