<?php
/**
 * Claim state machine — enforces valid transitions and writes atomic audit logs.
 *
 * Valid state graph:
 *
 *   pending ──────────────────► auto_verified  (terminal)
 *   pending ──► flagged ──────► auto_verified  (terminal, manual approval)
 *   pending ──► flagged ──────► rejected       (terminal)
 *   pending ──► expired                        (terminal, job-driven)
 *
 * Rules:
 *   • No transition from a terminal state (auto_verified, rejected, expired).
 *   • pending → rejected is NOT allowed; must pass through flagged first.
 *   • Every transition is recorded atomically in mpav_audit_log.
 *   • The DB UPDATE uses an optimistic lock (WHERE status = $from_state) so
 *     concurrent transitions on the same claim are detected and rejected.
 *   • WC order side-effects (complete, cancel, email) are NOT performed here;
 *     they are handled by subscribers to the `mpav_claim_transitioned` action
 *     hook that fires after COMMIT.
 *
 * Context array keys (all optional, stored in audit_log.context as JSON):
 *   actor          string   'system' | 'wp:123' | 'device:uuid'
 *   matched_sms_id int      SMS record ID (auto_verified path)
 *   reviewed_by    int      WP user ID (admin-initiated transitions)
 *   note           string   Free-text reason (sanitized before storage)
 *   amount_minor   int      Matched amount in paisa (audit only)
 *   provider       string   Provider slug (audit only)
 *   tid_normalized string   Normalised TID (audit only)
 *
 * @package ManualPaymentAutoVerify\Core
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Core;

/**
 * ClaimStateMachine.
 */
class ClaimStateMachine {

    // ── State graph ───────────────────────────────────────────────────────────

    private const ALL_STATES = [
        'pending',
        'auto_verified',
        'flagged',
        'rejected',
        'expired',
    ];

    /** States from which no outgoing transitions are permitted. */
    private const TERMINAL_STATES = [
        'auto_verified',
        'rejected',
        'expired',
    ];

    /**
     * Allowed transitions: from_state => [allowed_to_states].
     * Any (from, to) pair not listed here is forbidden.
     *
     * @var array<string, string[]>
     */
    private const TRANSITIONS = [
        'pending' => ['auto_verified', 'flagged', 'expired'],
        'flagged' => ['auto_verified', 'rejected'],
    ];

    // ── Dependencies ──────────────────────────────────────────────────────────

    private \wpdb $wpdb;
    private string $claims_table;
    private string $audit_table;
    private ClaimRepository $repository;

    public function __construct(ClaimRepository $repository) {
        global $wpdb;
        $this->wpdb         = $wpdb;
        $this->claims_table = $wpdb->prefix . 'mpav_claims';
        $this->audit_table  = $wpdb->prefix . 'mpav_audit_log';
        $this->repository   = $repository;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Attempts to transition a claim to a new state.
     *
     * Atomicity guarantee:
     *   The status UPDATE and the audit log INSERT are wrapped in a single DB
     *   transaction. If either fails the entire operation is rolled back.
     *   All tables use InnoDB, so this is fully ACID-compliant.
     *
     * @param int    $claim_id Claim primary key.
     * @param string $to_state Target state.
     * @param array<string, mixed> $context Metadata for the audit log (no raw PII).
     *
     * @return bool True on success; false if the transition is invalid, the
     *              claim does not exist, or a concurrent modification was detected.
     */
    public function transition(int $claim_id, string $to_state, array $context = []): bool {
        // ── Load current claim ────────────────────────────────────────────────
        $claim = $this->repository->find_by_id($claim_id);
        if (!$claim) {
            return false;
        }

        $from_state = (string) $claim->status;

        // ── Guard: validate the transition ────────────────────────────────────
        if (!$this->can_transition($from_state, $to_state)) {
            return false;
        }

        // ── Build the UPDATE column set ───────────────────────────────────────
        // Additional columns updated atomically with the status change.
        $set_data    = [
            'status'     => $to_state,
            'updated_at' => current_time('mysql', true),
        ];
        $set_formats = ['%s', '%s'];

        if ('auto_verified' === $to_state && !empty($context['matched_sms_id'])) {
            $set_data['matched_sms_id'] = (int) $context['matched_sms_id'];
            $set_formats[]              = '%d';
        }

        if (!empty($context['reviewed_by'])) {
            $set_data['reviewed_by'] = (int) $context['reviewed_by'];
            $set_formats[]           = '%d';
        }

        // ── Transaction ───────────────────────────────────────────────────────
        $this->wpdb->query('START TRANSACTION');

        // Optimistic lock: WHERE id = ? AND status = $from_state
        // If another request already changed the status, rows_updated = 0.
        $rows_updated = $this->wpdb->update(
            $this->claims_table,
            $set_data,
            ['id' => $claim_id, 'status' => $from_state],
            $set_formats,
            ['%d', '%s']
        );

        if (false === $rows_updated) {
            // DB error.
            $this->wpdb->query('ROLLBACK');
            $this->log_db_error('transition', 'claims UPDATE');
            return false;
        }

        if (0 === $rows_updated) {
            // Another request changed the status between our SELECT and UPDATE.
            $this->wpdb->query('ROLLBACK');
            return false;
        }

        // ── Write audit log ───────────────────────────────────────────────────
        $actor      = $this->extract_actor($context);
        $safe_ctx   = $this->sanitize_context($context);

        $logged = $this->wpdb->insert(
            $this->audit_table,
            [
                'claim_id'   => $claim_id,
                'order_id'   => (int) $claim->order_id,
                'actor'      => $actor,
                'event'      => sprintf('transition:%s->%s', $from_state, $to_state),
                'context'    => (string) wp_json_encode($safe_ctx, JSON_UNESCAPED_UNICODE),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );

        if (false === $logged) {
            $this->wpdb->query('ROLLBACK');
            $this->log_db_error('transition', 'audit INSERT');
            return false;
        }

        $this->wpdb->query('COMMIT');

        // ── Fire after COMMIT — side-effects are outside the transaction ──────
        // Subscribers must NOT start their own transactions that depend on this
        // one (they would be in a new transaction). They should treat this hook
        // as a notification only.
        do_action('mpav_claim_transitioned', $claim_id, $from_state, $to_state, $context, $claim);

        return true;
    }

    /**
     * Whether a given (from, to) pair is a valid transition.
     *
     * @param string $from Current state.
     * @param string $to   Desired state.
     *
     * @return bool
     */
    public static function can_transition(string $from, string $to): bool {
        // Both states must exist.
        if (!in_array($from, self::ALL_STATES, true)) {
            return false;
        }
        if (!in_array($to, self::ALL_STATES, true)) {
            return false;
        }

        // Cannot leave a terminal state.
        if (in_array($from, self::TERMINAL_STATES, true)) {
            return false;
        }

        // Must be in the allowed transition table.
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Returns true if the given state is a terminal state.
     *
     * @param string $state State identifier.
     * @return bool
     */
    public static function is_terminal(string $state): bool {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    /**
     * Returns all valid claim state identifiers.
     *
     * @return string[]
     */
    public static function valid_states(): array {
        return self::ALL_STATES;
    }

    /**
     * Returns the full transition table (from_state => [allowed_to_states]).
     *
     * @return array<string, string[]>
     */
    public static function valid_transitions(): array {
        return self::TRANSITIONS;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Extracts and validates the actor string from the context.
     *
     * Allowed formats: 'system', 'wp:{int}', 'device:{uuid_chars}'.
     * Anything else is replaced with 'system' (safe default).
     *
     * @param array<string, mixed> $context
     *
     * @return string
     */
    private function extract_actor(array $context): string {
        $actor = sanitize_text_field((string) ($context['actor'] ?? 'system'));

        // Anchored + bounded — not vulnerable to ReDoS.
        if (preg_match('/^(system|wp:\d{1,20}|device:[a-f0-9\-]{1,64})$/', $actor)) {
            return $actor;
        }

        return 'system';
    }

    /**
     * Returns a PII-free copy of the context array safe for JSON storage in the
     * audit log. Strips unknown keys; keeps only the documented safe set.
     *
     * @param array<string, mixed> $context Raw context from the caller.
     *
     * @return array<string, mixed>
     */
    private function sanitize_context(array $context): array {
        $safe = [];

        // actor — already validated by extract_actor(); include sanitised form.
        if (isset($context['actor'])) {
            $safe['actor'] = $this->extract_actor($context);
        }

        // Integer columns: safe as-is after cast.
        foreach (['matched_sms_id', 'reviewed_by', 'amount_minor'] as $int_key) {
            if (isset($context[$int_key])) {
                $safe[$int_key] = (int) $context[$int_key];
            }
        }

        // Bounded string fields that contain no PII.
        if (isset($context['provider'])) {
            $safe['provider'] = sanitize_key((string) $context['provider']);
        }

        if (isset($context['tid_normalized'])) {
            // TID is not PII; it is an opaque reference number.
            $val = preg_replace('/[^A-Z0-9\-]/i', '', (string) $context['tid_normalized']);
            $safe['tid_normalized'] = substr((string) $val, 0, 128);
        }

        // Admin note — sanitize but allow free text.
        if (isset($context['note'])) {
            $safe['note'] = substr(sanitize_text_field((string) $context['note']), 0, 255);
        }

        return $safe;
    }

    /**
     * Logs a DB error at WP_DEBUG level.
     *
     * @param string $method     Calling method name.
     * @param string $operation  Human-readable operation for context.
     */
    private function log_db_error(string $method, string $operation): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            '[MPAV] ClaimStateMachine::%s() DB error during %s.',
            $method,
            $operation
        ));
    }
}

