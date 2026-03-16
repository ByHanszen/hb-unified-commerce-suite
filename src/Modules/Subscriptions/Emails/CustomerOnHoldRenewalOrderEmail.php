<?php
namespace HB\UCS\Modules\Subscriptions\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerOnHoldRenewalOrderEmail extends AbstractRenewalOrderEmail {
    public function __construct() {
        $this->id = 'customer_on_hold_renewal_order';
        $this->title = __('Klant renewal order in de wacht', 'hb-ucs');
        $this->description = __('Wordt verstuurd naar de klant wanneer een renewal order voor een abonnement in de wacht staat op bevestiging van de betaalprovider.', 'hb-ucs');
        $this->trigger_action = 'hb_ucs_customer_on_hold_renewal_order_notification';
        $this->default_subject_text = __('Je abonnementsverlenging wacht op betaling voor bestelling #{order_number}', 'hb-ucs');
        $this->default_heading_text = __('Je abonnementsverlenging staat in de wacht', 'hb-ucs');
        $this->intro_text = __('We hebben een verlengbestelling voor je abonnement aangemaakt en wachten nog op een definitieve bevestiging van de betaalprovider.', 'hb-ucs');
        parent::__construct();
    }
}
