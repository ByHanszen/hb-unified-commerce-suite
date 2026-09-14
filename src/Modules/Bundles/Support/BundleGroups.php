<?php
namespace HB\UCS\Modules\Bundles\Support;

if (!defined('ABSPATH')) exit;

/** Pure choice-group rules shared by cart validation and smoke tests. */
final class BundleGroups {
    /**
     * @return array<int,array{code:string,group_id:string,message:string}>
     */
    public static function validate(array $groups, array $definitions, array $selection): array {
        $groups = BundleData::normalize_groups($groups);
        if (empty($groups)) {
            return [];
        }

        $totals = [];
        $selectedRows = [];
        $errors = [];
        foreach ($groups as $groupId => $group) {
            $totals[$groupId] = 0.0;
            $selectedRows[$groupId] = 0;
        }

        foreach ($selection as $key => $selected) {
            if (!isset($definitions[$key]) || empty($definitions[$key]['id'])) {
                continue;
            }
            $groupId = sanitize_key((string) ($definitions[$key]['group_id'] ?? ''));
            if ($groupId === '') {
                continue;
            }
            if (!isset($groups[$groupId])) {
                $errors[] = self::error('unknown_group', $groupId, __('De verzonden keuzegroep bestaat niet meer.', 'hb-ucs'));
                continue;
            }
            $qty = max(0.0, (float) ($selected['qty'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $totals[$groupId] += $qty;
            $selectedRows[$groupId]++;
            $maxPerItem = $groups[$groupId]['max_per_item'];
            if ($maxPerItem !== '' && $qty > (float) $maxPerItem) {
                $errors[] = self::error('max_per_item', $groupId, sprintf(
                    __('Je mag van een keuze binnen “%s” maximaal %s toevoegen.', 'hb-ucs'),
                    self::title($groups[$groupId]),
                    wc_format_localized_decimal((float) $maxPerItem)
                ));
            }
            if (empty($groups[$groupId]['allow_duplicates']) && $qty > 1) {
                $errors[] = self::error('duplicates', $groupId, sprintf(
                    __('Iedere keuze binnen “%s” mag maximaal eenmaal worden toegevoegd.', 'hb-ucs'),
                    self::title($groups[$groupId])
                ));
            }
        }

        foreach ($groups as $groupId => $group) {
            $total = (float) ($totals[$groupId] ?? 0);
            $min = (float) $group['min'];
            $max = (float) $group['max'];
            if ($total < $min) {
                $errors[] = self::error('minimum', $groupId, sprintf(
                    __('Kies minimaal %1$s binnen “%2$s”.', 'hb-ucs'),
                    wc_format_localized_decimal($min),
                    self::title($group)
                ));
            }
            if ($total > $max) {
                $errors[] = self::error('maximum', $groupId, sprintf(
                    __('Kies maximaal %1$s binnen “%2$s”.', 'hb-ucs'),
                    wc_format_localized_decimal($max),
                    self::title($group)
                ));
            }
            if ($group['type'] === 'single' && ((int) ($selectedRows[$groupId] ?? 0) > 1 || $total > 1)) {
                $errors[] = self::error('single', $groupId, sprintf(
                    __('Kies precies één optie binnen “%s”.', 'hb-ucs'),
                    self::title($group)
                ));
            }
        }

        $unique = [];
        foreach ($errors as $error) {
            $unique[$error['code'] . ':' . $error['group_id']] = $error;
        }
        return array_values($unique);
    }

    /** @return array{code:string,group_id:string,message:string} */
    private static function error(string $code, string $groupId, string $message): array {
        return ['code' => $code, 'group_id' => $groupId, 'message' => $message];
    }

    private static function title(array $group): string {
        $title = trim((string) ($group['title'] ?? ''));
        return $title !== '' ? $title : (string) ($group['group_id'] ?? __('Keuzegroep', 'hb-ucs'));
    }
}
