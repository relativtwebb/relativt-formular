# Ändringslogg

Formatet följer [Keep a Changelog](https://keepachangelog.com/sv/1.1.0/).
Versionerna följer [semantisk versionshantering](https://semver.org/lang/sv/).

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
