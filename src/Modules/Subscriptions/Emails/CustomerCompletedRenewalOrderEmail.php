<?php
namespace HB\UCS\Modules\Subscriptions\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerCompletedRenewalOrderEmail extends AbstractRenewalOrderEmail {
    public function __construct() {
        $this->id = 'customer_completed_renewal_order';
        $this->title = __('Klant renewal order voltooid', 'hb-ucs');
        $this->description = __('Wordt verstuurd naar de klant wanneer een renewal order voor een abonnement is voltooid.', 'hb-ucs');
        $this->trigger_action = 'hb_ucs_customer_completed_renewal_order_notification';
        $this->default_subject_text = __('Je abonnementsverlenging is voltooid voor bestelling #{order_number}', 'hb-ucs');
        $this->default_heading_text = __('Je abonnementsverlenging is voltooid', 'hb-ucs');
        $this->intro_text = __('De verlenging van je abonnement is succesvol afgerond. Hieronder vind je de details van deze renewal bestelling.', 'hb-ucs');
        parent::__construct();
    }
}
