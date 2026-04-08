# Changelog — HB Unified Commerce Suite

Alle noemenswaardige wijzigingen aan deze plugin worden in dit bestand bijgehouden.

Het formaat is geïnspireerd op “Keep a Changelog”.

## [0.3.160] — 2026-04-08
### Fixed
- Abonnement-frontend: het opslaan van artikelwijzigingen in Mijn Account bouwt subscription-items nu weer direct op uit de geposte selectie, in plaats van via de previewlaag. Daardoor blijven gekozen variatie-attributen zoals `Maling` behouden in dezelfde save/readback-stroom die eerder al correct werkte.

## [0.3.159] — 2026-04-08
### Fixed
- Abonnement-opslag: bij opslaan van frontend artikelwijzigingen blijft de geposte attribuutselectie nu leidend op het subscription-item, zodat gekozen opties zoals `Maling` niet meer wegvallen tijdens preview of persist.
- Abonnement-readback: aliassen van hetzelfde variatie-attribuut worden bij het teruglezen uit order-item meta niet meer opnieuw naast elkaar toegevoegd.

## [0.3.158] — 2026-04-08
### Fixed
- Abonnement-frontend: zodra een gekozen attribuutset matcht op een variatie, blijft de live samenvatting op de kaart nu gebaseerd op alle gekozen selectors in plaats van alleen op de WooCommerce-variatiesamenvatting. Daardoor blijven extra gekozen variatie-attributen zoals `Maling` zichtbaar tijdens bewerken.

## [0.3.157] — 2026-04-08
### Fixed
- Abonnement-attributen: aliassen zoals `attribute_pa_*` en `attribute_*` worden nu per product naar dezelfde canonieke attribuutkey teruggebracht. Daardoor blijft een gekozen variatie-attribuut zonder resolvable `variation_id` behouden en verdwijnt dubbele weergave van dezelfde keuze met en zonder `pa_`-variant.

## [0.3.156] — 2026-04-08
### Fixed
- Abonnement-attributen: een gekozen variatie-attribuutset zonder aparte `variation_id` blijft nu bewaard op het parent product, zolang de verplichte variatie-attributen compleet zijn.
- Abonnement-backend: gekozen variatie-attributen worden niet langer dubbel getoond als zowel variatiesamenvatting als losse meta-regels op dezelfde orderregel.

## [0.3.155] — 2026-04-08
### Fixed
- Abonnement-attributen: de productpicker en attribuutweergave tonen nu weer alleen attributen die echt als variatie-attribuut zijn gemarkeerd. Optionele productattributen blijven daarmee buiten de zichtbare abonnement-selectie.

## [0.3.154] — 2026-04-08
### Fixed
- Abonnement-attributen: optionele, niet-variatie-attributen van variabele producten blijven nu beschikbaar in de productpicker, worden niet meer weggenormaliseerd tijdens preview en opslag, en blijven zichtbaar naast de echte variatie-attributen. Alleen de echte variatie-attributen worden nog gebruikt om een WooCommerce-variatie te resolven.

## [0.3.153] — 2026-04-08
### Fixed
- Abonnement-attributen: order-item meta met attribuutachtige labels wordt niet langer opnieuw als gewone `display_meta` ingelezen. Daardoor blijft attribuutweergave volledig gebaseerd op de actuele `selected_attributes` opslag, zonder dubbele `pa_`/`attribute_pa_` varianten of ontbrekende combinaties door vervuilde meta.

## [0.3.152] — 2026-04-08
### Fixed
- Abonnement-attributen: labels als `attribute_pa_*`, `pa_*` en nette attribuutlabels worden nu allemaal naar dezelfde attribuutnaam genormaliseerd. Daardoor worden dubbele attribuutregels met en zonder prefix eindelijk als hetzelfde attribuut herkend en correct weggefilterd in frontend en backend.

## [0.3.151] — 2026-04-08
### Fixed
- Backend abonnementitems: admin-meta voor subscription order items filtert nu ook attribuutlabels zelf weg wanneer WooCommerce die al als geformatteerde meta zou tonen. Daardoor verdwijnen dubbele attribuutregels met en zonder `pa_` prefix in de backend.
- Backend abonnementitems: de custom meta-injectie bouwt attribuutregels nu altijd eerst op uit de actuele geselecteerde attributen en voegt alleen nog niet-attribuutmeta aanvullend toe. Daardoor blijven alle actuele gekozen variatie-attributen zichtbaar zonder terugval op verouderde bronmeta.

## [0.3.150] — 2026-04-08
### Fixed
- Abonnementen: nieuw opgebouwde subscription-items zonder echte bestaande orderregel bewaren niet langer automatisch nulwaardes als `line_subtotal`, `line_tax` en `line_total`. Daardoor worden verse productwijzigingen weer normaal uit actuele prijs- en belastingdata berekend en verschijnt de productprijs niet meer ten onrechte als korting in de backend.

## [0.3.149] — 2026-04-08
### Fixed
- Frontend abonnementen: bij het teruglezen van gewijzigde subscription-items wordt niet langer de oude bron-orderregel via `source_order_item_id` gebruikt voor prijs- of metaweergave. De frontend blijft daardoor na een productwijziging op de actuele subscription-item data.
- Frontend abonnementen: `display_meta` bewaart geen productattribuutregels meer. Gekozen variatie-attributen worden daardoor niet meer dubbel getoond naast order-item meta en alle actuele gekozen attributen komen uit de actuele selectie.

## [0.3.146] — 2026-04-08
### Fixed
- Abonnement-variaties: bij het teruglezen van subscription order items zijn de opgeslagen selectie en de variatie zelf nu leidend. Ruwe order-item attribuutmeta vult alleen nog ontbrekende waarden aan en overschrijft geen actuele selectie meer.
- Abonnement-variaties: HPOS subscription order items schrijven niet langer een extra WooCommerce `set_variation(...)` attribuutspoor weg. Daarmee verdwijnen dubbele backend-attribuutregels met `pa_`/zonder `pa_` en kan oude ruwe attribuutmeta de frontend-prijs of selectie niet meer vervuilen.

## [0.3.148] — 2026-04-08
### Changed
- Abonnementen gebruiken nu geen `source_item_snapshot` fallback meer voor productweergave, geselecteerde attributen of ordertotalen. De subscriptionsmodule leest daarvoor uitsluitend actuele order-item data en expliciet opgeslagen actuele itemvelden.

### Fixed
- Frontend en backend abonnementregels kunnen niet langer terugvallen op verouderde snapshotdata voor prijs- of attribuutweergave. Huidige line totals worden nu direct op het subscription-item opgeslagen en vervolgens als actuele orderdata gebruikt.

## [0.3.147] — 2026-04-08
### Fixed
- Frontend abonnementen: bij opslaan op Mijn Account wordt oude `display_meta` of een oude item-snapshot alleen nog hergebruikt wanneer exact dezelfde variatie behouden blijft. Simpele producten krijgen daarbij geen oude attribuutregels meer mee, zodat attributen van een volgend variabel artikel niet meer bij een simpel artikel in de lijst verschijnen.

## [0.3.145] — 2026-04-08
### Fixed
- Frontend abonnementen: bij het opbouwen van een subscription-item uit een gekozen variatie worden handmatig gekozen attributen niet meer overschreven door een mogelijk onvolledige attribuutset van de WooCommerce-variatie. De gebruikersselectie en variatie-attributen worden nu samengevoegd, zodat alle gekozen variatie-attributen behouden blijven.

## [0.3.144] — 2026-04-08
### Fixed
- Backend abonnementitems: de extra handmatige `attribute_*` order-item meta voor gekozen variatie-attributen wordt niet meer apart weggeschreven. Daardoor verschijnt in de backend geen tweede, potentieel verouderde `PA_*` attribuutregel meer naast de actuele WooCommerce-variatiegegevens.

## [0.3.143] — 2026-04-08
### Fixed
- Frontend abonnementen: bestaande variabele artikelen tonen in de attribute-samenvatting op Mijn Account nu alleen de actuele gekozen variatie-attributen. Oude attribuutregels uit achtergebleven display-meta worden daar niet langer opnieuw weergegeven.

## [0.3.142] — 2026-04-08
### Fixed
- Frontend abonnementen: gekozen variatie-attributen worden nu expliciet op het HPOS subscription order item opgeslagen, zowel als JSON-snapshot als per individueel `attribute_*` order-item meta veld. Daardoor blijven alle gekozen variatie-opties bij een volgende bewerking beschikbaar.
- Frontend abonnementen: bij opslaan wordt een oude snapshot of oude display-meta niet meer hergebruikt wanneer alleen de variatiekeuze onder hetzelfde parent-product is veranderd.

## [0.3.141] — 2026-04-08
### Fixed
- Frontend abonnementen: het opslaan van een toegevoegd simpel product op Mijn Account valt nu terug op directe item-opbouw wanneer de preview-resolutie geen preview-item teruggeeft. Daardoor worden geldige simpele producten niet meer onterecht afgekeurd met de variatie-foutmelding.

## [0.3.140] — 2026-04-08
### Fixed
- Frontend abonnementen: het opslaan van toegevoegde producten op Mijn Account gebruikt nu dezelfde selectie-resolutie als de preview, inclusief fallback naar bestaande variatie-attributen wanneer alleen het aantal wijzigt.
- Frontend abonnementen: de product-preview toont een variatie pas zodra alle verplichte opties van een variabel product zijn gekozen. Daardoor ontstaat geen schijnbaar geldige keuze meer die server-side alsnog wordt afgekeurd.

## [0.3.139] — 2026-04-08
### Changed
- Frontend abonnementen: productregels op Mijn Account gebruiken nu, net als verzendkosten, rechtstreeks de HPOS order-line totals wanneer die beschikbaar zijn. Daardoor worden productprijzen niet meer apart herberekend zonder btw terwijl verzending al inclusief btw uit orderregels kwam.

## [0.3.138] — 2026-04-08
### Changed
- Frontend abonnementen: productregels, toeslagen, verzendkosten en totalen op Mijn Account volgen nu dezelfde standaard WooCommerce prijsweergave-instelling als de rest van de shop (`incl.` of `excl.` btw), in plaats van een aparte account-specifieke btw-weergave af te dwingen.

## [0.3.137] — 2026-04-08
### Changed
- Abonnementen: de online/live bron voor subscription items, toeslagen en verzendregels gebruikt nu eerst HPOS order items van het subscription order object in plaats van `_hb_ucs_sub_*` shadow-meta.
- Abonnementen: wanneer deze module subscription items, toeslagen of verzendregels opslaat, worden de HPOS order items nu direct mee bijgewerkt. Daardoor kan een backend-save zonder inhoudelijke wijziging de frontend-prijzen niet meer via alleen shadow-meta laten verschuiven.

## [0.3.136] — 2026-04-08
### Changed
- Abonnementen: de templated product-picker variant op Mijn Account gebruikt nu ook een neutralere focus-state. De standaard HB UCS focus-ring op de wrapper wordt niet meer toegepast; alleen een subtiele outline op de Elementor-output blijft over voor toetsenbordnavigatie.

## [0.3.135] — 2026-04-08
### Changed
- Abonnementen: productkaarten in de frontend product-picker popup gebruiken bij Elementor Loop Item weergave niet langer een `button` wrapper, maar neutrale interactieve markup met dezelfde klik- en toetsenbordbediening. Daardoor lekt er geen browser- of thema-buttonstyling meer door naar loop items.

## [0.3.134] — 2026-04-08
### Changed
- Abonnementen: wanneer de product-picker popup een Elementor Loop Item template gebruikt, wordt de resterende HB UCS kaartstyling op die templated variant niet meer toegepast. Daardoor blijft alleen de Elementor-opmaak zichtbaar.

## [0.3.133] — 2026-04-08
### Changed
- Abonnementen: frontend prijzen op Mijn Account gebruiken nu dezelfde btw-inclusieve prijsbasis en abonnementsklant-taxcontext als de backend, zodat lijstweergave, detailsamenvatting, prijsopbouw en productregels dezelfde bedragen tonen.

## [0.3.132] — 2026-04-08
### Added
- Abonnementen: in de subscriptions-instellingen kan nu optioneel een Elementor Loop Item template worden gekozen voor de product-picker popup op Mijn Account.

### Changed
- Abonnementen: als een Elementor Loop Item template is geselecteerd, gebruikt de product-picker popup dat template voor de productkaartweergave. Als er geen template is gekozen of Elementor niets rendert, blijft de bestaande HB UCS kaart automatisch als fallback actief.

## [0.3.131] — 2026-04-08
### Added
- Abonnementen: nieuwe WordPress menu-locatie `HB UCS abonnementen productfilters` toegevoegd. Daarmee kunnen productcategorie-filters voor de frontend abonnementen-popup via het reguliere WordPress menu-beheer worden samengesteld en toegewezen.

### Fixed
- Abonnementen: de product-picker popup op Mijn Account toont producten nu als duidelijke kaarten met afbeelding, prijs en een consistente themastijl.
- Abonnementen: de categorie-filter gebruikt nu menu-gestuurde knoppen in plaats van de oude, onduidelijke dropdown.
- Abonnementen: de product-picker popup gebruikt nu een echte server-side live search. Zoeken en klikken op een menu-filter halen nu direct vanaf de server alleen producten op die voor abonnementsvormen zijn ingeschakeld en binnen de gekozen categorie vallen.

## [0.3.130] — 2026-04-08
### Added
- Abonnementen: nieuwe WP-CLI command `wp hb-ucs subscriptions backfill-order-meta` toegevoegd om in productie veilig de canonieke subscription-meta op WooCommerce order-type records te backfillen vanuit legacy-named meta op hetzelfde record.
- Documentatie: nieuw bestand `docs/DEPENDENCIES.md` toegevoegd met de volledige dependency-matrix van modules, optionele integraties, WooCommerce/HPOS gebruik en release-afhankelijkheden.

### Changed
- Abonnementen: de module draait nu alleen nog op de eigen HB UCS subscription-engine. De instellingen en runtime vallen niet langer terug op WooCommerce Subscriptions, zodat nieuwe abonnementen en renewals nog maar één interne bron van waarheid gebruiken.
- Releaseproces: `docs/RELEASING.md` vereist nu ook controle en update van `docs/DEPENDENCIES.md` als afhankelijkheden of integraties wijzigen.

### Fixed
- Abonnementen: renewal-creatie leest subscription-items nu zonder automatische repair-writeback en resolved de renewal-betaalmethode zonder direct het abonnement zelf te herschrijven. Daardoor kan het aanmaken van een renewal-order niet meer onbedoeld opgeslagen abonnementsprijzen of betaaldata muteren.
- Abonnementen: de frontend accountweergave valideert eigenaarschap van abonnementen nu expliciet via WooCommerce customer-id en interne eigenaar-meta. Ook gerelateerde bestellingen op de detailpagina worden alleen nog getoond als ze bij dezelfde account horen. Daardoor kunnen klanten niet langer abonnementen of gekoppelde orderdata van andere accounts zien als een query of datakoppeling te breed uitvalt.

### Removed
- Abonnementen: WCS migratie- en exporthooks worden niet meer geregistreerd en runtime-context leest geen fallback-data meer uit gekoppelde WCS bronabonnementen. Dat verkleint de kans op sync- en herstelverschillen met oude externe brondata.
- Abonnementen: dode WCS child-product, migratie- en add-to-cart paden zijn uit de subscriptions-module verwijderd, zodat de runtime alleen nog HB UCS eigen logica gebruikt.
- Abonnementen: dual-storage sync naar gekoppelde legacy subscription posts is uitgeschakeld. De WooCommerce order-type records blijven de enige actieve bron van waarheid, terwijl de WooCommerce admin- en orderintegratie intact blijft.

## [0.3.129] — 2026-04-07
### Fixed
- Abonnementen: renewal-productregels herstellen nu ook voor gewone HB UCS-abonnementen automatisch fout opgeslagen bruto `unit_price` waarden naar netto-opslag zodra de bewaarde tax-breakdown laat zien dat de artikelprijs inclusief btw was. Daardoor tonen renewal-orders weer de juiste ex-btw productprijs, terwijl bestaande abonnementen direct vanuit hun opgeslagen itemdata worden gecorrigeerd.

## [0.3.128] — 2026-04-06
### Fixed
- Abonnementen: due subscriptions die vastlopen in `payment_pending` of `pending_mandate` zonder open renewal-order krijgen nu een herstelpad in de minuutcron. Daardoor kunnen gemiste renewals weer doorstromen zodra er geen echte open renewal meer bestaat.
- Abonnementen: open en laatste renewal-orders worden nu ook gezocht over gekoppelde legacy- en order-type subscription-id's heen. Dat verkleint de kans dat een bestaande renewal-order gemist wordt en dezelfde renewal opnieuw wordt aangemaakt.
- Abonnementen: `last_order_id` en `last_order_date` worden nu consequent op gekoppelde subscription-records bijgewerkt, zodat duplicate guards en statusherstel op beide opslaglagen dezelfde ordercontext zien.
- Abonnementen: de renewal-flow logt nu de kritieke stappen rond orderopbouw, statuswissels en Mollie recurring-aanvragen. Onverwachte exceptions ruimen halflege nieuwe renewal-orders zonder regels of Mollie payment-id direct op in plaats van ze als losse `pending` orders te laten staan.
- Abonnementen: renewal fee- en shippingregels roepen niet langer protected WooCommerce `set_total_tax()` methodes aan. Daardoor crasht de renewal-opbouw niet meer zodra er belasting op verzend- of fee-regels aanwezig is.
- Abonnementen: renewal productregels leiden hun netto- en btw-bedragen nu direct af uit de opgeslagen abonnementsprijs en tax-breakdown. Daardoor wordt btw niet nogmaals van dezelfde abonnementsprijs afgehaald en sluiten renewal-orderbedragen weer aan op de abonnementprijzen.
- Abonnementen: de volgende betaaldatum wordt bij renewal-creatie nu minimaal vanaf het actuele aanmaaktijdstip doorgeschoven. Achterstallige abonnementen kunnen daardoor niet meer op dezelfde dag maar een paar minuten vooruit springen.

## [0.3.127] — 2026-04-06
### Fixed
- Abonnementen: de orderlijst-indicator voor gewone WooCommerce bestellingen doet niet langer per orderregel extra subscription-opzoekqueries. Bestaande ordermeta wordt nu leidend gebruikt, zodat het backend bestellingenoverzicht merkbaar lichter blijft.
- Abonnementen: handmatige backend productkeuzes op abonnementen worden weer catalogus-gestuurd opgeslagen. Bij het opnieuw opbouwen van subscription-items krijgen schema-prijzen nu voorrang boven oude fallback- of bronorderprijzen.
- Abonnementen: online renewal-orders krijgen nu een hardere duplicaatbeveiliging. Een bestaande open renewal-order wordt eerst opgezocht via de laatst gekoppelde order en via WooCommerce-statuskeys, terwijl de subscription tijdens een lopende online betaling tijdelijk op `payment_pending` gaat zodat de minuutcron geen tweede renewal blijft aanmaken.
- Abonnementen: mandate/online renewals forceren hun orderstatus nu opnieuw naar `on-hold` na de laatste save-stap rond Mollie payment-meta, zodat ze niet onbedoeld als `pending` blijven hangen.
- Abonnementen: renewal-items worden nu volledig gevalideerd voordat een nieuwe WooCommerce-order wordt aangemaakt. Daardoor kan een mislukte productresolutie geen lege renewal-order zonder regels meer achterlaten.

## [0.3.126] — 2026-04-01
### Fixed
- Abonnementen: handmatig aangemaakte renewal-orders vanuit de abonnementeditor behouden nu de direct doorgeschoven volgende betaaldatum. De admin-save schrijft de oude formulierdatum niet langer terug over de verse renewal-datum, terwijl expliciete handmatige datumwijzigingen en automatische online renewals ongewijzigd blijven.

## [0.3.125] — 2026-03-30
### Fixed
- Abonnementen: het interne HB UCS subscription order type `shop_subscription_hb` is nu uitgesloten van standaard WooCommerce order webhooks. Externe systemen ontvangen daardoor niet langer zowel het subscription-record als de gekoppelde echte order als twee losse orders.

## [0.3.124] — 2026-03-30
### Fixed
- Abonnementen: de minuutcron voor pending mandates en runtime-sync slaat subscription-status, mandate- en payment-meta niet langer opnieuw op als er inhoudelijk niets veranderde. Daardoor veroorzaken niet-due abonnementen geen onnodige order-type saves en externe WooCommerce webhooks meer.

## [0.3.123] — 2026-03-30
### Added
- Abonnementen: in het WooCommerce bestellingenoverzicht kun je `shop_order`-regels nu expliciet onderscheiden via een nieuwe filter voor gewone bestellingen, abonnement-gerelateerde orders, startbestellingen en renewal-orders. Interne orderoverzicht-links met `hb_ucs_subscription_id` filteren nu ook mee op het gekoppelde abonnement.

## [0.3.122] — 2026-03-25
### Fixed
- Abonnementen: SEPA-renewals sturen niet langer direct een renewal-factuur/betaalverzoek; de klant ontvangt nu bij de on-hold automatische incasso-order een informatieve verlengingsmail met uitleg dat de incasso loopt en dat verzending/bevestiging volgt na succesvolle betaling.

## [0.3.121] — 2026-03-25
### Fixed
- Abonnementen: runtime status-updates spiegelen nu ook naar gekoppelde legacy/order-type opslaglagen en renewal-eligibility weigert elke gekoppelde status die niet `active` is, zodat abonnementen op `on-hold` of `paused` geen renewals meer aanmaken door stale sync-meta.

## [0.3.120] — 2026-03-25
### Fixed
- Abonnementen: de renewal-cron verwerkt nu due abonnementen in batches met uitsluiting van al behandelde IDs en sorteert op volgende betaaldatum, zodat productie-runs niet meer stil blijven hangen op alleen de eerste 10 te verlengen abonnementen.

## [0.3.119] — 2026-03-25
### Added
- In de module `Besteloverzicht statussen` kun je de volgorde van statussen nu wijzigen via drag-and-drop in de instellingen. Die volgorde wordt direct gebruikt in de orderoverzicht-dropdown en filterlijst.

## [0.3.118] — 2026-03-25
### Changed
- De extra status-dropdown in het WooCommerce bestellingenoverzicht gebruikt weer de volledige statuskleur op de gesloten geselecteerde dropdown, zodat de actieve status direct duidelijk zichtbaar is in het overzicht.

## [0.3.117] — 2026-03-25
### Changed
- De extra status-dropdown in het WooCommerce bestellingenoverzicht toont nu bij de actieve status een subtiele kleurindicator in het gesloten overzicht, terwijl de dropdown zelf verder neutraal blijft.

## [0.3.116] — 2026-03-25
### Changed
- De gesloten extra status-dropdown in het WooCommerce bestellingenoverzicht blijft nu neutraal van achtergrond en rand, maar toont de geselecteerde status wel in de juiste WooCommerce-statuskleur zodat de actieve waarde direct zichtbaar is in het overzicht.

## [0.3.115] — 2026-03-25
### Changed
- De extra status in het WooCommerce bestellingenoverzicht is weer één enkele dropdown. De gesloten dropdown blijft neutraal, terwijl alleen de waarden in de dropdownlijst hun eigen statuskleur gebruiken.

## [0.3.114] — 2026-03-25
### Changed
- De extra statusweergave in het WooCommerce bestellingenoverzicht gebruikt nu een aparte gekleurde badge naast een neutrale dropdown. Daardoor blijft alleen de zichtbare status gekleurd in het overzicht, terwijl de dropdown zelf standaard oogt en geopende opties hun eigen kleur behouden.

## [0.3.113] — 2026-03-25
### Changed
- De tekstkleuren van de extra HB UCS-statussen zijn nu exact gelijkgetrokken met de standaard WooCommerce admin-orderstatuskleuren, zodat de gekleurde statusdropdown visueel hetzelfde kleurcontrast gebruikt als de native orderstatus-badges.

## [0.3.112] — 2026-03-25
### Changed
- De gekleurde extra status-dropdown in het WooCommerce bestellingenoverzicht gebruikt nu dezelfde normale tekstdikte als standaard WooCommerce-selects, zodat de kolomweergave visueel beter aansluit op de standaard orderregels.

## [0.3.111] — 2026-03-25
### Added
- In het WooCommerce bestellingenoverzicht kun je nu ook filteren op de extra HB UCS-overzichtsstatussen via een dropdown boven de orderlijst, zowel voor klassieke orders als HPOS.

## [0.3.110] — 2026-03-25
### Changed
- De module `Besteloverzicht statussen` dwingt statuslabels nu af op maximaal 28 tekens, zowel in de instellingen-UI als tijdens server-side opslag, zodat labels netjes leesbaar blijven in de WooCommerce orderkolom.

## [0.3.109] — 2026-03-25
### Added
- De module `Besteloverzicht statussen` ondersteunt nu per status een vaste WooCommerce-kleurkeuze uit standaard orderstatuskleuren. Die kleur is meteen zichtbaar in de dropdownkolom van het WooCommerce bestellingenoverzicht.

## [0.3.108] — 2026-03-25
### Added
- Nieuwe module `Besteloverzicht statussen` toegevoegd. Beheerders kunnen nu in HB UCS eigen extra orderoverzicht-statussen beheren en deze als aparte dropdownkolom direct in het WooCommerce bestellingenoverzicht aanpassen; wijzigingen worden meteen op de order opgeslagen.

## [0.3.107] — 2026-03-24
### Fixed
- Backend abonnementen rondde interne ex-btw regelprijzen tijdens sync/heropbouw nog te vroeg af op de zichtbare shopprecisie (`wc_get_price_decimals()`, vaak 2 decimalen). De repository en datastore gebruiken nu WooCommerce-interne afrondingsprecisie voor opslag- en rekentussenstappen, zodat handmatige kortingen van 1 cent niet meer verdwijnen door een te vroege afronding.

## [0.3.106] — 2026-03-24
### Fixed
- Backend handmatige abonnementskortingen bleven terugvallen omdat HB UCS bij extractie en rebuild nog maar één prijs per regel bewaarde en daarna `subtotal` en `total` opnieuw gelijk zette. De sync bewaart nu de actuele regelprijs uit `get_total()` én de referentieprijs uit `get_subtotal()`/`catalog_unit_price`, zodat backend abonnementregels hun handmatige korting behouden na opslaan.

## [0.3.105] — 2026-03-24
### Fixed
- Backend abonnementskortingen bleven bij self-sync nog fout terugvallen naar de originele regelprijs, omdat de repository de abonnementsprijs uit `line_item->get_subtotal()` haalde in plaats van uit het werkelijk opgeslagen afgeprijsde `line_item->get_total()`. Order-type abonnementen nemen handmatige backend-kortingen nu correct over vanuit het echte regel-totaal.

## [0.3.104] — 2026-03-24
### Fixed
- Order-type abonnementen zonder gekoppelde legacy-post hielden hun opgeslagen `_hb_ucs_sub_items`, fee-lines en shipping-lines op het orderrecord zelf nog op oude waarden, ook wanneer de echte WooCommerce orderregels al waren bijgewerkt. Die gestructureerde order-meta wordt nu tijdens self-sync ook direct ververst, zodat backendweergave en vervolgsyncs niet meer terugvallen op verouderde prijsregels of handmatige kortingen overschrijven.

## [0.3.103] — 2026-03-24
### Fixed
- Backend abonnement-save voert de HB UCS synchronisatie nu pas aan het einde van de request uit, nadat WooCommerce zelf alle orderregels definitief heeft opgeslagen. Daardoor worden handmatig gewijzigde regelprijzen en kortingen niet meer voortijdig teruggezet door een te vroege sync tijdens dezelfde save-cyclus.

## [0.3.102] — 2026-03-24
### Fixed
- Handmatig aangepaste abonnementsregels in de backend worden bij opslaan niet langer direct terug overschreven door verouderde `_hb_ucs_sub_items` schaduwmeta. De order-type self-sync leest artikel-, toeslag- en verzendregels nu altijd opnieuw uit de live WooCommerce-order, zodat handmatige kortingen en prijswijzigingen behouden blijven.

## [0.3.101] — 2026-03-24
### Fixed
- Het openen van HB UCS abonnementen kon vastlopen door een recursielus bij order-type abonnementen zonder opgeslagen item-meta. De repository probeert orderregels nu nog steeds als fallback te gebruiken, maar met een guard die voorkomt dat dezelfde data-resolutie zichzelf via het order-datastore opnieuw blijft aanroepen en zo memory exhaustion veroorzaakt.

## [0.3.100] — 2026-03-24
### Fixed
- Handmatig aangemaakte backend-abonnementen konden een totaal van `0` tonen terwijl subtotalen en btw wel zichtbaar waren. Voor order-type abonnementen zonder gekoppelde legacy-post worden artikel-, toeslag- en verzendregels nu ook direct uit de echte orderregels afgeleid en bij admin-save teruggesynchroniseerd, zodat de opgeslagen totaalbedragen correct worden opgebouwd.

## [0.3.99] — 2026-03-24
### Fixed
- Backend abonnementen tonen nu altijd de bewerkbare velden voor betaalmethode, Mollie Payment ID, Payment Mode, Customer ID en Mandate ID, ook als die waardes nog leeg zijn. Daardoor kun je handmatig toegevoegde abonnementen nu dezelfde Mollie/transactiegegevens meegeven als gemigreerde abonnementen.

## [0.3.98] — 2026-03-24
### Fixed
- Bestaande handmatige HB UCS abonnementen volgen nu reguliere productprijswijzigingen weer automatisch, ook wanneer de abonnementsprijs via frequentiekorting of vaste abonnementsprijs uit het product wordt afgeleid.
- Handmatig aangepaste abonnementsprijzen blijven behouden: subscription-items bewaren nu hun laatst bekende catalogusprijs apart, zodat alleen catalogus-gekoppelde regels worden meegeüpdatet en handmatige kortingen of handmatige prijsoverschrijvingen in een abonnement niet worden overschreven.

## [0.3.97] — 2026-03-24
### Fixed
- Backend abonnement-bewerkingen triggerden nog standaard WooCommerce ordermails, omdat het interne abonnements-ordertype bij opslaan als gewone bestelling werd behandeld voor e-mailnotificaties. Die standaard mails worden nu onderdrukt voor HB UCS abonnementen, zodat klanten en admins geen onterechte "nieuwe bestelling" of vergelijkbare ordermails meer ontvangen bij het updaten van een bestaand abonnement.

## [0.3.89] — 2026-03-24
### Fixed
- Frontend verborgen meta: bestaande abonnementen konden `_reduced_stock` nog tonen wanneer die sleutel al eerder als gewone `display_meta` label/value rij in een opgeslagen snapshot terecht was gekomen. Die opgeslagen display-meta wordt nu ook tegen dezelfde uitgesloten keys gefilterd, zodat `reduced_stock` niet meer zichtbaar blijft in Mijn Account.

## [0.3.96] — 2026-03-24
### Fixed
- Frequenties abonnementen: de backend-schema dropdown gebruikte nog een aparte hardcoded lijst waardoor `Elke 6 weken` daar niet zichtbaar was, ook als die in de module-instellingen was ingeschakeld. De backend leest nu alle ondersteunde schema’s mee en ondersteunt daarnaast ook `Elke 5 weken`, `Elke 7 weken` en `Elke 8 weken`.
- Frequentieondersteuning: de nieuwe schema’s `5w`, `7w` en `8w` zijn toegevoegd aan instellingen, validatie, runtime frequentielijsten, child-product generatie en uninstall cleanup, zodat ze overal consistent beschikbaar zijn.

## [0.3.95] — 2026-03-24
### Fixed
- SEPA volgende orderdatum: na het WCS-achtige statusgedrag bleef de subscription bij een succesvol aangemaakte Mollie/SEPA renewal onterecht op de oude volgende betaaldatum staan totdat de definitieve betaalbevestiging binnenkwam. Na succesvolle renewal-creatie schuift de volgende orderdatum nu meteen door naar de volgende cyclus, terwijl het abonnement zelf actief blijft en alleen de renewal-order op `on-hold` wacht op verwerking.

## [0.3.94] — 2026-03-24
### Fixed
- SEPA renewal-status: automatische/Mollie renewals zetten het abonnement niet langer direct op `on-hold` tijdens de verwerking van een open SEPA incasso. Alleen de renewal-order blijft op `on-hold` totdat Mollie de betaling definitief terugkoppelt. Het abonnement zelf gaat pas naar `on-hold` als de betaling echt mislukt.
- Subscription notities: bij een open SEPA renewal wordt nu ook expliciet vastgelegd dat het abonnement actief blijft zolang de incasso nog verwerkt wordt, in lijn met het verwachte WCS-gedrag.

## [0.3.93] — 2026-03-24
### Fixed
- Renewal lifecycle: automatische/Mollie renewals volgen nu meer de WCS-logica. Zodra een automatische renewal vervalt, gaat het abonnement eerst naar `on-hold`, wordt één renewal-order aangemaakt en pas na succesvolle betaling weer geactiveerd. Bij mislukte betaling blijft het abonnement op `on-hold`.
- Volgende betaaldatum: voor automatische renewals wordt de verlengingsdatum niet meer al tijdens ordercreatie gemuteerd, maar pas via dezelfde reactivatieflow na een geslaagde betaling opnieuw berekend. Daarbij blijft de bestaande offline/manual afhandeling bewust intact: handmatige/offline renewals gaan nog steeds direct door, inclusief onmiddellijke datum-update.
- Duplicate prevention: doordat automatische renewals nu een WCS-achtige `on-hold` lifecycle gebruiken én open renewal-orders blijven blokkeren, ontstaan er geen extra renewals meer zolang de bestaande renewal nog openstaat.

## [0.3.92] — 2026-03-24
### Fixed
- SEPA-renewals: het abonnement springt niet langer naar `betaling in behandeling` wanneer een renewal-order voor Mollie SEPA wordt aangemaakt. Alleen de renewal-order zelf blijft op `on-hold` wachten op incasso, terwijl het abonnement zijn eigen status behoudt.
- Renewal planning: zodra een SEPA-renewal succesvol bij Mollie is aangemaakt, schuift de volgende betaaldatum meteen door naar de volgende cyclus. Daardoor blijft de verlengingsdatum in het abonnement up-to-date en kan de cron niet elke minuut opnieuw dezelfde renewal starten.
- Duplicate guard: zolang er al een open renewal-order met status `pending` of `on-hold` voor hetzelfde abonnement bestaat, wordt er geen extra automatische renewal aangemaakt. Dat vangt ook resterende randgevallen op bij gepauzeerde abonnementen of oudere statusmismatches.

## [0.3.91] — 2026-03-24
### Fixed
- SEPA/Mollie renewals: automatische renewals konden elke minuut opnieuw worden aangemaakt omdat de flow na het starten van een Mollie-incasso alleen `_hb_ucs_sub_status=payment_pending` bijwerkte, terwijl de order-type sync nog een oude interne `_hb_ucs_subscription_status=active` terugschreef. Renewal-status en volgende betaaldatum worden nu op beide opslaglagen tegelijk bijgewerkt, waardoor `payment_pending` blijft staan tot betaling is afgerond en de cron geen duplicaten meer maakt.
- Gepauzeerde abonnementen: automatische renewals slaan nu abonnementen over zodra één van de statuslagen niet echt `active` is. Daardoor worden gepauzeerde/on-hold abonnementen niet meer alsnog door Mollie-renewals verwerkt bij statusmismatches.

## [0.3.90] — 2026-03-24
### Fixed
- Backend datum/schemabewerkingen op order-type abonnementen schreven nog vooral naar de interne `_hb_ucs_subscription_*` order-meta, terwijl Mijn Account de legacy/frontend sleutels zoals `_hb_ucs_sub_next_payment` en `_hb_ucs_sub_scheme` leest. Admin saves spiegelen nu ook weer de volgende betaaldatum, proefeinde, einddatum en het schema naar die frontend-meta, zodat handmatige backendwijzigingen direct zichtbaar blijven in het accountoverzicht.

## [0.3.88] — 2026-03-24
### Fixed
- Backend afrondingsverschillen: de repository rondde de interne exclusief-btw opslagprijs voor shadow order-items nog af op 2 decimalen voordat de backend-orderregels werden opgebouwd. Daardoor kon een frontendprijs van `11,50` incl. btw alsnog als `11,51` in het abonnement verschijnen. Die tussenafronding gebruikt nu weer interne precisie, zodat frontend en backend op hetzelfde bedrag uitkomen.

## [0.3.87] — 2026-03-24
### Fixed
- Backend afronding incl. btw: de abonnement-backoffice berekende itemtotalen nog via exclusief-btw subtotalen plus apart afgeronde belasting, terwijl de frontend het incl.-btw eindbedrag direct via de display-prijshelper toonde. Bij bepaalde bedragen gaf dat nog steeds verschillen zoals `11,50` versus `11,51`. De backend gebruikt voor incl.-btw weergave nu dezelfde display-bedrag helper als de frontend, zodat beide kanten op hetzelfde afgeronde totaal uitkomen.

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
