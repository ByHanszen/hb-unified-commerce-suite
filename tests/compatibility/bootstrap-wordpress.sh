#!/usr/bin/env bash
set -Eeuo pipefail

: "${WP_PATH:=/tmp/wordpress}"
: "${DB_NAME:=wordpress}"
: "${DB_USER:=root}"
: "${DB_PASSWORD:=root}"
: "${DB_HOST:=127.0.0.1:3306}"

db_host="${DB_HOST%:*}"
client_options=(--protocol=tcp --host="$db_host" --port="${DB_HOST##*:}" --user="$DB_USER")
if [[ "$db_host" == "127.0.0.1" ]]; then
    # Match WordPress's connection to the disposable database exposed on the runner.
    client_options+=(--skip-ssl)
fi

for attempt in {1..30}; do
    if MYSQL_PWD="$DB_PASSWORD" mariadb "${client_options[@]}" --batch --skip-column-names --execute='SELECT 1' >/dev/null; then
        break
    fi
    if [[ "$attempt" -eq 30 ]]; then
        echo "::error::MariaDB did not become ready."
        exit 1
    fi
    sleep 2
done

rm -rf "$WP_PATH"
mkdir -p "$WP_PATH"

wp core download \
    --path="$WP_PATH" \
    --version=latest \
    --locale=en_US \
    --force

wp config create \
    --path="$WP_PATH" \
    --dbname="$DB_NAME" \
    --dbuser="$DB_USER" \
    --dbpass="$DB_PASSWORD" \
    --dbhost="$DB_HOST" \
    --skip-check \
    --force

wp config set WP_DEBUG true --raw --path="$WP_PATH"
wp config set WP_DEBUG_LOG true --raw --path="$WP_PATH"
wp config set WP_DEBUG_DISPLAY false --raw --path="$WP_PATH"
wp config set SCRIPT_DEBUG true --raw --path="$WP_PATH"
wp config set WP_ENVIRONMENT_TYPE "'local'" --raw --path="$WP_PATH"
wp config set WP_MEMORY_LIMIT "'512M'" --raw --path="$WP_PATH"

wp core install \
    --path="$WP_PATH" \
    --url="http://127.0.0.1:8080" \
    --title="HB compatibility test" \
    --admin_user="compat-admin" \
    --admin_password="compat-password-not-used" \
    --admin_email="compatibility@example.invalid" \
    --skip-email

plugin_dir="$WP_PATH/wp-content/plugins/hb-unified-commerce-suite"
mkdir -p "$plugin_dir"
rsync -a --delete \
    --exclude=".git/" \
    --exclude=".github/" \
    "$GITHUB_WORKSPACE/" "$plugin_dir/"

wp plugin install woocommerce --activate --path="$WP_PATH"
wp plugin activate hb-unified-commerce-suite --path="$WP_PATH"

mkdir -p /tmp/hb-compatibility
: > "$WP_PATH/wp-content/debug.log"

echo "Installed versions:"
wp core version --path="$WP_PATH"
php --version | head -n 1
wp plugin get woocommerce --field=version --path="$WP_PATH"
wp plugin get hb-unified-commerce-suite --field=version --path="$WP_PATH"
