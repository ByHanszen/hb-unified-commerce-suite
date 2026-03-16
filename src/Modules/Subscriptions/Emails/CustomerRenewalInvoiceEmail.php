<?php
namespace HB\UCS\Modules\Subscriptions\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerRenewalInvoiceEmail extends AbstractRenewalOrderEmail {
    public function __construct() {
        $this->id = 'customer_renewal_invoice';
        $this->title = __('Klant renewal factuur', 'hb-ucs');
        $this->description = __('Wordt verstuurd naar de klant wanneer een renewal order is aangemaakt en wacht op bevestiging van de betaalprovider.', 'hb-ucs');
        $this->trigger_action = 'hb_ucs_customer_renewal_invoice_notification';
        $this->default_subject_text = __('Je abonnementsverlenging wacht op betaling voor bestelling #{order_number}', 'hb-ucs');
        $this->default_heading_text = __('Je abonnementsverlenging is aangemaakt', 'hb-ucs');
        $this->intro_text = __('We hebben een nieuwe verlengbestelling voor je abonnement aangemaakt. Zodra de betaling bevestigd is, verwerken we de verlenging automatisch.', 'hb-ucs');
        parent::__construct();
    }
}
