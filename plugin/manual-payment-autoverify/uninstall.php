<?php
/**
 * Runs on plugin uninstall.
 *
 * WordPress invokes this file directly when the user clicks "Delete" from the
 * Plugins screen. The WP_UNINSTALL_PLUGIN constant is set by WordPress before
 * the call; we exit immediately if it is not defined.
 *
 * @package ManualPaymentAutoVerify
 */

declare(strict_types=1);

// Guard: must be called by WordPress uninstall routine.
defined('WP_UNINSTALL_PLUGIN') || exit;

// PSR-4 autoloader — identical to the main plugin file so Uninstaller loads.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'ManualPaymentAutoVerify\\';
        $len    = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        $file     = __DIR__ . '/includes/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

\ManualPaymentAutoVerify\Uninstaller::run();

