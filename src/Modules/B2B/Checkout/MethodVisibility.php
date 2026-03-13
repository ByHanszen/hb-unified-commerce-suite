<?php
// =============================
// src/Modules/B2B/Checkout/MethodVisibility.php
// =============================
namespace HB\UCS\Modules\B2B\Checkout;

use HB\UCS\Modules\B2B\Storage\CustomerRulesStore;
use HB\UCS\Modules\B2B\Storage\ProfilesStore;
use HB\UCS\Modules\B2B\Storage\RoleRulesStore;
use HB\UCS\Modules\B2B\Storage\SettingsStore;
use HB\UCS\Modules\B2B\Support\Context;

if (!defined('ABSPATH')) exit;

class MethodVisibility {
    private SettingsStore $settings;
    private RoleRulesStore $roleRules;
    private CustomerRulesStore $customerRules;
    private ProfilesStore $profiles;

    public function __construct(SettingsStore $settings) {
        $this->settings = $settings;
        $this->roleRules = new RoleRulesStore();
        $this->customerRules = new CustomerRulesStore();
        $this->profiles = new ProfilesStore();
    }

    public function filter_package_rates(array $rates, array $package): array {
        if (!Context::is_admin_safe_context()) return $rates;

        $user_id = Context::get_effective_user_id();
        $allowed = $this->effective_allowed_methods($user_id, 'shipping');
        if ($allowed === null) return $rates;

        $filtered = $this->filter_shipping_rates_by_allowed($rates, $allowed);
        if (!empty($filtered)) return $this->order_shipping_rates_by_allowed($filtered, $allowed);

        // Graceful fallback if everything got filtered out.
        $settings = $this->settings->get();
        $fallback = (string)($settings['graceful_fallback_methods'] ?? 'all');
        if ($fallback === 'none') return [];
        if ($fallback === 'default') {
            $defaults = (array)($settings['default_allowed_shipping'] ?? []);
            $fallbackRates = $this->filter_shipping_rates_by_allowed($rates, $defaults);
            if (!empty($fallbackRates)) return $this->order_shipping_rates_by_allowed($fallbackRates, $defaults);
        }
        return $rates;
    }

    public function filter_payment_gateways(array $gateways): array {
        if (!Context::is_admin_safe_context()) return $gateways;

        $user_id = Context::get_effective_user_id();
        $allowed = $this->effective_allowed_methods($user_id, 'payment');
        if ($allowed === null) return $gateways;

        $filtered = [];
        foreach ($gateways as $id => $gw) {
            $id = (string) $id;
            if (in_array($id, $allowed, true)) {
                $filtered[$id] = $gw;
            }
        }

        if (!empty($filtered)) return $this->order_payment_gateways_by_allowed($filtered, $allowed);

        $settings = $this->settings->get();
        $fallback = (string)($settings['graceful_fallback_methods'] ?? 'all');
        if ($fallback === 'none') return [];
        if ($fallback === 'default') {
            $defaults = (array)($settings['default_allowed_payments'] ?? []);
            foreach ($gateways as $id => $gw) {
                if (in_array((string)$id, $defaults, true)) {
                    $filtered[(string)$id] = $gw;
                }
            }
            if (!empty($filtered)) return $this->order_payment_gateways_by_allowed($filtered, $defaults);
        }

        return $gateways;
    }

    /**
     * Reorder shipping rates to match the admin-selected whitelist order.
     * Keeps the existing allow/deny behavior intact.
     */
    private function order_shipping_rates_by_allowed(array $rates, array $allowed): array {
        $allowed = array_values(array_unique(array_map('strval', is_array($allowed) ? $allowed : [])));
        if (empty($allowed) || empty($rates)) return $rates;

        $orderMap = [];
        foreach ($allowed as $i => $key) {
            $key = (string) $key;
            if ($key === '') continue;
            if (!isset($orderMap[$key])) {
                $orderMap[$key] = (int) $i;
            }
        }

        $meta = [];
        $pos = 0;
        foreach ($rates as $rate_key => $rate) {
            $rate_key = (string) $rate_key;
            $method_id = is_object($rate) && method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '';

            $instance_key = '';
            if (is_object($rate) && method_exists($rate, 'get_instance_id')) {
                $iid = (int) $rate->get_instance_id();
                if ($method_id !== '' && $iid > 0) {
                    $instance_key = $method_id . ':' . $iid;
                }
            }

            $score = 999999;

            // Prefer explicit instance keys, then type keys.
            if ($rate_key !== '' && isset($orderMap[$rate_key])) {
                $score = (int) $orderMap[$rate_key] * 10;
            } elseif ($instance_key !== '' && isset($orderMap[$instance_key])) {
                $score = (int) $orderMap[$instance_key] * 10;
            } elseif ($method_id !== '' && isset($orderMap[$method_id])) {
                $score = ((int) $orderMap[$method_id] * 10) + 5;
            }

            $meta[$rate_key] = ['score' => $score, 'pos' => $pos];
            $pos++;
        }

        uksort($rates, function ($a, $b) use ($meta) {
            $ma = $meta[(string) $a] ?? ['score' => 999999, 'pos' => 0];
            $mb = $meta[(string) $b] ?? ['score' => 999999, 'pos' => 0];
            if ($ma['score'] === $mb['score']) {
                return $ma['pos'] <=> $mb['pos'];
            }
            return $ma['score'] <=> $mb['score'];
        });

        return $rates;
    }

    /** Reorder payment gateways to match the admin-selected whitelist order. */
    private function order_payment_gateways_by_allowed(array $gateways, array $allowed): array {
        $allowed = array_values(array_unique(array_map('strval', is_array($allowed) ? $allowed : [])));
        if (empty($allowed) || empty($gateways)) return $gateways;

        $ordered = [];
        foreach ($allowed as $id) {
            $id = (string) $id;
            if ($id === '') continue;
            if (isset($gateways[$id])) {
                $ordered[$id] = $gateways[$id];
            }
        }

        // Append any remaining gateways (should normally be none after filtering).
        foreach ($gateways as $id => $gw) {
            $id = (string) $id;
            if (!isset($ordered[$id])) {
                $ordered[$id] = $gw;
            }
        }

        return $ordered;
    }

    /** Returns array<string> allowed ids or null when no filtering should occur. */
    private function effective_allowed_methods(int $user_id, string $type): ?array {
        $s = $this->settings->get();

        // Guests / no user.
        if ($user_id <= 0) {
            $guestBehavior = (string)($s['guest_behavior'] ?? 'inherit_default');

            if ($guestBehavior === 'woocommerce_all') {
                return null;
            }

            if ($guestBehavior === 'whitelist') {
                return $type === 'shipping'
                    ? (array)($s['guest_allowed_shipping'] ?? [])
                    : (array)($s['guest_allowed_payments'] ?? []);
            }

            // inherit_default
            if (($s['default_behavior'] ?? 'woocommerce_all') === 'whitelist') {
                return $type === 'shipping'
                    ? (array)($s['default_allowed_shipping'] ?? [])
                    : (array)($s['default_allowed_payments'] ?? []);
            }

            return null;
        }

        // Customer config (direct rules and/or linked profiles)
        $customer = $this->customerRules->get($user_id);
        $customerProfiles = $this->profiles->profiles_for_user($user_id);
        $customerHasConfig = !empty($customer) || !empty($customerProfiles);

        $customerAllowed = $this->merge_customer_allowed_with_profiles($user_id, $type, $customer, $customerProfiles);

        // Role-level allowed.
        $roleAllowed = $this->merge_roles_allowed_with_profiles($user_id, $type);

        $mode = (string)($s['customer_overrule_mode'] ?? 'override');
        if ($mode === 'override' && $customerHasConfig) {
            return $customerAllowed;
        }
        if ($mode === 'merge' && $customerHasConfig) {
            return $this->merge_sets($roleAllowed, $customerAllowed, (string)($s['roles_merge'] ?? 'union'));
        }

        // No customer config: use role rules when they exist.
        if ($roleAllowed !== null) {
            return $roleAllowed;
        }

        // Default behavior for normal B2C users.
        if (($s['default_behavior'] ?? 'woocommerce_all') === 'whitelist') {
            return $type === 'shipping'
                ? (array)($s['default_allowed_shipping'] ?? [])
                : (array)($s['default_allowed_payments'] ?? []);
        }

        return null;
    }

    private function merge_roles_allowed_with_profiles(int $user_id, string $type): ?array {
        $user = get_user_by('id', $user_id);
        if (!$user) return null;

        $roles = (array) $user->roles;
        if (empty($roles)) return null;

        $s = $this->settings->get();
        $mergeMode = (string)($s['roles_merge'] ?? 'union');

        $acc = null;
        $hasAnyRoleConfig = false;
        foreach ($roles as $role) {
            $role = (string) $role;
            $rule = $this->roleRules->get($role);

            $set = [];
            if (is_array($rule)) {
                $set = $type === 'shipping'
                    ? (array)($rule['allowed_shipping'] ?? [])
                    : (array)($rule['allowed_payments'] ?? []);
            }

            // Add profiles linked to this role.
            $roleProfiles = $this->profiles->profiles_for_role($role);
            foreach ($roleProfiles as $p) {
                if (!is_array($p)) continue;
                $pSet = $type === 'shipping'
                    ? (array)($p['allowed_shipping'] ?? [])
                    : (array)($p['allowed_payments'] ?? []);
                $set = array_values(array_unique(array_merge($set, $pSet)));
            }

            // Only treat this role as configured if it has explicit allows (direct or via profiles).
            if (!empty($set) || !empty($roleProfiles)) {
                $hasAnyRoleConfig = true;
                $acc = $this->merge_sets($acc, $set, $mergeMode);
            }
        }

        if (!$hasAnyRoleConfig) return null;
        return $acc;
    }

    private function merge_customer_allowed_with_profiles(int $user_id, string $type, array $customer, array $profiles): array {
        $set = $type === 'shipping'
            ? (array)($customer['allowed_shipping'] ?? [])
            : (array)($customer['allowed_payments'] ?? []);

        foreach ($profiles as $p) {
            if (!is_array($p)) continue;
            $pSet = $type === 'shipping'
                ? (array)($p['allowed_shipping'] ?? [])
                : (array)($p['allowed_payments'] ?? []);
            $set = array_values(array_unique(array_merge($set, $pSet)));
        }

        return $set;
    }

    private function merge_sets($a, $b, string $mode): array {
        $a = is_array($a) ? array_values(array_unique(array_map('strval', $a))) : null;
        $b = is_array($b) ? array_values(array_unique(array_map('strval', $b))) : [];

        if ($a === null) {
            return $b;
        }

        if ($mode === 'intersection') {
            return array_values(array_intersect($a, $b));
        }

        return array_values(array_unique(array_merge($a, $b)));
    }

    private function filter_shipping_rates_by_allowed(array $rates, array $allowed): array {
        $allowed = array_values(array_unique(array_map('strval', $allowed)));
        if (empty($allowed)) return [];

        $allowed_types = [];
        $allowed_instances = [];
        $instances_by_type = [];

        foreach ($allowed as $key) {
            $key = (string) $key;
            if ($key === '') continue;

            // Instance keys look like "flat_rate:3".
            if (strpos($key, ':') !== false) {
                $allowed_instances[$key] = true;
                $parts = explode(':', $key, 2);
                $type = (string) ($parts[0] ?? '');
                if ($type !== '') {
                    $instances_by_type[$type] = $instances_by_type[$type] ?? [];
                    $instances_by_type[$type][$key] = true;
                }
                continue;
            }

            $allowed_types[$key] = true;
        }

        $filtered = [];
        foreach ($rates as $rate_key => $rate) {
            $rate_key = (string) $rate_key;
            $method_id = is_object($rate) && method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '';

            $instance_key = '';
            if (is_object($rate) && method_exists($rate, 'get_instance_id')) {
                $iid = (int) $rate->get_instance_id();
                if ($method_id !== '' && $iid > 0) {
                    $instance_key = $method_id . ':' . $iid;
                }
            }

            // 1) Explicit instance allow.
            if ($rate_key !== '' && isset($allowed_instances[$rate_key])) {
                $filtered[$rate_key] = $rate;
                continue;
            }
            if ($instance_key !== '' && isset($allowed_instances[$instance_key])) {
                $filtered[$rate_key] = $rate;
                continue;
            }

            // 2) Type allow (only when no instance restriction exists for that type).
            if ($method_id !== '' && isset($allowed_types[$method_id])) {
                if (empty($instances_by_type[$method_id])) {
                    $filtered[$rate_key] = $rate;
                    continue;
                }
            }
        }

        return $filtered;
    }
}
