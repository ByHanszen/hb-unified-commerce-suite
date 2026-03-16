<?php
namespace HB\UCS\Modules\Subscriptions\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerProcessingRenewalOrderEmail extends AbstractRenewalOrderEmail {
    public function __construct() {
        $this->id = 'customer_processing_renewal_order';
        $this->title = __('Klant renewal order in verwerking', 'hb-ucs');
        $this->description = __('Wordt verstuurd naar de klant wanneer een renewal order voor een abonnement in verwerking staat.', 'hb-ucs');
        $this->trigger_action = 'hb_ucs_customer_processing_renewal_order_notification';
        $this->default_subject_text = __('Je abonnementsverlenging wordt verwerkt voor bestelling #{order_number}', 'hb-ucs');
        $this->default_heading_text = __('Je abonnementsverlenging wordt verwerkt', 'hb-ucs');
        $this->intro_text = __('We hebben de verlenging van je abonnement in verwerking genomen. Hieronder vind je de details van deze renewal bestelling.', 'hb-ucs');
        parent::__construct();
    }
}
