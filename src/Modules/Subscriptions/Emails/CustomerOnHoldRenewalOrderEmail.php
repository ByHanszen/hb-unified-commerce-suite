<?php
namespace HB\UCS\Modules\Subscriptions\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerOnHoldRenewalOrderEmail extends AbstractRenewalOrderEmail {
    public function __construct() {
        $this->id = 'customer_on_hold_renewal_order';
        $this->title = __('Klant SEPA-renewal aangemaakt', 'hb-ucs');
        $this->description = __('Wordt verstuurd naar de klant wanneer een automatische SEPA-renewal is aangemaakt en de incasso nog verwerkt wordt.', 'hb-ucs');
        $this->trigger_action = 'hb_ucs_customer_on_hold_renewal_order_notification';
        $this->default_subject_text = __('Je verlengingsaanvraag is aangemaakt voor bestelling #{order_number}', 'hb-ucs');
        $this->default_heading_text = __('Je verlengingsaanvraag is aangemaakt', 'hb-ucs');
        $this->intro_text = __('We hebben een nieuwe verlengingsaanvraag voor je abonnement aangemaakt. De betaling verloopt via automatische incasso. Zodra de betaling compleet is, wordt je bestelling verzonden en ontvang je een bevestiging.', 'hb-ucs');
        parent::__construct();
    }
}
