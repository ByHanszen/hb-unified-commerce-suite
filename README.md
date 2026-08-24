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

## Architectuur-notitie
- Voor de migratie van abonnementen van de huidige CPT-editor naar een WooCommerce order type / HPOS-achtige editor, zie `docs/subscription-hpos-migration-plan.md`.

## Productbundels
- De eigen module Productbundels gebruikt hetzelfde producttype en opslagcontract als WPC Product Bundles 8.6.4.
- Activeer WPC Product Bundles en de HB-bundelengine nooit tegelijk; bij een conflict pauzeert HB UCS de eigen engine automatisch.
- Zowel de klassieke WooCommerce winkelmand/checkout als Cart, Mini-Cart en Checkout Blocks worden ondersteund.
- Bestaande producten kunnen zonder gegevensconversie worden overgenomen. Maak wel eerst een back-up en volg de migratiestappen.

Zie `docs/BUNDLES.md` voor opslag, beheer, abonnementen, beperkingen en de veilige overstap vanaf WPC.

## Afhankelijkheden
- Voor de volledige dependency-matrix van modules, optionele integraties, WooCommerce/HPOS gebruik en release-afhankelijkheden, zie `docs/DEPENDENCIES.md`.

## Belangrijk
- Updates overschrijven bestanden, maar verwijderen geen data uit de database.
- Verwijderen (uninstall) doet alleen cleanup als per module `delete_data_on_uninstall` aan staat.
