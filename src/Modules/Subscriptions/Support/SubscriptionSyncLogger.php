<?php

namespace HB\UCS\Modules\Subscriptions\Support;

use HB\UCS\Core\Settings;

if (!defined('ABSPATH')) exit;

class SubscriptionSyncLogger {
    private const SOURCE = 'hb-ucs-subscription-sync';

    private static function get_subscription_settings(): array {
        if (!function_exists('get_option')) {
            return [];
        }

        $settings = get_option(Settings::OPT_SUBSCRIPTIONS, []);

        return is_array($settings) ? $settings : [];
    }

    public static function is_enabled(): bool {
        $enabled = defined('HB_UCS_SUBSCRIPTION_SYNC_DEBUG')
            ? (bool) HB_UCS_SUBSCRIPTION_SYNC_DEBUG
            : !empty(self::get_subscription_settings()['debug_logging_enabled']);

        return (bool) apply_filters('hb_ucs_subscription_sync_debug_enabled', $enabled);
    }

    public static function debug(string $event, array $context = []): void {
        self::write('debug', $event, $context, true);
    }

    public static function info(string $event, array $context = []): void {
        self::write('info', $event, $context);
    }

    public static function warning(string $event, array $context = []): void {
        self::write('warning', $event, $context);
    }

    private static function write(string $level, string $event, array $context = [], bool $respectDebugGate = false): void {
        if ($respectDebugGate && !self::is_enabled()) {
            return;
        }

        $payload = [
            'event' => $event,
            'context' => self::normalize($context),
        ];

        $message = function_exists('wp_json_encode')
            ? (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            if (method_exists($logger, $level)) {
                $logger->{$level}($message, ['source' => self::SOURCE]);
                return;
            }

            if (method_exists($logger, 'log')) {
                $logger->log($level, $message, ['source' => self::SOURCE]);
                return;
            }

            return;
        }

        error_log(strtoupper($level) . ' ' . self::SOURCE . ' ' . $message);
    }

    private static function normalize($value) {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = self::normalize($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            if (method_exists($value, 'get_id')) {
                return [
                    'class' => get_class($value),
                    'id' => (int) $value->get_id(),
                ];
            }

            return ['class' => get_class($value)];
        }

        return (string) $value;
    }
}
