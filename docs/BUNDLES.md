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
- Compacte winkelmand-/orderselectie: `product-id/unieke-sleutel/aantal/urlencoded-attributen`.
- Historische WPC-formaten `product-id/aantal` en SKU-identificatie worden gelezen.
- WPC-prijsvelden zoals `woosb_disable_auto_price`, `woosb_discount`, `woosb_discount_amount`, `woosb_shipping_fee` en `woosb_manage_stock` blijven leidend.
- Orders bewaren `_woosb_ids`, `_woosb_parent_id` en `_woosb_price`, aangevuld met een onveranderlijke HB-snapshot en een unieke bundelgroep-ID.

HB-specifieke klantteksten, labels en groepen worden als extra velden binnen dezelfde `woosb_ids`-array opgeslagen. Daardoor blijft de kernsamenstelling bruikbaar wanneer tijdelijk naar WPC wordt teruggeschakeld.

## Beheer

Kies bij een product het type **Productbundel**. In **Bundel samenstellen** zijn beschikbaar:

- producten en vaste variaties toevoegen en verslepen;
- verplichte of optionele onderdelen met standaard-, minimum- en maximumaantal;
- toegestane variatiekeuzes per kenmerk;
- klanttitel, uitleg, label en visuele groep per onderdeel;
- vrije tekst- en tussenkopregels tussen onderdelen;
- vaste bundelprijs of automatisch componenttotaal;
- procentuele of vaste bundelkorting;
- minimum/maximum aantal en minimum/maximum totaal;
- voorraad op bundelniveau of afgeleid van onderdelen;
- verzending via hoofdproduct, onderdelen of beide;
- kaart- of lijstweergave en teksten boven/onder de samenstelling.

Algemene presentatie en standaardteksten staan onder **HB UCS → Productbundels**.

## Winkelmand en bestelling

Servervalidatie controleert verplichtingen, aantallen, variaties, toegestane termen, bestelbaarheid en voorraad opnieuw. De browser is dus niet de autoritatieve bron.

- Een vaste-prijsbundel draagt de prijs op de ouderregel; onderdelen hebben prijs nul.
- Een componentbundel draagt de prijs op de onderdeelregels; korting wordt evenredig verdeeld.
- Ouder en onderdelen hebben één unieke groep-ID, zodat verwijderen, herstellen en wijzigen atomair gebeurt.
- De opgeslagen ordersnapshot blijft de daadwerkelijk bestelde samenstelling tonen, ook als producten later wijzigen.
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

## Testen

Voer vanuit de pluginmap uit:

```bash
php tests/bundles/runtime-smoke.php
```

De test start WordPress alleen-lezen en controleert het WPC-opslagcontract, compacte round-trip en legacy formaat.

