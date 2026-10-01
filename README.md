# Relativt Formulär

Formulärmotor för WordPress. Bygg formulär i wp-admin, varje formulär får en egen shortcode.

Byggd för byråarbete: samma motor på flera kundsajter, formulär som kan flyttas mellan projekt, och en kund som klarar att ändra i sina egna fält utan att höra av sig.

## Vad den gör

- **Formulärbyggare i wp-admin.** Fält läggs till, döps om och sorteras genom att dra. Varje formulär får en shortcode.
- **Villkorliga fält** som utvärderas *på servern*. Ett fält som inte ska synas renderas dolt direkt i HTML:en — det behöver alltså inte JavaScript för att göra rätt, och en besökare med skript avstängt ser samma formulär som alla andra.
- **Mottagarregler.** Skicka till olika adresser beroende på vad besökaren svarat. Regler får skrivas med antingen etiketten eller det tekniska värdet — båda träffar.
- **Validering** av e-post, telefon och URL, spegelvänd mellan PHP och JavaScript så klienten och servern aldrig är oense. Svenska nummer normaliseras till ett format, internationella släpps igenom. URL:er normaliseras med `https://` om schemat saknas, så länken alltid går att klicka på i mailet.
- **Spamskydd** utan CAPTCHA: honungsfälla, HMAC-signerad tidsstämpel med minsta tid, frekvensspärr per IP och länkspärr i textrutor. Valfritt per formulär: [Cloudflare Turnstile](#cloudflare-turnstile).
- **UTM-attribution** via förstapartskaka, så att inskicket bär med sig vilken kampanj besökaren kom ifrån. Finns [Relativt Cookie Consent](https://github.com/relativtwebb/relativt-cookie-consent) på sajten skrivs kakan först när besökaren samtyckt – se [Kampanjkakan och samtycke](#kampanjkakan-och-samtycke).
- **Inskickslagring** med konfigurerbar gallring och CSV-export.
- **Export och import av formulärdefinitioner** som JSON.
- **Headless.** Formulärdefinitionen finns som publikt REST-endpoint, så en frontend (t.ex. Next.js) kan rendera formuläret själv och skicka till samma API – se [Headless: rendera formuläret själv](#headless-rendera-formuläret-själv).

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

Shortcoden tar också ett `class`-attribut som lägger egna klasser på formulärets rot – se [Olika utseende per formulär](#olika-utseende-per-formulär).

### Tack-sida i stället för tack-rutan

Fyll i **Tack-sida (URL)** under formulärets flik Texter – t.ex. `/tack/` – så skickas besökaren dit efter lyckat inskick, i stället för att tack-rutan visas. Under **Formulär → Standardvärden** kan en gemensam tack-sida sättas för hela sajten; formulärets eget värde vinner alltid.

Det här är rätt upplägg för konverteringsspårning: gör tack-sidan till mål i GA4 eller Google Ads, så behövs ingen event-koppling alls. Eventet `relativt-form:success` sänds visserligen även vid omdirigering (se [Event](#event)), men en event-baserad spårning hinner sällan iväg innan sidbytet – på sajter med tack-sida hör spårningen hemma på själva tack-sidan.

## Styla formuläret

Motorn levererar neutral markup, och pluginets CSS är ett golv – inte ett tak. Ingen utseende-regel använder `!important` och inga typografiska tyckanden (versaler, letter-spacing) ligger i vägen: formuläret ärver sajtens typografi, och sajtens CSS vinner med vanlig specificitet. De enda `!important` som finns är funktionsregler som aldrig ska stylas – honungsfällans döljning, villkorsdolda fält och builder-läget.

Det finns tre nivåer, från minst till mest arbete. Gemensamt för alla: **redigera aldrig pluginets egna filer.** De skrivs över vid varje uppdatering, så en färg som ändrats direkt i `assets/css/relativt-formular.css` försvinner nästa gång en release rullas ut.

### Nivå 1: skriv över variablerna (räcker nästan alltid)

Allt utseende – färger, luft, rundning, storlekar – styrs av CSS-variabler på `.relativt-form`. Skriv över dem från sajtens egen CSS (Oxygens stylesheet, child-temat, eller **Utseende → Anpassa → Ytterligare CSS**). Den dubblade klassen gör att sajtens värden alltid vinner över pluginets standard, oavsett i vilken ordning filerna råkar laddas:

```css
.relativt-form.relativt-form {
	--xf-accent: #0a3d62;        /* knappens bakgrund */
	--xf-accent-text: #ffffff;   /* knappens text och ikon */
	--xf-field-bg: #f0f4f8;      /* fältens bakgrund */
	--xf-radius: 8px;            /* rundning på fält och knapp */
}
```

Typsnittet behöver aldrig sättas – formuläret ärver alltid sajtens (`font-family: inherit` rakt igenom).

| Variabel | Standard | Styr |
|---|---|---|
| `--xf-gap` | `20px` | avstånd mellan kolumner, val-knappar |
| `--xf-gap-row` | `22px` | avstånd mellan rader |
| `--xf-radius` | `2px` | rundning på fält och knapp |
| `--xf-field-bg` | `#f5f5f4` | fältens bakgrund |
| `--xf-field-bg-focus` | `#efeeec` | fältens bakgrund vid hover/fokus |
| `--xf-field-pad-y` / `--xf-field-pad-x` | `16px` / `18px` | fältens inre luft |
| `--xf-text` | `#2b2a28` | text i fälten |
| `--xf-placeholder` | `#b6b4b0` | platshållartext |
| `--xf-label` | `#2b2a28` | etiketternas färg |
| `--xf-label-size` | `13px` | etiketternas storlek |
| `--xf-input-size` | `16px` | textstorlek i fälten och på knappen |
| `--xf-active-bg` / `--xf-active-text` | `#2b2a28` / `#fff` | vald val-knapp |
| `--xf-accent` / `--xf-accent-text` | `#2b2a28` / `#fff` | skicka-knappen |
| `--xf-icon-size` | `22px` | pilikonen i knappen |
| `--xf-error` | `#b32d2e` | felmeddelanden |
| `--xf-focus-ring` | `#2b2a28` | fokusram |

Typografin är medvetet neutral – ingen `text-transform`, ingen `letter-spacing`. Vill sajten ha versala etiketter läggs det till på samma sätt: `.relativt-form.relativt-form .xf-label { text-transform: uppercase; letter-spacing: .08em; }`. Skicka-knappen är innehållsbred på desktop och full bredd under 768px; ska den alltid vara full bredd: `.relativt-form.relativt-form .xf-submit { width: 100%; }`. Brytpunkten (768px) är däremot ingen variabel – CSS kan inte läsa variabler i media-frågor.

### Olika utseende per formulär

Grunden sätts en gång; en variant skriver bara över de variabler som skiljer. Tre sätt att peka ut vilket formulär som avviker:

**Per formulär, via id.** Roten bär alltid formulärets id som `data-xf-form`, så ett enskilt formulär kan stylas utan något förarbete:

```css
.relativt-form[data-xf-form="123"] { --xf-accent: #7a1f1f; }
```

**Per placering eller som namngiven variant, via shortcoden.** `class`-attributet lägger egna klasser på roten – samma formulär kan se olika ut på två sidor, och varianten följer med om formuläret exporteras till en annan sajt (till skillnad från id:t, som blir nytt vid import):

```
[relativt_formular id="123" class="form--mork"]
```

```css
.relativt-form.form--mork {
	--xf-field-bg: #2b2a28;
	--xf-text: #ffffff;
	--xf-placeholder: #8b8985;
}
```

**Per formulär, i knappfiltren.** Filtren får formulärets id som andra argument:

```php
add_filter( 'relativt_form_submit_class', fn( $c, $form_id ) =>
	123 === $form_id ? trim( "$c btn-secondary" ) : $c, 10, 2 );
```

### Nivå 2: låt knappen ärva temats utseende

På en sidbyggarsajt vill man ofta att skicka-knappen ska se ut och animeras exakt som temats övriga knappar. Skjut in temats klasser via filtren (exempel under [Filter](#filter) nedan). Krockar pluginets egen knappstyling med temats – t.ex. paddingen eller bakgrunden – skrivs den över från sajtens CSS med dubblad klass, i stället för att röra pluginfilen:

```css
.relativt-form .xf-submit.xf-submit { padding: 14px 24px; background: none; }
```

### Nivå 3: stäng av pluginets CSS och styla allt själv

```php
add_filter( 'relativt_form_enqueue_css', '__return_false' );
```

Då levererar pluginet bara markup, och sajten stylar mot klasserna: `.xf-grid`, `.xf-field` (`--full`/`--half`), `.xf-label`, `.xf-input`, `.xf-textarea`, `.xf-select`, `.xf-buttons` med `.xf-choice-label`, `.xf-radios`, `.xf-checks`/`.xf-check`, `.xf-help`, `.xf-error`, `.xf-form-error`, `.xf-actions`/`.xf-submit`, `.xf-consent`, `.xf-thanks`.

**Tre saker MÅSTE sajtens CSS då ta ansvar för**, eftersom de annars låg i stilmallen som stängdes av:

1. **Honungsfällan.** `.xf-hp` måste flyttas ut ur synfältet (position absolut utanför skärmen – inte `display: none`, en del bottar hoppar över helt dolda fält). Syns den fyller riktiga besökare i den, och deras inskick tystas som spam.
2. **Villkorsdolda fält.** Sätter sajten `display` på `.xf-field` slår det ut webbläsarens inbyggda döljning av `[hidden]` – lägg till `.xf-field[hidden] { display: none !important; }`, annars syns fält som villkoren skulle dölja.
3. **Tack-rutan och formuläret.** Samma sak där: `.xf-form[hidden], .xf-thanks[hidden] { display: none !important; }`.

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

## Cloudflare Turnstile

Ett extra spamskydd ovanpå honungsfällan och spärrarna, för formulär som behöver det. **Avstängt som standard** – ingenting ändras på en sajt som inte slår på det.

1. Skapa en widget i Cloudflare (Turnstile → Add widget) för sajtens domän och, för headless, frontendens domän.
2. Lägg nycklarna under **Formulär → Standardvärden**, eller – bättre för secret – i `wp-config.php`. Konstanterna vinner över fälten i wp-admin:

```php
define( 'RELATIVT_FORM_TURNSTILE_SITE_KEY', '0x4AAAA…' );
define( 'RELATIVT_FORM_TURNSTILE_SECRET', '0x4AAAA…' );
```

3. Kryssa i **Kräv Turnstile** under formulärets flik **Skydd**.

Secret skrivs aldrig ut i wp-admin efter att den sparats, och följer aldrig med i formulärexporten (valet Kräv Turnstile gör det).

**Saknas en nyckel** skickas formuläret som vanligt, utan Turnstile, och formuläret i wp-admin visar en notis om vilken nyckel som saknas. Hellre ett formulär utan extra skydd än ett som inte går att skicka.

**Om Cloudflare inte går att nå** – nätverksfel, timeout, ett svar som inte är JSON – släpps inskicket igenom (*fail open*), felet loggas via `error_log` och en notis visas i formulär- och inskicksvyerna tills en verifiering lyckas igen. Skälet: det felet kan en angripare inte framkalla, det är servern som pratar med Cloudflare. Det uppstår vid ett riktigt avbrott eller när webbhotellet spärrar utgående trafik, och då vore ett stängt formulär – leads som tyst uteblir – värre än ett formulär med de fyra vanliga skydden kvar. Ett uttryckligt nej från Cloudflare avvisar däremot alltid inskicket (`403`, kod `turnstile`). Avvisar Cloudflare själva secret syns det också som notis, eftersom varje inskick då stoppas.

Servern kontrollerar Turnstile **efter** honungsfälla, nonce, tidsspärr och frekvensspärr. Turnstile-tokens kan bara verifieras en gång, och JS gör tyst om ett inskick som stoppats av tidsspärren – låg kontrollen före skulle omsändningen falla på en redan förbrukad token.

Testnycklar från Cloudflare för utveckling: site key `1x00000000000000000000AA` passerar alltid, `2x00000000000000000000AB` blockerar alltid; secret `1x0000000000000000000000000000000AA` godkänner alltid.

## Event

Vid lyckat inskick sänds ett event på `document`, så spårning kan hängas på utan att bakas in i motorn:

```js
document.addEventListener('relativt-form:success', function (e) {
  // e.detail.formId — formulärets id
  // e.detail.root   — formulärets rot-element
});
```

## Headless: rendera formuläret själv

En frontend som inte laddar pluginets CSS och JS – t.ex. Next.js på en annan domän – kan rendera formuläret själv och prata direkt med REST-API:et. Fälten definieras fortfarande på ett ställe, i wp-admin; frontend hämtar definitionen.

### Endpoints

| Metod | Rutt | Gör |
|---|---|---|
| `GET` | `/wp-json/relativt-form/v1/form/<id>` | Formulärdefinitionen. Bara publicerade formulär, annars `404`. |
| `GET` | `/wp-json/relativt-form/v1/token?form=<id>` | `{ nonce, ts, sig }` för ett inskick. |
| `POST` | `/wp-json/relativt-form/v1/submit` | Själva inskicket, JSON. |

Definitionen innehåller fälten (som byggaren sparat dem, rubriker inräknade; `choices` är alltid ett objekt värde → etikett), knapp-, tack- och samtyckestexter, felmeddelandena efter filtret `relativt_form_messages`, honungsfältets namn, Turnstile-läget och sajtens REST-rot. Mottagare, regler, avsändare, ämnesrad, lagringsval och nycklar följer **aldrig** med – svaret är publikt och kan cachas av vem som helst.

**CORS behöver ingen kod.** WordPress-kärnan speglar `Origin` på `/wp-json/` och svarar på preflight med `Access-Control-Allow-Headers: … Content-Type`. Det fungerar alltså även när frontend ligger på en annan domän än WordPress. Bygg ingen egen CORS-lösning ovanpå.

### Flödet

1. **Hämta definitionen vid bygget** (eller med ISR) och rendera fälten. Honungsfältet (`honeypot`, i dag `xf_website`) renderas som ett textfält med samma namn, flyttat utanför skärmen – inte `display: none`, en del bottar hoppar över helt dolda fält. Villkorliga fält (`cond_field`/`cond_value`) utvärderas med samma regel som servern: fältet visas om **antingen** det tekniska värdet **eller** etiketten på det styrande fältet matchar något av de kommaseparerade värdena i `cond_value` (skiftlägesokänsligt); tomt `cond_value` = det styrande fältet ska bara ha ett värde. Dolda fält skickas inte med.
2. **Hämta token vid första fokus/input**, inte vid sidladdning – nonce har en livslängd och sidan kan vara cachad. Tidsspärren (3 s) mäts från token.
3. **POST `/submit` med JSON.** `credentials: 'omit'` räcker. `page` är frontendens egen adress. Värden: valfält skickar det tekniska värdet (nyckeln i `choices`), Flerval en array av värden, Kryssruta `"1"` eller `""`.
4. **Hantera svaren som pluginets egen JS:**
   - `200 { ok: true, title, text, redirect? }` → visa tacktexten (eller följ `redirect`, om frontend vill).
   - `422 { errors: { <nyckel>: <text> } }` → fältfel per nyckel (`xf_consent` för samtyckesrutan).
   - `425 { code: "toofast", retry_after }` → vänta `retry_after` sekunder och skicka om, högst två gånger.
   - `403 { code: "nonce" }` → hämta ny token och skicka om, en gång.
   - `403 { code: "turnstile" }` → återställ widgeten, visa `message` vid den.
   - övrigt (`429 rate`, `400 sig`, `404`, `500 mail`) → visa `message`, annars `texts.error`.
5. **Posta direkt från webbläsaren till WordPress, inte via en server-proxy** (t.ex. en Next route handler). Bakom en proxy delar alla besökare proxyns IP, och frekvensspärren – fem inskick per tio minuter – gäller då hela sajten på en gång. Ligger WordPress själv bakom en proxy/CDN pekar sajten ut rätt header med `relativt_form_client_ip` (se [Filter](#filter)).
6. **UTM:** utan pluginets JS skrivs ingen `xf_src`-kaka. Frontend kan skicka `utm` själv i samma format (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `landing`, `referrer`); annars är fälten tomma i mailet. Inget mer behövs.

**Turnstile i headless:** är `turnstile.enabled` sant renderar frontend widgeten med `turnstile.site_key` (lägg till frontendens domän på widgeten hos Cloudflare) och skickar token som `turnstile`. Återställ widgeten efter varje slutgiltigt misslyckat svar – men inte vid de tysta omförsöken för `toofast` och `nonce`, där är token fortfarande oanvänd.

**Bakom ett webbhotells CDN** är det inte alltid dokumenterat vilken header som bär besökarens IP. LiteSpeed-servrar skriver ofta själva om `REMOTE_ADDR` från betrodda CDN:er, och då behövs inget filter alls. Kontrollera en gång på sajten innan filtret läggs in: logga tillfälligt `$_SERVER['REMOTE_ADDR']` och kandidatheadrarna vid ett inskick från en känd IP,

```php
add_action( 'rest_api_init', fn() => error_log( wp_json_encode( array_intersect_key( $_SERVER,
	array_flip( [ 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP' ] ) ) ) ) );
```

och peka bara om filtret om `REMOTE_ADDR` visar CDN:ets adress. Ta bort loggningen efteråt – IP-adresser är personuppgifter.

### TypeScript

```ts
export type FieldType =
  | 'text' | 'email' | 'tel' | 'url' | 'number' | 'date' | 'textarea'
  | 'select' | 'buttons' | 'radio' | 'checkboxes' | 'checkbox' | 'hidden' | 'heading';

export interface FormField {
  type: FieldType;
  key: string;                       // tom för heading
  label: string;
  show_label: boolean;
  placeholder: string;
  help: string;
  choices: Record<string, string>;   // värde => etikett, {} om inga val
  default: string;
  required: boolean;
  width: 'full' | 'half';
  cond_field: string;
  cond_value: string;
}

export interface FormDefinition {
  id: number;
  title: string;
  fields: FormField[];
  texts: {
    submit: string; sending: string;
    thanks_title: string; thanks_text: string;
    error: string; redirect: string;
    consent: string;                 // html
    consent_box: boolean;
  };
  messages: Record<string, string>;  // required, email, tel, …, turnstile
  honeypot: string;
  turnstile: { enabled: boolean; site_key: string };
  rest: string;                      // slutar med /
}

export type SubmitResult =
  | { ok: true; title: string; text: string; redirect?: string }
  | { ok: false; errors: Record<string, string> }
  | { ok: false; code?: string; message?: string };

type Token = { nonce: string; ts: number; sig: string };

export const getForm = (wp: string, id: number): Promise<FormDefinition> =>
  fetch(`${wp}/wp-json/relativt-form/v1/form/${id}`).then((r) => {
    if (!r.ok) throw new Error(`Formulär ${id}: ${r.status}`);
    return r.json();
  });

const getToken = (def: FormDefinition): Promise<Token> =>
  fetch(`${def.rest}token?form=${def.id}`, { credentials: 'omit' }).then((r) => r.json());

/** Hämta token vid första fokus och skicka in den här, så mäts tidsspärren rätt. */
export async function submit(
  def: FormDefinition,
  fields: Record<string, string | string[]>,
  opts: { token: Promise<Token>; turnstile?: string; consent?: boolean; honeypot?: string; utm?: Record<string, string> },
): Promise<SubmitResult> {
  let token = await opts.token;

  for (let attempt = 0; attempt < 3; attempt++) {
    const res = await fetch(`${def.rest}submit`, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        form: def.id,
        fields,
        utm: opts.utm ?? {},
        page: window.location.origin + window.location.pathname,
        [def.honeypot]: opts.honeypot ?? '',
        xf_consent: opts.consent ? 1 : 0,
        ...(opts.turnstile !== undefined ? { turnstile: opts.turnstile } : {}),
        ...token,
      }),
    });
    const data = await res.json().catch(() => ({ ok: false }));

    if (data?.code === 'toofast' && attempt < 2) {
      await new Promise((r) => setTimeout(r, Math.min(10, Number(data.retry_after) || 3) * 1000 + 250));
      continue;
    }
    if (data?.code === 'nonce' && attempt === 0) {
      token = await getToken(def);
      continue;
    }
    return data as SubmitResult; // vid ok:false: återställ Turnstile-widgeten
  }
  return { ok: false, message: def.texts.error };
}
```

## Flytta ett formulär mellan sajter

**Formulär → hovra över formuläret → Exportera JSON.** På den nya sajten: **Formulär → Importera**.

Formuläret skapas som utkast med en ny shortcode. Ingenting skrivs över.

Importen läser aldrig in fältnamn rakt av — allt passerar en vitlista, och det som inte står i den kastas. Exporten innehåller bara definitionen, aldrig inskicken: de är personuppgifter och hör hemma i CSV-exporten.

**Kontrollera alltid mottagaradresserna efter en import.** De följer med från sajten filen kom ifrån.

## Standardvärden

**Formulär → Standardvärden** sätter avsändare, tacktexter och samtyckestext en gång per sajt. Ett formulär som lämnar motsvarande fält tomt ärver värdet därifrån. Formulärets eget värde vinner alltid.

## Tidszon

Tidsstämplarna – Datum och Tid i mailen och inskicksvyn, inskickens publiceringstid, CSV-exporten – följer **sajtens tidszonsinställning**, precis som resten av WordPress.

Visar inskicken rätt datum men fel klockslag står sajten nästan säkert kvar på UTC, som är standardläget på en ny WordPress-installation. Ställ **Inställningar → Allmänt → Tidszon** till *Stockholm* – gör det till en punkt i lanseringschecklistan. Redan sparade inskick behåller stämpeln de fick.

## E-post

Motorn anropar `wp_mail()` och bryr sig inte om vad som ligger bakom. På en sajt utan SMTP skickar WordPress direkt från webbservern, vilket ofta landar i skräpposten — koppla på en riktig avsändartjänst innan lansering.

Avsändaradressen måste ligga på en domän som är verifierad hos leverantören. Svara-till sätts alltid till besökarens adress, så mottagaren kan svara direkt ur mailet.

Misslyckas ett mail sparas inskicket ändå (om lagringen är på) och en varning visas i formulär- och inskicksvyerna i wp-admin, med länk till de drabbade inskicken. Ett trasigt SMTP ska synas samma dag, inte upptäckas när kunden undrar var alla leads tog vägen.

## Tester

```bash
npm ci
npx playwright install chromium

php tests/server-test.php   # 317 assertions: validering, villkor, routing, mail, rendering, REST-flödet, definitionen, Turnstile, import
npx playwright test         # 110 tester i riktig webbläsare, desktop och mobil
```

Har du redan en Chromium på maskinen som Playwright inte installerat själv, peka ut den med `CHROMIUM_PATH=/sökväg/till/chrome npx playwright test`. Utan variabeln används Playwrights egen.

IDN-testet (`kontakt@räksmörgås.se`) hoppas över om PHP-tillägget `intl` saknas, eftersom motorn hoppar över punycode-översättningen i samma läge. CI installerar `intl`, så den vägen testas där.

Demon som webbläsartesterna körs mot genereras av den riktiga renderaren via reflektion (`php tests/build-demo.php`). Den kan alltså inte glida ifrån koden. Cloudflares Turnstile-skript ersätts där av en stub med samma yta, så testerna inte beror på nätverket.

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
