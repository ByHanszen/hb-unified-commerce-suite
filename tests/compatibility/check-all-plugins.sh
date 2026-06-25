#!/usr/bin/env bash
set -Eeuo pipefail

: "${WP_PATH:=/tmp/wordpress}"
result_dir="/tmp/hb-compatibility"
mkdir -p "$result_dir"
summary="$result_dir/summary.md"
failures=0
skipped=0

cat > "$summary" <<'MARKDOWN'
# HB Unified Commerce Suite compatibility report

The public plugins are downloaded from WordPress.org at their latest available version. Private and premium plugins are downloaded only when their configured URL secret is available.

| Plugin | Source | Version | Result |
|---|---|---:|---|
MARKDOWN

clean_test_plugins() {
    wp plugin deactivate --all --path="$WP_PATH" >/dev/null 2>&1 || true

    while IFS= read -r plugin; do
        case "$plugin" in
            hb-unified-commerce-suite|woocommerce)
                ;;
            *)
                wp plugin delete "$plugin" --path="$WP_PATH" >/dev/null 2>&1 || true
                ;;
        esac
    done < <(wp plugin list --field=name --path="$WP_PATH")

    wp plugin activate woocommerce --path="$WP_PATH" >/dev/null
    wp plugin activate hb-unified-commerce-suite --path="$WP_PATH" >/dev/null
    : > "$WP_PATH/wp-content/debug.log"
}

install_prerequisites() {
    local prerequisites="${1:-}"
    [[ -z "$prerequisites" ]] && return 0

    IFS=',' read -ra deps <<< "$prerequisites"
    for dep in "${deps[@]}"; do
        [[ -z "$dep" || "$dep" == "woocommerce" ]] && continue
        if ! wp plugin is-installed "$dep" --path="$WP_PATH"; then
            wp plugin install "$dep" --path="$WP_PATH"
        fi
        wp plugin activate "$dep" --path="$WP_PATH"
    done
}

record() {
    local name="$1"
    local source="$2"
    local version="$3"
    local result="$4"
    printf '| %s | %s | %s | %s |\n' "$name" "$source" "${version:-n/a}" "$result" >> "$summary"
}

run_smoke() {
    local label="$1"
    bash "$GITHUB_WORKSPACE/tests/compatibility/smoke-test.sh" "$label"
}

test_public_plugin() {
    local name="$1"
    local slug="$2"
    local prerequisites="$3"
    local safe_name
    safe_name="$(echo "$slug" | tr -c '[:alnum:]._- ' '-' | tr ' ' '-')"
    local log="$result_dir/public-${safe_name}.log"

    clean_test_plugins

    (
        set -Eeuo pipefail
        echo "Testing $name ($slug)"
        install_prerequisites "$prerequisites"

        if [[ "$slug" != "woocommerce" ]]; then
            wp plugin install "$slug" --force --path="$WP_PATH"
            wp plugin activate "$slug" --path="$WP_PATH"
        fi

        wp plugin activate hb-unified-commerce-suite --path="$WP_PATH"
        run_smoke "$name"
    ) >"$log" 2>&1

    local status=$?
    local version="n/a"
    if wp plugin is-installed "$slug" --path="$WP_PATH"; then
        version="$(wp plugin get "$slug" --field=version --path="$WP_PATH" 2>/dev/null || echo unknown)"
    fi

    if [[ "$status" -eq 0 ]]; then
        record "$name" "WordPress.org" "$version" "PASS"
    else
        failures=$((failures + 1))
        record "$name" "WordPress.org" "$version" "FAIL"
        echo "::error title=Plugin compatibility failed::$name failed. See $(basename "$log")."
        cat "$log"
    fi
}

download_private_zip() {
    local url="$1"
    local destination="$2"

    if [[ -n "${PRIVATE_PLUGIN_DOWNLOAD_TOKEN:-}" ]]; then
        curl --fail --location --retry 3 \
            --header "Authorization: Bearer ${PRIVATE_PLUGIN_DOWNLOAD_TOKEN}" \
            "$url" --output "$destination"
    else
        curl --fail --location --retry 3 "$url" --output "$destination"
    fi
}

test_private_plugin() {
    local name="$1"
    local url_variable="$2"
    local prerequisites="$3"
    local url="${!url_variable:-}"

    if [[ -z "$url" ]]; then
        skipped=$((skipped + 1))
        record "$name" "private ZIP" "n/a" "SKIPPED — missing \`$url_variable\`"
        return 0
    fi

    clean_test_plugins
    local safe_name
    safe_name="$(echo "$url_variable" | tr '[:upper:]_' '[:lower:]-')"
    local archive="$result_dir/${safe_name}.zip"
    local log="$result_dir/private-${safe_name}.log"

    (
        set -Eeuo pipefail
        echo "Testing $name from $url_variable"
        install_prerequisites "$prerequisites"

        mapfile -t before_plugins < <(wp plugin list --field=name --path="$WP_PATH" | sort)
        download_private_zip "$url" "$archive"

        [[ -s "$archive" ]] || {
            echo "Downloaded ZIP is empty."
            exit 1
        }

        wp plugin install "$archive" --force --path="$WP_PATH"

        mapfile -t after_plugins < <(wp plugin list --field=name --path="$WP_PATH" | sort)
        mapfile -t new_plugins < <(comm -13 \
            <(printf '%s\n' "${before_plugins[@]}") \
            <(printf '%s\n' "${after_plugins[@]}"))

        if [[ "${#new_plugins[@]}" -eq 0 ]]; then
            package_folder="$(unzip -Z1 "$archive" | awk -F/ 'NF > 1 {print $1; exit}')"
            [[ -n "$package_folder" ]] || {
                echo "Could not determine the installed plugin directory."
                exit 1
            }
            new_plugins=("$package_folder")
        fi

        for plugin in "${new_plugins[@]}"; do
            wp plugin activate "$plugin" --path="$WP_PATH"
        done

        wp plugin activate hb-unified-commerce-suite --path="$WP_PATH"
        run_smoke "$name"
        printf '%s\n' "${new_plugins[@]}" > "$result_dir/${safe_name}.installed-plugins"
    ) >"$log" 2>&1

    local status=$?
    local version="unknown"
    if [[ -f "$result_dir/${safe_name}.installed-plugins" ]]; then
        first_plugin="$(head -n 1 "$result_dir/${safe_name}.installed-plugins")"
        version="$(wp plugin get "$first_plugin" --field=version --path="$WP_PATH" 2>/dev/null || echo unknown)"
    fi

    if [[ "$status" -eq 0 ]]; then
        record "$name" "private ZIP" "$version" "PASS"
    else
        failures=$((failures + 1))
        record "$name" "private ZIP" "$version" "FAIL"
        echo "::error title=Premium plugin compatibility failed::$name failed. See $(basename "$log")."
        cat "$log"
    fi
}

test_public_stack() {
    clean_test_plugins
    local log="$result_dir/combined-public-stack.log"

    (
        set -Eeuo pipefail
        while IFS='|' read -r name slug prerequisites; do
            [[ -z "$name" || "$name" == \#* ]] && continue
            install_prerequisites "$prerequisites"
            if [[ "$slug" != "woocommerce" ]]; then
                wp plugin install "$slug" --force --path="$WP_PATH"
            fi
        done < "$GITHUB_WORKSPACE/tests/compatibility/public-plugins.txt"

        wp plugin activate --all --path="$WP_PATH"
        run_smoke "Combined public plugin stack"
    ) >"$log" 2>&1

    local status=$?
    if [[ "$status" -eq 0 ]]; then
        record "Combined public plugin stack" "WordPress.org" "latest" "PASS"
    else
        failures=$((failures + 1))
        record "Combined public plugin stack" "WordPress.org" "latest" "FAIL"
        echo "::error title=Combined compatibility failed::The combined public plugin stack failed."
        cat "$log"
    fi
}

set +e

while IFS='|' read -r name slug prerequisites; do
    [[ -z "$name" || "$name" == \#* ]] && continue
    test_public_plugin "$name" "$slug" "$prerequisites"
done < "$GITHUB_WORKSPACE/tests/compatibility/public-plugins.txt"

while IFS='|' read -r name url_variable prerequisites; do
    [[ -z "$name" || "$name" == \#* ]] && continue
    test_private_plugin "$name" "$url_variable" "$prerequisites"
done < "$GITHUB_WORKSPACE/tests/compatibility/private-plugins.txt"

test_public_stack

set -e

cat "$summary" >> "$GITHUB_STEP_SUMMARY"

echo
cat "$summary"
echo
echo "Failures: $failures"
echo "Skipped private plugins: $skipped"

if [[ "$failures" -gt 0 ]]; then
    exit 1
fi
