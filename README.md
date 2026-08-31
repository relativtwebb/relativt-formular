# Relativt Formulär

Formulärmotor för WordPress. Bygg formulär i wp-admin, varje formulär får en egen shortcode.

Byggd för byråarbete: samma motor på flera kundsajter, formulär som kan flyttas mellan projekt, och en kund som klarar att ändra i sina egna fält utan att höra av sig.

## Vad den gör

- **Formulärbyggare i wp-admin.** Fält läggs till, döps om och sorteras genom att dra. Varje formulär får en shortcode.
- **Villkorliga fält** som utvärderas *på servern*. Ett fält som inte ska synas renderas dolt direkt i HTML:en — det behöver alltså inte JavaScript för att göra rätt, och en besökare med skript avstängt ser samma formulär som alla andra.
- **Mottagarregler.** Skicka till olika adresser beroende på vad besökaren svarat. Regler får skrivas med antingen etiketten eller det tekniska värdet — båda träffar.
- **Validering** av e-post och telefon, spegelvänd mellan PHP och JavaScript så klienten och servern aldrig är oense. Svenska nummer normaliseras till ett format, internationella släpps igenom.
- **Spamskydd** utan CAPTCHA: honungsfälla, HMAC-signerad tidsstämpel med minsta tid, frekvensspärr per IP och länkspärr i textrutor.
- **UTM-attribution** via förstapartskaka, så att inskicket bär med sig vilken kampanj besökaren kom ifrån. Finns [Relativt Cookie Consent](https://github.com/relativtwebb/relativt-cookie-consent) på sajten skrivs kakan först när besökaren samtyckt – se [Kampanjkakan och samtycke](#kampanjkakan-och-samtycke).
- **Inskickslagring** med konfigurerbar gallring och CSV-export.
- **Export och import av formulärdefinitioner** som JSON.

## Krav

- WordPress 6.0 eller senare
- PHP 8.0 eller senare
- **Advanced Custom Fields Pro** — formulärbyggaren är byggd på repeater-fältet, som bara finns i Pro

Saknas ACF Pro startar motorn ändå, så att posttyper, sparade formulär och inskick förblir åtkomliga, men byggaren visas inte och ett tydligt meddelande förklarar varför.

## Installation

Ladda ner senaste zip-filen under [Releases](../../releases), installera under **Insticksprogram → Lägg till → Ladda upp**, aktivera.

Uppdateringar dyker sedan upp som vanligt under Insticksprogram så fort en ny release taggas här.

## Användning

Skapa ett formulär under **Formulär → Skapa nytt**, bygg fälten, publicera. Kopiera shortcoden:

```
[relativt_formular id="123"]
```

### Förvälja ett värde per sida

Alla shortcode-attribut som matchar en fältnyckel blir förvalt värde. Ligger samma formulär på två sidor kan de alltså börja i olika lägen:

```
[relativt_formular id="123" jag_ar="foretag"]
[relativt_formular id="123" jag_ar="kandidat"]
```

Villkorliga fält rättar sig efter förvalet redan vid renderingen.

## Filter

Motorn levererar neutral markup som fungerar i vilket tema som helst. Vill sajten att knappen ska ärva sitt eget utseende skjuter den in sina klasser:

```php
// Oxygen: temats knappanimation letar efter just de här klasserna.
add_filter( 'relativt_form_submit_class',      fn( $c ) => trim( "$c btn" ) );
add_filter( 'relativt_form_submit_text_class', fn( $c ) => trim( "$c ct-text-block" ) );
add_filter( 'relativt_form_submit_icon_class', fn( $c ) => trim( "$c ct-fancy-icon" ) );
```

| Filter | Standard | Gör |
|---|---|---|
| `relativt_form_enqueue_css` | `true` | Sätt `false` om sajten stylar formuläret själv |
| `relativt_form_always_enqueue` | `true` | Sätt `false` för att bara ladda på sidor som faktiskt renderar shortcoden |
| `relativt_form_submit_class` | `''` | Extra klasser på knappen |
| `relativt_form_submit_text_class` | `''` | Extra klasser på knapptexten |
| `relativt_form_submit_icon_class` | `''` | Extra klasser på ikonen |
| `relativt_form_submit_icon` | pil höger | Byt ikonens SVG, eller returnera `''` för ingen ikon |
| `relativt_form_messages` | svenska texter | Byt besökartexterna (felmeddelanden m.m.). Samma lista driver PHP och JS |
| `relativt_form_max_links` | `3` | Max antal länkar i en textruta innan inskicket avvisas. `0` stänger av |
| `relativt_form_utm_cookie` | `'auto'` | Kampanjkakans samtyckesläge: `auto`, `always` eller `never` |
| `relativt_form_client_ip` | `REMOTE_ADDR` | Peka ut besökarens riktiga IP bakom proxy/CDN |

**Om `relativt_form_client_ip`:** bakom Cloudflare eller annan proxy är `REMOTE_ADDR` proxyns adress – då delar alla besökare samma frekvensspärr (fem inskick per tio minuter för hela sajten) och IP-loggen blir meningslös. På en Cloudflare-sajt:

```php
add_filter( 'relativt_form_client_ip', fn( $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );
```

Filtrera aldrig in `X-Forwarded-For` rakt av på en sajt utan betrodd proxy – den headern kan vem som helst skicka, och då väljer spammaren sin egen frekvensspärr.

**Om `relativt_form_messages`:** för en engelskspråkig kundsajt räcker det att byta texterna – inga språkfiler:

```php
add_filter( 'relativt_form_messages', fn( $m ) => array_merge( $m, [
	'required' => 'This field is required.',
	'email'    => 'Please check the email address.',
] ) );
```

**Om `always_enqueue`:** standard är att ladda överallt. Renderas formuläret i en modal som byggs i sidfoten — vilket är fallet i de flesta sidbyggare — hinner en villkorlig laddning inte med, och stilmallen skulle hamna efter sidan ritats. Vet sajten att formuläret bara finns i innehållet går det att stänga av.

## Kampanjkakan och samtycke

Attributionen sparas i förstapartskakan **`xf_src`** (90 dagar): UTM-parametrar, `gclid`/`fbclid`, landningssida och hänvisande sida. Den är inte nödvändig för att formuläret ska fungera, så den lyder under samtyckesreglerna – ta med den i sajtens cookie-policy.

Läget styrs av filtret `relativt_form_utm_cookie`:

- **`auto`** (standard). Är [Relativt Cookie Consent](https://github.com/relativtwebb/relativt-cookie-consent) aktivt på sajten skrivs kakan först när besökaren godkänt **statistik eller marknadsföring**, tas bort om samtycket dras tillbaka, och skrivs i efterhand om samtycket kommer senare på sidan (motorn lyssnar på `rcc_consent_updated`). Utan samtyckesverktyg skrivs kakan direkt – då är det sajtens ansvar att dokumentera den.
- **`always`.** Skriv alltid. För sajter som hanterar samtycket på annat håll, t.ex. genom att blockera skriptet tills samtycke finns.
- **`never`.** Skriv aldrig. Attributionen lever då bara i minnet, så den följer med inskick från landningssidan men inte mellan sidladdningar.

Innan samtycket är avgjort hålls attributionen i minnet: landar besökaren på kampanjsidan och skickar formuläret där följer kampanjen med i inskicket även utan kaka.

## Event

Vid lyckat inskick sänds ett event på `document`, så spårning kan hängas på utan att bakas in i motorn:

```js
document.addEventListener('relativt-form:success', function (e) {
  // e.detail.formId — formulärets id
  // e.detail.root   — formulärets rot-element
});
```

## Flytta ett formulär mellan sajter

**Formulär → hovra över formuläret → Exportera JSON.** På den nya sajten: **Formulär → Importera**.

Formuläret skapas som utkast med en ny shortcode. Ingenting skrivs över.

Importen läser aldrig in fältnamn rakt av — allt passerar en vitlista, och det som inte står i den kastas. Exporten innehåller bara definitionen, aldrig inskicken: de är personuppgifter och hör hemma i CSV-exporten.

**Kontrollera alltid mottagaradresserna efter en import.** De följer med från sajten filen kom ifrån.

## Standardvärden

**Formulär → Standardvärden** sätter avsändare, tacktexter och samtyckestext en gång per sajt. Ett formulär som lämnar motsvarande fält tomt ärver värdet därifrån. Formulärets eget värde vinner alltid.

## E-post

Motorn anropar `wp_mail()` och bryr sig inte om vad som ligger bakom. På en sajt utan SMTP skickar WordPress direkt från webbservern, vilket ofta landar i skräpposten — koppla på en riktig avsändartjänst innan lansering.

Avsändaradressen måste ligga på en domän som är verifierad hos leverantören. Svara-till sätts alltid till besökarens adress, så mottagaren kan svara direkt ur mailet.

Misslyckas ett mail sparas inskicket ändå (om lagringen är på) och en varning visas i formulär- och inskicksvyerna i wp-admin, med länk till de drabbade inskicken. Ett trasigt SMTP ska synas samma dag, inte upptäckas när kunden undrar var alla leads tog vägen.

## Tester

```bash
npm ci
npx playwright install chromium

php tests/server-test.php   # 187 assertions: validering, villkor, routing, mail, rendering, REST-flödet, import
npx playwright test         # 82 tester i riktig webbläsare, desktop och mobil
```

Har du redan en Chromium på maskinen som Playwright inte installerat själv, peka ut den med `CHROMIUM_PATH=/sökväg/till/chrome npx playwright test`. Utan variabeln används Playwrights egen.

IDN-testet (`kontakt@räksmörgås.se`) hoppas över om PHP-tillägget `intl` saknas, eftersom motorn hoppar över punycode-översättningen i samma läge. CI installerar `intl`, så den vägen testas där.

Demon som webbläsartesterna körs mot genereras av den riktiga renderaren via reflektion (`php tests/build-demo.php`). Den kan alltså inte glida ifrån koden.

Båda sviterna kör automatiskt vid varje push. Servertesterna körs mot PHP 8.0, 8.2 och 8.4 — det är den matrisen som bevisar `Requires PHP: 8.0`, inte headern i sig.

## Släppa en ny version

1. Ändra koden, kör testerna lokalt.
2. Höj versionen på **tre** ställen: `Version`-headern och konstanten `RELATIVT_FORM_VERSION` i `relativt-formular.php`, samt `Stable tag` i `readme.txt`. `release.yml` vägrar tagga om något av dem inte stämmer, så en miss stannar i CI i stället för på kundsajterna.
3. Skriv en ny rubrik överst i `CHANGELOG.md`.
4. Commit, push.
5. Tagga och skjut upp taggen:

```bash
git tag v1.0.1
git push origin v1.0.1
```

Resten sköter `release.yml`: den kontrollerar att taggen matchar Version-headern, kör servertesterna, bygger zippen och publicerar releasen med zippen bifogad och changelog-avsnittet som beskrivning.

Kundsajterna ser uppdateringen inom tolv timmar — uppdateraren cachar GitHub-svaret så länge. Ska den fram direkt: **Insticksprogram → Sök efter uppdateringar**, eller töm transienten `relativt_form_release_*`.

**Om taggen inte stämmer med header, konstant och Stable tag faller releasen med flit.** En sajt som installerat v1.0.1 medan filen inuti säger 1.0.0 blir annars erbjuden samma uppdatering om och om igen — och en konstant som släpar efter serverar gammal JS ur webbläsarcachen efter uppdateringen.

## Licens

GPL-2.0-or-later.
