<?php
/**
 * Lite / Pro feature flag gate.
 *
 * Code for Pro features MUST exist in the codebase but MUST be gated through
 * this class. This separates commercial concerns from business logic and keeps
 * the upgrade path clean without dead-code drift.
 *
 * @package ManualPaymentAutoVerify\Util
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Util;

/**
 * Feature flag registry.
 *
 * Usage:
 *   if (FeatureFlags::enabled('sms_auto_verify')) { ... }
 *
 * To activate Pro features, hook `mpav_is_pro`:
 *   add_filter('mpav_is_pro', '__return_true');
 */
final class FeatureFlags {

    /**
     * Feature → requires-Pro map.
     * false = Lite (always on); true = requires active Pro licence.
     *
     * @var array<string, bool>
     */
    private const FEATURES = [
        // Lite features.
        'manual_checkout'     => false,
        'claim_state_machine' => false,
        'admin_queue'         => false,
        'order_metabox'       => false,
        // Pro features.
        'sms_auto_verify'     => true,
        'ocr_helper'          => true,  // Helper only — NEVER sole proof.
        'multi_device'        => true,
        'advanced_parser'     => true,
        'export_reports'      => true,
        'heartbeat_tracking'  => true,
    ];

    /**
     * Whether the Pro licence is active.
     *
     * Filterable so a licence-check implementation can be plugged in without
     * modifying this class.
     */
    public static function is_pro(): bool {
        return (bool) apply_filters('mpav_is_pro', false);
    }

    /**
     * Whether a named feature is enabled for the current licence tier.
     *
     * Unknown feature names return false (safe default).
     *
     * @param string $feature One of the keys in FEATURES.
     */
    public static function enabled(string $feature): bool {
        if (!array_key_exists($feature, self::FEATURES)) {
            return false;
        }
        return !self::FEATURES[$feature] || self::is_pro();
    }
}

