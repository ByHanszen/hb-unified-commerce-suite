# Dependencies — HB Unified Commerce Suite

Doel: één centrale plek met de functionele en technische afhankelijkheden van de plugin.

Deze lijst moet mee worden bijgewerkt zodra modules, integraties, vereiste APIs of releasevereisten veranderen.

## Onderhoudsregel

Werk dit document bij bij wijzigingen in:
- module-initialisatie of module-toggles;
- onderlinge module-afhankelijkheden;
- vereiste of optionele plugins/integraties;
- vereiste WordPress, WooCommerce, PHP of HPOS APIs;
- WP-CLI commands, releaseflow of deployment-afhankelijkheden.

## Globale afhankelijkheden

### Vereist

#### WordPress Core
- Vereist voor de volledige plugin.
- Gebruikt voor hooks, admin-schermen, options, post meta, users, roles en transients.
- Hard minimum versie: niet expliciet afgedwongen in code.

#### WooCommerce
- Vereist voor de actieve commerce-modules:
  - B2B
  - QLS
  - Invoice Email
  - Order Overview Status
  - Subscriptions
- Deze modules initialiseren niet als `WooCommerce` niet aanwezig is.
- Hard minimum versie: niet expliciet afgedwongen in code.

### Optioneel

#### WooCommerce HPOS / OrderUtil
- Alleen relevant voor delen van de Subscriptions-module.
- Wordt gebruikt als beschikbaar voor WooCommerce order-type admin-schermen en hybrid datastore gedrag.
- Als deze APIs niet beschikbaar zijn, blijft de plugin terugvallen op de CPT-datastore-variant voor subscription orders.
- Hard minimum versie: niet expliciet afgedwongen in code.

#### Mollie WooCommerce
- Optionele integratie voor automatische subscription renewals via Mollie/SEPA.
- Zonder Mollie blijft de handmatige subscription-flow bruikbaar.
- Vereist een publiek bereikbare webhook-URL voor recurring betalingen.
- Hard minimum versie: niet expliciet afgedwongen in code.

#### Elementor
- Optionele integratie voor de Subscriptions account-widget.
- Alleen actief als Elementor classes beschikbaar zijn.

#### WP-CLI
- Optioneel voor beheer- en migratietaken.
- Wordt gebruikt voor `wp hb-ucs subscriptions backfill-order-meta`.

#### Git Updater
- Geen runtime dependency.
- Alleen relevant voor deployment/updateflow via GitHub Releases.

#### WPO WCPDF
- Optionele integratie voor het koppelen van documenten aan invoice emails.
- Geen harde runtime dependency.

## Versies en compatibiliteit

## Belangrijk
- Deze plugin dwingt momenteel geen expliciete minimumversies af voor WordPress, WooCommerce of PHP.
- Compatibiliteit volgt dus uit de gebruikte APIs, niet uit een formele version guard.

### Praktische compatibiliteitsnotities
- WordPress: vereist een moderne versie die de gebruikte admin-, meta-, roles- en hook-APIs ondersteunt.
- WooCommerce: Subscriptions gebruikt moderne WooCommerce order-type en HPOS-gerelateerde APIs wanneer beschikbaar, zoals `wc_register_order_type()` en `Automattic\\WooCommerce\\Utilities\\OrderUtil`.
- PHP: de codebase gebruikt moderne taalconstructies zoals typed properties/signatures, null coalescing en recente WooCommerce integratiepatronen. Er staat geen expliciete minimumeis in code, maar de plugin moet worden gedraaid op een PHP-versie die door de actieve WooCommerce-versie wordt ondersteund.

## Modulematrix

| Module | Toggle via instellingen | Vereist | Optioneel | Interne afhankelijkheden | Opmerkingen |
|---|---|---|---|---|---|
| Invoice Email | Ja | WordPress, WooCommerce | WPO WCPDF, B2B-profieldata | Core Settings | Kan factuurmails filteren op rollen en optioneel B2B-profielen. |
| QLS | Ja | WordPress, WooCommerce | Geen | Core Settings | Werkt op WooCommerce orders en REST filtering. |
| B2B | Ja | WordPress, WooCommerce | Roles-config op data-niveau | Core Settings | Pricing, checkout-zichtbaarheid en klant/profielregels. |
| Roles | Ja | WordPress | Geen | Core Settings | Beheert extra rollen en rolinstellingen. |
| Customer Order Note | Ja | WordPress | WooCommerce voor ordercontext | Core Settings | Kan ook zonder WooCommerce deels actief zijn, maar ordercontext is WooCommerce-gedreven. |
| Order Overview Status | Ja | WordPress, WooCommerce | HPOS order list APIs | Core Settings | Extra orderoverzicht-statussen voor klassieke en HPOS orderlijsten. |
| Subscriptions | Ja | WordPress, WooCommerce | HPOS/OrderUtil, Mollie, Elementor, WP-CLI | Core Settings, eigen admin/domain/datastore klassen | Gebruikt een eigen WooCommerce order type voor abonnementen. |

## Onderlinge module-afhankelijkheden

### Kernel en Settings als centrale laag
- Alle modules worden gestart vanuit `Kernel` op basis van module toggles uit `Settings`.
- Admin handlers voor B2B, Roles en Order Overview Status worden ook vanuit `Settings` geregistreerd.

### Invoice Email -> B2B (zachte dependency)
- Invoice Email kan optioneel werken met `allowed_b2b_profiles`.
- Dat is een data-/configuratiekoppeling, geen harde code-import van de B2B-module.

### B2B -> Roles (zachte dependency)
- B2B gebruikt WordPress roles en heeft instellingen zoals `roles_merge`.
- Er is geen harde runtime-import van de Roles-module nodig; de koppeling zit op instellingen- en dataniveau.

### Subscriptions -> optionele externe integraties
- Mollie: voor automatische incasso/recurring renewals.
- Elementor: voor accountweergave-widget.
- WP-CLI: voor backfill/migratiecommando.
- HPOS/OrderUtil: voor order-type admin en hybrid datastoregedrag als beschikbaar.

## Module-details

### Invoice Email
- Bestand: `src/Modules/Customers/InvoiceEmailModule.php`
- Vereist WooCommerce.
- Optionele WPO WCPDF integratie via filters.
- Leest instellingen uit de pluginopties en kan optioneel B2B-profielen gebruiken als filtercriterium.

### QLS
- Bestand: `src/Modules/QLS/QLSModule.php`
- Vereist WooCommerce.
- Werkt op WooCommerce orders, meta en REST API filtering.

### B2B
- Bestand: `src/Modules/B2B/B2BModule.php`
- Vereist WooCommerce.
- Gebruikt interne stores, validator, context en pricing-engine.
- Werkt samen met WordPress roles en optioneel met de door de plugin beheerde rolleninstellingen.

### Roles
- Bestand: `src/Modules/Roles/RolesModule.php`
- Vereist alleen WordPress.
- Geen WooCommerce-harddependency in de module-boot zelf.

### Customer Order Note
- Bestand: `src/Modules/CustomerOrderNote/CustomerOrderNoteModule.php`
- Primair WordPress-module, met WooCommerce-ordercontext waar relevant.

### Order Overview Status
- Bestand: `src/Modules/OrderOverviewStatus/OrderOverviewStatusModule.php`
- Vereist WooCommerce.
- Ondersteunt ook HPOS order list hooks als die beschikbaar zijn.

### Subscriptions
- Bestand: `src/Modules/Subscriptions/SubscriptionsModule.php`
- Vereist WooCommerce.
- Gebruikt:
  - eigen order type registratie;
  - eigen adminlaag;
  - eigen repository/service/datatstore klassen;
  - optionele HPOS/OrderUtil APIs;
  - optionele Mollie recurring integratie;
  - optionele Elementor widget;
  - optionele WP-CLI command.

## Release- en beheerafhankelijkheden

### GitHub Releases / Git Updater
- Voor productie-updates via GitHub Releases is Git Updater de beoogde updateflow.
- Dit is geen runtime dependency van de plugin zelf.

### WP-CLI migratiecommand
- Beschikbaar als WP-CLI actief is:

```bash
wp hb-ucs subscriptions backfill-order-meta
```

- Doel: canonieke subscription-meta op WooCommerce subscription orders backfillen vanaf legacy-named meta op hetzelfde orderrecord.

## Bronnen in de code

Belangrijkste plekken voor dependency-wijzigingen:
- `hb-unified-commerce-suite.php`
- `src/Core/Kernel.php`
- `src/Core/Settings.php`
- `src/Modules/*/*Module.php`
- `src/Modules/Subscriptions/OrderTypes/SubscriptionOrderType.php`
- `src/Modules/Subscriptions/Admin/SubscriptionAdmin.php`
- `src/Modules/Subscriptions/DataStores/HybridOrderDataStore.php`
- `src/Modules/Subscriptions/Elementor/SubscriptionsAccountWidget.php`
- `src/Modules/Subscriptions/Cli/SubscriptionMetaBackfillCommand.php`

## Updatecheck bij wijzigingen

Werk dit document minimaal bij als één van deze dingen verandert:
- een module wordt toegevoegd of verwijderd;
- een module WooCommerce verplicht of juist optioneel maakt;
- een nieuwe externe integratie wordt toegevoegd;
- een HPOS-, Mollie-, Elementor- of WP-CLI pad verandert;
- release- of deploymentflow verandert.