# Changelog — HB Unified Commerce Suite

Alle noemenswaardige wijzigingen aan deze plugin worden in dit bestand bijgehouden.

Het formaat is geïnspireerd op “Keep a Changelog”.

## [0.3.18] — 2026-03-23
### Fixed
- Abonnementen: backend wijzigingen aan status, volgende datum en adresdata synchroniseren nu weer correct terug naar de frontend abonnementspagina, doordat de admin-editor na opslaan de legacy/frontend abonnementsdata vanuit het ordertype-record bijwerkt.

## [0.3.17] — 2026-03-23
### Fixed
- Abonnementen: Mijn Account acties voor pauzeren, hervatten, annuleren en planning wijzigen schrijven status- en schemawijzigingen nu via het WooCommerce abonnement-orderobject weg, zodat de abonnementsstatus echt persistent wijzigt en zichtbaar blijft in frontend én backend.

## [0.3.16] — 2026-03-23
### Fixed
- Abonnementen: Mijn Account status- en planningsacties schrijven nu ook de gekoppelde ordertype-meta weg, zodat frontend- en backendstatus niet meer uiteenlopen of teruggedraaid worden bij synchronisatie.

## [0.3.15] — 2026-03-23
### Fixed
- Abonnementen: wijzigingen vanuit Mijn Account synchroniseren nu weer correct naar het backend abonnement-record, zodat statussen zoals gepauzeerd en geannuleerd overal gelijk blijven.
- Abonnementen: klant- en backend-acties op abonnementen worden nu gelogd in de abonnementsnotities, inclusief statuswijzigingen, planningswijzigingen en artikelupdates.
- Abonnementen: statusbadges op frontend en backend gebruiken nu consistente kleuren voor actief, gepauzeerd/in behandeling en geannuleerd/verlopen.

## [0.3.14] — 2026-03-20
### Added
- Abonnementen: de WCS-exporttool voor handmatige migratie bevat nu ook gestructureerde JSON-snapshots van artikelen, fees, verzending, totalen en adresdata, plus extra kolommen zoals HB-statusmapping, mandate-indicatie en parent-order context.

## [0.3.10] — 2026-03-19
### Fixed
- Abonnementen: Mollie Payment ID, Payment Mode, Customer ID en Mandate ID worden nu ook zichtbaar gerenderd in de backend orderdata-box onder Facturering op het abonnement ordertype-scherm.

## [0.3.13] — 2026-03-20
### Added
- Abonnementen: backend abonnement-editor ondersteunt nu handmatige invoer van betaalmethode, betaalmethode-titel, Mollie Customer ID, Mollie Mandate ID, Mollie Payment ID en Payment Mode voor handmatige overname van bestaande abonnementen.
- Abonnementen: WCS-notice op het abonnementenoverzicht bevat nu een CSV-export van relevante WooCommerce Subscriptions gegevens voor handmatige migratie.

### Fixed
- Abonnementen: handmatig ingevulde incasso- en betaalmethodegegevens worden nu direct teruggesynchroniseerd naar het onderliggende abonnement-orderrecord, zodat renewals de juiste Mollie mandate-data gebruiken.

## [0.3.12] — 2026-03-19
### Fixed
- Abonnementen: naar de prullenbak verplaatste abonnementen krijgen nu weer een zichtbare `Prullenbak`-view op het custom ordertype-scherm, inclusief correcte query-state en telling.

## [0.3.11] — 2026-03-19
### Fixed
- Abonnementen: WCS-migratie slaat artikelprijzen nu consistent exclusief btw op in de abonnements-opslaglaag, zodat gemigreerde incl.-btw bedragen niet meer in de ex.-btw velden van het abonnementsscherm terechtkomen.
- Abonnementen: bestaande uit WCS gemigreerde abonnementen met verkeerd opgeslagen bruto artikelprijzen worden bij uitlezen automatisch hersteld naar de verwachte ex.-btw opslagwaarde.

## [0.3.9] — 2026-03-19
### Fixed
- Abonnementen: de hoofdstatusselectie op het ordertype-abonnementenscherm gebruikt nu abonnementsstatussen in plaats van reguliere WooCommerce orderstatussen, en wordt bij opslaan veilig terug gemapt naar de onderliggende Woo status.

## [0.3.8] — 2026-03-19
### Fixed
- Abonnementen: backend abonnement-editor normaliseert het statuskeuzeveld nu expliciet naar de HB UCS abonnementsstatussen, zodat oude of Woo-achtige statussen niet meer zichtbaar blijven.

## [0.3.7] — 2026-03-19
### Fixed
- Abonnementen: backend abonnementenlijst verbergt nu ook zichtbare WooCommerce orderstatusfilters in de UI, zodat alleen abonnementsstatusfilters overblijven.

## [0.3.6] — 2026-03-19
### Fixed
- Abonnementen: admin abonnementenlijst negeert nu reguliere WooCommerce orderstatus-requests robuuster, zodat abonnementsstatusfilters leidend blijven.
- Abonnementen: bulkacties op het abonnementenscherm tonen geen standaard WooCommerce orderstatus-overgangen meer, alleen abonnementsacties.

## [0.3.5] — 2026-03-19
### Added
- Abonnementen: ondersteuning voor een extra frequentie van iedere 6 weken in instellingen, runtime en migratie.

### Changed
- Abonnementen: My Account detailpagina toont uitgebreidere abonnementsinformatie en gebruikt robuustere fallback-resolving voor Mollie metadata op gemigreerde abonnementen.
- Abonnementen: adminlijst gebruikt echte abonnementsstatusfilters met correcte tellingen in plaats van cosmetisch hernoemde WooCommerce orderstatus-tabs.

### Fixed
- Abonnementen: migratie vanuit WooCommerce Subscriptions hydrateert klant-, adres-, betaalmethode- en Mollie metadata vollediger op het HB UCS abonnement.
- Abonnementen: gemigreerde en nieuwe abonnementen synchroniseren `payment_method`, `payment_method_title`, `Mollie Payment ID`, `Mollie Payment Mode`, `Mollie Customer ID` en mandate-data betrouwbaarder naar het abonnement en ordertype-record.
- Abonnementen: next payment kolom in de backend is sorteerbaar en gebruikt de abonnementsdata in plaats van alleen Woo orderstatusgedrag.
- Abonnementen: pending mandate / active statusovergangen en renewal-aanmaak zijn consistenter voor online en handmatige betaalmethoden.

## [0.3.4] — 2026-03-16
### Fixed
- Backend order editing (B2B): handmatig aangepaste regelprijzen worden niet meer overschreven door automatisch herberekenen; de herberekening slaat gelockte regels over (Shift-klik forceert overschrijven).

## [0.3.3] — 2026-03-13
### Fixed
- Backend order herberekening (B2B): behoudt originele regelprijs in subtotal en zet B2B prijs in total, zodat korting zichtbaar is op factuur/order.

## [0.3.2] — 2026-03-13
### Fixed
- B2B prijsweergave: prijzen vóór/na korting volgen nu WooCommerce btw-weergave (shop/cart context) consistent.
- B2B afronding: afronding gelijkgetrokken met WooCommerce tax rounding mode om 1-cent verschillen te voorkomen.
- B2B verzendmethodes: robuustere detectie van WooCommerce verzendzone-instances (incl. zone 0 / “Rest van de wereld”).

## [0.3.1] — 2026-03-XX
### Added
- Modulaire basis + bestaande modules (QLS, B2B, Rollen, Invoice e-mail, Klantnotitie).
