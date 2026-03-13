# HB Unified Commerce Suite

Modulaire WordPress/WooCommerce plugin.

## GitHub → productie updates (Optie 1: Git Updater)

Deze plugin is bedoeld om via GitHub Releases/tags te updaten met de WordPress plugin **Git Updater**.

### Vereisten
- Productie site: installeer de plugin **Git Updater**.
- Gebruik bij voorkeur een GitHub repo-naam gelijk aan de plugin map: `hb-unified-commerce-suite`.

### Release workflow (kort)
1. Pas versie aan in:
   - `hb-unified-commerce-suite.php` → header `Version:`
   - `HB_UCS_VERSION` constante in hetzelfde bestand
2. Voeg release notes toe in `CHANGELOG.md`.
3. Commit & push.
4. Maak een Git tag die exact gelijk is aan de plugin versie (bijv. `0.3.2`).
5. Maak een GitHub Release op basis van die tag.
6. In WP Admin → Plugins: klik **Bijwerken** (Git Updater biedt de update aan).

Zie `docs/RELEASING.md` voor het volledige stappenplan.

## Belangrijk
- Updates overschrijven bestanden, maar verwijderen geen data uit de database.
- Verwijderen (uninstall) doet alleen cleanup als per module `delete_data_on_uninstall` aan staat.
