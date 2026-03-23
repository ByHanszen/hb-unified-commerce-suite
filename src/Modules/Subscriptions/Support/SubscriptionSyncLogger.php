<?php

namespace HB\UCS\Modules\Subscriptions\Support;

if (!defined('ABSPATH')) exit;

class SubscriptionSyncLogger {
    private const SOURCE = 'hb-ucs-subscription-sync';

    public static function is_enabled(): bool {
        $enabled = defined('HB_UCS_SUBSCRIPTION_SYNC_DEBUG')
            ? (bool) HB_UCS_SUBSCRIPTION_SYNC_DEBUG
            : false;

        return (bool) apply_filters('hb_ucs_subscription_sync_debug_enabled', $enabled);
    }

    public static function debug(string $event, array $context = []): void {
        if (!self::is_enabled()) {
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
            wc_get_logger()->debug($message, ['source' => self::SOURCE]);
            return;
        }

        error_log(self::SOURCE . ' ' . $message);
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
