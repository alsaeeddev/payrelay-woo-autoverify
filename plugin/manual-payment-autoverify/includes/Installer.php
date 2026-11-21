<?php
/**
 * Installer — creates DB tables and seeds options on plugin activation.
 *
 * Uses dbDelta() for idempotent, forward-compatible schema management.
 * All tables use utf8mb4_unicode_ci for full Unicode / emoji support.
 * Money columns use BIGINT (paisa), never DECIMAL or FLOAT.
 *
 * Note on `updated_at`: dbDelta does not understand ON UPDATE CURRENT_TIMESTAMP,
 * so that clause is omitted. Repositories explicitly set updated_at = NOW() in
 * every UPDATE query instead.
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify;

use ManualPaymentAutoVerify\Uploads\ScreenshotUploader;

/**
 * Installer handles DB creation and option seeding.
 */
class Installer {

    /**
     * Full installation: creates tables, seeds options, creates the protected
     * upload directory. Called once from the activation hook.
     */
    public function install(): void {
        $this->create_tables();
        $this->seed_options();
        $this->create_upload_directory();
        update_option('mpav_db_version', MPAV_DB_VERSION, false);
    }

    /**
     * Self-healing install check. Runs on plugins_loaded (see main plugin file).
     *
     * Activation hooks do not fire when files are replaced, when a host copies the
     * plugin in already-active, or when activation fails half way. This re-runs
     * the table creation whenever the stored DB version is stale OR a core table
     * is missing, throttled to once per 5 minutes. Any failure is stored in the
     * `mpav_install_error` option and shown as an admin notice.
     */
    public function ensure_installed(): void {
        $current = (string) get_option('mpav_db_version', '');
        if (MPAV_DB_VERSION === $current && $this->core_tables_exist()) {
            return;
        }
        if (false !== get_transient('mpav_install_lock')) {
            return;
        }
        set_transient('mpav_install_lock', 1, 5 * MINUTE_IN_SECONDS);

        global $wpdb;
        $wpdb->last_error = '';
        $this->create_tables();
        $this->seed_options();

        if ($this->core_tables_exist()) {
            update_option('mpav_db_version', MPAV_DB_VERSION, false);
            delete_option('mpav_install_error');
            $this->create_upload_directory();
        } else {
            update_option(
                'mpav_install_error',
                'Could not create tables. DB error: ' . ('' !== $wpdb->last_error ? $wpdb->last_error : 'none reported (check that the DB user has CREATE privilege)'),
                false
            );
        }
    }

    /**
     * True when every plugin table exists.
     */
    public function core_tables_exist(): bool {
        global $wpdb;
        foreach (['claims', 'sms_messages', 'devices', 'pairing_tokens', 'used_nonces', 'audit_log'] as $name) {
            $table = $wpdb->prefix . 'mpav_' . $name;
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Version-gated upgrade path.
     *
     * Called from Plugin::run() on every page load when the stored DB version
     * is behind MPAV_DB_VERSION. dbDelta is idempotent so re-running it is safe.
     *
     * @param string $from_version Version currently stored in the DB.
     */
    public function maybe_upgrade(string $from_version): void {
        // For v1 there is only one schema version; just (re)create tables.
        $this->create_tables();
        update_option('mpav_db_version', MPAV_DB_VERSION, false);
    }

    // =========================================================================
    // DB tables
    // =========================================================================

    /**
     * Creates or upgrades all plugin DB tables via dbDelta.
     *
     * dbDelta requirements (from WP docs):
     *   - Two spaces between column name and column type.
     *   - PRIMARY KEY must be on its own line.
     *   - KEY (not INDEX) for secondary indexes.
     *   - No trailing commas before closing parenthesis.
     */
    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Claims ────────────────────────────────────────────────────────────
        // UNIQUE KEY (provider, tid_normalized) is the authoritative duplicate
        // guard. ClaimRepository catches MySQL 1062 instead of doing a
        // check-then-insert (which would have a TOCTOU race condition).
        dbDelta("
CREATE TABLE {$wpdb->prefix}mpav_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  tid_raw VARCHAR(128) NOT NULL,
  tid_normalized VARCHAR(128) NOT NULL,
  amount_minor BIGINT NOT NULL,
  sender_number VARCHAR(24) NOT NULL DEFAULT '',
  screenshot_path VARCHAR(512) NOT NULL DEFAULT '',
  ocr_tid VARCHAR(128) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  matched_sms_id BIGINT UNSIGNED DEFAULT NULL,
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uk_provider_tid (provider,tid_normalized),
  KEY idx_order (order_id),
  KEY idx_status (status),
  KEY idx_expires (expires_at)
) $charset_collate;
");

        // ── SMS Messages ──────────────────────────────────────────────────────
        // body_hash stores hex(SHA-256(original body)).
        // The plaintext SMS body is NEVER stored (privacy minimization).
        dbDelta("
CREATE TABLE {$wpdb->prefix}mpav_sms_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(64) NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  sender_raw VARCHAR(128) NOT NULL,
  sender_masked VARCHAR(128) NOT NULL,
  body_hash CHAR(64) NOT NULL,
  parsed_provider VARCHAR(32) NOT NULL DEFAULT '',
  parsed_tid VARCHAR(128) NOT NULL DEFAULT '',
  parsed_amount BIGINT DEFAULT NULL,
  received_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uk_device_idem (device_id,idempotency_key),
  KEY idx_parsed (parsed_provider,parsed_tid),
  KEY idx_received (received_at)
) $charset_collate;
");

        // ── Devices ───────────────────────────────────────────────────────────
        // encrypted_secret = "keyid:base64(nonce+ciphertext)" — libsodium/OpenSSL.
        // device_meta stores JSON diagnostics (app_version, battery_optimized).
        // device_uuid = stable per-install ID sent by the Android app at pairing, so
        // re-pairing the same phone reuses its row instead of adding a new one (NULL for
        // devices paired by older app versions).
        dbDelta("
CREATE TABLE {$wpdb->prefix}mpav_devices (
  device_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_name VARCHAR(128) NOT NULL,
  device_uuid VARCHAR(64) DEFAULT NULL,
  encrypted_secret TEXT NOT NULL,
  device_meta TEXT DEFAULT NULL,
  last_seen_at DATETIME DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (device_id),
  UNIQUE KEY uk_device_uuid (device_uuid),
  KEY idx_revoked (revoked_at)
) $charset_collate;
");

        // ── Pairing Tokens ────────────────────────────────────────────────────
        // token_hash = hex(SHA-256(raw_token)). Raw token never stored.
        dbDelta("
CREATE TABLE {$wpdb->prefix}mpav_pairing_tokens (
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (token_hash),
  KEY idx_expires (expires_at)
) $charset_collate;
");

        // ── Used Webhook Nonces ───────────────────────────────────────────────
        // Prevents replay attacks. Rows expire after timestamp + 300 seconds.
        dbDelta("
CREATE TABLE {$wpdb->prefix}mpav_used_nonces (
  nonce VARCHAR(128) NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY  (nonce),
  KEY idx_expires (expires_at)
) $charset_collate;
");

        // ── Audit / State Log ─────────────────────────────────────────────────
        // Immutable record of every claim state transition.
        // context column holds JSON — no raw PII, masked values only.
        dbDelta("
CREATE TABLE {$wpdb->prefix}mpav_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id BIGINT UNSIGNED DEFAULT NULL,
  order_id BIGINT UNSIGNED DEFAULT NULL,
  actor VARCHAR(64) NOT NULL DEFAULT 'system',
  event VARCHAR(64) NOT NULL,
  context TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_claim (claim_id),
  KEY idx_created (created_at)
) $charset_collate;
");
    }

    // =========================================================================
    // Options
    // =========================================================================

    /**
     * Seeds default plugin options.
     *
     * Uses add_option() so existing values are never overwritten on re-activation.
     */
    private function seed_options(): void {
        $defaults = [
            'mpav_sms_match_window_minutes' => 60,
            'mpav_claim_expiry_minutes'     => 1440,  // 24 hours.
            'mpav_sms_retention_days'       => 90,
            'mpav_keep_data_on_uninstall'   => '1',   // '1' = keep data (safe default).
            'mpav_custom_regex_jazzcash'    => '',
            'mpav_custom_regex_easypaisa'   => '',
        ];

        foreach ($defaults as $key => $value) {
            add_option($key, $value, '', false);
        }
    }

    // =========================================================================
    // Upload directory
    // =========================================================================

    /**
     * Creates the protected screenshot upload directory.
     *
     * Screenshots are stored outside the normal WP uploads URL structure to
     * prevent direct HTTP access. The directory is served exclusively through
     * ProtectedFileServer (Stage 7).
     */
    private function create_upload_directory(): void {
        $upload_base = wp_upload_dir();

        if (!empty($upload_base['error'])) {
            // wp_upload_dir() error; non-fatal at install time.
            return;
        }

        $mpav_dir = trailingslashit($upload_base['basedir']) . ScreenshotUploader::UPLOAD_SUBDIR;

        if (!wp_mkdir_p($mpav_dir)) {
            return;
        }

        $uploader = new ScreenshotUploader();
        $uploader->ensure_directory_security($mpav_dir);
    }
}

