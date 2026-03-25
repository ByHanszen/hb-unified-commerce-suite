<?php
// =============================
// src/Modules/OrderOverviewStatus/Storage/SettingsStore.php
// =============================
namespace HB\UCS\Modules\OrderOverviewStatus\Storage;

use HB\UCS\Core\Settings as CoreSettings;

if (!defined('ABSPATH')) exit;

class SettingsStore {
    public const OPT = CoreSettings::OPT_ORDER_OVERVIEW_STATUS;
    public const DEFAULT_COLOR = 'processing';
    public const MAX_LABEL_LENGTH = 28;

    public function ensure_defaults(): void {
        add_option(self::OPT, $this->defaults());
    }

    public function get(): array {
        $stored = get_option(self::OPT, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = array_replace_recursive($this->defaults(), $stored);
        $settings['statuses'] = $this->sanitize_statuses((array) ($settings['statuses'] ?? []));
        $settings['delete_data_on_uninstall'] = !empty($settings['delete_data_on_uninstall']) ? 1 : 0;

        return $settings;
    }

    public function update(array $raw): array {
        $current = $this->get();
        $settings = [
            'delete_data_on_uninstall' => !empty($raw['delete_data_on_uninstall']) ? 1 : 0,
            'statuses' => $this->sanitize_statuses(isset($raw['statuses']) && is_array($raw['statuses']) ? $raw['statuses'] : []),
        ];

        $settings = array_replace_recursive($current, $settings);
        update_option(self::OPT, $settings, false);

        return $settings;
    }

    /**
     * @return array<int,array{id:string,label:string,color:string}>
     */
    public function get_statuses(): array {
        $settings = $this->get();
        return isset($settings['statuses']) && is_array($settings['statuses']) ? $settings['statuses'] : [];
    }

    /**
     * @return array<string,string>
     */
    public function get_status_options(): array {
        $options = [];
        foreach ($this->get_statuses() as $status) {
            $id = (string) ($status['id'] ?? '');
            $label = (string) ($status['label'] ?? '');
            if ($id === '' || $label === '') {
                continue;
            }
            $options[$id] = $label;
        }

        return $options;
    }

    /**
     * @return array<string,array{label:string,background:string,text:string}>
     */
    public function get_color_palette(): array {
        return [
            'pending' => [
                'label' => __('In afwachting (grijs)', 'hb-ucs'),
                'background' => '#e5e5e5',
                'text' => '#454545',
            ],
            'processing' => [
                'label' => __('Verwerken (groen)', 'hb-ucs'),
                'background' => '#c6e1c6',
                'text' => '#2c4700',
            ],
            'on-hold' => [
                'label' => __('On-hold (oranje)', 'hb-ucs'),
                'background' => '#f8dda7',
                'text' => '#573B00',
            ],
            'completed' => [
                'label' => __('Voltooid (blauw)', 'hb-ucs'),
                'background' => '#c8d7e1',
                'text' => '#003d66',
            ],
            'cancelled' => [
                'label' => __('Geannuleerd (rood)', 'hb-ucs'),
                'background' => '#eba3a3',
                'text' => '#570000',
            ],
        ];
    }

    /**
     * @return array<string,array{label:string,color:string}>
     */
    public function get_status_payload(): array {
        $payload = [];
        foreach ($this->get_statuses() as $status) {
            $id = (string) ($status['id'] ?? '');
            $label = (string) ($status['label'] ?? '');
            $color = (string) ($status['color'] ?? self::DEFAULT_COLOR);
            if ($id === '' || $label === '') {
                continue;
            }

            $payload[$id] = [
                'label' => $label,
                'color' => $color,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    public function get_status_color_map(): array {
        $colors = [];
        foreach ($this->get_status_payload() as $id => $status) {
            $colors[$id] = (string) ($status['color'] ?? self::DEFAULT_COLOR);
        }

        return $colors;
    }

    private function defaults(): array {
        return [
            'delete_data_on_uninstall' => 0,
            'statuses' => [],
        ];
    }

    /**
     * @param array<int|string,mixed> $statuses
     * @return array<int,array{id:string,label:string,color:string}>
     */
    private function sanitize_statuses(array $statuses): array {
        $normalized = [];
        $used = [];
        $palette = $this->get_color_palette();

        foreach ($statuses as $status) {
            if (!is_array($status)) {
                continue;
            }

            $label = isset($status['label']) ? $this->sanitize_label((string) $status['label']) : '';
            $rawId = isset($status['id']) ? wp_unslash((string) $status['id']) : '';
            $id = sanitize_title($rawId !== '' ? $rawId : $label);

            if ($label === '' || $id === '') {
                continue;
            }

            $rawColor = isset($status['color']) ? sanitize_key(wp_unslash((string) $status['color'])) : self::DEFAULT_COLOR;
            $color = isset($palette[$rawColor]) ? $rawColor : self::DEFAULT_COLOR;

            $id = $this->ensure_unique_id($id, $used);
            $used[$id] = true;

            $normalized[] = [
                'id' => $id,
                'label' => $label,
                'color' => $color,
            ];
        }

        return array_values($normalized);
    }

    private function sanitize_label(string $label): string {
        $label = sanitize_text_field(wp_unslash($label));
        if ($label === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return trim((string) mb_substr($label, 0, self::MAX_LABEL_LENGTH));
        }

        return trim(substr($label, 0, self::MAX_LABEL_LENGTH));
    }

    /**
     * @param array<string,bool> $used
     */
    private function ensure_unique_id(string $id, array $used): string {
        if (!isset($used[$id])) {
            return $id;
        }

        $suffix = 2;
        $base = $id;
        while (isset($used[$base . '-' . $suffix])) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }
}
