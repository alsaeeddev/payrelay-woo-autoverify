<?php
/**
 * Data-access layer for the mpav_claims table.
 *
 * All queries parameterised via $wpdb->prepare().
 * Money always stored/read as integer paisa (minor units).
 *
 * Duplicate-key safety (MySQL 1062):
 *   The UNIQUE KEY (provider, tid_normalized) is the authoritative guard.
 *   create() catches the error code rather than doing a check-then-insert
 *   (which would have a TOCTOU race).
 *
 * WP 6.0 compat:
 *   $wpdb->last_error_no was introduced in WordPress 6.1.
 *   On WP 6.0 we fall back to strpos() on $wpdb->last_error.
 *
 * @package ManualPaymentAutoVerify\Core
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Core;

use DateTimeImmutable;
use ManualPaymentAutoVerify\Util\TidNormalizer;

/**
 * ClaimRepository — full CRUD for mpav_claims.
 */
class ClaimRepository {

    /** MySQL duplicate-key violation error number. */
    private const MYSQL_DUPLICATE_KEY = 1062;

    /**
     * Maximum rows returned by get_queue() regardless of per_page argument.
     * Prevents unbounded result sets.
     */
    private const MAX_PER_PAGE = 200;

    /** Allowed column names for ORDER BY — prevents SQL injection. */
    private const ALLOWED_ORDERBY = [
        'id'           => 'id',
        'created_at'   => 'created_at',
        'expires_at'   => 'expires_at',
        'status'       => 'status',
        'provider'     => 'provider',
        'amount_minor' => 'amount_minor',
        'order_id'     => 'order_id',
    ];

    private \wpdb $wpdb;

    /** @var string Fully-qualified table name. */
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'mpav_claims';
    }

    // =========================================================================
    // Write operations
    // =========================================================================

    /**
     * Inserts a new claim row and returns the new primary key.
     *
     * Returns false in two situations:
     *   a) A claim with the same (provider, tid_normalized) already exists
     *      (MySQL 1062 — duplicate key).
     *   b) Any other DB error (logged at WP_DEBUG level, no PII exposed).
     *
     * @param array{
     *     order_id:        int,
     *     provider:        string,
     *     tid_raw:         string,
     *     tid_normalized:  string,
     *     amount_minor:    int,
     *     sender_number:   string,
     *     screenshot_path: string,
     *     expires_at:      string,
     * } $data Column values sanitized by the caller.
     *
     * @return int|false New claim ID on success; false on duplicate or error.
     */
    public function create(array $data) {
        $result = $this->wpdb->insert(
            $this->table,
            [
                'order_id'        => (int)    $data['order_id'],
                'provider'        => (string) $data['provider'],
                'tid_raw'         => (string) $data['tid_raw'],
                'tid_normalized'  => (string) $data['tid_normalized'],
                'amount_minor'    => (int)    $data['amount_minor'],
                'sender_number'   => (string) ($data['sender_number']   ?? ''),
                'screenshot_path' => (string) ($data['screenshot_path'] ?? ''),
                'status'          => 'pending',
                'created_at'      => current_time('mysql', true),
                'updated_at'      => current_time('mysql', true),
                'expires_at'      => (string) $data['expires_at'],
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (false === $result) {
            if ($this->is_duplicate_key_error()) {
                return false;  // Expected race condition; caller handles.
            }
            $this->log_db_error('create');
            return false;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Updates the status of a claim.
     *
     * Also bumps updated_at. The caller (ClaimStateMachine) wraps this inside
     * a DB transaction together with an audit log write; this method does NOT
     * start its own transaction.
     *
     * @param int    $id          Claim primary key.
     * @param string $to_status   New status value.
     * @param string $from_status Expected current status (optimistic lock).
     *
     * @return int Number of rows updated (0 = concurrent modification or not found).
     */
    public function update_status_from(int $id, string $to_status, string $from_status): int {
        $result = $this->wpdb->update(
            $this->table,
            [
                'status'     => $to_status,
                'updated_at' => current_time('mysql', true),
            ],
            [
                'id'     => $id,
                'status' => $from_status,  // Optimistic lock.
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return (false === $result) ? 0 : (int) $result;
    }

    /**
     * Updates arbitrary columns on a claim row.
     *
     * Column names must be whitelisted below. Always bumps updated_at.
     *
     * @param int                    $id   Claim primary key.
     * @param array<string, mixed>   $data Column => value pairs.
     *
     * @return bool True on success, false on failure.
     */
    public function update(int $id, array $data): bool {
        // Whitelist writable columns.
        $allowed = [
            'screenshot_path', 'ocr_tid', 'matched_sms_id', 'reviewed_by', 'sender_number',
        ];

        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = current_time('mysql', true);

        $formats = array_map(
            static function ($col): string {
                return in_array($col, ['matched_sms_id', 'reviewed_by'], true) ? '%d' : '%s';
            },
            array_keys($filtered)
        );

        $result = $this->wpdb->update(
            $this->table,
            $filtered,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return false !== $result;
    }

    // =========================================================================
    // Read operations
    // =========================================================================

    /**
     * Finds a claim by primary key.
     *
     * @param int $id Claim ID.
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
     * Finds the most recent pending claim for a given order.
     *
     * @param int $order_id WooCommerce order ID.
     *
     * @return object|null DB row or null.
     */
    public function find_pending_for_order(int $order_id): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE order_id = %d AND status = 'pending'
             ORDER BY created_at DESC
             LIMIT 1",
            $order_id
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Finds all claims associated with a given order, newest first.
     *
     * @param int $order_id WooCommerce order ID.
     *
     * @return object[] Array of DB row objects.
     */
    public function find_by_order_id(int $order_id): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE order_id = %d
             ORDER BY created_at DESC",
            $order_id
        );

        return $this->wpdb->get_results($sql) ?: [];
    }

    /**
     * Finds a pending, non-expired claim matchable by (provider, tid_normalized).
     *
     * This is the first step in SMS → claim matching. The ClaimMatcherService
     * then validates amount and time window on the returned row.
     *
     * @param string $provider       Provider slug (jazzcash|easypaisa|bank).
     * @param string $tid_normalized Already-normalized TID from the SMS.
     *
     * @return object|null DB row or null.
     */
    public function find_matchable(string $provider, string $tid_normalized): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE provider       = %s
               AND tid_normalized = %s
               AND status         = 'pending'
               AND expires_at     > UTC_TIMESTAMP()
             ORDER BY created_at DESC
             LIMIT 1",
            $provider,
            $tid_normalized
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Finds a pending, non-expired claim by TID regardless of provider.
     *
     * Fallback for SMS whose detected provider differs from the provider the
     * customer selected. Callers must flag (not auto-verify) such matches.
     *
     * @param string $tid_normalized Normalized TID.
     *
     * @return object|null DB row or null.
     */
    public function find_matchable_any_provider(string $tid_normalized): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE tid_normalized = %s
               AND status         = 'pending'
               AND expires_at     > UTC_TIMESTAMP()
             ORDER BY created_at DESC
             LIMIT 1",
            $tid_normalized
        );

        return $this->wpdb->get_row($sql) ?: null;
    }

    /**
     * Returns still-pending, non-expired claims (oldest first) for reconciliation.
     *
     * @param int $limit Maximum rows.
     *
     * @return object[] Claim rows.
     */
    public function find_pending_unexpired(int $limit = 100): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE status = 'pending'
               AND expires_at > UTC_TIMESTAMP()
             ORDER BY created_at ASC
             LIMIT %d",
            max(1, $limit)
        );

        return $this->wpdb->get_results($sql) ?: [];
    }

    /**
     * Returns a paginated list of claims for the admin verification queue.
     *
     * @param array{
     *     status?:   string|string[],
     *     provider?: string,
     *     search?:   string,  Order number (exact) or TID (partial).
     *     per_page?: int,
     *     page?:     int,
     *     orderby?:  string,
     *     order?:    string,
     * } $args Query arguments.
     *
     * @return object[] Array of DB row objects.
     */
    public function get_queue(array $args = []): array {
        [$where, $values] = $this->build_queue_where($args);

        $orderby  = $this->safe_orderby($args['orderby'] ?? 'created_at');
        $order    = 'ASC' === strtoupper($args['order'] ?? 'DESC') ? 'ASC' : 'DESC';
        $per_page = min((int) ($args['per_page'] ?? 20), self::MAX_PER_PAGE);
        $page     = max(1, (int) ($args['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $sql_base = "SELECT * FROM `{$this->table}` {$where} ORDER BY {$orderby} {$order}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = empty($values)
            ? $this->wpdb->prepare($sql_base . ' LIMIT %d OFFSET %d', $per_page, $offset) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $this->wpdb->prepare($sql_base . ' LIMIT %d OFFSET %d', array_merge($values, [$per_page, $offset])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $this->wpdb->get_results($sql) ?: [];
    }

    /**
     * Returns the total count for the admin queue (matches get_queue filters).
     *
     * @param array<string, mixed> $args Same args as get_queue(), page/per_page ignored.
     *
     * @return int
     */
    public function count_queue(array $args = []): int {
        [$where, $values] = $this->build_queue_where($args);

        $sql_base = "SELECT COUNT(*) FROM `{$this->table}` {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $sql = empty($values)
            ? $sql_base
            : $this->wpdb->prepare($sql_base, $values); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return (int) $this->wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Deletes claim rows older than the given cutoff timestamp.
     *
     * Only deletes terminal states (auto_verified, rejected, expired) so that
     * in-progress claims are never purged.
     *
     * @param DateTimeImmutable $cutoff Rows with created_at before this are deleted.
     *
     * @return int Number of rows deleted.
     */
    public function delete_older_than(DateTimeImmutable $cutoff): int {
        $cutoff_str = $cutoff->format('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->table}`
                 WHERE created_at < %s
                   AND status IN ('auto_verified', 'rejected', 'expired')",
                $cutoff_str
            )
        );

        return (false === $result) ? 0 : (int) $result;
    }

    /**
     * Finds pending claims whose expiration timestamp has passed.
     *
     * Used by ExpiryJob to expire abandoned payment claims.
     *
     * @param int                      $limit      Maximum number of claims to retrieve.
     * @param DateTimeImmutable|null   $now_cutoff Optional custom cutoff time (defaults to current GMT).
     *
     * @return object[] Array of claim rows.
     */
    public function find_expired_pending(int $limit = 50, ?DateTimeImmutable $now_cutoff = null): array {
        $cutoff_str = $now_cutoff ? $now_cutoff->format('Y-m-d H:i:s') : current_time('mysql', true);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE status = 'pending'
               AND expires_at <= %s
             ORDER BY expires_at ASC
             LIMIT %d",
            $cutoff_str,
            $limit
        );

        return $this->wpdb->get_results($sql) ?: [];
    }

    /**
     * Finds terminal claims (auto_verified, rejected, expired) created before the given cutoff.
     *
     * Used by CleanupJob to delete associated screenshot files before deleting DB records.
     *
     * @param DateTimeImmutable $cutoff Cutoff timestamp.
     * @param int               $limit  Maximum rows to return.
     *
     * @return object[] Array of claim rows.
     */
    public function find_terminal_older_than(DateTimeImmutable $cutoff, int $limit = 100): array {
        $cutoff_str = $cutoff->format('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE created_at < %s
               AND status IN ('auto_verified', 'rejected', 'expired')
             LIMIT %d",
            $cutoff_str,
            $limit
        );

        return $this->wpdb->get_results($sql) ?: [];
    }

    /**
     * Anonymizes customer personal data on a claim (GDPR / Privacy erasure).
     *
     * Clears sender_number, screenshot_path, and ocr_tid.
     *
     * @param int $id Claim ID.
     *
     * @return bool True on success, false on failure.
     */
    public function anonymize_claim(int $id): bool {
        $result = $this->wpdb->update(
            $this->table,
            [
                'sender_number'   => '',
                'screenshot_path' => '',
                'ocr_tid'         => '',
                'updated_at'      => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        return false !== $result;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Builds WHERE clause + values array for queue queries.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: array<mixed>}
     */
    private function build_queue_where(array $args): array {
        $conditions = [];
        $values     = [];

        // Status filter — accept string or string[].
        if (!empty($args['status'])) {
            $statuses = (array) $args['status'];
            // Whitelist against known states.
            $valid = array_filter(
                $statuses,
                static fn(string $s): bool => in_array(
                    $s,
                    ['pending', 'auto_verified', 'flagged', 'rejected', 'expired'],
                    true
                )
            );
            if (!empty($valid)) {
                $placeholders = implode(', ', array_fill(0, count($valid), '%s'));
                $conditions[] = "status IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                array_push($values, ...$valid);
            }
        }

        // Provider filter.
        if (!empty($args['provider'])) {
            $provider = sanitize_key($args['provider']);
            if (in_array($provider, ['jazzcash', 'easypaisa', 'bank'], true)) {
                $conditions[] = 'provider = %s';
                $values[]     = $provider;
            }
        }

        // Single search box: matches order number (exact) OR transaction ID (partial).
        if (!empty($args['search'])) {
            $term = ltrim(trim((string) $args['search']), '#');

            if ('' !== $term) {
                $tid_like = '%' . $this->wpdb->esc_like(TidNormalizer::normalize($term)) . '%';

                if (ctype_digit($term) && strlen($term) <= 10) {
                    $conditions[] = '(order_id = %d OR tid_normalized LIKE %s)';
                    $values[]     = (int) $term;
                    $values[]     = $tid_like;
                } else {
                    $conditions[] = 'tid_normalized LIKE %s';
                    $values[]     = $tid_like;
                }
            }
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }

    /**
     * Returns a safe ORDER BY column name.
     *
     * @param string $column Requested column name.
     *
     * @return string Whitelisted column name, default 'created_at'.
     */
    private function safe_orderby(string $column): string {
        return self::ALLOWED_ORDERBY[$column] ?? 'created_at';
    }

    /**
     * Whether the last wpdb error was a duplicate key violation.
     *
     * Supports WP 6.0 (no last_error_no) and WP 6.1+ (has last_error_no).
     */
    private function is_duplicate_key_error(): bool {
        // WP 6.1+ sets last_error_no as an integer property.
        if (isset($this->wpdb->last_error_no) && is_int($this->wpdb->last_error_no)) {
            return self::MYSQL_DUPLICATE_KEY === $this->wpdb->last_error_no;
        }

        // WP 6.0 fallback: inspect the error string.
        return false !== strpos($this->wpdb->last_error, 'Duplicate entry');
    }

    /**
     * Logs a DB error at WP_DEBUG level. Never exposes PII or the full query.
     *
     * @param string $method Calling method name for context.
     */
    private function log_db_error(string $method): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            '[MPAV] ClaimRepository::%s() DB error (code %d).',
            $method,
            (int) ($this->wpdb->last_error_no ?? 0)
        ));
    }
}
