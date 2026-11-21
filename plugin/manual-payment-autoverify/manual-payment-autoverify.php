<?php
/**
 * Plugin Name:       Manual Payment Auto-Verify
 * Plugin URI:        https://alsaeeddev.com/resources
 * Description:       Pakistan-focused manual payment gateway (JazzCash, Easypaisa, bank) with SMS-based auto-verification via companion Android app.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Al Saeed
 * Author URI:        https://alsaeeddev.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       manual-payment-autoverify
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// ─── Plugin constants ─────────────────────────────────────────────────────────
define('MPAV_VERSION',    '1.0.0');
define('MPAV_DB_VERSION', '2');
define('MPAV_FILE',       __FILE__);
define('MPAV_DIR',        plugin_dir_path(__FILE__));
define('MPAV_URL',        plugin_dir_url(__FILE__));
define('MPAV_SLUG',       'manual-payment-autoverify');
define('MPAV_PREFIX',     'mpav');

// ─── PSR-4 autoloader ────────────────────────────────────────────────────────
// Prefers Composer autoloader; falls back to a lightweight inline loader so the
// plugin works without running `composer install` in development.
if (file_exists(MPAV_DIR . 'vendor/autoload.php')) {
    require_once MPAV_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'ManualPaymentAutoVerify\\';
        $len    = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        $file     = MPAV_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

// ─── HPOS (High-Performance Order Storage) compatibility ─────────────────────
// Must fire before WooCommerce reads feature declarations, hence this action.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            MPAV_FILE,
            true
        );
    }
});

// ─── Block checkout compatibility ────────────────────────────────────────────
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            MPAV_FILE,
            true
        );
    }
});

// ─── Activation ──────────────────────────────────────────────────────────────
register_activation_hook(MPAV_FILE, static function (): void {
    if (!class_exists('WooCommerce')) {
        wp_die(
            esc_html__(
                'Manual Payment Auto-Verify requires WooCommerce to be installed and active.',
                'manual-payment-autoverify'
            ),
            esc_html__('Activation Error', 'manual-payment-autoverify'),
            ['back_link' => true]
        );
    }
    (new \ManualPaymentAutoVerify\Installer())->install();
    flush_rewrite_rules();
});

// ─── Self-healing DB install ─────────────────────────────────────────────────
// Creates missing tables even if the activation hook never ran (live servers,
// file-copy installs, failed activations) and reports problems to the admin.
add_action('plugins_loaded', static function (): void {
    (new \ManualPaymentAutoVerify\Installer())->ensure_installed();
});
add_action('admin_notices', static function (): void {
    $err = get_option('mpav_install_error', '');
    if ('' === $err || !current_user_can('manage_options')) {
        return;
    }
    printf(
        '<div class="notice notice-error"><p><strong>Manual Payment Auto-Verify:</strong> %s</p></div>',
        esc_html((string) $err)
    );
});

// ─── Deactivation ────────────────────────────────────────────────────────────
register_deactivation_hook(MPAV_FILE, static function (): void {
    // Unschedule recurring jobs via Action Scheduler.
    if (class_exists(\ManualPaymentAutoVerify\Scheduler\SchedulerRegistrar::class)) {
        (new \ManualPaymentAutoVerify\Scheduler\SchedulerRegistrar())->unschedule_all();
    }
    flush_rewrite_rules();
});

// ─── Plugins page "Settings" link ────────────────────────────────────────────
add_filter(
    'plugin_action_links_' . plugin_basename(MPAV_FILE),
    static function (array $links): array {
        $url  = admin_url('admin.php?page=wc-settings&tab=checkout&section=mpav');
        $link = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Settings', 'manual-payment-autoverify')
        );
        array_unshift($links, $link);
        return $links;
    }
);

// ─── Bootstrap ───────────────────────────────────────────────────────────────
// Runs after WooCommerce is fully loaded so all WC classes/hooks are available.
add_action('woocommerce_loaded', static function (): void {
    \ManualPaymentAutoVerify\Plugin::instance()->run();
});

// ─── Missing WooCommerce admin notice ────────────────────────────────────────
add_action('admin_notices', static function (): void {
    if (class_exists('WooCommerce')) {
        return;
    }
    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__(
            'Manual Payment Auto-Verify for WooCommerce requires WooCommerce to be installed and active.',
            'manual-payment-autoverify'
        )
    );
});

