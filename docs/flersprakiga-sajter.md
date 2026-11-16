# Flerspråkiga sajter – how-to

*Relativt Formulär 1.15.0 och senare.*

Pluginet fungerar på flerspråkiga sajter, med eller utan Polylang. Det här dokumentet beskriver modellen, var texterna översätts och vad som inte ingår.

Sajter med ett enda språk behöver inte göra någonting. Där beter sig 1.15.0 som 1.14.0.

---

## Modellen: ett formulär per språk

Formulären är inte översättningsbara inlägg. Varje språk får **sitt eget formulär**, med egen shortcode, och den engelska sidan bäddar in det engelska formuläret.

Så här gör du:

1. Bygg formuläret på originalspråket och se till att det fungerar.
2. **Formulär → hovra över formuläret → Exportera JSON.**
3. **Formulär → Importera** på samma sajt. Kopian skapas som utkast med ett nytt id.
4. Öppna kopian och översätt det som hör till formuläret:
   - etiketter, platshållare, hjälptexter och valens etiketter
   - stegrubriker och stegens ingresser
   - fliken **Texter**: knapptext, knapptext under skick, tackrubrik, tacktext, felmeddelande, samtyckestext och **tack-sida (URL)**
   - knapptexterna för Nästa och Tillbaka, om formuläret har steg
5. Publicera kopian och lägg dess shortcode på sidan i rätt språk.

**Låt fältnycklarna vara som de är.** Nycklarna (`namn`, `epost`) och valens tekniska värden (`foretag : Företag` → `foretag : Company`) styr villkor, mottagarregler och statistik. Har båda formulären samma nycklar går inskicken att jämföra, och CSV-exporten får samma kolumner.

**Kontrollera mottagarna.** Mottagare, avsändare och ämnesrad följer med i kopian. Ska de engelska inskicken till någon annan ändrar du det i det engelska formuläret.

### Ange tack-sida per formulär

Använder sajten en tack-sida i stället för tack-rutan: fyll i **Tack-sida (URL)** i *varje* formulär, med sidan på formulärets språk (`/tack/` i det svenska, `/en/thank-you/` i det engelska).

Formulärets eget värde vinner alltid över sajtens standardvärde. Det är det säkraste sättet att vara säker på att en engelsk besökare inte hamnar på en svensk tack-sida, och det fungerar oavsett vilket flerspråkstillägg sajten använder.

Tänk också på konverteringsspårningen: två tack-sidor betyder två mål i GA4 eller Google Ads, eller ett mål som matchar båda adresserna.

---

## Vad som inte ligger i formuläret

Några texter som besökaren ser hör inte till något enskilt formulär:

| Text | Exempel | Var den kommer ifrån |
|---|---|---|
| Felmeddelanden | *Fyll i detta fält.* | Motorn (`messages()`) |
| Fel från spärrarna | *Du har skickat flera meddelanden på kort tid …* | Motorn, via REST-svaret |
| Stegräknaren | *Steg 2 av 4* | Motorn |
| Fallbacks | *Skicka*, *Skickar…*, *Tack!*, *Nästa*, *Tillbaka* | Motorn, när formuläret lämnar fältet tomt |
| Sajtens standardvärden | Tackrubrik, tacktext, tack-sida, samtyckestext … | **Formulär → Standardvärden** |

De översätts på ett av två sätt: i Polylang, eller med ett filter.

---

## Med Polylang

### Var texterna översätts

**Språk → Översättningar** (*Languages → Translations*, i äldre versioner *Strings translations*). Filtrera på gruppen **Relativt Formulär**.

Där finns:

- alla felmeddelanden, med namn som *Meddelande: required*
- **Stegräknare** – mallen `Steg {current} av {total}`. Låt `{current}` och `{total}` stå kvar i översättningen: `Step {current} of {total}`
- fallbacks: *Knapptext*, *Knapptext under skick*, *Tackrubrik*, *Felmeddelande*, *Knapp: nästa steg*, *Knapp: föregående steg* och de två felen *okänt formulär* och *ogiltig begäran*
- sajtens standardvärden som är besökartext, med namn som *Standardvärde: tacktext* – men bara de som har ett värde under **Formulär → Standardvärden**

Skriv översättningen, spara, klart. En text utan översättning visas på originalspråket.

Texterna dyker upp i listan först när någon har besökt wp-admin efter uppdateringen – Polylang tar bara emot registreringar där.

### Vad som översätts och vad som inte gör det

- **Formulärets egna texter översätts aldrig av Polylang.** Står det *Skicka* i det engelska formulärets knapptext visas *Skicka*, även om *Skicka* har en översättning i listan. Översättningarna gäller bara det formuläret *inte* anger. Det är avsiktligt: formulärets egna texter skrivs på formulärets språk.
- **Standardvärden** översätts. Vill ni ha en gemensam tacktext för alla formulär och översätta den på ett ställe: lämna fältet tomt i formulären, fyll i standardvärdet och översätt det i Polylang.
- **Tack-sidans URL** i standardvärdena går också att översätta (*Standardvärde: tack-sida (URL)*): skriv den engelska sidans adress som översättning. Se ändå rådet ovan om att ange den per formulär.
- **Mottagare, avsändare och ämnesrad** är inte översättningsbara. De hör till notismailet, som går till mottagaren och inte till besökaren.

### Ändra en standardtext

Pluginet har en `wpml-config.xml` som talar om för Polylang vilka standardvärden som är text. Det gör att Polylang flyttar med översättningen när originaltexten ändras under **Formulär → Standardvärden**, i stället för att den nya texten börjar om som oöversatt.

Två saker att känna till:

- Ställ språkväljaren i adminfältet på **Visa alla språk** när du redigerar standardvärdena. Står den på ett annat språk än originalets visar Polylang de översatta värdena i fälten.
- Efter en större omskrivning: titta i **Språk → Översättningar** att översättningarna fortfarande stämmer med de nya originalen.

### Så följer språket med till servern

Det här behöver du inte göra något åt, men det förklarar vad som händer.

En sida vet vilket språk den är på. Ett REST-anrop gör det inte: `/wp-json/…/submit` är samma adress för alla språk. Före 1.15.0 kom därför fel från servern – frekvensspärren, valideringen, tacktexterna – på sajtens standardspråk även på en engelsk sida.

Nu skriver pluginet sidans språk i skriptets konfiguration (`relativtFormConfig.lang`), och skriptet skickar det med både `/token` och `/submit`. Servern kontrollerar att värdet är ett av sajtens språk, växlar till det, tar fram texterna och växlar tillbaka. Ett okänt värde ignoreras, och anropet beter sig då som förut.

Innan inskicket sparas och notismailet skickas växlar WordPress tillbaka till sajtens eget språk. Mailet går till mottagaren, och dess fasta texter översätts inte.

### Krav

Polylang 3.3 eller senare rekommenderas. Språkstödet är läst mot Polylangs källkod och provkört mot Polylang 3.9.2 på WordPress 6.8. Växlingen i REST-anropen är skriven så att den också fungerar med äldre versioner, som inte själva läser språket ur anropet – det fallet är provkört genom att stänga av Polylangs egen hantering, inte mot en gammal version.

---

## Utan Polylang: filter

Sajter med ett annat flerspråkstillägg, eller en egen lösning, översätter med filter. Filtret `relativt_form_messages` får sedan 1.15.0 språket och formulärets id:

```php
add_filter( 'relativt_form_messages', function ( array $messages, string $lang, int $form_id ): array {
	// Utan Polylang är $lang en WordPress-locale: sv_SE, en_GB, de_DE …
	if ( 0 !== strpos( $lang, 'en' ) ) {
		return $messages;
	}

	return array_merge( $messages, [
		'required' => 'This field is required.',
		'email'    => 'Please check the email address.',
		'tel'      => 'Please enter a valid phone number.',
		'consent'  => 'You need to accept the terms.',
		'toofast'  => 'That was a little too fast. Wait a second and try again.',
		'rate'     => 'You have sent several messages in a short time. Please wait a while and try again.',
		'nonce'    => 'The session has expired. Reload the page and try again.',
		'generic'  => 'Something went wrong. Please try again in a moment.',
	] );
}, 10, 3 );
```

Glöm inte `10, 3` sist – utan det skickar WordPress bara det första argumentet.

Alla nycklar: `required`, `email`, `tel`, `phone_country`, `number`, `date`, `url`, `confirm`, `choice`, `links`, `consent`, `toofast`, `nonce`, `rate`, `generic`, `turnstile`. Okända nycklar och tomma texter ignoreras.

- **`$lang`** är språket som gäller när texterna tas fram. På en sida är det sidans språk. I ett REST-anrop är det språket som skriptet skickade med. Med Polylang är det språkkoden (`sv`, `en`), annars en locale (`sv_SE`, `en_GB`).
- **`$form_id`** är formulärets id när texterna gäller ett visst formulär (serverns svar och headless-definitionen), och `0` för texterna som skriptet på sidan använder – de delas av alla formulär på sidan. Vill ni ha olika texter per formulär når ni alltså serverns fel, men inte webbläsarens validering.

**Befintliga filter fungerar oförändrade.** Ett filter som bara tar emot listan får fortfarande bara listan:

```php
add_filter( 'relativt_form_messages', fn( $m ) => array_merge( $m, [ 'required' => 'Obligatoriskt.' ] ) );
```

Stegräknaren har ett eget filter. Det får inte språket som argument, så fråga WordPress med `determine_locale()`:

```php
add_filter( 'relativt_form_step_counter', function ( string $template, int $form_id ): string {
	return 0 === strpos( determine_locale(), 'en' ) ? 'Step {current} of {total}' : $template;
}, 10, 2 );
```

Under ett REST-anrop har pluginet redan växlat WordPress språk till det skriptet skickade med, så `determine_locale()`, `get_locale()` och `__()` svarar på rätt språk även där. Det förutsätter att språket är installerat i WordPress (**Inställningar → Allmänt → Webbplatsens språk** listar de installerade).

Fallbacks som *Skicka* och *Tack!* har inget filter. Fyll i texterna i formuläret i stället – med ett formulär per språk är det ändå där de hör hemma.

### Ordningen när båda finns

Originaltexten → Polylangs översättning → filtret. **Filtret körs sist och vinner alltid.** Det gäller både `relativt_form_messages` och `relativt_form_step_counter`. Filtret får Polylangs översättning som utgångsvärde, så det går att ändra en enda text och låta resten komma från Polylang.

---

## Headless

`GET /form/<id>` tar emot `?lang=`:

```
GET /wp-json/relativt-form/v1/form/123?lang=en
```

Då kommer `messages`, fallbacks i `texts` och stegräknarens mall på det språket. Skicka samma värde som `lang` i `/token` och i `/submit`-kroppen, så kommer serverns fel också rätt:

```js
await fetch(`${rest}submit`, {
	method: 'POST',
	headers: { 'Content-Type': 'application/json' },
	body: JSON.stringify({ form: 123, lang: 'en', fields, nonce, ts, sig, xf_website: '' }),
});
```

Utan `lang` är svaren som före 1.15.0. Tänk på att cacha definitionen per språk – adressen skiljer sig redan åt genom parametern.

---

## Begränsningar

- **Landsnamnen i telefonfältets landsväljare** är på svenska. Byt dem med filtret `relativt_form_phone_countries`.
- **Notismailets fasta texter** (rubriker som *Sida*, *Kanal*) är på svenska. Mailet går till mottagaren.
- **wp-admin** är på svenska.
- **Formulären är inte översättningsbara inlägg i Polylang.** Det finns ingen koppling mellan det svenska och det engelska formuläret; de är två formulär.
- **En cachad sida från före uppdateringen** har ingen språkuppgift i konfigurationen. Den fungerar som förut – med fel på standardspråket – tills sidcachen töms. Töm cachen efter uppdateringen på flerspråkiga sajter.

## Felsökning

**Felen kommer på svenska på den engelska sidan.**
Öppna sidans källkod och sök efter `relativtFormConfig`. Står det `"lang":"en"`? Om inte: töm sidcachen. Om ja: öppna webbläsarens nätverksflik, skicka formuläret och titta på anropet till `/submit` – `lang` ska finnas i kroppen. Finns den där är texten antagligen inte översatt: kontrollera **Språk → Översättningar**.

**Texterna finns inte i Polylangs lista.**
Ladda om en sida i wp-admin och titta igen. Standardvärden syns bara om de har ett värde.

**Knappen står på svenska fast *Skicka* är översatt.**
Formuläret har en egen knapptext. Ändra den i formuläret, eller töm fältet så används den översatta fallbacken.
