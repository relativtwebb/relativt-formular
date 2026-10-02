# Ändringslogg

Formatet följer [Keep a Changelog](https://keepachangelog.com/sv/1.1.0/).
Versionerna följer [semantisk versionshantering](https://semver.org/lang/sv/).

## [1.8.0] – 2026-10-02

Integritet och tillgänglighet. Formulären fungerar som förut för besökarna,
men två standardvärden ändras – se **Ändrat**.

### Rättat
- **Samtyckesversionen i Relativt Cookie Consent respekteras alltid.** När
  cookie-pluginets skript inte hunnit köras läste formuläret samtyckeskakan
  direkt, utan att kontrollera versionen. Efter en höjd samtyckesversion
  kunde `xf_src` då skrivas fast besökaren inte samtyckt på nytt. Nu:
  - samtycket läses med `window.rcc.getConsent()`, som bortser från
    inaktuella samtycken; formuläret väntar på cookie-pluginets API
    (`rcc_ready`, annars tills sidan laddat färdigt) i stället för att läsa
    kakan direkt;
  - körs cookie-pluginets skript aldrig läses kakan direkt, men bara om
    versionen stämmer med sajtens (`rccConsentVersion`, skickas från PHP).
    Okänd version räknas som inget samtycke.

### Ändrat
- **Klick-id följer bara med inskicket med samtycke.** `gclid` och `fbclid`
  skickas med ett inskick bara om besökaren samtyckt till kategorin för
  klick-id (normalt marknadsföring) när formuläret skickas. Före 1.8.0 hölls
  de utanför kakan men skickades ändå med inskicket från landningssidan, och
  sparades då i databasen och i mailet tillsammans med namn och
  e-postadress. UTM-parametrar, landningssida och hänvisare följer med som
  förut. Statistikens kort Annonsklick och kanalerna för betald trafik kan
  därför visa färre inskick än annonsplattformen.
- **IP-adressen sparas inte längre som standard.** Valet *Spara IP-adress*
  är av för nya formulär. Formulär som sparats tidigare behåller sitt val –
  gå igenom dem och stäng av där IP-adressen inte behövs. Frekvensspärren
  fungerar oförändrat.
- **Gallring för formulär utan värde.** Ett formulär som saknar ett sparat
  värde för *Radera inskick efter (dagar)* gallras nu efter 365 dagar – det
  byggaren redan visade som standard. Tidigare betydde ett saknat värde
  "radera aldrig". Gäller i praktiken bara formulär som aldrig sparats i
  byggaren, t.ex. importerade från en mycket gammal export. Ett uttryckligt
  0 betyder fortfarande aldrig.

### Nytt
- **WordPress integritetsverktyg.** Inskicken ingår i *Verktyg → Exportera
  personuppgifter* och *Radera personuppgifter*. Ett inskick hittas om
  adressen står i något av formulärets e-postfält (inte bara det första),
  oavsett versaler; en adress som bara nämns i ett meddelande räknas inte.
  Raderingen tar bort inskicken helt. Filtret `relativt_form_privacy_erase`
  håller kvar inskick som måste sparas, och de redovisas i verktyget.
- **Samtyckesrutan sparas med inskicket.** Med *Kräv ikryssad ruta* sparas
  *Godkänt:* och samtyckestexten som gällde, och visas i inskicket, mailet,
  CSV-exporten och personuppgiftsexporten. Samtyckestexten läses aldrig in
  på statistiksidan.
- **Fältvalet Autofyll** (`autocomplete`, WCAG 1.3.5) för text, e-post,
  telefon och URL. *Automatiskt* (standard) sätter e-post, telefon och URL
  efter typen och textfält efter nyckeln – `namn`, `foretag`, `fornamn`,
  `efternamn`, `titel`, `adress`, `postnummer`, `ort`, `land` m.fl. – så
  befintliga formulär får attributet utan att öppnas. Okända fält får inget.
  Valet följer med i JSON-export/import, och headless-definitionen har det
  färdiga värdet i `autocomplete`.

### Tester
- 46 nya serverassertions (543 totalt): autofyll (gissning, uttryckligt
  val, Inget, rensning av okända värden, rendering, headless, export),
  IP och gallring som standard, samtyckesbeviset, integritetsverktygen
  (sökning, flera e-postfält, falska träffar, export, radering, kvarhållna
  inskick, evig loop), samtyckesversionen i konfigurationen.
- 7 nya Playwright-tester (160 totalt): samtyckesversion med och utan
  cookie-pluginets API, API som laddas efter formulärskriptet, klick-id i
  inskicket med och utan samtycke, autofyll. `withRcc` efterliknar nu
  cookie-pluginets API med versionskontroll.

## [1.7.0] – 2026-10-02

> **Beteendeändring – läs före uppdatering.** På sajter **utan**
> samtyckesverktyg skrivs kampanjkakan `xf_src` inte längre. Före 1.7.0
> skrevs den direkt i standardläget `auto` när inget samtyckesverktyg fanns;
> det är fel standard på en EU-sajt. Nu hålls attributionen bara i minnet:
> ett inskick från landningssidan får kampanjen, men den följer inte med
> till nästa sida. En befintlig `xf_src` från en äldre version tas bort vid
> nästa sidvisning.
>
> Sajter med Relativt Cookie Consent berörs bara av den nya kategoriregeln
> för klick-id nedan. Den som vill ha det gamla beteendet – kakan skrivs
> alltid, med klick-id – sätter läget `always`:
>
> ```php
> add_filter( 'relativt_form_utm_cookie', fn() => 'always' );
> ```

### Ändrat
- **Klick-id kräver samtycke till marknadsföring.** `gclid` och `fbclid` är
  annonsplattformarnas identifierare för ett enskilt klick, så samtycke till
  statistik räcker inte längre för dem:
  - UTM-parametrar, landningssida och hänvisande sida: statistik **eller**
    marknadsföring, som förut;
  - `gclid` och `fbclid`: bara marknadsföring. Utan det hålls de i minnet på
    sidan (ett inskick därifrån får dem) och tas bort ur en befintlig kaka.
- **Samma regel när samtycket ändras**, även vid nedgradering från
  marknadsföring till bara statistik: kakan skrivs om utan klick-id, med
  oförändrad livslängd. Det kakan inte längre får innehålla läses inte heller
  ur den, och avskalade värden kommer inte tillbaka om samtycket ges igen.
- Kakans livslängd räknas från när kampanjen fångades, inte från senaste
  skrivningen – en omskrivning förlänger den aldrig.
- **Utan samtyckesverktyg skrivs kakan inte** (se rutan ovan).

### Nytt
- **Filtret `relativt_form_consent_categories`** styr kategorierna för
  kakans två delar, `attribution` och `click_ids` (någon kategori i listan
  räcker). En del som saknas behåller standardvärdet; en tom lista betyder
  att delen aldrig sparas.
- **WP Consent API.** Är API:et aktivt läses samtycket med `wp_has_consent()`
  och följs via `wp_listen_for_consent_change`. Kakan registreras med
  `wp_add_cookie_info()` (kategori: den första för attributionen, normalt
  `statistics`), och pluginet förklarar sig kompatibelt
  (`wp_consent_api_registered_…`). API:et utan samtyckestyp – alltså utan
  något samtyckesverktyg som kopplat in sig – räknas inte: då svarar
  `wp_has_consent()` ja på allt, och det svaret används inte.
- **Generell JS-krok för egna samtyckeslösningar:**
  `window.relativtFormConsent = (kategori) => true/false`, och händelsen
  `relativt-form:consent` när samtycket ändras (`detail` kan bära beskedet
  direkt, t.ex. `{ statistics: true, marketing: false }`). Kroken får
  definieras efter formulärskriptet.
- **Prioritet:** Relativt Cookie Consent, sedan WP Consent API, sedan
  JS-kroken. Det första som finns gäller.
- `window.relativtForm.consent()` visar läge, verktyg och vad kakan får
  innehålla – för felsökning av en samtyckesintegration.

### Tekniskt
- WP Consent API:s skript köas med mycket hög prioritet och körs efter
  formulärskriptet. Därför avgörs "inget samtyckesverktyg" först när sidan
  laddat färdigt; fram till dess lämnas kakan orörd och attributionen hålls i
  minnet. PHP skickar `wpConsentApi: true` så att skriptet vet att det ska
  vänta.
- Ett samtyckesverktyg som kastar fel tolkas aldrig som ja.

### Tester
- 18 nya serverassertions (497 totalt): kategorierna i konfigurationen,
  filtret del för del, tom lista, ogiltiga filtervärden, WP Consent
  API-flaggan, `wp_add_cookie_info` med och utan API:et, kompatibilitets-
  förklaringen.
- 14 nya Playwright-tester (146 totalt med desktop och mobil): statistik
  respektive marknadsföring, nedgradering med oförändrad livslängd,
  återkallat samtycke mellan sidladdningar, filtrerade kategorier, inget
  samtyckesverktyg, gammal kaka, `always`, WP Consent API (med och utan
  samtyckestyp, laddat efter formulärskriptet), JS-kroken, trasig krok och
  prioritetsordningen. De befintliga UTM-testerna körs nu med samtycke.

## [1.6.1] – 2026-10-02

### Ändrat
- **Restrad i topplistorna.** Andelarna räknas på alla inskick i perioden,
  men listorna visade bara inskick som hade ett värde – "google.com 56 %"
  och ingenting om de andra 44 procenten. Listor som inte täcker alla inskick
  får nu en grå sista rad med resten:
  - Hänvisande webbplatser → *Ingen extern webbplats*, med en förklaring
    (direkttrafik, okänd källa eller trafik från sajten själv);
  - `utm_source` / `utm_medium` / `utm_campaign` → *Utan utm_…*;
  - Annonsklick → *Utan annonsklick*;
  - Landningssidor och Skickat från → *Uppgift saknas*;
  - följda fält → *Ej besvarat* (samma mekanism som i 1.6.0).
- Restraden står alltid sist, kapas aldrig bort och har ingen stapel – den
  är ingen kategori bland de andra, och i skalan krympte den de riktiga
  staplarna till streck. Staplarna skalas mot listans största riktiga värde.
- "Visar N av M" räknar nu de rader som faktiskt visas.
- Kanaler, Per formulär, Enhet och Webbläsare täcker alltid alla inskick och
  får ingen restrad.
- Cacheversionen höjd.

### Tester
- Restraden per lista, att andelarna summerar till 100 %, att den står kvar
  när listan kapas, "Visar 9 av 14", och en lista helt utan värden
  (479 serverassertions). Playwright-korten väljs nu på exakt rubrik.

## [1.6.0] – 2026-10-02

Ingenting ändras förrän någon slår på det nya fältvalet. Formulär sparade före
1.6.0 saknar nyckeln och beter sig som förut.

### Nytt
- **Fältvalet Visa i statistiken.** Finns för rullista, val-knappar,
  radioknappar, flerval, kryssruta och dolt fält (`Relativt_Form::STATS_TYPES`).
  Av som standard. `get_fields()` kontrollerar typen igen, så ett fält som
  byter typ till fritext slutar räknas även om valet ligger kvar sparat.
- **Formulär → Statistik → Formulärsvar.** Ett kort per följt fält med
  fördelningen av svaren under perioden:
  - val räknas på det tekniska värdet och visas med dagens etikett, så en
    omdöpt etikett inte delar upp statistiken; borttagna val visas med
    etiketten som sparades med inskicket;
  - flerval ger en rad per val (andelarna kan summera över 100 %);
  - **Ej besvarat** (grå stapel) för tomma fält och fält dolda av villkor –
    andelarna räknas på alla inskick från formuläret;
  - med *Alla formulär* står formulärets namn i kortets rubrik;
  - utan följda fält visar sektionen var valet slås på.
- Valet följer med i JSON-export/import. Det tas bort ur det publika
  headless-endpointet – det är en admininställning, inte en del av formuläret.
- Fältkartan i sidokolumnen märker följda fält med "i statistiken".

### Integritet
- `_xf_values` läses bara när minst ett fält följs, och reduceras direkt vid
  inläsningen till de följda fälten. Namn, e-post och fritext lämnar aldrig
  inläsningen. Utan följda fält är frågorna exakt som i 1.5.2.
- Dolda fält kapas till 100 tecken i statistiken.

### Tester
- 29 nya serverassertions (470 totalt): fältvalet och vilka typer det visas
  för, att fritext aldrig kan följas, export, etikettlogiken (omdöpta och
  borttagna val, flerval, kryssruta, dolda fält), att `_xf_values` bara läses
  när något följs och att namn/e-post aldrig följer med, fördelning och
  Ej besvarat, cachebrytning när ett fält slås på, sektionen på sidan.
- Statistikdemon följer två fält; Playwright kontrollerar korten.

## [1.5.2] – 2026-10-02

### Ändrat
- **Statistik → Kampanjer redovisar klick-id för sig.** Andelen med
  kampanjdata räknade redan `gclid` och `fbclid`, men korten visade bara
  UTM-taggar. Ett inskick som kom via Google Ads automatiska taggning eller en
  Facebook-länk – med klick-id men utan en enda UTM-tagg – syntes därför i
  "38 av 100" men inte i något kort, och korten stod tomma.
  - Raden under rubriken delar upp siffran: *"38 av 100 inskick har
    kampanjdata – 12 med UTM-taggar och 26 med bara annonsklick-id
    (gclid/fbclid)."*
  - Nytt kort **Annonsklick** med antal inskick per klick-id, och en
    påminnelse om att `fbclid` följer med alla länkar från Facebook och
    Instagram, inte bara annonser.
  - Tomma UTM-kort säger *"Inga UTM-taggade inskick. N inskick har bara
    annonsklick-id"* i stället för bara "Inga uppgifter under perioden".
- Cacheversionen höjd, så att rapporter cachade av 1.5.0/1.5.1 byggs om.

### Tester
- Nya assertions för uppdelningen och för just fallet ovan: bara klick-id,
  inga UTM-taggar (441 totalt). Playwright kontrollerar det nya kortet.

## [1.5.1] – 2026-10-02

### Ändrat
- `includes/class-relativt-form-stats.php` läses bara in när `is_admin()` är
  sant. Statistiksidan har ingen logik utanför wp-admin, så besökarnas
  sidvisningar slipper läsa in filen (46 kB, ungefär 1,5 ms per sidvisning
  på servrar utan opcache). Ingen ändring i funktion.

### Tester
- Serversviten bevisar att klassen saknas på frontend och, i en egen
  PHP-process, att den startas och hänger på `admin_menu` i wp-admin
  (433 assertions).

## [1.5.0] – 2026-10-02

Ingenting ändras i formulären, mailen, REST-API:et eller den data som sparas
med varje inskick. Det nya är en sida som läser det som redan finns.

### Nytt
- **Formulär → Statistik.** Sammanställer metadatan från sparade inskick för
  vald period (7/30/90 dagar, 12 månader, i år, alla sparade inskick eller
  eget intervall) och ett eller alla formulär:
  - nyckeltal: antal inskick mot föregående period (”I år” mot samma datum i
    fjol), snitt per vecka, andel med kampanjdata och misslyckade notismail
    med länk till de drabbade inskicken;
  - inskick över tid per dag, vecka eller månad, med verktygstips per stapel
    och tabellvy – en vecka eller månad som perioden bara delvis täcker säger
    det i stället för att se ut som ett ras;
  - kanaler (betald sök, betald social, övriga annonser, organisk sök, social,
    AI-assistenter, e-post, övriga kampanjer, hänvisning, direkt/okänd),
    inskick per formulär, enhet och webbläsare (appwebbläsare som LinkedIns
    räknas för sig);
  - topplistor för `utm_source`, `utm_medium`, `utm_campaign`, hänvisande
    webbplatser, landningssidor och sidorna formuläret skickades från;
  - veckodag × klockslag i sajtens tidszon.
- Filtret `relativt_form_stats_channel( $channel, $meta )` för sajter med
  egna utm-konventioner.
- `Relativt_Form::stores_entries()` och `retention_days()` – samma tolkning
  av lagring och gallring som motorn själv använder.

### Detaljer
- Sidan säger vad siffrorna inte täcker: formulär som inte sparar inskick, och
  gallring som redan tagit bort början av perioden.
- Inga personuppgifter visas. Fältvärden och e-post läses aldrig ur
  databasen, IP-adressen i metadatan släpps direkt vid inläsningen och user
  agent används bara för enhetstyp och webbläsare.
- Inskicken läses med egna frågor i batcher om 500, bläddrade på id i stället
  för OFFSET, och bara metanycklarna `_xf_form_id`, `_xf_meta` och
  `_xf_mail_ok` hämtas. Jämförelseperioden räknas med `COUNT(*)`.
- Sammanställningen cachas i sex timmar. Cachenyckeln innehåller antal och
  högsta id bland de sparade inskicken plus senast skrivna mailstatus, så ett
  nytt eller gallrat inskick syns direkt – även på sajter med beständig
  objektcache, där `wp_count_posts()` inte märker gallringen – och ett
  inskick vars mail fortfarande skickas fryses inte halvfärdigt i cachen.
- Eget intervall hålls mellan år 2000 och i dag och kapas till tio år.
- Diagrammen är inline-SVG och CSS – inga externa skript i wp-admin.
- Kräver samma behörighet som inskickslistan (`edit_pages`).

### Tester
- 115 nya serverassertions (432 totalt): kanalklassning, enhet/webbläsare,
  sidnycklar, perioder och jämförelseperioder, ISO-veckor över årsskiftet, skottdagen,
  orimliga intervall, PHP-tidszon som inte är UTC, axelns skalsteg,
  sammanställningen, batchläsning med id-bokmärke mot en
  testdatabas som filtrerar på riktigt, att IP, e-post och fältvärden aldrig
  följer med, cache och cachebrytning (nytt, gallrat, mailstatus), escaping av
  kampanjvärden och hänvisare, täckningsnotisen.
- Ny demo `demo-stats.html` (`tests/build-stats-demo.php`) och fyra
  Playwright-tester (118 totalt, desktop och mobil): rendering, periodväljaren,
  verktygstips och tabellvy, ingen sidledsscroll på mobil.

## [1.4.0] – 2026-10-01

Ingenting ändras för sajter som inte slår på Turnstile. Nyttolasten,
svarsformaten, shortcode-markupen, CSS-standardvärdena och exportens befintliga
nycklar är oförändrade, och formulär sparade före 1.4.0 saknar den nya
nyckeln och beter sig som förut.

### Nytt
- **Headless: `GET /wp-json/relativt-form/v1/form/<id>`.** Publikt endpoint
  med formulärdefinitionen – fälten som `get_fields()` returnerar dem
  (rubriker inräknade; `choices` alltid ett JSON-objekt), knapp-, tack- och samtyckestexter via samma kedja som
  renderaren (formulär → Standardvärden → kodens fallback), meddelandena
  *efter* filtret `relativt_form_messages`, honungsfältets namn,
  Turnstile-läget och sajtens REST-rot. En frontend som renderar formuläret
  själv behöver alltså inte definiera fälten en gång till. Bara publicerade
  formulär svarar (annars 404). Svaret byggs av en uttrycklig lista:
  mottagare, regler, avsändare, ämnesrad, lagringsval och nycklar följer
  aldrig med.
- **Cloudflare Turnstile**, avstängt som standard. Kryssrutan **Kräv
  Turnstile** under formulärets nya flik Skydd; nycklarna under Formulär →
  Standardvärden eller som konstanterna `RELATIVT_FORM_TURNSTILE_SITE_KEY` /
  `RELATIVT_FORM_TURNSTILE_SECRET` i `wp-config.php`, som vinner över
  databasen. Secret renderas aldrig i HTML. Kryssat men en nyckel saknas:
  formuläret skickas som vanligt, utan Turnstile, och en admin-notis säger
  vilken nyckel som saknas.
- Servern verifierar token (`turnstile` eller widgetens eget
  `cf-turnstile-response` i nyttolasten) mot siteverify *efter* honungsfälla,
  nonce, tidsspärr och frekvensspärr – så att JS:ens tysta omsändning efter
  `toofast` inte bränner engångstoken. Avslag: `403 { code: "turnstile" }`
  med den nya meddelandenyckeln `turnstile`. `remoteip` går genom
  `relativt_form_client_ip`.
- **Fail open när Cloudflare inte går att nå** (nätverksfel, timeout, svar
  som inte är JSON): inskicket släpps igenom med 1.3.0:s skydd, felet loggas
  via `error_log` och syns som admin-notis tills en verifiering lyckas igen.
  Ett uttryckligt `success: false` från Cloudflare avvisar alltid; avvisas
  själva secret loggas det och syns också i admin.
- Shortcode-läget: widgeten renderas ovanför knappen, `api.js` laddas
  `async defer` bara på sidor där ett formulär kräver det. JS kräver token
  innan sändning, skickar den med och återställer widgeten efter varje
  misslyckat svar. Flera formulär på samma sida har varsin widget.
- `xf_turnstile` följer med i export/import. Nycklarna gör det inte.
  `uninstall.php` tar bort nycklarna tillsammans med övriga alternativ.

### Dokumentation
- Ny README-sektion **Headless: rendera formuläret själv** med flödet,
  CORS (WordPress-kärnan räcker), proxy-varningen och ett TypeScript-exempel.
  Testantalen i README var inaktuella och är rättade.

### Tester
- 317 serverassertions (från 217): definitionsendpointet (innehåll,
  fallback-kedjan, 404 för utkast, läckagetest som letar efter både
  nycklarnas namn och värden), Turnstile av/på/utan nycklar, giltig och
  ogiltig token, ordningen (toofast, nonce, frekvensspärr och honungsfälla
  anropar aldrig siteverify), fail open med loggning, konstanterna före
  databasen (i en egen PHP-process), att secret aldrig renderas.
- 110 Playwright-tester (från 94): widgeten renderas, token följer med,
  inget inskick utan token, återställning efter 422 och 403 men inte vid
  tyst toofast-omsändning, två formulär med varsin widget. Cloudflares
  api.js stubbas i demon, så CI inte beror på nätverket.

## [1.3.0] – 2026-09-21

### Nytt
- **Fältvalet "Visa etikett".** Ikryssad som standard i fältbyggaren; kryssas
  den ur döljs fältets etikett på sajten. Gäller alla fälttyper utom
  Rubrik/avdelare, där etiketten är hela innehållet.
- Döljningen är rent visuell (en `xf-sr-only`-klass), inte en DOM-borttagning:
  etiketten ligger kvar för skärmläsare, och gruppfältens (val-knappar,
  radio, flerval) `aria-labelledby` fortsätter peka på ett riktigt namn. En
  dold kryssruta-etikett förblir sin `<label>`:s klickyta.
- Bakåtkompatibelt: formulär sparade före 1.3.0 saknar helt den nya nyckeln
  och visar sina etiketter precis som innan uppdateringen.
- Följer med i export/import av formulärdefinitioner (portabilitetens
  vitlista).

### Tester
- 217 serverassertions (från 208), plus tre nya Playwright-tester som via
  role/name-lokatorer verifierar att textfält, kryssrutor och gruppfält
  behåller sitt tillgängliga namn när etiketten är avstängd.

## [1.2.0] – 2026-09-09

### Nytt
- **Fälttypen URL.** Renderas som `<input type="url">` (rätt tangentbord på
  mobil, webbläsarens inbyggda formathjälp) och valideras/normaliseras
  server- och klientsidan precis som e-post och telefon: saknas schemat
  läggs `https://` på automatiskt, så "linkedin.com/in/namn" sparas som en
  riktig, klickbar länk i stället för en trasig relativ sådan.
- **URL-fält renderas som en klickbar länk i notismejlet.** Tidigare skrevs
  alla fältvärden ut som ren text i mailet – ett URL-fält blir nu en
  `<a href>` istället, så mottagaren kan klicka sig rakt till t.ex. en
  kandidats LinkedIn-profil.
- Ny publik metod `normalize_url()`, samma mönster som `normalize_phone()`.
  Speglad i `relativt-formular.js` som `normalizeUrl()`.

### Tester
- 208 serverassertions (från 195). Nytt: normalisering av giltiga och
  ogiltiga webbadresser (saknat schema, tom host, ren text) samt att
  URL-fält faktiskt renderas som `<a href>` i mailkroppen.

## [1.1.3] – 2026-09-03

### Rättat
- **Fälttypen Dolt fält skickades aldrig med i inskicket.** Fältet
  renderades korrekt med sitt värde (t.ex. från ett shortcode-attribut),
  men nyttolasten hoppade över alla fält med dold wrapper – skyddet som
  finns för att *villkorsdolda* fält inte ska skickas träffade även typen
  Dolt fält, som bär samma hidden-attribut. Värdet nådde därför aldrig
  mail, ämnesrad, inskicksvy eller CSV. Nu avgör enbart fältets villkor
  om det deltar i inskicket. Buggen har funnits sedan 1.0.0.

### Tester
- 195 serverassertions och 88 webbläsartester. Testformuläret har nu ett
  dolt fält med värde satt via shortcode-attribut, och regressionen täcks
  i båda leden: värdet valideras, når ämnesraden och skickas från riktig
  webbläsare.

## [1.1.2] – 2026-09-02

### Ändrat – standardutseendet, SYNS på sajterna
Standard-CSS:en har städats utifrån verklig användning på kundsajt. Sajter
som förlitat sig på de gamla standardvärdena ser skillnad efter
uppdateringen:

- **Skicka-knappen är innehållsbred på desktop**; full bredd först under
  768px. Alltid full bredd = `width: 100%` från sajtens CSS.
- **Ingen `!important` i utseende-regler.** Sajtens CSS vinner nu med
  vanlig specificitet. Kvar bara i funktionsregler som aldrig ska stylas:
  honungsfällans döljning, villkorsdolda fält, fälttypen Dolt fält,
  builder-läget och reducerad rörelse.
- **Typografiska tyckanden borttagna**: ingen `text-transform: uppercase`
  och ingen `letter-spacing` på etiketter och knapp – formuläret ärver
  sajtens typografi rakt av. Variabeln `--xf-label-spacing` är borttagen.
  Knappens textstorlek följer `--xf-input-size` i stället för hårdkodade
  14px.
- **Kryssrutor centreras mot sin text** (radioalternativ toppjusteras som
  förut, eftersom deras etiketter kan vara långa), plus en justering för
  Oxygen-teman som ritar egen bock med `::after`.

## [1.1.1] – 2026-09-02

### Nytt
- **Tack-sida per formulär.** Nytt fält "Tack-sida (URL)" under fliken
  Texter: fylls det i skickas besökaren dit efter lyckat inskick i stället
  för att tack-rutan visas – gjort för konverteringsspårning i GA4/Ads.
  Kan även sättas som sajtgemensamt standardvärde; formulärets eget värde
  vinner. Följer med i export/import. Knappen behåller "Skickar…" tills
  sidbytet sker, så dubbelinskick inte hinner ske.

### Dokumentation
- Nytt README-avsnitt **Tidszon**: tidsstämplarna följer sajtens
  tidszonsinställning, precis som resten av WordPress. Rätt datum men fel
  klockslag betyder att sajten står kvar på UTC (standard på en ny
  installation) – ställ Inställningar → Allmänt → Tidszon till Stockholm.

### Tester
- 192 serverassertions och 86 webbläsartester; tack-sidans hela väg från
  inställning till omdirigering testas i riktig webbläsare.

## [1.1.0] – 2026-08-31

### Rättat
- **Uppdateraren pekade på ett repo som inte finns** (`relativt/…` i stället
  för `relativtwebb/…`), så kundsajter erbjöds aldrig några uppdateringar.
  Samma URL rättad i pluginheadern och readme.txt.
- **Tidsspärren åt riktiga inskick.** Ett inskick inom tre sekunder fick
  fejkad succé – besökaren såg "Tack!" men inget skickades och inget sparades.
  Med webbläsarens autofyll var det fullt möjligt att vara så snabb. Spärren
  svarar nu med ett mjukt fel (`425`, kod `toofast`) som JS tyst gör om efter
  väntetiden; besökaren märker ingenting, boten får ett fel i stället för en
  succé.
- Tack-rutan har `tabindex="-1"` så att fokusflytten vid tack-läget faktiskt
  sker – tidigare var `focus()` en tyst no-op och skärmläsare lämnades kvar i
  det dolda formuläret.
- Export och import ger ett begripligt besked i stället för en vit sida när
  ACF Pro saknas.
- Datumvalideringen använder `checkdate()` – `2026-13-45` matchade tidigare
  mönstret och släpptes igenom.
- Gallringen tömmer hela backloggen i batchar i stället för att stanna vid
  200 inskick per formulär och dygn.

### Säkerhet
- CSV-exporten skyddar mot formelinjektion: celler som börjar med `=`, `+`,
  `-` eller `@` prefixas med apostrof så att Excel läser dem som text.
- Nytt filter `relativt_form_client_ip` för sajter bakom Cloudflare/proxy –
  utan det delade alla besökare proxyns IP och därmed samma frekvensspärr.
- Ny länkspärr: textrutor med fler än tre länkar avvisas (filtret
  `relativt_form_max_links` justerar taket, `0` stänger av). Speglad i JS.

### Nytt
- **Kampanjkakan respekterar samtycke.** Finns Relativt Cookie Consent på
  sajten skrivs `xf_src` först när besökaren godkänt statistik eller
  marknadsföring, tas bort vid återkallat samtycke, och skrivs i efterhand
  när samtycket kommer. Utan samtyckesverktyg är beteendet som i 1.0.
  Filtret `relativt_form_utm_cookie` (`auto`/`always`/`never`) styr.
- Hjälptext per fält i byggaren – renderaren stödde den redan, nu finns
  fältet. Följer med i export/import.
- Varning i formulär- och inskicksvyerna när mail inte kunnat skickas, med
  länk till de drabbade inskicken (`?xf_mail=failed`).
- Alla besökartexter kan bytas via filtret `relativt_form_messages` – samma
  lista driver PHP och JS, så klient och server säger alltid samma sak.
- Uppdateraren skickar med `requires_php`, så en framtida version med högre
  PHP-krav inte erbjuds servrar som inte klarar den.
- Utgången nonce gör om inskicket automatiskt med ny token i stället för att
  be besökaren trycka igen.

### Tillgänglighet
- Fel kopplas till fältet med `aria-invalid` och `aria-describedby` (även
  hjälptexten), val-knapps- och radiogrupper får sitt namn via
  `aria-labelledby`, flervalsgrupper `role="group"`, och obligatoriska
  flervalsgrupper valideras nu även i klienten via `data-xf-required`.

### Byggkedjan
- `release.yml` kräver att taggen matchar Version-headern, konstanten
  `RELATIVT_FORM_VERSION` **och** `Stable tag` i readme.txt. Klasskonstanten
  `Relativt_Form::VERSION` är borttagen – huvudfilens konstant är enda källan,
  och det är den som cache-bustar CSS/JS.

### Dokumentation
- Nytt avsnitt **Styla formuläret** i README: tre nivåer (skriv över
  CSS-variablerna, låt knappen ärva temats utseende, stäng av stilmallen
  helt), komplett variabeltabell, uppdateringssäkra kodexempel och hur
  enskilda formulär får eget utseende (`data-xf-form`-selektorn,
  shortcodens `class`-attribut, formulär-id:t i knappfiltren).
  CSS-filens gamla "justera här"-instruktioner är omskrivna – nu när
  auto-uppdateringarna fungerar skrivs pluginets filer över vid varje
  release, så all anpassning ska ske från sajtens egen CSS.

### Tester
- 187 serverassertions (från 146) och 82 webbläsartester (från 64). Nytt:
  hela REST-inskicksflödet körs rakt igenom i riggen (honungsfälla, nonce,
  signatur, tidsspärr, frekvensspärr, lagring, mail), den tysta omsändningen
  och samtyckeslägena testas i riktig webbläsare, och definitionscachen kan
  tömmas så att testerna mäter koden i stället för cachen.

## [1.0.0] – 2026-08-27

Första releasen.

### Ingår
- Formulärbyggare i wp-admin byggd på ACF Pro. Varje formulär får en egen
  shortcode, och shortcode-attribut kan förvälja värden per sida.
- Villkorliga fält som utvärderas på servern, så att formuläret ser likadant
  ut med och utan JavaScript.
- Mottagarregler som träffar på både etikett och tekniskt värde.
- Validering av e-post och telefon, spegelvänd mellan PHP och JavaScript.
- Spamskydd utan CAPTCHA: honungsfälla, HMAC-signerad tidsstämpel med minsta
  tid, och frekvensspärr per IP.
- UTM-attribution via förstapartskaka.
- Inskickslagring med konfigurerbar gallring och CSV-export.
- Export och import av formulärdefinitioner som JSON, med vitlistad import.
- Inställningssida för globala standardvärden som nya formulär ärver.
- Uppdateringar från publikt GitHub-repo, direkt i wp-admin.
- Filter för att skjuta in temats egna knappklasser och byta eller ta bort
  knappikonen.
- Admin-notis när ACF Pro saknas, i stället för att ingenting händer.
- `uninstall.php` som medvetet inte raderar data utan att bli tillsagd.

### Tester
- 146 serverassertions och 64 webbläsartester, alla gröna.
