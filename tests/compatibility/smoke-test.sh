#!/usr/bin/env bash
set -Eeuo pipefail

label="${1:-Compatibility smoke test}"
: "${WP_PATH:=/tmp/wordpress}"

debug_log="$WP_PATH/wp-content/debug.log"
touch "$debug_log"

wp eval '
    if (!defined("HB_UCS_VERSION")) {
        fwrite(STDERR, "HB_UCS_VERSION is not defined.\n");
        exit(1);
    }
    echo "HB Unified Commerce Suite loaded: " . HB_UCS_VERSION . PHP_EOL;
' --path="$WP_PATH"

wp plugin is-active hb-unified-commerce-suite --path="$WP_PATH"
wp plugin is-active woocommerce --path="$WP_PATH"

if grep -Eiq 'PHP (Fatal error|Parse error)|Uncaught (Error|TypeError|ValueError)|Allowed memory size .* exhausted' "$debug_log"; then
    echo "::error title=${label}::A fatal PHP error was written to wp-content/debug.log"
    cat "$debug_log"
    exit 1
fi

echo "Smoke test passed: $label"
