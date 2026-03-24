# Changelog — HB Unified Commerce Suite

Alle noemenswaardige wijzigingen aan deze plugin worden in dit bestand bijgehouden.

Het formaat is geïnspireerd op “Keep a Changelog”.

## [0.3.86] — 2026-03-24
### Fixed
- Mijn Account abonnementspagina: technische Mollie-velden zoals payment ID, payment mode en customer ID worden niet langer aan klanten getoond in de frontend. Die betaalmeta blijft intern beschikbaar, maar is nu verborgen uit de accountweergave.

## [0.3.85] — 2026-03-24
### Fixed
- Afrondingsverschil account/backend: bij shops met prijzen inclusief btw kon een frontend abonnementsprijs van bijvoorbeeld `11,50` incl. btw in de backend als `11,51` uitkomen, omdat de opgeslagen subscription-unitprijs via een apart exclusief-btw prijs/discount pad werd berekend. Productwijzigingen via Mijn Account leiden de opslagprijs nu af van exact dezelfde frontend abonnementsprijsberekening, zodat frontend en backend op hetzelfde incl.-btw bedrag uitkomen.

## [0.3.84] — 2026-03-24
### Fixed
- Frontend abonnement-meta: interne WooCommerce order-itemmeta zoals `_reduced_stock` wordt nu expliciet verborgen in abonnementsweergaves. Daardoor zien klanten die technische voorraadmeta niet meer terug als losse tekstregel in Mijn Account of andere frontend item-meta.

## [0.3.83] — 2026-03-24
### Fixed
- Mijn Account productkiezer: prijzen in de potlood-flow gebruikten nog een oudere prijsberekening waardoor in sommige winkels exclusief-btw bedragen zichtbaar bleven. De account productmodal en variatie-preview gebruiken nu dezelfde btw-correcte subscriptions-pricing helper als de productpagina, zodat daar weer inclusief-btw prijzen worden getoond wanneer de shop dat zo weergeeft.

## [0.3.82] — 2026-03-24
### Fixed
- Backend abonnementsregels: variatie-attributen konden dubbel zichtbaar worden wanneer dezelfde optie zowel via geselecteerde attributen als via opgeslagen `display_meta` opnieuw op de orderregel terechtkwam. Tijdens sync worden die dubbele attribuut-rows nu uitgefilterd, terwijl aanvullende niet-variatie productopties zichtbaar blijven.

## [0.3.81] — 2026-03-24
### Fixed
- Backend abonnement-attributen na frontend wijzigen: bij het opnieuw opbouwen van echte order-items voor het abonnement werden niet alle gekozen `selected_attributes` en snapshot `display_meta` teruggezet op die backend-items. Daardoor verdwenen sommige eerder opgeloste productopties opnieuw in de backoffice. De sync zet die metadata nu weer volledig door.

## [0.3.80] — 2026-03-24
### Fixed
- Backend abonnementregels: wanneer een order-type abonnement geen geldige gekoppelde legacy-post had, werkte een frontend productwijziging wel de opgeslagen subscription-meta bij maar niet de echte order-items die de backoffice toont. De self-sync bouwt die order-items nu ook opnieuw op uit de actuele subscription-data, zodat backend en Mijn Account weer dezelfde productkeuze laten zien.

## [0.3.79] — 2026-03-24
### Fixed
- Mijn Account productopties: na het kiezen van een hoofdartikel werd de attribuutselector nog verborgen door de editor-wrapper. Die wrapper toont nu weer correct de variatievelden, zodat productopties direct op de abonnementspagina zichtbaar en kiesbaar zijn na de hoofdartikelkeuze.

## [0.3.78] — 2026-03-24
### Fixed
- Mijn Account productwissels: de productmodal toont bij variabele producten weer alleen de hoofdartikelen. Na keuze op het potlood verschijnen de attribuutselecties vervolgens op de abonnementspagina zelf in een afgeschermde edit-sectie, zodat klanten eerst een hoofdproduct kiezen en daarna pas de variatie-opties instellen.

## [0.3.77] — 2026-03-24
### Fixed
- Mijn Account abonnementen: productwissels via het potlood kiezen nu concrete varianten direct in de modal in plaats van daarna nog inline attribuut-dropdowns te verwachten. Daardoor oogt de abonnementspagina weer read-only buiten de potloodactie en wordt de echt gekozen product/variantcombinatie ook correct opgeslagen in het abonnement.

## [0.3.76] — 2026-03-24
### Fixed
- Backend abonnement-attributen: de virtuele order-items van het abonnement-ordertype krijgen nu weer hun interne subscription-meta (`base_product`, `base_variation`, `scheme`) en vallen voor zichtbare display-meta ook terug op de opgeslagen source snapshot. Daardoor zijn gekozen opties zoals voorheen weer zichtbaar in de backoffice, ook wanneer de frontend al correct was.

## [0.3.75] — 2026-03-24
### Fixed
- Abonnement-ordertype: corrupte of zelf-refererende `_hb_ucs_legacy_subscription_post_id` waarden worden niet langer als geldige legacy-bron behandeld. Daardoor vallen abonnementen weer correct terug op hun eigen order-type data voor items, btw en verzending, in plaats van leeg te laden zonder producten.

## [0.3.74] — 2026-03-24
### Fixed
- Renewal regressie: wanneer een order-type abonnement onvoldoende opgeslagen `_hb_ucs_sub_items` of verzend-/fee-meta had, viel de renewal terug op één minimale productregel zonder correcte btw of verzending. Renewals herstellen die data nu eerst uit de echte subscription-order items, taxes en shipping-regels zelf, zodat meerdere producten, btw en verzending weer correct meegenomen worden.

## [0.3.73] — 2026-03-24
### Fixed
- Legacy-sync hardening: admin- en account-acties die order-type abonnementen bijwerken maken niet langer impliciet een ontbrekend legacy-abonnement aan tijdens `sync_legacy_from_order()`. Daardoor veroorzaken datum/status/adres-wijzigingen en vergelijkbare synchronisaties geen onbedoelde extra records meer via andere callsites van dezelfde sync-logica.

## [0.3.72] — 2026-03-24
### Fixed
- Backend abonnement-editor: het handmatig aanpassen van de volgende betaling/verlengen-datum maakt niet langer eenmalig onbedoeld een extra legacy-abonnement aan. De admin-save synchroniseert nu alleen nog naar legacy-opslag wanneer er al een gekoppeld legacy-record bestaat.

## [0.3.71] — 2026-03-24
### Fixed
- Checkout/cart abonnement-prijzen gebruiken nu weer dezelfde prijsbasis als WooCommerce zelf. In winkels waar productprijzen inclusief btw worden ingevoerd, wordt de handmatige abonnementsprijs in winkelwagen en afrekenen niet langer op een exclusief-btw bedrag gezet, waardoor btw niet meer als schijnbare korting zichtbaar wordt.

## [0.3.70] — 2026-03-24
### Fixed
- Productpagina abonnement-prijzen volgen nu dezelfde zichtbare prijsbasis als de WooCommerce productprijs. Bij winkels die prijzen inclusief btw invoeren en tonen, gebruikt de abonnementsweergave nu die incl.-btw basis direct voor simpele producten en variaties, zodat de frontend productpagina niet langer exclusief btw toont.

## [0.3.69] — 2026-03-24
### Fixed
- Frontend abonnement-prijzen volgen nu weer correct de WooCommerce btw-weergave-instelling. Bij inclusief-btw weergave rekent de frontend itemprijzen voortaan consistent vanaf de opslagprijs en producttax-config, in plaats van in sommige gevallen terug te vallen op een opgeslagen prijs/tax-flag combinatie waardoor exclusief btw werd getoond.

## [0.3.68] — 2026-03-24
### Changed
- Onderhoud repositorylaag: `SubscriptionRepository` gebruikt nu gedeelde helpers voor uitgesloten display-meta keys, bron order-item-id resolutie en extractie van geselecteerde attributen uit order-items. Dit is een interne leesbaarheidsrefactor zonder beoogde gedragswijziging.

## [0.3.67] — 2026-03-24
### Changed
- Onderhoud subscriptionsmodule: kleine interne duplicaties in admin order-item meta-afhandeling zijn samengebracht in gedeelde helpers voor verborgen meta-keys, uitgesloten display-meta keys en subscription order-item context. Dit is een leesbaarheidsrefactor zonder beoogde gedragswijziging.

## [0.3.66] — 2026-03-24
### Changed
- Opschoning abonnementenmodule: dode code uit het admin item-meta pad is verwijderd, interne `source_order_item_id` meta wordt explicieter uitgesloten van zichtbare display-meta extractie, en dubbele `display_meta` extractie in de repository is samengevoegd tot één berekening.

### Fixed
- Voorraadmarkering: na basisvoorraad-afboeking op subscription-orders wordt `_hb_ucs_subs_base_stock_reduced` nu weer op de juiste plek opgeslagen, zodat de restore-logica correct blijft werken.

## [0.3.65] — 2026-03-24
### Fixed
- Backend performance: de admin item-meta fallback voor abonnementen heeft nu een expliciete recursie-guard en leest in dat pad geen `formatted_meta_data` meer uit hetzelfde item. Dit voorkomt herhaalde nested WooCommerce meta-opbouw bij het openen van orders en abonnementen in de backoffice.

## [0.3.64] — 2026-03-24
### Fixed
- Backend performance: het openen van orders en abonnementen triggert niet langer een recursieve tweede `formatted_meta_data` opbouw voor subscription-items. De admin-fallback voor zichtbare attributen leest nu in dat pad alleen directe meta, waardoor backend detailpagina’s weer normaal snel moeten laden.

## [0.3.63] — 2026-03-24
### Fixed
- Performance: de extra fallback die ontbrekende abonnement-attributen zichtbaar maakt in WooCommerce order-item-meta draait nu alleen nog in de backend. Daardoor blijft de backoffice-weergave compleet, terwijl frontend en checkout niet onnodig hetzelfde zwaardere herstelpad uitvoeren.

## [0.3.62] — 2026-03-24
### Fixed
- Backend abonnementen/order-items: WooCommerce admin krijgt nu dezelfde zichtbare attribuut-fallback als de frontend. Ontbrekende item-meta wordt tijdens backend render aangevuld vanuit opgeslagen geselecteerde attributen en, indien nodig, het exacte bron-orderitem. Daardoor worden opties zoals `Maling` nu ook in de backoffice zichtbaar bij abonnementen en renewals.

## [0.3.61] — 2026-03-24
### Fixed
- Abonnementen: gekozen opties die wel in `selected_attributes` zitten maar niet als echte Woo variatie-attribute in de productconfig voorkomen, worden nu altijd omgezet naar zichtbare abonnement-meta. Daardoor worden waarden zoals `Maling` ook zonder aparte losse order-meta correct zichtbaar in abonnementen en renewals. Deze fallback is gecontroleerd op testorder 420 / abonnement 421.

## [0.3.60] — 2026-03-24
### Fixed
- Abonnementen: gekozen opties uit de eerste order worden nu als blijvende bron-snapshot op subscription-items opgeslagen, inclusief zichtbare item-meta en gekozen attributen. Tijdens sync wordt een bestaand bron-item-id niet meer onterecht overschreven door subscription-order item ids, en renewals/UI vallen nu eerst terug op die snapshot wanneer het originele order-item niet meer direct beschikbaar is.

## [0.3.59] — 2026-03-24
### Fixed
- Abonnementen: elk subscription-item bewaart nu het exacte bronregel-ID van de eerste order. Bestaande abonnementen herstellen die koppeling automatisch vanuit de parent order, abonnementweergaves vullen zichtbare item-meta daarvan opnieuw aan, en renewals nemen die zichtbare meta voortaan direct van die eerste orderregel over.

## [0.3.58] — 2026-03-24
### Fixed
- Abonnementen: zichtbare orderregel-meta uit Woo formatted item meta wordt nu ook meegenomen wanneer de onderliggende meta-key intern met `_` begint. Daardoor blijven opties zoals `Maling` niet meer onterecht buiten abonnementen en renewals.

## [0.3.57] — 2026-03-24
### Fixed
- Abonnementen: zichtbare orderregel-meta die geen echt variatie-attribuut is, zoals `Maling`, wordt nu apart opgeslagen op subscription-items, automatisch hersteld vanuit de eerste order voor bestaande abonnementen, en ook weer getoond in Mijn Account, admin en renewal/order-syncs.

## [0.3.56] — 2026-03-24
### Fixed
- Abonnementen: intern opgeslagen `_hb_ucs_subscription_selected_attributes` op de eerste orderregel dient nu alleen nog als startset. Ontbrekende attributen worden daarna alsnog aangevuld vanuit de overige orderregel-meta en Woo formatted meta, zodat keuzes zoals `Maling` niet meer wegvallen.

## [0.3.55] — 2026-03-24
### Fixed
- Abonnementen: gekozen variatie-attributen worden nu op abonnementregels zichtbaar getoond vanuit de opgeslagen `selected_attributes`, zowel in Mijn Account als in de admin abonnementsregels. Interne orderregel-meta `_hb_ucs_subscription_selected_attributes` wordt daarnaast verborgen uit WooCommerce meta-weergaves.

## [0.3.54] — 2026-03-24
### Fixed
- Abonnementen: de eerste orderregel bewaart nu de exacte checkout-variatiekeuze ook in eigen HB UCS item-meta, gebaseerd op WooCommerce `values['variation']`. De abonnement-aanmaak gebruikt die bron nu als primaire waarheid, zodat geen gekozen attribuut meer verloren gaat tussen checkout en abonnement.

## [0.3.53] — 2026-03-24
### Fixed
- Abonnementen: de eerste order → abonnement extractie vertaalt nu ook WooCommerce order-item variatiemeta zonder `attribute_`-prefix direct terug naar de canonical attribuutkeys en leest alle formatted meta entries uit. Hierdoor worden ook resterende ontbrekende variatie-attributen correct meegenomen.

## [0.3.52] — 2026-03-23
### Fixed
- Abonnementen: de initiële order → abonnement attribuutovername leest nu naast ruwe attribute-meta ook WooCommerce formatted item meta uit. Daardoor worden ook variatiekeuzes die alleen als zichtbare orderregel-meta aanwezig zijn correct teruggezet in het abonnement.

## [0.3.51] — 2026-03-23
### Fixed
- Abonnementen: bij het aanmaken vanuit de eerste bestelling worden ontbrekende variatie-attributen nu per attribuut aangevuld vanuit de orderregel-meta, ook wanneer een deel al via de variatie bekend is. Hierdoor gaan geen geselecteerde attribuutwaarden meer verloren bij variabele abonnementsartikelen.

## [0.3.50] — 2026-03-23
### Fixed
- Abonnementen: variatie-attributen worden niet meer dubbel zichtbaar/opgeslagen op subscription- en renewal-orderitems. Wanneer een abonnementsregel al aan een WooCommerce-variatieproduct gekoppeld is, voegt HB UCS die attribuutmeta niet nogmaals handmatig toe.

## [0.3.49] — 2026-03-23
### Fixed
- Abonnementen: bij het aanmaken van een nieuw abonnement vanuit de eerste bestelling worden gekozen variatie-attributen nu expliciet meegeslagen in de opgeslagen subscription items. Daardoor blijven variabele abonnementsartikelen dezelfde attribuutselectie houden voor de actieve abonnementsweergave en voor renewals.

## [0.3.48] — 2026-03-23
### Fixed
- Abonnementen: ongeldige of lege variatie-attributen zoals een lege `attribute_`-key worden nu weggefilterd uit de actieve productweergave, attribuutselectors en renewal order item-meta. Daardoor verschijnt geen extra leeg attribuutveld meer met alleen “Kies een optie…” en komt die vervuiling ook niet meer op pakbonnen terecht.

## [0.3.47] — 2026-03-23
### Changed
- Abonnementen: de melding over een gekoppelde open bestelling op Mijn Account gebruikt nu de frontend WooCommerce-statusnamen in plaats van de interne statussleutels `on-hold` en `processing`.

## [0.3.46] — 2026-03-23
### Fixed
- Abonnementen: attribuut-selectievelden van variabele producten op Mijn Account blijven nu binnen de productkaart en het scherm zichtbaar. De attribute-grid en selects schalen voortaan responsief mee met de beschikbare breedte, zonder horizontale overflow op kleine schermen.

## [0.3.45] — 2026-03-23
### Changed
- Abonnementen: de productkaarten op Mijn Account hebben nu een lichtere volledige border voor duidelijkere scheiding, responsieve tekstgroottes die leesbaar blijven bij verschillende schermbreedtes, en verbeterde small-screen uitlijning zodat de kaart centraal staat, de beschikbare breedte beter benut en geen tekst buiten beeld valt of horizontale scroll veroorzaakt.

## [0.3.44] — 2026-03-23
### Changed
- Abonnementen: de productpicker-label in Mijn Account toont nu niet alleen de productnaam, maar ook alle gekozen attribuutwaarden van het geselecteerde artikel. Deze labeltekst wordt zowel bij eerste render als live tijdens frontend attribuutwijzigingen bijgewerkt.

## [0.3.43] — 2026-03-23
### Changed
- Abonnementen: de responsive productrij op Mijn Account blijft nu ook rond tablet/kleinere schermen naast de productafbeelding uitgelijnd. De naam en prijs blijven naast de afbeelding staan, de quantity-stepper staat daaronder en de pencil/trash acties blijven op de volgende rij naast elkaar in plaats van onder elkaar.

## [0.3.42] — 2026-03-23
### Changed
- Abonnementen: vanaf tabletbreedte is de productrij op Mijn Account compacter opgebouwd. De abonnementsnaam en prijs per levering staan nu direct naast de afbeelding, daaronder volgt eerst de quantity-stepper en op de volgende rij de pencil- en trash-icon acties naast elkaar. Daarnaast zijn de overbodige `hb-ucs-product-picker-field__label` labels uit de productpicker-markup verwijderd.

## [0.3.41] — 2026-03-23
### Changed
- Abonnementen: de laatste oude remove-sporen in Mijn Account zijn opgeschoond. De frontend gebruikt niet langer de oude `hb-ucs-product-card__dismiss` knop of het oude `items[..][remove]` veld in de productrij-markup; in plaats daarvan wordt nu alleen nog de compacte trash-knop met een interne `_hb_ucs_remove` flag gebruikt.

## [0.3.40] — 2026-03-23
### Changed
- Abonnementen: de productlijst op Mijn Account is opgeschoond. De dubbele verwijder-checkbox is verwijderd, productregels gebruiken nu compacte trash- en pencil-icon acties, de hoeveelheid-stepper staat direct in de bovenste actiezone, en de lijst heeft een duidelijkere maar rustige scheiding tussen productrijen.

## [0.3.39] — 2026-03-23
### Fixed
- Abonnementen: in Mijn Account toont de productkaart nu bij “per levering” het bedrag voor de volledige productregel inclusief het gekozen aantal, in plaats van altijd alleen de prijs voor één stuk. Daardoor blijft de frontend kaartprijs ook bij aantallen groter dan 1 gelijk aan de echte abonnementsprijs per levering.

## [0.3.38] — 2026-03-23
### Fixed
- Abonnementen: nieuw toegevoegde producten vanuit Mijn Account gebruiken nu overal dezelfde exclusief-btw opslagprijs als bron voor subscription pricing, cart-data en opgeslagen itemregels, terwijl de frontend prijsweergave apart volgens de WooCommerce shop-instelling (incl./excl. btw) wordt opgebouwd. Daardoor wordt na opslaan niet langer nogmaals btw bovenop de al juiste frontend prijs gezet.

## [0.3.37] — 2026-03-23
### Fixed
- Abonnementen: na frontend product- of adreswijzigingen worden subscription-itemprijzen nu altijd teruggeschreven als exclusief-btw opslagprijs, met btw apart herberekend. Daarnaast gebruikt de backend shadow-order synchronisatie voortaan dezelfde storage-prijsnormalisatie. Daardoor komt in de backend op de ex-btw plek niet langer de incl.-btw prijs terecht en wordt product-btw niet nog eens extra bovenop die prijs opgeteld.

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
