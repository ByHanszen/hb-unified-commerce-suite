# Beoordeling en herstel van de plugin-audit

Datum: 30 oktober 2026. Versie na herstel: 0.5.9.

Bron: `HB-UCS-plugin-audit-2026-10-30.pdf`, audit van commit `3abfe05a2d0d261169128c7127dbe8bc9a589548`. De reparaties zijn beoordeeld tegen de lokale code op basis van `7b6f6bc`, waarin de eerdere reparatie voor abonnementsregels al aanwezig was.

## Beoordeling per bevinding

| ID | Beoordeling van de aanname | Reparatie en controle |
| --- | --- | --- |
| A01 | Bevestigd voor de handlers. Het menu vereiste al `manage_options`; de opslaghandlers accepteerden ten onrechte ook alleen `manage_woocommerce`. | Beheer vereist `manage_options` en `promote_users`. Systeemrollen zijn beschermd tegen bewerken/verwijderen. Rechten klonen of toekennen boven de eigen bevoegdheid wordt geweigerd vóór een wijziging. Getest met shopmanager, beheerder en gewone klantrollen. |
| A02 | Bevestigd: generieke bestandsnamen en sorteren op datum konden een andere factuur selecteren. Een daadwerkelijke verkeerde verzending is niet aangetoond. | XML moet een exacte orderreferentie of een exact bekend factuurnummer bevatten. Een expliciet afwijkende orderreferentie wordt geweigerd. Alle XML-bestanden in een ZIP moeten bij dezelfde order horen; oncontroleerbare archieven worden overgeslagen. Een exacte bestandsnaam krijgt daarna voorrang. Getest met een nieuwere verkeerde UBL, ontbrekende match, gemengd ZIP-archief en externe XML-entiteiten. |
| A03 | Al hersteld vóór deze auditopdracht; de oorspronkelijke audit beschrijft oudere code. | De bestaande integratietest is opnieuw uitgevoerd: aantal, afgesproken prijs, korting, btw, totaal en opgeslagen gegevens voor vervolgbestellingen blijven correct. |
| A04 | Bevestigd. Alleen een lege foutmelding verwijderen zou onvoldoende zijn: de termijn werd al doorgeschoven en een open order blokkeerde herhalen. De volledige test vond bovendien een verouderde metadata/statuscache. | Aanmaken en herstellen gebruiken één order en één opgeslagen betaalopdracht. De opdracht en idempotentiesleutel worden vóór de HTTP-aanroep opgeslagen. Pas bij geaccepteerde betaling schuift de termijn op. Mislukte aanmaak blijft `payment_pending`; cron en **Maak of herstel vervolgorder** kunnen dezelfde order hervatten. Runtime-status wordt via CRUD bijgewerkt. Getest met een gesimuleerde timeout, herstel, identieke payload/sleutel, herhaald uitvoeren en één uiteindelijke vervolgorder. |
| A05 | Bevestigd voor `current` op het door de engine gewijzigde productobject. | De engine bewaart de oorspronkelijke prijs per productobject en hergebruikt die bij herberekenen en prijsweergave. Bij vervallen B2B-prijstoepassing wordt de basis hersteld. Getest met drie berekeningen, actieprijs, `regular`, `current`, B2B en gast. |
| A06 | Bevestigd. Het bestaan van het nieuwe orderscherm alleen bewijst overigens niet of HPOS of synchronisatie actief is. | Metabox ondersteunt `WP_Post` en `WC_Order`, beide schermen en CRUD-opslag. Aanwezige CRUD-waarden gaan vóór legacy-postmeta. Legacy-uitsluiting wordt bij expliciet opslaan overgenomen wanneer die in de actieve opslag ontbreekt. Getest met klassieke opslag en HPOS met synchronisatie uit. |
| A07 | Bevestigd als API/pagineringsfout. Of de externe QLS-client bij een korte pagina stopt, is niet aangetoond en is voor het herstel niet nodig. | Uitsluitingen worden vóór de orderquery/paginering toegepast; collectie en totaalheaders gebruiken dezelfde selectie. Ook de v3-statusfiltervolgorde en de combinatie van `post__in`/`post__not_in` in klassieke WordPress-opslag zijn afgedekt. Het echte REST-endpoint is getest met tien uitgesloten en een elfde toegestane order. |
| A08 | Bevestigd: verhogen van de offset terwijl de bronselectie krimpt slaat gebruikers over. | Steeds de eerste resterende batch verwerken, doelrol toevoegen zonder andere rollen te verwijderen, identieke rollen weigeren en resterende gebruikers controleren voordat een rol verdwijnt. Getest met 201 en 450 gebruikers die ook een tweede rol hebben. |
| A09 | Bevestigd voor numerieke statuslijsten. | De gehele lijst wordt vervangen. Verwijderen tot één status en vervolgens leegmaken is getest tegen de echte instellingenopslag. |
| A10 | Bevestigd. | Nieuwe velden gebruiken een oplopende teller op basis van de hoogste bestaande index. De echte JavaScript-eventhandlers zijn getest na verwijderen, toevoegen en sorteren. |
| A11 | Bevestigd onder de genoemde btw-instellingen; dat deze instellingen in productie actief waren is niet vastgesteld. | De hoofdregel gebruikt werkelijke component-subtotalen en belastingen, in de winkelmand- of ordercontext en met de ordervaluta. Getest met echte WooCommerce-berekeningen: 9%/21%, invoer inclusief/exclusief btw, weergave inclusief/exclusief btw en afwijkende ordervaluta. |
| A12 | Bevestigd en met WooCommerce nagebootst. | Doelbedrag wordt in valutacenten over componentregels verdeeld, met een vaste verdeling van de afrondingsrest. Stukprijzen houden voldoende precisie. Getest: €10 over drie stuks, meerdere componenten/aantallen en herhaald berekenen van de echte winkelmand. |
| A13 | De ontbrekende refundverrekening is bevestigd. Een refund is op zichzelf geen bewijs van een fysieke retour; daarvoor is expliciet beleid nodig. | Het toegepaste beleid sluit productaantallen uit refunds uit; bedragcorrecties zonder aantallen tellen niet mee. In retourbeheer kunnen refunds aan de bijbehorende retour worden gekoppeld. Alleen gecontroleerde overlap voor dezelfde order/regel wordt eenmaal geteld; onafhankelijke aantallen worden opgeteld. Getest met externe gedeeltelijke refund, gekoppelde retour, verkeerde koppeling en bedragcorrectie. |

## Aanvullende punten uit het rapport

- **B2B en abonnementen:** volgens de bevestigde gebruikerskeuze heeft een toepasselijke B2B-prijs voorrang. Deze wordt berekend op de catalogusprijs volgens de ingestelde prijsbasis; de abonnementsprijs wordt niet opnieuw met B2B-korting verlaagd. Zonder toepasselijke B2B-regel blijft de abonnementsprijs gelden. Dit is afgedekt in herprijzen, prijsweergave en de bundelberekening, inclusief regressietests.
- **Retourbeleid:** door de gebruiker bevestigd: terugbetaalde productaantallen zijn uitgesloten van nieuwe retouren; gekoppelde overlap met bestaande retourmeldingen telt eenmaal. Het onder A13 beschreven herstel sluit hierbij aan.
- **Aanvullende bundelregressie:** tests met echte `WC_Product_Woosb`-objecten toonden dat de dynamische catalogusberekening de toegewezen nulprijs van een winkelmandhoofdregel kon vervangen. Bundelproducten bewaren nu hun toegewezen winkelmandprijs afzonderlijk van de catalogusberekening. Vaste en dynamische abonnementsbundels en B2B-regels op componenten zijn met de echte hookvolgorde en herhaalde totalenberekening getest.
- **Factuurmailguards:** de documentguard is beperkt tot klantmails. Daarnaast bleek het vierde argument van `woocommerce_email_attachments` een `WC_Email`-object te zijn, terwijl de code het als boolean behandelde. De guard gebruikt nu de echte e-mailcontext; beheermails behouden hun bijlagen. Beide paden zijn getest.
- **Nieuwe factuurbestanden:** worden in een unieke tijdelijke map gemaakt en na `wp_mail` verwijderd. Historische bestanden in uploads zijn niet verwijderd. Hun webserverbeveiliging is niet in productie onderzocht. Een mailplugin die bijlagen pas later asynchroon van schijf leest, moet afzonderlijk worden getest.
- **Gelijktijdigheid:** controleren en aanmaken van vervolgorders en retouren is beschermd met connection-scoped MySQL/MariaDB-vergrendeling. Een test met een tweede databaseverbinding bevestigt dat een concurrerend verzoek niet doorgaat. Een herhaalde retouraanmelding kan dezelfde beschikbare aantallen niet opnieuw reserveren.
- **Klantnotities, Elementor en productzoekweergave:** het rapport geeft hier praktijktests en geen bewezen extra fout. Er zijn daarom geen wijzigingen aan deze productregels gedaan. De bestaande productzoek- en bundelsmoketests zijn wel opnieuw uitgevoerd.

## Grenzen van automatisch betaalherstel

Mollie bewaart idempotentiesleutels één uur en koppelt ze aan de gebruikte credentials. Binnen een marge van 55 minuten hergebruikt de plugin dezelfde opgeslagen opdracht. Daarna, of na een wijziging van credentials, zoekt hij bestaande klantbetalingen op en controleert order, abonnement, bedrag en mandaat. Alleen één eenduidige match wordt overgenomen. Bij geen match, meerdere matches of onvoldoende zekerheid wordt geen nieuwe incasso gestart; de herstelactie meldt dat controle bij Mollie nodig is. Er worden maximaal tien pagina's van 250 betalingen onderzocht.

Officiële bronnen: [Mollie API idempotency](https://docs.mollie.com/reference/api-idempotency), [List customer payments](https://docs.mollie.com/reference/list-customer-payments).

## Verificatie en gebruik

De functionele tests gebruiken tijdelijke fixtures, blokkeren echte e-mail/betalingen en rollen databasewijzigingen terug. De uitgebreide auditregressies draaien zowel met klassieke opslag als met HPOS zonder synchronisatie. De lokale WooCommerce-versie tijdens de verificatie is 11.0.1.

```text
php tests/audit/runtime-regressions.php
php tests/audit/renewal-recovery.php
php tests/audit/bundle-pricing.php
node tests/audit/status-repeater.cjs
php tests/subscriptions/item-persistence.php
php tests/bundles/runtime-smoke.php
php tests/product-search/runtime-smoke.php
php tests/product-search/wordpress-integration.php
```

Gebruik voor de auditregressies de omgevingsvariabele `HB_UCS_TEST_HPOS=yes` of `no` om de opslagmodus tijdelijk voor dat testproces te forceren. De dagelijkse compatibiliteitsworkflow voert deze functionele controles nu daadwerkelijk uit, naast PHP-/JavaScript-syntax en de bestaande laadcontroles.

De QLS-selectie beoordeelt kandidaatorders in batches van 200 voordat paginering plaatsvindt. Dit geeft correcte aantallen, ook voor product-, verzend- en externe uitsluitingsfilters, maar moet bij een grote orderhistorie op prestaties worden gemeten. Een gedenormaliseerde uitsluitingsindex kan dan een afzonderlijke optimalisatie zijn.

De code is lokaal hersteld; er is niet gedeployed en geen GitHub-workflow op afstand gestart. Productie-QLS, echte incasso's en handmatige browserinteracties zijn niet uitgevoerd. Historische facturen, bestellingen, incasso's en klantrollen zijn niet gecorrigeerd.
