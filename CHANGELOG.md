# Ändringslogg

Formatet följer [Keep a Changelog](https://keepachangelog.com/sv/1.1.0/).
Versionerna följer [semantisk versionshantering](https://semver.org/lang/sv/).

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
