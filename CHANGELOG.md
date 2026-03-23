# Changelog — HB Unified Commerce Suite

Alle noemenswaardige wijzigingen aan deze plugin worden in dit bestand bijgehouden.

Het formaat is geïnspireerd op “Keep a Changelog”.

## [0.3.36] — 2026-03-23
### Fixed
- Abonnementen: bij productwijzigingen vanuit Mijn Account worden nu niet alleen de gewijzigde artikelregels, maar ook hun product-btw en de verzend-btw eerst opnieuw berekend in de actuele klant/adrescontext voordat legacy- en backend-abonnementrecords worden gesynchroniseerd. Daardoor tonen backend orderregels en totalen na product- of adreswijzigingen weer de correcte btw voor zowel artikelen als verzendkosten.

## [0.3.35] — 2026-03-23
### Fixed
- Abonnementen: wijzigingen aan factuur- of verzendadres vanuit Mijn Account (en het handmatig herladen van klantadressen in de backend) triggeren nu dezelfde WooCommerce shipping-recalculatie als itemwijzigingen. Daardoor worden verzendmethode, gratis-verzending-regels, B2B shipping filters en btw op verzendkosten ook na adreswijzigingen opnieuw correct toegepast en naar backend/legacy gesynchroniseerd.

## [0.3.34] — 2026-03-23
### Fixed
- Abonnementen: na productwijzigingen vanuit Mijn Account worden verzendkosten nu opnieuw via WooCommerce shipping rates berekend op basis van de actuele abonnement-items, het afleveradres en B2B shipping filters. Daardoor werken regels zoals gratis verzending per abonnementsbedrag weer correct en wordt shipping tax opgeslagen als ex btw + aparte belasting, in lijn met WooCommerce.

## [0.3.33] — 2026-03-23
### Fixed
- Abonnementen: itemwijzigingen vanuit Mijn Account schrijven de nieuwe productset nu direct ook naar de gekoppelde legacy subscription-post. Daardoor gebruikt de daaropvolgende synchronisatie niet langer oude legacy itemdata en komen product- en attribuutwijzigingen alsnog correct in het backend abonnement terecht.

## [0.3.32] — 2026-03-23
### Fixed
- Abonnementen: frontend itemwijzigingen gebruiken bij het bijwerken van een bestaand order-type abonnement nu expliciet de legacy → order synchronisatierichting. Daardoor worden gewijzigde producten en attributen niet meer direct terug overschreven door oude backend orderdata.

## [0.3.31] — 2026-03-23
### Fixed
- Abonnementen: product-, variatie-, attribuut- en prijswijzigingen vanuit Mijn Account verversen nu ook de echte orderregels van het backend abonnementrecord. Daardoor tonen frontend en backend na itemwijzigingen weer dezelfde producten en bedragen.

## [0.3.30] — 2026-03-23
### Fixed
- Abonnementen: frontend wijzigingen vanuit Mijn Account gebruiken vaste abonnementsprijzen nu weer als exclusief-btw opslagprijs. De prijsconfiguratie uit product- en variatie-instellingen wordt bij uitlezen en opslaan correct omgerekend vanuit de beheer-UI (incl. btw), zodat abonnement-items na frontend wijzigingen niet opnieuw extra btw krijgen.

## [0.3.29] — 2026-03-23
### Fixed
- Abonnementen: renewals nemen nu expliciet de offline/handmatige betaalmethode van de oorspronkelijke bestelling over als een opgeslagen subscription-betaalmethode onterecht nog op een Mollie mandate-pad stond. Daardoor vallen B2B renewals niet meer automatisch terug naar SEPA Direct Debit.

## [0.3.28] — 2026-03-23
### Fixed
- Abonnementen: nieuw aangemaakte abonnement-orders vanuit winkelwagen/checkout schrijven de gekozen frequentie nu direct ook naar de order-type meta (`_hb_ucs_subscription_scheme` en gerelateerde schemawaarden). Daardoor is de frequentie meteen zichtbaar in de backend en hoeft die niet eerst handmatig opgeslagen te worden.

## [0.3.27] — 2026-03-23
### Changed
- Abonnementen: de uitgebreide synchronisatie-debuglogging blijft beschikbaar in de code, maar staat voortaan standaard uit. Je kunt deze alleen nog expliciet aanzetten via de constante `HB_UCS_SUBSCRIPTION_SYNC_DEBUG` of de filter `hb_ucs_subscription_sync_debug_enabled`.

## [0.3.26] — 2026-03-23
### Fixed
- Abonnementen: wijzigingen vanuit Mijn Account voor pauzeren, hervatten, annuleren en planning aanpassen gebruiken nu dezelfde persist- en synchronisatieroute als backend. Status, volgende orderdatum en schema worden eerst hard naar het orderrecord geschreven en daarna direct naar legacy/frontend gesynchroniseerd, zodat frontend-acties niet meer stil terugvallen naar oude waarden.

## [0.3.25] — 2026-03-23
### Fixed
- Abonnementen: op het backend bewerkscherm krijgt de daadwerkelijk gekozen hoofdstatus nu voorrang boven een mogelijk verouderde waarde uit de schema-meta-box, en alle statusselects worden bij wijziging en submit expliciet gelijkgetrokken. Daardoor wordt een keuze zoals `Actief` niet meer overschreven door een oude `Gepauzeerd` waarde tijdens opslaan.

## [0.3.24] — 2026-03-23
### Added
- Abonnementen: extra debuglogging voor het herladen van abonnement-orderobjecten via de custom data-store en voor het renderen van de backend schema-meta-box. Hiermee wordt zichtbaar of waarden pas na opslaan, bij het opnieuw laden van het scherm, terugvallen.

## [0.3.23] — 2026-03-23
### Added
- Abonnementen: gerichte debuglogging voor backend save, repository synchronisatie en frontend uitlezing van status en volgende orderdatum. De logs maken per stap zichtbaar welke order-id, legacy-id, status en datum gebruikt worden en waar een mismatch ontstaat.

## [0.3.22] — 2026-03-23
### Fixed
- Abonnementen: de repository bewaart de legacy-koppeling van ordertype-abonnementen nu correct tijdens self-sync en kopieert ook legacy schema- en datumvelden terug naar het orderrecord. Daardoor blijven backend statuswijzigingen niet meer hangen aan een los record en toont de frontend weer dezelfde status en volgende orderdatum als de backend.

## [0.3.21] — 2026-03-23
### Fixed
- Abonnementen: backend synchronisatie laadt het abonnement-orderobject na opslaan niet meer opnieuw in vóór de legacy/frontend sync, omdat het custom data-store zo'n reload vanuit de oude legacy-data hydrateerde en daarmee handmatige status- en datumwijzigingen weer terugdraaide.

## [0.3.20] — 2026-03-23
### Fixed
- Abonnementen: backend statusacties en handmatige status-/planningswijzigingen forceren nu ook de onderliggende subscription-meta en Woo-orderstatus naar de database voordat de frontend/legacy sync draait, zodat statussen zoals `gepauzeerd` niet meer direct terugvallen naar de oude waarde.

## [0.3.19] — 2026-03-23
### Fixed
- Abonnementen: wijzigingen die je in de backend-editor opslaat voor status, schema en datums worden nu via het WooCommerce abonnement-orderobject persisted voordat synchronisatie draait, zodat `Opslaan` deze waarden niet meer onbedoeld terugdraait en de frontend dezelfde actuele abonnementsstatus en planning toont.

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
