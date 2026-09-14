# Productbundels

De module Productbundels maakt het WooCommerce-producttype `woosb` beschikbaar in HB Unified Commerce Suite. De technische productopslag blijft compatibel met WPC Product Bundles 8.6.4, terwijl beheer en frontend door HB UCS worden verzorgd.

## Veilig overstappen vanaf WPC Product Bundles

De HB-module en WPC Product Bundles registreren hetzelfde producttype en mogen daarom niet tegelijk actief zijn.

1. Maak eerst een databaseback-up.
2. Laat de HB-module **Productbundels** nog uitgeschakeld.
3. Deactiveer **WPC Product Bundles for WooCommerce**.
4. Activeer onder **HB UCS → Modules** de module **Productbundels**.
5. Controleer een bestaande bundel in beheer zonder hem direct opnieuw op te slaan.
6. Test productpagina, winkelmand, checkout, orderweergave en een herhaalbestelling.
7. Test de daadwerkelijk ingestelde winkelmand en checkout; zowel klassieke templates als WooCommerce Blocks worden ondersteund.

Bij een conflict pauzeert HB UCS zijn bundelengine en toont het een beheerwaarschuwing. Bestaande product- en ordergegevens worden daarbij niet aangepast.

## Gegevenscontract

- Producttype: `woosb`.
- Samenstelling: productmeta `woosb_ids` in het WPC 7+/8.x arrayformaat.
- Optionele HB-keuzegroepen: productmeta `hb_ucs_bundle_groups`.
- Compacte winkelmand-/orderselectie: `product-id/unieke-sleutel/aantal/urlencoded-attributen`.
- Historische WPC-formaten `product-id/aantal` en SKU-identificatie worden gelezen.
- WPC-prijsvelden zoals `woosb_disable_auto_price`, `woosb_discount`, `woosb_discount_amount`, `woosb_shipping_fee` en `woosb_manage_stock` blijven leidend.
- Orders bewaren `_woosb_ids`, `_woosb_parent_id` en `_woosb_price`, aangevuld met een onveranderlijke HB-snapshot en een unieke bundelgroep-ID.

HB-specifieke klantteksten en labels worden als extra velden binnen dezelfde `woosb_ids`-array opgeslagen. Het bestaande vrije tekstveld `group` blijft uitsluitend een visuele tussenkop. Het nieuwe `group_id` is een stabiele technische verwijzing naar een record in `hb_ucs_bundle_groups`; een titelwijziging verandert deze identifier niet. Daardoor blijft de kernsamenstelling bruikbaar wanneer tijdelijk naar WPC wordt teruggeschakeld.

## Keuzegroepen

Een keuzegroep toont een expliciet geselecteerde productpool eenmaal en laat de klant daarbinnen een gezamenlijk aantal kiezen. De producten blijven gewone regels in `woosb_ids`; alleen `group_id` koppelt ze aan de aanvullende configuratie.

```text
hb_ucs_bundle_groups[beer_selection]:
  group_id: beer_selection
  title: Kies je speciaalbieren
  description: Kies minimaal 4 en maximaal 6 bieren.
  type: multi
  min: 4
  max: 6
  max_per_item: 6
  allow_duplicates: 1
  layout: cards
  show_images: 1
  show_prices: 1
  show_descriptions: 0
  source: explicit
```

- `single` staat maximaal één geselecteerd product en maximaal één stuk toe. Met `min: 1` is de keuze verplicht.
- `multi` telt de aantallen van alle gekoppelde producten bij elkaar op.
- `min` en `max` gelden voor het groepstotaal; `max_per_item` begrenst één product.
- Als `allow_duplicates` uit staat, is ieder gekoppeld product maximaal eenmaal selecteerbaar.
- `layout` ondersteunt `standard`, `cards`, `compact`, `list` en `radio_cards` als theme-neutrale presentatiehint.
- `source` is nu altijd `explicit`. Het veld reserveert een backward-compatible uitbreidingspunt voor latere bronnen; categoriebronnen worden nog niet geladen.

Normalisatie repareert `max < min`, normaliseert onbekende typen naar `multi`, dwingt bij `single` een maximum van één af en maakt dubbele IDs uniek. Alleen groepen die daadwerkelijk in meta staan activeren de nieuwe configurator.

## Beheer

Kies bij een product het type **Productbundel**. In **Bundel samenstellen** zijn beschikbaar:

- producten en vaste variaties toevoegen en verslepen;
- verplichte of optionele onderdelen met standaard-, minimum- en maximumaantal;
- toegestane variatiekeuzes per kenmerk;
- klanttitel, uitleg, label en visuele groep per onderdeel;
- vrije tekst- en tussenkopregels tussen onderdelen;
- inklapbare single- en multi-keuzegroepen met een stabiele interne identifier;
- meerdere producten tegelijk binnen een groep zoeken, sorteren, verwijderen of naar een andere groep verplaatsen;
- vaste bundelprijs of automatisch componenttotaal;
- procentuele of vaste bundelkorting;
- minimum/maximum aantal en minimum/maximum totaal;
- voorraad op bundelniveau of afgeleid van onderdelen;
- verzending via hoofdproduct, onderdelen of beide;
- kaart- of lijstweergave en teksten boven/onder de samenstelling.

Algemene presentatie en standaardteksten staan onder **HB UCS → Productbundels**.

## Winkelmand en bestelling

Servervalidatie controleert verplichtingen, aantallen, variaties, toegestane termen, bestelbaarheid en voorraad opnieuw. De browser is dus niet de autoritatieve bron.

Voor een keuzegroep wordt eerst de server-side relatie tussen component key en `group_id` hersteld uit de productdefinitie. Daarna worden groepstotaal, minimum, maximum, maximum per product, duplicaten en single-choice-regels gecontroleerd. Vervolgens doorloopt ieder gekozen product ongewijzigd de bestaande product-, variatie-, bestelbaarheids- en voorraadvalidatie. De globale `woosb_limit_whole_*`- en prijsgrenzen blijven daar bovenop actief.

- Een vaste-prijsbundel draagt de prijs op de ouderregel; onderdelen hebben prijs nul.
- Een componentbundel draagt de prijs op de onderdeelregels; korting wordt evenredig verdeeld.
- Ouder en onderdelen hebben één unieke groep-ID, zodat verwijderen, herstellen en wijzigen atomair gebeurt.
- De opgeslagen ordersnapshot blijft de daadwerkelijk bestelde samenstelling tonen, ook als producten later wijzigen.
- Snapshots met groepen gebruiken schema 2 en bewaren een kopie van de groepsdefinities plus `group_id` per component. Legacy snapshots blijven schema 1 en worden ongewijzigd gelezen.
- Herhaalbestellen gebruikt de opgeslagen selectie en bouwt de onderdeelregels opnieuw op.

## Abonnementen

Een bundel kan via de HB-abonnementenmodule als abonnement worden aangeboden.

- Mijn account toont één begrijpelijke bundelregel met de opgeslagen samenstelling.
- Gewone product-, aantal- en verzendwijzigingen vanaf Mijn account blijven hetzelfde werken.
- Als dezelfde bundel behouden blijft, blijft de gekozen samenstelling als verborgen snapshot gekoppeld.
- Een verlengorder klapt de snapshot opnieuw uit naar echte ouder- en onderdeelregels.
- Prijs, belasting, voorraad en verzending per onderdeel worden opnieuw opgebouwd zonder de bundelouder dubbel op voorraad af te boeken.

## WooCommerce Blocks

De module registreert een eigen Store API-integratie voor Cart, Mini-Cart en Checkout Blocks.

- Ouder- en onderdeelregels krijgen herkenbare Blocks-klassen en gekoppelde aantallen.
- Onderdeelregels kunnen niet zelfstandig worden gewijzigd of verwijderd.
- Verwijderen van een ouderregel verwijdert de volledige groep; herstellen zet de volledige groep terug.
- Dynamische bundeltotalen worden op de ouderregel getoond zonder de orderberekening te verdubbelen.
- De instelling om onderdeelregels te verbergen en de link om de samenstelling te wijzigen werken ook in Blocks.

## Beperkingen

- Geneste bundels worden bewust geweigerd.
- Wanneer een opgeslagen onderdeel is verwijderd of niet meer bestelbaar is, wordt een nieuwe bundel of verlengorder geblokkeerd in plaats van stilzwijgend met een andere samenstelling door te gaan.
- Dynamische categoriebronnen, afhankelijkheden tussen groepen en product-quick-view vallen buiten deze eerste versie.

## Backward compatibility

Een `woosb`-product zonder `hb_ucs_bundle_groups` volgt exact de bestaande renderer en validatieregels. Er is geen migratie of lazy write. `woosb_ids`, compacte selecties, parent/child cart-items, instance-ID's, vaste en dynamische prijsverdeling, ordermeta, repeat orders, abonnementen en Store API/Blocks blijven het bestaande contract gebruiken. De aanvullende groepssnapshot is alleen aanwezig bij een product dat groepen heeft.

## Voorbeeld: bierpakket

1. Voeg een keuzegroep toe met ID `beer_selection`, type `multi`, minimum 4, maximum 6, maximum per product 6 en dubbele aantallen aan.
2. Voeg Rude Trip, Stoere Dirk, Lieve Alie, Slimme Leen, Aardig Stout, Citroengras Blond, Eigen Weizen, Rozemarijn Tripel en Tureluur Vol Blond via de productzoeker aan die groep toe.
3. Controleer op de productpagina dat de pool eenmaal verschijnt, bijvoorbeeld `Rude Trip ×2`, `Stoere Dirk ×1`, `Eigen Weizen ×1` accepteert en `4 van 6 gekozen` toont.
4. Controleer dat de plusknoppen bij zes blokkeren, verlagen mogelijk blijft en manipulatie van `woosb_ids` door de server wordt geweigerd.

## Handmatige regressiematrix

| Gebied | Controle |
|---|---|
| Desktop | Single/multi configureren, sticky samenvatting, add-to-cart, vanuit cart bewerken, checkout en orderdetail |
| Mobiel | Twee-koloms productcards, plus/min, variation-keuze, sticky balk, toetsenbordbedienbaar paneel en add-to-cart |
| Cart | Klassieke cart, Mini Cart Block, Cart Block en Checkout Block; child kan niet zelfstandig wijzigen/verwijderen |
| Prijs | Vaste parentprijs, dynamische componentsom, procentuele korting en vaste korting; live totaal vergelijken met cart |
| Voorraad | Voldoende/onvoldoende simple stock, variation stock en bundelaantal groter dan beschikbare componentvoorraad |
| Historie | Oude bundel zonder groepsmeta, oude order/snapshot, repeat order en bestaande abonnementsverlenging |

## Testen

Voer vanuit de pluginmap uit:

```bash
php tests/bundles/runtime-smoke.php
```

De test start WordPress alleen-lezen en controleert het WPC-opslagcontract, compacte round-trip, legacy formaat, groepsnormalisatie en single/multi-grensregels.

