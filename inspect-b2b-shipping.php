<?php
define("WP_USE_THEMES", false);
require_once "C:/xampp/htdocs/wordpress/wp-load.php";
if (!class_exists("HB\\UCS\\Modules\\B2B\\Support\\Validator")) {
    require_once "hb-unified-commerce-suite.php";
}
echo "--- Validator::shipping_choices() ---\n";
$choices = HB\UCS\Modules\B2B\Support\Validator::shipping_choices();
print_r($choices);
