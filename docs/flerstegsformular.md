# Flerstegsformulär – how-to

*Relativt Formulär 1.14.0 och senare.*

Ett flerstegsformulär visar fälten ett steg i taget, med **Nästa** och **Tillbaka** och en stegindikator ("Steg 2 av 4"). Varje steg kontrolleras innan besökaren får gå vidare, så felen rättas där de uppstår och inte i en klump vid inskicket. Allt skickas fortfarande som **ett** inskick i sista steget, med samma spamskydd, mottagarregler, mail, lagring och statistik som ett vanligt formulär.

Flerstegsläget är **av som standard**. Ett formulär blir aldrig flerstegs av en uppdatering. Se [Befintliga formulär](#befintliga-formulär).

---

## Kom igång

1. Öppna formuläret under **Formulär** i wp-admin.
2. I fältlistan: lägg till en rad av typen **Nytt steg (flerstegsformulär)** där nästa steg ska börja. Dra den till rätt plats.
   - **Etikett** blir stegets rubrik, t.ex. *Om företaget*.
   - **Hjälptext** blir en ingress under rubriken (valfri).
   - **Visa endast om** hoppar över hela steget när villkoret inte är uppfyllt (valfritt, se [Villkor på steg](#villkor-på-steg)).
3. Upprepa för varje steg. Fälten **före** den första stegraden är steg 1.
4. Under **Inställningar → Steg**: slå på **Flerstegsformulär**. Ställ in rubrik för första steget, indikator, animation, knapptexter och ikoner.
5. Uppdatera och titta på sidan.

Exempel på en fältlista med tre steg:

```
Namn                      (Text)
E-post                    (E-post)
Jag är                    (Val-knappar: Företag / Privatperson)
── Om företaget           (Nytt steg, visa endast om Jag är = Företag)
Företag                   (Text)
Antal anställda           (Rullista)
── Ditt ärende            (Nytt steg)
Meddelande                (Textruta)
```

Med **Rubrik för första steget** = *Om dig* får besökaren stegen *Om dig → Om företaget → Ditt ärende*. En privatperson får *Om dig → Ditt ärende* (Steg 1 av 2).

### Varningar i wp-admin

Formulärets redigeringsskärm säger till när:

- **Flerstegsformulär är på men fältlistan inte ger två steg.** Formuläret visas då som ett vanligt formulär. Det behövs fält både före och efter minst en stegrad.
- **Fältlistan har stegrader men reglaget är av.** Raderna visas då som vanliga rubriker. Det är avsiktligt: stegen kan slås av tillfälligt (t.ex. för ett A/B-test) utan att raderna tas bort.
- **Ett steg styrs av ett fält i samma eller ett senare steg.** Se nedan.

---

## Så delas formuläret i steg

- Ett nytt steg börjar vid varje rad av typen **Nytt steg**. Själva raden blir inget fält, bara rubrik och ingress.
- **Tomma steg tas bort.** En stegrad allra först eller allra sist, eller två stegrader i rad, ger inget tomt steg.
- **Samtyckesrutan, Turnstile, samtyckestexten och Skicka-knappen** visas bara i sista steget.
- Fälttyperna fungerar som vanligt i alla steg, också bekräftelsefält, landsväljare och dolda fält. Ett dolt fält kan ligga i vilket steg som helst och skickas alltid med, så länge steget inte hoppats över.

---

## Villkor på steg

Ett villkor på en stegrad (**Visa endast om … har värdet …**) gäller hela steget. Ett steg vars villkor inte är uppfyllt:

- **hoppas över** av Nästa och Tillbaka;
- **räknas inte** i stegindikatorn ("Steg 1 av 2" i stället för "1 av 3");
- **skickas inte med**: fälten i det kräver inget svar och deras värden sparas inte, inte ens om de fyllts i innan besökaren ändrade sig. Servern kontrollerar samma sak, så ett manipulerat inskick kan inte få med dem.

Ett steg hoppas också över när **alla dess fält är dolda av sina egna villkor**, t.ex. ett steg med bara *Mobil* (visas om *Kontakta mig* = *Ring mig*). Det finns inget kvar att fylla i, så besökaren slipper ett tomt steg.

> **Villkoret ska bero på ett fält i ett tidigare steg.** Ett steg som styrs av ett fält i samma steg, eller ett senare, kan inte avgöras när besökaren kommer dit. wp-admin varnar för det.

Fält inne i ett steg kan som vanligt ha egna villkor. De gäller utöver stegets.

Villkor matchar både det tekniska värdet och etiketten (`foretag` och `Företag`), precis som för fält.

---

## Validering per steg

| Händelse | Vad kontrolleras |
|---|---|
| **Nästa** | Fälten i det aktuella steget, med samma regler som servern: obligatoriskt, e-post, telefon, nummer, datum, URL, länkspärr, bekräftelser. Felen visas vid fälten, fokus flyttas till det första och besökaren stannar i steget. |
| **Tillbaka** | Ingenting. Besökaren ska kunna backa från ett halvfärdigt steg. Det som är ifyllt ligger kvar. |
| **Enter** i ett fält | Före sista steget: samma som Nästa. I sista steget: skickar formuläret. |
| **Skicka** (sista steget) | Hela formuläret, plus samtyckesrutan och Turnstile. |
| **Fel från servern** | Ligger felet i ett tidigare steg byter formuläret till det steget och flyttar fokus till fältet. |

Ett bekräftelsefält vars original ligger i ett senare steg kontrolleras först när besökaren nått originalet.

Fel som bara servern kan hitta dyker upp först vid inskicket. Ett fältfel från ett tilläggsplugin via `relativt_form_errors` får formuläret att hoppa till rätt steg, enligt tabellen. Frekvensspärren och andra fel som inte hör till ett fält visas under formuläret i sista steget, som i ett vanligt formulär.

---

## Knappar och ikoner

Under **Inställningar → Steg**:

- **Text på Nästa-knappen** (standard *Nästa*) och **Text på Tillbaka-knappen** (standard *Tillbaka*).
- **Ikon i Nästa-knappen**: samma ikonbibliotek som skicka-knappen. Standard är samma pil som skicka-knappen har. Ikonen står efter texten.
- **Ikon i Tillbaka-knappen**: standard är samma pil spegelvänd, *Standard (pil vänster)*. Ikonen står före texten. *Pil vänster*, *Vinkel vänster*, *Dubbel vinkel vänster* och *Pil i cirkel, vänster* finns också. *Ingen ikon* ger bara text.

Skicka-knappen behåller sin egen text och ikon (fliken **Texter**).

### Temats knappstil

Nästa-knappen **ärver skicka-knappens temaklasser** (`relativt_form_submit_class`, `_text_class`, `_icon_class`), så på en sajt där skicka-knappen lånar temats utseende ser Nästa likadan ut. Tillbaka ärver inte. Den är en sekundär knapp med eget, diskret utseende.

Styr knapparna för sig med riktningen som tredje argument:

```php
// Tillbaka som temats sekundära knapp, Nästa som skicka-knappen (ärvs redan).
add_filter( 'relativt_form_step_button_class', function ( $classes, $form_id, $dir ) {
	return 'prev' === $dir ? trim( "$classes btn btn--ghost" ) : $classes;
}, 10, 3 );
```

| Filter | Standard | Gör |
|---|---|---|
| `relativt_form_step_button_class` | Nästa: värdet från `relativt_form_submit_class`. Tillbaka: `''` | Klasser på knappen. Får `$classes`, `$form_id`, `$dir` (`'next'`/`'prev'`) |
| `relativt_form_step_button_text_class` | som ovan, från `relativt_form_submit_text_class` | Klasser på knapptexten |
| `relativt_form_step_button_icon_class` | som ovan, från `relativt_form_submit_icon_class` | Klasser på ikonen |
| `relativt_form_step_icon` | ikonen som valts i byggaren | Byt SVG:t, eller `''` för ingen ikon. Får `$svg`, `$form_id`, `$dir`, `$icon_key` |
| `relativt_form_step_counter` | `'Steg {current} av {total}'` | Texten i stegindikatorn, t.ex. `'{current}/{total}'` eller en annan språkversion |

---

## Stegindikatorn

**Stegindikator** under **Inställningar → Steg**:

- **Stapel och text** (standard): *Steg 2 av 4* och en förloppsstapel.
- **Bara text**.
- **Ingen**: inget syns, men texten finns kvar för skärmläsare.

Indikatorn räknar bara de steg besökaren faktiskt går igenom. Totalen kan alltså ändras när ett svar hoppar över eller tar fram ett steg.

---

## Animation mellan stegen

**Animation mellan stegen** under **Inställningar → Steg**:

- **Glid och tona** (standard). Steget glider åt sidan och tonar ut, nästa glider in från andra hållet. Riktningen vänds när besökaren backar.
- **Tona**.
- **Ingen**.

Animationen **stängs av automatiskt** för besökare som valt *minskad rörelse* i sitt operativsystem (`prefers-reduced-motion`). Stegbytet sker då direkt.

### Justera med CSS-variabler

Från sajtens egen CSS (aldrig i pluginfilen):

```css
.relativt-form.relativt-form {
	--xf-step-duration: 300ms;   /* per halva av bytet: först ut, sedan in (220ms) */
	--xf-step-distance: 40px;    /* hur långt steget glider (24px) */
	--xf-step-easing: ease-in-out;
}
```

### Egen CSS-animation

Stegbytet sker i två faser. Det gamla steget får klassen `is-leaving` och JS väntar tills dess animation är klar. Sedan får det nya steget `is-active is-entering`. Roten har `is-going-back` när besökaren backar. Byt animationen genom att peka på egna keyframes:

```css
@keyframes min-ut { to { opacity: 0; transform: scale(0.97); } }
@keyframes min-in { from { opacity: 0; transform: translateY(16px); } }

.relativt-form.xf-multistep .xf-step.is-leaving  { animation: min-ut 200ms ease both; }
.relativt-form.xf-multistep .xf-step.is-entering { animation: min-in 320ms ease both; }
```

JS läser animationens längd från den beräknade stilen, så ingen JS behöver ändras. Utan animation (`animation: none`) byts steget direkt.

### Animation i JS (t.ex. GSAP)

Sätt **Animation mellan stegen** till **Ingen**, så att CSS:en inte animerar samtidigt, och häng på eventen:

```js
// Ut: håll kvar steget tills animationen är klar.
document.addEventListener('relativt-form:step-leave', (e) => {
	const out = gsap.to(e.detail.element, { opacity: 0, x: e.detail.direction === 'next' ? -40 : 40, duration: 0.25 });
	e.detail.waitUntil(out.then());
});

// In: det nya steget är redan synligt här.
document.addEventListener('relativt-form:step', (e) => {
	gsap.fromTo(e.detail.element, { opacity: 0, x: e.detail.direction === 'next' ? 40 : -40 }, { opacity: 1, x: 0, duration: 0.3 });
});
```

`waitUntil()` tar ett promise. Det gamla steget ligger kvar tills det är uppfyllt, och ett promise som misslyckas släpper också igenom bytet, så formuläret kan inte fastna. Kolla `window.matchMedia('(prefers-reduced-motion: reduce)')` om din animation ska respektera minskad rörelse.

### Scroll

Har formulärets överkant scrollat ur bild flyttas sidan tillbaka till formuläret vid stegbytet, så att besökaren inte hamnar mitt i nästa steg. Har sajten en fast meny: `--xf-step-scroll-offset: 100px;` ger luft ovanför.

---

## Spårning: var hoppar besökarna av?

`relativt-form:step` sänds på formulärets rot (bubblar till `document`) varje gång ett nytt steg visas:

```js
document.addEventListener('relativt-form:step', (e) => {
	// e.detail.formId    – formulärets id
	// e.detail.step      – stegets nummer bland de steg besökaren går igenom (1, 2, …)
	// e.detail.total     – antal sådana steg just nu
	// e.detail.title     – stegets rubrik
	// e.detail.direction – 'next' eller 'prev'
	// e.detail.element   – stegets element
	window.dataLayer?.push({
		event: 'form_step',
		form_id: e.detail.formId,
		form_step: e.detail.step,
		form_step_title: e.detail.title,
	});
});
```

Med en GTM-utlösare på `form_step` och `relativt-form:success` (eller tack-sidan) syns tratten steg för steg i GA4. Steg 1 sänder inget event, eftersom det visas när sidan laddas.

`relativt-form:step-leave` sänds innan ett steg lämnas, med samma `detail` plus `to` (nästa stegs element) och `waitUntil()`.

---

## Styla stegen

Variablerna (standardvärden inom parentes):

| Variabel | Gör |
|---|---|
| `--xf-step-duration` (`220ms`) | Animationens längd per halva |
| `--xf-step-distance` (`24px`) | Hur långt steget glider |
| `--xf-step-easing` | Animationens kurva |
| `--xf-step-scroll-offset` (`24px`) | Luft ovanför formuläret när sidan scrollar dit |
| `--xf-progress-height` (`4px`) | Stapelns höjd |
| `--xf-progress-track` (`#e7e5e1`) | Stapelns bakgrund |
| `--xf-progress-fill` (`--xf-accent`) | Stapelns fyllning |
| `--xf-prev-bg` (`transparent`), `--xf-prev-text`, `--xf-prev-border` | Tillbaka-knappen |

Nästa-knappen använder samma variabler som skicka-knappen (`--xf-accent`, `--xf-accent-text`, `--xf-radius`, `--xf-icon-size`).

Klasserna:

| Klass | Element |
|---|---|
| `.xf-multistep` | Formulärets rot, bara på flerstegsformulär |
| `.is-first-step`, `.is-last-step`, `.is-going-back` | Roten, efter läget |
| `.xf-anim-slide`, `.xf-anim-fade`, `.xf-anim-none` | Roten, efter valet i byggaren |
| `.xf-progress`, `.xf-progress-text`, `.xf-progress-bar`, `.xf-progress-fill` | Stegindikatorn |
| `.xf-steps` | Behållaren för alla steg |
| `.xf-step` (`.is-active`, `.is-leaving`, `.is-entering`) | Ett steg |
| `.xf-step-title`, `.xf-step-help` | Stegets rubrik och ingress |
| `.xf-step-btn`, `.xf-next`, `.xf-prev`, `.xf-step-btn-text`, `.xf-step-btn-icon` | Knapparna |

Att bara det aktiva steget syns, och att knapparna följer läget, är **funktionsregler** med `!important`, som villkorsdolda fält. Skriv inte över dem. Allt utseende går att skriva över med vanlig specificitet.

---

## Tillgänglighet

- Varje steg är en grupp (`role="group"`) med stegets rubrik som namn. Steg utan rubrik namnges av räknaren ("Steg 1 av 3").
- Vid stegbyte flyttas fokus till det nya steget, så att skärmläsaren läser upp rubriken. Indikatorns text är en `aria-live`-region.
- Vid fel flyttas fokus till det första felet, som i ett vanligt formulär.
- Nästa och Tillbaka är riktiga knappar (`type="button"`) och fungerar med tangentbord.
- Minskad rörelse respekteras, se [Animation](#animation-mellan-stegen).

---

## Turnstile och samtycke

- **Turnstile** ligger i sista steget och renderas först när besökaren kommer dit, inte i en dold behållare. Token är därmed färsk när formuläret skickas.
- **Samtyckesrutan** och **samtyckestexten** visas i sista steget, intill Skicka.
- Spamskyddet (honungsfälla, tidsspärr, frekvensspärr, länkspärr) är oförändrat. Flera steg ger dessutom mer ifyllnadstid.

---

## Headless

`GET /wp-json/relativt-form/v1/form/{id}` för ett flerstegsformulär har:

- stegraderna bland `fields`, med `"type": "step"` (`label` = rubrik, `help` = ingress, `cond_field`/`cond_value` = stegets villkor);
- en nyckel `multistep`:

```json
"multistep": {
	"first_title": "Om dig",
	"indicator": "bar",
	"animation": "slide",
	"counter": "Steg {current} av {total}",
	"next": "Nästa",
	"prev": "Tillbaka",
	"next_icon": "standard",
	"prev_icon": "standard-left"
}
```

Inskicket är oförändrat: ett `POST /submit` med alla fält. Servern hoppar själv över fälten i steg vars villkor inte är uppfyllt.

För formulär **utan** flerstegsläge saknas nyckeln `multistep` och stegraderna har `"type": "heading"`. En frontend byggd före 1.14.0 ser aldrig typen `step`.

---

## Befintliga formulär

Ett formulär där **Flerstegsformulär** inte slagits på renderas byte för byte som i 1.13.0, och dess headless-definition är oförändrad. Servertesterna bevisar det: `tests/fixtures/render-1.13.0.json` genererades mot 1.13.0-koden innan flerstegsstödet fanns, och `server-test.php` jämför dagens rendering med den. All ny CSS och JS gäller bara formulär med klassen `xf-multistep`.

Ändras markupen avsiktligt i en framtida version genereras ett nytt facit (`php tests/render-snapshots.php > tests/fixtures/render-<version>.json`), och skälet skrivs i CHANGELOG.

---

## Begränsningar

- **Inga delinskick.** Ingenting sparas eller mailas förrän besökaren skickar i sista steget. Den som hoppar av i steg 2 lämnar inget efter sig. (Spåra avhoppen med `relativt-form:step` i stället.)
- **Ingen "fortsätt senare".** Laddas sidan om börjar formuläret om från steg 1. Webbläsaren kan ha behållit ifyllda värden.
- **Webbläsarens bakåtknapp** går till föregående sida, inte till föregående steg.
- **Villkor** bygger som förut på ett styrfält och ett eller flera värden (ELLER). Villkor där flera svar måste stämma samtidigt (OCH) finns inte.
- Ett formulär med steg kräver JavaScript, precis som alla formulär i pluginet (inskicket görs med JS).

---

## Felsökning

| Symptom | Orsak |
|---|---|
| Formuläret visas som vanligt trots stegrader | **Flerstegsformulär** är av, eller fältlistan ger färre än två steg. Se varningen överst på formulärets redigeringsskärm. |
| Ett steg visas aldrig | Stegets villkor är inte uppfyllt, eller alla dess fält är dolda av sina egna villkor. Kontrollera villkoren i fältkartan i sidokolumnen. |
| Ett steg visas trots att det inte borde | Stegets villkor beror på ett fält i samma eller ett senare steg. wp-admin varnar för det. |
| Nästa ser inte ut som skicka-knappen | Skicka-knappens temaklasser kommer från `relativt_form_submit_class`, som Nästa ärver. Har sajten satt klasserna med CSS i stället för filtret, sätt samma CSS på `.xf-next`. |
| Animationen hackar eller dubblas | En egen JS-animation körs samtidigt som CSS-animationen. Sätt **Animation mellan stegen** till **Ingen**. |
| Två steg syns samtidigt | Sajtens CSS skriver över funktionsregeln för `.xf-step`. Ta bort `display` på `.xf-step` ur sajtens CSS. |
