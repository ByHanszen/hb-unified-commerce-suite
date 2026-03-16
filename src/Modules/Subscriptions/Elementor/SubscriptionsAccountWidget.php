<?php
namespace HB\UCS\Modules\Subscriptions\Elementor;

use HB\UCS\Modules\Subscriptions\SubscriptionsModule;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Elementor\\Widget_Base')) {
    return;
}

class SubscriptionsAccountWidget extends \Elementor\Widget_Base {
    private SubscriptionsModule $module;

    public function __construct(SubscriptionsModule $module, array $data = [], array $args = []) {
        $this->module = $module;
        parent::__construct($data, $args);
    }

    public function get_name(): string {
        return 'hb-ucs-subscriptions-account';
    }

    public function get_title(): string {
        return __('HB Abonnementen Account', 'hb-ucs');
    }

    public function get_icon(): string {
        return 'eicon-my-account';
    }

    public function get_categories(): array {
        return ['general'];
    }

    public function get_keywords(): array {
        return ['hb', 'subscription', 'abonnement', 'account', 'woocommerce'];
    }

    protected function register_controls(): void {
        $this->start_controls_section(
            'section_content',
            [
                'label' => __('Inhoud', 'hb-ucs'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'content_notice',
            [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                'raw' => esc_html__('Gebruik deze widget op een accounttemplate of pagina waar de klant zijn abonnementen moet beheren. De inhoud volgt automatisch het ingelogde account en actieve abonnement.', 'hb-ucs'),
                'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_layout',
            [
                'label' => __('Layout', 'hb-ucs'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'content_gap',
            [
                'label' => __('Verticale ruimte', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 80],
                ],
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-shell' => '--hb-ucs-gap-lg: {{SIZE}}px; --hb-ucs-gap-md: calc({{SIZE}}px * 0.75); --hb-ucs-gap-sm: calc({{SIZE}}px * 0.5);',
                ],
            ]
        );

        $this->add_responsive_control(
            'panel_radius',
            [
                'label' => __('Afronding vlakken', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 48],
                ],
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-shell' => '--hb-ucs-radius-lg: {{SIZE}}px; --hb-ucs-radius-md: calc({{SIZE}}px * 0.75); --hb-ucs-radius-sm: calc({{SIZE}}px * 0.5);',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_colors',
            [
                'label' => __('Kleuren algemeen', 'hb-ucs'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label' => __('Tekstkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-shell' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'muted_text_color',
            [
                'label' => __('Secundaire tekstkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-intro, {{WRAPPER}} .hb-ucs-panel__header p, {{WRAPPER}} .hb-ucs-subscription-item-card__heading p, {{WRAPPER}} .hb-ucs-subscription-item-card__help, {{WRAPPER}} .hb-ucs-action-row__copy span, {{WRAPPER}} .hb-ucs-hero-meta__item span, {{WRAPPER}} .hb-ucs-subscription-card__meta-item span, {{WRAPPER}} .hb-ucs-info-list__row span, {{WRAPPER}} .hb-ucs-subscription-items-footer__meta span, {{WRAPPER}} .hb-ucs-quantity-field span, {{WRAPPER}} .hb-ucs-subscription-card__label, {{WRAPPER}} .hb-ucs-related-order__main span, {{WRAPPER}} .hb-ucs-related-order__meta span' => 'color: {{VALUE}}; opacity: 1;',
                ],
            ]
        );

        $this->add_control(
            'panel_background',
            [
                'label' => __('Achtergrond vlakken', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-shell' => '--hb-ucs-card-bg: {{VALUE}}; --hb-ucs-card-bg-soft: {{VALUE}}; --hb-ucs-modal-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'panel_border_color',
            [
                'label' => __('Randkleur vlakken', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-shell' => '--hb-ucs-card-border: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'panel_shadow',
                'selector' => '{{WRAPPER}} .hb-ucs-account-hero, {{WRAPPER}} .hb-ucs-panel, {{WRAPPER}} .hb-ucs-subscription-card, {{WRAPPER}} .hb-ucs-empty-state, {{WRAPPER}} .hb-ucs-subscription-item-card',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_headings',
            [
                'label' => __('Titels', 'hb-ucs'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'heading_color',
            [
                'label' => __('Kleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-account-title, {{WRAPPER}} .hb-ucs-subscription-card h3, {{WRAPPER}} .hb-ucs-panel__header h3, {{WRAPPER}} .hb-ucs-subscription-item-card h4, {{WRAPPER}} .hb-ucs-address-block h4' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'heading_typography',
                'selector' => '{{WRAPPER}} .hb-ucs-account-title, {{WRAPPER}} .hb-ucs-subscription-card h3, {{WRAPPER}} .hb-ucs-panel__header h3, {{WRAPPER}} .hb-ucs-subscription-item-card h4, {{WRAPPER}} .hb-ucs-address-block h4',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_buttons',
            [
                'label' => __('Knoppen', 'hb-ucs'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->start_controls_tabs('tabs_buttons');

        $this->start_controls_tab(
            'tab_buttons_normal',
            ['label' => __('Normaal', 'hb-ucs')]
        );

        $this->add_control(
            'button_text_color',
            [
                'label' => __('Tekstkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button, {{WRAPPER}} .hb-ucs-account-shell .button' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_background_color',
            [
                'label' => __('Achtergrond', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button, {{WRAPPER}} .hb-ucs-account-shell .button' => 'background: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_border_color',
            [
                'label' => __('Randkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button, {{WRAPPER}} .hb-ucs-account-shell .button' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'tab_buttons_hover',
            ['label' => __('Hover', 'hb-ucs')]
        );

        $this->add_control(
            'button_hover_text_color',
            [
                'label' => __('Tekstkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button:hover, {{WRAPPER}} .hb-ucs-account-shell .button:hover' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_hover_background_color',
            [
                'label' => __('Achtergrond', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button:hover, {{WRAPPER}} .hb-ucs-account-shell .button:hover' => 'background: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_hover_border_color',
            [
                'label' => __('Randkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button:hover, {{WRAPPER}} .hb-ucs-account-shell .button:hover' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();
        $this->end_controls_tabs();

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'button_typography',
                'selector' => '{{WRAPPER}} .hb-ucs-button, {{WRAPPER}} .hb-ucs-account-shell .button',
            ]
        );

        $this->add_responsive_control(
            'button_radius',
            [
                'label' => __('Afronding', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 60],
                ],
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-button, {{WRAPPER}} .hb-ucs-account-shell .button' => 'border-radius: {{SIZE}}px;',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_badges',
            [
                'label' => __('Badges', 'hb-ucs'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'badge_text_color',
            [
                'label' => __('Tekstkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-status-badge' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'badge_background_color',
            [
                'label' => __('Achtergrond', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-status-badge' => 'background: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'badge_border_color',
            [
                'label' => __('Randkleur', 'hb-ucs'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .hb-ucs-status-badge' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void {
        echo $this->module->render_account_shortcode();
    }
}