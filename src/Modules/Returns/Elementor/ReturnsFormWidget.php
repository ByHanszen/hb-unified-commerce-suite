<?php
namespace HB\UCS\Modules\Returns\Elementor;

use HB\UCS\Modules\Returns\ReturnsModule;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Elementor\\Widget_Base')) {
    return;
}

class ReturnsFormWidget extends \Elementor\Widget_Base {
    private ?ReturnsModule $module = null;

    public function __construct(array $data = [], ?array $args = null) {
        parent::__construct($data, $args);
    }

    public function get_name(): string {
        return 'hb-ucs-returns-form';
    }

    public function get_title(): string {
        return __('HB Retourformulier', 'hb-ucs');
    }

    public function get_icon(): string {
        return 'eicon-post-list';
    }

    public function get_categories(): array {
        return ['general'];
    }

    public function get_keywords(): array {
        return ['hb', 'retour', 'return', 'woocommerce', 'herroeping'];
    }

    protected function register_controls(): void {
        $this->start_controls_section('section_content', [
            'label' => __('Inhoud', 'hb-ucs'),
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('content_notice', [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => esc_html__('Deze widget toont het HB UCS retourformulier. De lookup en bevestiging gebruiken automatisch ordernummer, factuurpostcode en de globale module-instellingen.', 'hb-ucs'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_layout', [
            'label' => __('Layout', 'hb-ucs'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('panel_radius', [
            'label' => __('Afronding', 'hb-ucs'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => [
                'px' => ['min' => 0, 'max' => 48],
            ],
            'selectors' => [
                '{{WRAPPER}} .hb-ucs-returns' => '--hb-ucs-returns-radius: {{SIZE}}px;',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_style_colors', [
            'label' => __('Kleuren algemeen', 'hb-ucs'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        foreach ([
            'accent_color' => ['label' => __('Accent', 'hb-ucs'), 'var' => '--hb-ucs-returns-accent'],
            'accent_text_color' => ['label' => __('Accent tekst', 'hb-ucs'), 'var' => '--hb-ucs-returns-accent-text'],
            'background_color' => ['label' => __('Achtergrond', 'hb-ucs'), 'var' => '--hb-ucs-returns-bg'],
            'panel_color' => ['label' => __('Panelen', 'hb-ucs'), 'var' => '--hb-ucs-returns-panel'],
            'border_color' => ['label' => __('Randen', 'hb-ucs'), 'var' => '--hb-ucs-returns-border'],
            'text_color' => ['label' => __('Tekst', 'hb-ucs'), 'var' => '--hb-ucs-returns-text'],
            'muted_color' => ['label' => __('Secundaire tekst', 'hb-ucs'), 'var' => '--hb-ucs-returns-muted'],
            'success_color' => ['label' => __('Succesvlak', 'hb-ucs'), 'var' => '--hb-ucs-returns-success'],
            'error_color' => ['label' => __('Foutvlak', 'hb-ucs'), 'var' => '--hb-ucs-returns-error'],
        ] as $controlKey => $config) {
            $this->add_control($controlKey, [
                'label' => $config['label'],
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-returns' => $config['var'] . ': {{VALUE}};',
                ],
            ]);
        }

        $this->end_controls_section();

        $this->start_controls_section('section_style_buttons', [
            'label' => __('Knoppen', 'hb-ucs'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'button_typography',
            'selector' => '{{WRAPPER}} .hb-ucs-returns__button',
        ]);

        $this->add_responsive_control('button_padding', [
            'label' => __('Padding', 'hb-ucs'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em'],
            'selectors' => [
                '{{WRAPPER}} .hb-ucs-returns__button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_control('button_hover_heading', [
            'label' => __('Hover', 'hb-ucs'),
            'type' => \Elementor\Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        foreach ([
            'primary_hover_bg' => ['label' => __('Primary hover achtergrond', 'hb-ucs'), 'var' => '--hb-ucs-returns-button-primary-hover-bg'],
            'primary_hover_text' => ['label' => __('Primary hover tekst', 'hb-ucs'), 'var' => '--hb-ucs-returns-button-primary-hover-text'],
            'primary_hover_border' => ['label' => __('Primary hover rand', 'hb-ucs'), 'var' => '--hb-ucs-returns-button-primary-hover-border'],
            'secondary_hover_bg' => ['label' => __('Secondary hover achtergrond', 'hb-ucs'), 'var' => '--hb-ucs-returns-button-secondary-hover-bg'],
            'secondary_hover_text' => ['label' => __('Secondary hover tekst', 'hb-ucs'), 'var' => '--hb-ucs-returns-button-secondary-hover-text'],
            'secondary_hover_border' => ['label' => __('Secondary hover rand', 'hb-ucs'), 'var' => '--hb-ucs-returns-button-secondary-hover-border'],
        ] as $controlKey => $config) {
            $this->add_control($controlKey, [
                'label' => $config['label'],
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-returns' => $config['var'] . ': {{VALUE}};',
                ],
            ]);
        }

        $this->end_controls_section();
    }

    protected function render(): void {
        echo $this->get_module()->render_public_form('elementor');
    }

    private function get_module(): ReturnsModule {
        if ($this->module instanceof ReturnsModule) {
            return $this->module;
        }

        $this->module = new ReturnsModule();

        return $this->module;
    }
}