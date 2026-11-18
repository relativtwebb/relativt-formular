# Dynamiska alternativ och sidkontext – how-to

*Relativt Formulär 1.16.0 och senare.*

Två saker som hänger ihop:

- **Sidkontext.** Formuläret vet vilken sida det ligger på. Post-ID och sidtitel följer med inskicket, och sajtens kod kan läsa dem.
- **Dynamiska alternativ.** Sajtens kod fyller alternativen i en rullista, radioknappar, val-knappar eller flerval, och kan spärra enskilda alternativ. Ett spärrat alternativ syns men går inte att välja. Det typiska fallet är kurstillfällen ur ett ACF-fält på kurssidan, där fullbokade tillfällen ska synas men inte gå att välja.

Båda är **av som standard**. Ett formulär som inte slås på, på en sajt som inte använder filtret, beter sig exakt som i 1.15.0. Ett regressionstest bevakar det: markup, svar, mejl, sparade inskick och CSV-rader jämförs mot 1.15.0.

---

## Kom igång

1. Öppna formuläret och slå på **Inställningar → Lagring → Koppla inskicket till sidan**.
2. Lägg formulärets shortcode på sidorna, t.ex. i en Oxygen-mall för posttypen `kurs`. Samma formulär kan ligga på alla sidor.
3. Lägg filtret `relativt_form_field_choices` i sajtens eget plugin (se [Komplett exempel](#komplett-exempel)).
4. **Töm sidcachen.** Sidor som cachades innan inställningen slogs på saknar sidkontexten och skickar in med post-ID 0.

Alternativen i fältets textruta i byggaren används bara om filtret lämnar dem orörda. För ett fält som alltid fylls av koden kan textrutan lämnas tom, eller innehålla en rad som förklarar för redaktören var alternativen kommer ifrån.

---

## Sidkontexten

### Vilken sida räknas

- Formuläret ritas ut på en **enskild post** (`is_singular()`): post-ID:t är `get_queried_object_id()`, det vill säga sidans post och inte en post i en loop på sidan.
- Posten måste vara **publik** (`is_post_publicly_viewable()`). Utkast, privata och borttagna poster ger 0.
- På ett arkiv, i en widget, i en förhandsvisning av ett utkast och i headless-läget är post-ID:t **0**.
- Är **Koppla inskicket till sidan** av är post-ID:t alltid 0.

### Hur det följer med inskicket

Formuläret får ett dolt fält, `_xf_post`, med värdet `82:<signatur>`. Signaturen är en HMAC över formulärets id och post-ID:t, med sajtens salt.

- Signaturen är **densamma för alla besökare** på sidan, så den tål sidcache.
- Ändras post-ID:t i inskicket stämmer inte signaturen, och post-ID:t blir **0**. Det blir aldrig ett fel.
- Fältet ligger utanför stegen i ett flerstegsformulär och följer därför alltid med.
- Det ligger bland de vanliga fälten i stället för i en egen nyckel. Därför skickar även JS:en från 1.15.0 med det, om en besökare har det gamla skriptet i cachen.
- Fältnyckeln `_xf_post` är reserverad. Ge inget eget fält den nyckeln.

Vid inskicket kontrollerar servern signaturen och att posten fortfarande är publik. Avpubliceras en kurs efter att sidan cachades blir post-ID:t 0.

**Vad signaturen inte gör.** Den visar att formuläret har ritats ut på posten någon gång, inte att det fortfarande står där. Den går aldrig ut. Byts sajtens salter (`wp_salt()`) blir alla cachade signaturer ogiltiga, och inskicken från cachade sidor får post-ID 0 tills cachen byggs om. Det som faktiskt skyddar mot manipulerade val är servervalideringen mot listan för just den posten (se nedan).

### Var post-ID:t syns

Med inställningen på och en post att peka på:

| Var | Vad |
|---|---|
| `$body['post_id']` i `relativt_form_errors` och `relativt_form_values` | Post-ID:t, kontrollerat. `0` om sidan är okänd |
| `$meta['post_id']`, `$meta['post_title']` i `relativt_form_mail_body` och `relativt_form_submitted` | Bara när post-ID:t inte är 0. Titeln läses på servern, aldrig ur inskicket |
| Notismejlet | *Sidtitel* och *Post-ID* direkt efter *Skickat från* |
| Det sparade inskicket i wp-admin | Samma rader under Metadata |
| CSV-exporten | Kolumnerna *Sidtitel* och *Post-ID* sist, bara om något av inskicken i exporten har dem |
| Integritetsexporten | Sidtitel och Post-ID |

Med inställningen av får `$body` ingen `post_id` och `$meta` inga nya nycklar. Läs därför med en fallback: `$body['post_id'] ?? 0`.

---

## Filtret `relativt_form_field_choices`

```php
add_filter( 'relativt_form_field_choices', function ( array $list, array $field, int $form_id, array $context ) {
	// …
	return $list;
}, 10, 4 );
```

Körs för varje fält av typen Rullista, Radioknappar, Val-knappar och Flerval.

| Argument | Innehåll |
|---|---|
| `$list` | Fältets alternativ i ordning, ur textrutan i byggaren: `[ [ 'value' => 'a', 'label' => 'A', 'disabled' => false ], … ]` |
| `$field` | Fältet: `key`, `type`, `label`, `required`, `default` med flera |
| `$form_id` | Formulärets id |
| `$context` | `[ 'post_id' => int, 'phase' => string ]` |

**Returnera** en lista i samma form. `label` kan utelämnas (då används värdet) och `disabled` kan utelämnas (då är alternativet öppet). Formen `[ 'värde' => 'Etikett' ]` fungerar också. Tomma värden hoppas över. Finns ett värde två gånger gäller det första. Returnerar filtret något annat än en lista blir listan tom.

Returnerar filtret listan oförändrad rör motorn inte fältet alls.

### Faserna

Filtret körs på tre ställen, med samma lista som svar. `phase` talar om var, men **listan får inte bero på fasen**. Det som visas och det som godkänns ska komma från samma källa.

| Fas | När | `post_id` |
|---|---|---|
| `render` | När formuläret ritas ut med shortcoden | Sidans post, eller 0 |
| `validate` | När ett inskick tas emot (REST-anropet `/submit`) | Post-ID:t ur inskicket, kontrollerat, eller 0 |
| `definition` | När `GET /wp-json/relativt-form/v1/form/{id}` svarar (headless) | **Alltid 0** |

**`definition`** gäller bara headless-frontends som hämtar formulärdefinitionen som JSON. Shortcode-formulär använder den aldrig. Svaret är publikt och kan cachas av vem som helst, och det vet inte vilken sida frontenden visar. Returnera därför det som gäller utan sida:
- för ett fält vars alternativ beror på sidan: en tom lista, eller textrutans lista oförändrad
- för ett fält vars alternativ inte beror på sidan (t.ex. kommande webbinarier för hela sajten): samma lista som i de andra faserna

Lägg aldrig något i listan som inte får vara publikt.

**Använd `$context['post_id']`, aldrig `get_the_ID()` eller `global $post`.** Vid inskicket finns ingen global post. Där skulle `get_the_ID()` ge fel eller ingenting, och listan vid validering skulle inte stämma med listan som visades.

### Spärrade alternativ

`'disabled' => true` gör att alternativet syns men inte går att välja:

| Fälttyp | Markup |
|---|---|
| Rullista | `<option value="…" class="is-disabled" disabled>` |
| Radioknappar, Val-knappar | `disabled` på `<input>`, klassen `is-disabled` på etiketten (`label.xf-choice-label`) |
| Flerval | `disabled` på `<input>`, klassen `is-disabled` på `label.xf-check` |

Pluginets CSS gör spärrade alternativ blekare (`--xf-disabled-opacity`, standard `0.45`) och ger dem muspekaren *not-allowed*. Egen stil:

```css
.relativt-form .xf-buttons .xf-choice-label.is-disabled {
	opacity: 1;
	text-decoration: line-through;
}
```

Ett spärrat alternativ förväljs aldrig: varken av standardvärdet i byggaren, ett shortcode-attribut eller en URL-parameter (`?tillfalle=…`).

### Valideringen

Vid inskicket körs filtret igen, för posten inskicket kom från och med listan som den ser ut **nu**:

| Inskickat värde | Svar |
|---|---|
| Finns i listan och är öppet | Godkänt. Etiketten sparas och mailas, det tekniska värdet följer med som `raw` |
| Finns inte i listan (passerat, annan kurs, påhittat) | Felet `choice`: *Ogiltigt val.* |
| Finns men är spärrat (fullbokat sedan sidan cachades) | Felet `choice_disabled`: *Alternativet går inte längre att välja.* |
| Tomt i ett obligatoriskt fält | Felet `required`, även när listan är tom |
| Flerval: okända värden | Rensas bort utan fel, som förut |
| Flerval: ett spärrat värde | `choice_disabled` |

Felen visas vid fältet. I ett flerstegsformulär byter formuläret till steget där fältet ligger.

`choice_disabled` går att byta med `relativt_form_messages` och översätta i Polylang (gruppen *Relativt Formulär*, *Meddelande: choice_disabled*). Texten skickas inte med i skriptets konfiguration, eftersom bara servern använder den.

Villkor (*Visa endast om*) och mottagarregler som pekar på ett fält med dynamiska alternativ fungerar mot de filtrerade värdena och etiketterna.

### Cachade sidor

En cachad sida visar listan som den såg ut när sidan cachades. **Det är valideringen som avgör.** Har ett tillfälle blivit fullbokat sedan dess får besökaren `choice_disabled` vid fältet och kan välja ett annat.

Töm cachen för sidan när underlaget ändras, så att besökarna slipper se inaktuella listor (se exemplet nedan). Någon uppdatering av listan via JS görs inte.

---

## Komplett exempel

Kursanmälan. Ett formulär (id 123) ligger på alla kurssidor (posttypen `kurs`). Tillfällena kommer ur ACF-repeatern `kurs_tillfallen` på kursen:

| Underfält | Typ | Används till |
|---|---|---|
| `datum_fran` | Datumväljare, returformat `Ymd` | Start, obligatorisk |
| `datum_till` | Datumväljare, returformat `Ymd`, tom = endagskurs | Slut |
| `plats_typ` | `webbaserad` / `plats` | Etikett: Distans / På plats |
| `fullbokad` | Sant/falskt | Spärrar alternativet |
| `fatal_platser` | Sant/falskt | Tillägg i etiketten |

I formuläret: fältet **Tillfälle**, nyckel `tillfalle`, typ Rullista, obligatoriskt, med platshållaren *Välj tillfälle*. **Koppla inskicket till sidan** är på.

Koden ligger i sajtens eget plugin, inte i temat:

```php
<?php
/**
 * Plugin Name: Kursanmälan
 */

const KURS_FORM_ID = 123;

/**
 * Tillfällena i fältet tillfalle: kommande tillfällen på kursen formuläret
 * står på. Samma lista när formuläret ritas ut och när anmälan valideras.
 */
add_filter( 'relativt_form_field_choices', function ( $list, $field, $form_id, $context ) {
	if ( KURS_FORM_ID !== (int) $form_id || 'tillfalle' !== $field['key'] ) {
		return $list;
	}

	// Kontextens post, aldrig get_the_ID(): vid inskicket finns ingen global post.
	$post_id = (int) ( $context['post_id'] ?? 0 );

	// Ingen kurs (post-ID 0, headless-definitionen eller fel posttyp): inga tillfällen.
	if ( $post_id <= 0 || 'kurs' !== get_post_type( $post_id ) ) {
		return [];
	}

	return kursanmalan_tillfallen( $post_id );
}, 10, 4 );

/**
 * @return array<int,array{value:string,label:string,disabled:bool}>
 */
function kursanmalan_tillfallen( int $post_id ): array {
	$rows = get_field( 'kurs_tillfallen', $post_id );
	if ( ! is_array( $rows ) ) {
		return [];
	}

	// Tillfällen som börjat före i dag tas inte med. På startdagen går det fortfarande att anmäla sig.
	$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Ymd' );
	$out   = [];

	foreach ( $rows as $row ) {
		$from = (string) ( $row['datum_fran'] ?? '' );
		$to   = (string) ( $row['datum_till'] ?? '' );
		if ( ! preg_match( '/^\d{8}$/', $from ) || $from < $today ) {
			continue;
		}

		$type  = 'webbaserad' === ( $row['plats_typ'] ?? '' ) ? 'webbaserad' : 'plats';
		$label = kursanmalan_datum( $from, $to ) . ' · ' . ( 'webbaserad' === $type ? 'Distans' : 'På plats' );
		$full  = ! empty( $row['fullbokad'] );

		if ( $full ) {
			$label .= ' (Fullbokat)';
		} elseif ( ! empty( $row['fatal_platser'] ) ) {
			$label .= ' (Fåtal platser kvar)';
		}

		$out[] = [
			// Två tillfällen kan börja samma dag med olika platstyp.
			'value'    => $from . '_' . $type,
			'label'    => $label,
			'disabled' => $full,
		];
	}

	usort( $out, fn( $a, $b ) => strcmp( $a['value'], $b['value'] ) );
	return $out;
}

/** "1 mars 2027", "2–3 februari 2027", "30 januari–2 februari 2027", "30 december 2026–2 januari 2027". */
function kursanmalan_datum( string $from, string $to ): string {
	$tz    = wp_timezone();
	$start = DateTimeImmutable::createFromFormat( '!Ymd', $from, $tz );
	$end   = preg_match( '/^\d{8}$/', $to ) ? DateTimeImmutable::createFromFormat( '!Ymd', $to, $tz ) : null;

	if ( ! $start ) {
		return $from;
	}
	if ( ! $end || $end <= $start ) {
		return wp_date( 'j F Y', $start->getTimestamp(), $tz );
	}
	if ( $start->format( 'Ym' ) === $end->format( 'Ym' ) ) {
		return $start->format( 'j' ) . '–' . wp_date( 'j F Y', $end->getTimestamp(), $tz );
	}
	if ( $start->format( 'Y' ) === $end->format( 'Y' ) ) {
		return wp_date( 'j F', $start->getTimestamp(), $tz ) . '–' . wp_date( 'j F Y', $end->getTimestamp(), $tz );
	}
	return wp_date( 'j F Y', $start->getTimestamp(), $tz ) . '–' . wp_date( 'j F Y', $end->getTimestamp(), $tz );
}

/**
 * Töm LiteSpeed-cachen för kursen när den sparas, så att besökarna ser
 * aktuella tillfällen. Prioritet 20: efter att ACF sparat sina fält.
 * Valideringen avgör ändå – det här gör bara listan aktuell snabbare.
 */
add_action( 'acf/save_post', function ( $post_id ) {
	if ( ! is_numeric( $post_id ) || 'kurs' !== get_post_type( (int) $post_id ) ) {
		return;
	}
	do_action( 'litespeed_purge_post', (int) $post_id );
}, 20 );
```

Månadsnamnen kommer från `wp_date()` och följer sajtens språk.

### Stoppa anmälningar efter starttiden

För ett event-formulär där anmälan ska stänga när eventet börjar (ACF-fältet `event_start`, returformat `Y-m-d H:i:s`):

```php
const EVENT_FORM_ID = 456;

add_filter( 'relativt_form_errors', function ( $errors, $form_id, $body ) {
	if ( EVENT_FORM_ID !== (int) $form_id ) {
		return $errors;
	}

	$post_id = (int) ( $body['post_id'] ?? 0 );
	if ( $post_id <= 0 || 'event' !== get_post_type( $post_id ) ) {
		// En nyckel som inte är ett fält visas under formuläret.
		$errors['anmalan'] = 'Anmälan kunde inte kopplas till ett event. Ladda om sidan och försök igen.';
		return $errors;
	}

	$tz    = wp_timezone();
	$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) get_field( 'event_start', $post_id ), $tz );
	if ( $start && $start <= new DateTimeImmutable( 'now', $tz ) ) {
		$errors['anmalan'] = 'Anmälan har stängt – eventet har redan börjat.';
	}

	return $errors;
}, 10, 3 );
```

Kräver **Koppla inskicket till sidan** på event-formuläret.

---

## Headless

`GET /wp-json/relativt-form/v1/form/{id}` kör filtret i fasen `definition` med `post_id` 0. Ett fält med spärrade alternativ får nyckeln `disabled_choices` (en lista med de tekniska värdena). Fält utan spärrade alternativ har ingen sådan nyckel.

Sidkontext för headless-frontends ingår inte: endpointen delar inte ut någon signatur, så ett headless-inskick har alltid post-ID 0. Annars hade vem som helst kunnat hämta en giltig signatur för vilken publik post som helst.

---

## Flytta ett formulär mellan miljöer

**Koppla inskicket till sidan** följer med i JSON-exporten när den är på. Formulärets id ändras vid import, så konstanter som `KURS_FORM_ID` i sajtens plugin måste uppdateras. Signaturerna bygger på sajtens salter: en ny miljö har andra salter, och sidor som cachats i en miljö fungerar inte i en annan. Töm sidcachen efter flytten.

---

## Begränsningar

- Statistiken räknar ett dynamiskt fält på den sparade etiketten, eftersom värdet inte finns i fältets textruta. Ändras etiketten, t.ex. när "(Fåtal platser kvar)" kommer och går, räknas samma tillfälle som olika svar.
- Ett värde som innehåller `", "` (komma följt av mellanslag) fungerar inte i Flerval, i villkor eller i statistiken. Använd tekniska värden utan kommatecken.
- Med JS från 1.15.0 i webbläsarcachen kan en URL-parameter förvälja ett spärrat alternativ. Servern avvisar det då med `choice_disabled`.
- Pluginet räknar inga anmälningar och vet inget om platser. Fullbokat är något sajtens kod bestämmer.
