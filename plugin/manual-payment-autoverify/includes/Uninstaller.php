<?php
/**
 * Uninstaller — cleans up all plugin data on uninstall.
 *
 * Called exclusively by uninstall.php, which verifies WP_UNINSTALL_PLUGIN.
 * Respects the merchant's data-retention preference stored in
 * 'mpav_keep_data_on_uninstall'. When set to '1' (the safe default), all
 * payment records are preserved and only scheduled jobs are removed.
 *
 * Table names are built from $wpdb->prefix — never from user input — so the
 * bare interpolation in DROP TABLE is safe and intentional.
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify;

/**
 * Uninstaller.
 */
class Uninstaller {

    /**
     * Entry point called by uninstall.php.
     *
     * Never call this directly from within the plugin; it must only run on
     * the WordPress uninstall routine.
     */
    public static function run(): void {
        // Paranoia guard: WP sets this before including uninstall.php.
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }

        $keep_data = get_option('mpav_keep_data_on_uninstall', '1');

        if ('1' === $keep_data) {
            // Merchant wants to keep order data. Only clean up transient state.
            static::unschedule_jobs();
            return;
        }

        // Full wipe requested.
        static::drop_tables();
        static::delete_options();
        static::delete_upload_directory();
        static::unschedule_jobs();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Drops all plugin-owned DB tables.
     *
     * Tables are dropped in reverse dependency order (audit log first,
     * claims last) to avoid any FK constraint issues on future-proofed schemas.
     */
    private static function drop_tables(): void {
        global $wpdb;

        // Ordered: most-dependent first so potential FK constraints don't block drops.
        $tables = [
            $wpdb->prefix . 'mpav_audit_log',
            $wpdb->prefix . 'mpav_used_nonces',
            $wpdb->prefix . 'mpav_pairing_tokens',
            $wpdb->prefix . 'mpav_devices',
            $wpdb->prefix . 'mpav_sms_messages',
            $wpdb->prefix . 'mpav_claims',
        ];

        foreach ($tables as $table) {
            // Table name is composed from $wpdb->prefix + a hardcoded suffix.
            // This is safe; the PHPCS suppression is intentional.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * Deletes all plugin-owned WP options and gateway settings.
     */
    private static function delete_options(): void {
        $options = [
            'mpav_db_version',
            'mpav_sms_match_window_minutes',
            'mpav_claim_expiry_minutes',
            'mpav_sms_retention_days',
            'mpav_keep_data_on_uninstall',
            'mpav_encryption_key_id',
            'mpav_custom_regex_jazzcash',
            'mpav_custom_regex_easypaisa',
            // WC gateway settings are stored under this key by WooCommerce.
            'woocommerce_mpav_settings',
        ];

        foreach ($options as $option) {
            delete_option($option);
        }
    }

    /**
     * Recursively removes the screenshot upload directory and all its contents.
     */
    private static function delete_upload_directory(): void {
        $upload_base = wp_upload_dir();

        if (!empty($upload_base['error'])) {
            return;
        }

        $mpav_dir = trailingslashit($upload_base['basedir']) . 'mpav-screenshots';

        if (!is_dir($mpav_dir)) {
            return;
        }

        static::recursive_rmdir($mpav_dir);
    }

    /**
     * Recursively deletes a directory tree.
     *
     * Skips symlinks to avoid escaping the directory tree.
     *
     * @param string $dir Absolute path to the directory.
     */
    private static function recursive_rmdir(string $dir): void {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $items = scandir($dir);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                static::recursive_rmdir($path);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Removes all MPAV Action Scheduler recurring actions.
     */
    private static function unschedule_jobs(): void {
        $hooks = ['mpav_expire_claims', 'mpav_cleanup'];

        foreach ($hooks as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, [], 'mpav');
            } else {
                wp_clear_scheduled_hook($hook);
            }
        }
    }
}

