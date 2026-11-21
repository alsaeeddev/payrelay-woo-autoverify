<?php
/**
 * Data-access layer for the mpav_sms_messages table.
 *
 * The raw SMS body is NEVER stored. Only the hex SHA-256 hash of the body
 * is persisted (privacy minimisation). Parsed fields (provider, TID, amount)
 * are stored separately and are what the matcher uses.
 *
 * Idempotency: The UNIQUE KEY (device_id, idempotency_key) prevents the same
 * SMS from being processed twice even if the Android app retries the POST.
 * Duplicate key errors (MySQL 1062) are handled silently and the caller is
 * expected to return a "duplicate" response to the device.
 *
 * @package ManualPaymentAutoVerify\Core
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Core;

use DateTimeImmutable;

/**
 * SmsRepository — CRUD for mpav_sms_messages.
 */
class SmsRepository {

    private \wpdb $wpdb;

    /** @var string Fully-qualified table name. */
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'mpav_sms_messages';
    }

    // =========================================================================
    // Write operations
    // =========================================================================

    /**
     * Inserts a new SMS record.
     *
     * Returns false on:
     *   a) Duplicate (device_id, idempotency_key) — MySQL 1062.
     *   b) Any other DB error (logged at WP_DEBUG level).
     *
     * @param array{
     *     device_id:       string,
     *     idempotency_key: string,
     *     sender_raw:      string,
     *     sender_masked:   string,
     *     body_hash:       string,
     *     parsed_provider: string,
     *     parsed_tid:      string,
     *     parsed_amount:   int|null,
     *     received_at:     string,
     * } $data Column values sanitized by the caller.
     *
     * @return int|false New SMS record ID on success; false on duplicate or error.
     */
    public function insert(array $data) {
        $insert_data = [
            'device_id'       => (string) ($data['device_id'] ?? ''),
            'idempotency_key' => (string) ($data['idempotency_key'] ?? ''),
            'sender_raw'      => (string) ($data['sender_raw'] ?? ''),
            'sender_masked'   => (string) ($data['sender_masked'] ?? ''),
            'body_hash'       => (string) ($data['body_hash'] ?? ''),
            'parsed_provider' => (string) ($data['parsed_provider'] ?? ''),
            'parsed_tid'      => (string) ($data['parsed_tid'] ?? ''),
            'received_at'     => (string) ($data['received_at'] ?? current_time('mysql', true)),
            'created_at'      => current_time('mysql', true),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if (array_key_exists('parsed_amount', $data) && null !== $data['parsed_amount']) {
            $insert_data['parsed_amount'] = (int) $data['parsed_amount'];
            $formats[]                    = '%d';
        }

        $result = $this->wpdb->insert(
            $this->table,
            $insert_data,
            $formats
        );

        if (false === $result) {
            if ($this->is_duplicate_key_error()) {
                return false;  // Expected; caller returns "duplicate" to device.
            }
            $this->log_db_error('insert');
            return false;
        }

        return (int) $this->wpdb->insert_id;
    }

    // =========================================================================
    // Read operations
    // =========================================================================

    /**
     * Finds an SMS record by primary key.
     *
     * @param int $id SMS record ID.
     *
     * @return object|null DB row or null.
     */
    public function find_by_id(int $id): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}` WHERE id = %d LIMIT 1",
            $id
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Finds an SMS record by (device_id, idempotency_key) — the idempotency check.
     *
     * Called by SmsController BEFORE inserting a new record. If a row already
     * exists, the request is a duplicate and the controller returns {status:"duplicate"}.
     *
     * @param string $device_id      Paired device UUID.
     * @param string $idempotency_key Client-generated unique key for this SMS.
     *
     * @return object|null DB row or null.
     */
    public function find_by_idempotency_key(string $device_id, string $idempotency_key): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE device_id = %s AND idempotency_key = %s
             LIMIT 1",
            $device_id,
            $idempotency_key
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Finds the newest stored SMS whose parsed provider + TID match a claim.
     *
     * Used for "reverse matching": the payment SMS normally arrives BEFORE the
     * customer submits the TID at checkout, so the matcher must also look at
     * SMS rows that were stored earlier.
     *
     * @param string $provider Provider slug.
     * @param string $tid      Normalized transaction ID.
     *
     * @return object|null DB row or null.
     */
    public function find_latest_by_parsed(string $provider, string $tid): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE parsed_provider = %s
               AND parsed_tid      = %s
               AND parsed_amount IS NOT NULL
             ORDER BY received_at DESC
             LIMIT 1",
            $provider,
            $tid
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Finds the newest stored SMS with this TID regardless of provider.
     *
     * Used by reverse matching when the customer picked a different provider
     * than the one detected from the SMS (the matcher then flags, never
     * auto-verifies, a provider mismatch).
     *
     * @param string $tid Normalized transaction ID.
     *
     * @return object|null DB row or null.
     */
    public function find_latest_by_tid(string $tid): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE parsed_tid = %s
               AND parsed_provider <> ''
               AND parsed_amount IS NOT NULL
             ORDER BY received_at DESC
             LIMIT 1",
            $tid
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Deletes SMS records older than the given cutoff (privacy/retention purge).
     *
     * @param DateTimeImmutable $cutoff Rows with received_at before this are deleted.
     *
     * @return int Rows deleted.
     */
    public function delete_older_than(DateTimeImmutable $cutoff): int {
        $cutoff_str = $cutoff->format('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->table}` WHERE received_at < %s",
                $cutoff_str
            )
        );

        return (false === $result) ? 0 : (int) $result;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Whether the last wpdb error was a duplicate key violation.
     * Supports WP 6.0 (no last_error_no) and WP 6.1+ (has last_error_no).
     */
    private function is_duplicate_key_error(): bool {
        if (isset($this->wpdb->last_error_no) && is_int($this->wpdb->last_error_no)) {
            return 1062 === $this->wpdb->last_error_no;
        }
        return false !== strpos($this->wpdb->last_error, 'Duplicate entry');
    }

    /**
     * @param string $method Calling method name for context.
     */
    private function log_db_error(string $method): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            '[MPAV] SmsRepository::%s() DB error (code %d).',
            $method,
            (int) ($this->wpdb->last_error_no ?? 0)
        ));
    }
}

