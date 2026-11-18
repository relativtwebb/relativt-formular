# Webhook – how-to

*Relativt Formulär 1.17.0 och senare.*

Ett formulär kan skicka varje inskick som JSON till en adress, t.ex. en Zapier-zap som för vidare till ett CRM. Webhooken är **av som standard**. Ett formulär som inte slår på den är oförändrat.

Mejlnotisen påverkas inte. Den går som förut, före webhooken, och fungerar som reserv om webhooken eller tjänsten bakom den inte svarar.

---

## Kom igång

1. Öppna formuläret och gå till fliken **Webhook**.
2. Slå på **Skicka inskick till en webhook** och fyll i **Adress**. Bara `https://`.
3. Valfritt: ett **eget huvud** med en delad hemlighet, t.ex. `X-Webhook-Secret` och ett långt slumpat värde, som mottagaren kontrollerar.
4. **Uppdatera** formuläret.
5. Klicka **Skicka testinskick**. Exempeldata med formulärets riktiga fältnycklar skickas till den sparade adressen, med nyckeln `"test": true`. Det räcker för att sätta upp zappen innan sajten är i drift. Resultatet (svarskoden) visas överst på sidan.

**Sparar formuläret inskick** (Inställningar → Lagring, på som standard) får du status, omförsök och knappen *Skicka om*. Utan lagring görs ett enda försök, se [Utan lagring](#utan-lagring).

---

## Vad som skickas

`POST` med `Content-Type: application/json; charset=utf-8`:

```json
{
  "form_id": 123,
  "form_title": "Kursanmälan",
  "submission_id": 4567,
  "submitted_at": "2026-11-18T09:31:12+01:00",
  "fields": {
    "namn": "Anna Andersson",
    "epost": "anna@exempel.se",
    "telefon": "070-123 45 67",
    "tillfalle": "20270202_plats",
    "tillval": ["lunch", "middag"],
    "nyhetsbrev": "1"
  },
  "fields_display": {
    "namn": "Anna Andersson",
    "epost": "anna@exempel.se",
    "telefon": "0701234567",
    "tillfalle": "2–3 februari 2027 · På plats",
    "tillval": "Lunch, Middag",
    "nyhetsbrev": "Ja"
  },
  "meta": {
    "page": "https://exempel.se/kurser/test-1/",
    "post_id": 82,
    "post_title": "Test 1",
    "landing": "https://exempel.se/kurser/test-1/?utm_source=linkedin",
    "referrer": "https://www.linkedin.com/",
    "consent": "",
    "utm": { "utm_source": "linkedin", "utm_medium": "paid" }
  }
}
```

| Nyckel | Innehåll |
|---|---|
| `form_id`, `form_title` | Formuläret |
| `submission_id` | Det sparade inskickets id, `null` om formuläret inte sparar inskick |
| `submitted_at` | ISO 8601 med sajtens tidszon |
| `fields` | Fältnyckel → tekniskt värde som besökaren skickade det. Flerval är en lista, en kryssruta `"1"` eller `""` |
| `fields_display` | Fältnyckel → visningsvärdet, som i mejlet: etiketten, normaliserat telefonnummer, *Ja*/*Nej* |
| `meta.page` | Sidan formuläret skickades från, utan query string |
| `meta.post_id`, `meta.post_title` | Sidans post (1.16.0, *Koppla inskicket till sidan*). `0` och `""` annars |
| `meta.landing`, `meta.referrer`, `meta.utm` | Kampanjdata, enligt samtyckesreglerna för kampanjkakan. `utm` är alltid ett objekt, ibland tomt |
| `meta.consent` | Samtyckesrutans post, om formuläret har en |
| `meta.ip` | Bara om formuläret sparar IP-adressen |

Fält som är dolda av sitt villkor, fält i överhoppade steg och bekräftelsefält tas inte med, precis som i mejlet. Rader som `relativt_form_values` lagt till kommer med.

---

## När och hur

Ordningen är **spara → mejl → webhook**. Ett fel i webhooken stoppar aldrig mejlet och ger aldrig besökaren ett fel. Besökaren får sitt tack innan webhooken anropas.

1. **Första försöket** görs efter att svaret skickats till besökaren:
   - På PHP-FPM och LiteSpeed (de flesta webbhotell) avslutar pluginet svaret med `fastcgi_finish_request()` eller `litespeed_finish_request()` och skickar sedan, i samma anrop.
   - Saknas båda och inskicket är sparat, görs försöket i stället av **WP-Cron**, vid nästa sidvisning på sajten.
2. **Timeout** 10 sekunder. Omdirigeringar följs inte.
3. **Lyckat** = svarskod 2xx. Allt annat, och nätverksfel, är ett misslyckande.
4. **Omförsök:** ett, efter fem minuter, via WP-Cron.
5. **Status** sparas på inskicket: *Skickad*, *Väntar* eller *Misslyckades*, tillsammans med tidpunkt, svarskod, antal försök och felet. Statusen syns i inskickslistan, under Mail, och i inskicket.
6. **Skicka om:** knappen i inskicket skickar exakt samma data igen, direkt. Det fungerar även för ett inskick som redan gått fram (*Skicka igen*).

Varje försök skickar exakt samma JSON-text. Den sparas på inskicket när det skickas in.

Varken lagring eller mejl lyckas? Då får besökaren ett fel och skickar troligen igen. Ingen webhook skickas, så att mottagaren inte får dubbletter.

### Utan lagring

Sparar formuläret inte inskick görs **ett** försök, efter svaret. Ett misslyckande skrivs till PHP:s fellogg:

```
Relativt Formulär: webhooken för formulär 123 misslyckades (Svarskod 500.). Inskicket sparas inte, så inget omförsök görs.
```

Inget omförsök görs. Annars skulle inskickets personuppgifter behöva ligga i WP-Cron-listan i `wp_options`, där ingen gallring når dem. Mejlet är reserven.

Har servern varken `fastcgi_finish_request()` eller `litespeed_finish_request()` (Apache med mod_php, ovanligt i dag) görs försöket i slutet av anropet. Då väntar besökaren på det, i högst 10 sekunder. Med lagring på går det i stället via WP-Cron.

### WP-Cron behöver trafik – eller ett riktigt cron-jobb

WP-Cron körs när någon besöker sajten. Omförsöket, och första försöket på servrar utan finish-funktion, kan därför dröja tills nästa besök. Har sajten `define( 'DISABLE_WP_CRON', true );` i `wp-config.php` körs WP-Cron **aldrig**, utan ett cron-jobb på servern. Då blir inskicken stående på *Väntar*.

Ett riktigt cron-jobb, var femte minut:

```
*/5 * * * * curl -s https://exempel.se/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

eller med WP-CLI:

```
*/5 * * * * cd /sökväg/till/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

---

## Filter

### `relativt_form_webhook_payload`

Ändra eller lägg till nycklar. Körs en gång per inskick, och för testinskicket.

```php
add_filter( 'relativt_form_webhook_payload', function ( array $data, int $form_id, array $values, array $meta ) {
	// …
	return $data; // eller null för att inte skicka något för det här inskicket
}, 10, 4 );
```

I filtret är `fields`, `fields_display` och `meta['utm']` vanliga arrayer: `$data['fields']['tillfalle']`. De blir JSON-objekt efteråt.

### `relativt_form_webhook_args`

Argumenten till `wp_safe_remote_post()`: headers, timeout m.m. Timeouten hålls inom 1–10 sekunder. `body` är den färdiga JSON-texten; ändra datan i filtret ovan, inte här.

```php
add_filter( 'relativt_form_webhook_args', function ( array $args, int $form_id, array $data ) {
	$args['headers']['X-Sajt'] = 'exempel.se';
	return $args;
}, 10, 3 );
```

---

## Komplett exempel: kursanmälan till Zapier

Kursformuläret (id 123) har fältet `tillfalle` med värden som `20270202_plats` (startdatum och platstyp, se [dynamiska alternativ](dynamiska-alternativ.md)). Zapier behöver start, slut och platstyp som egna fält, så de läggs till här. Slutdatumet finns bara i ACF-repeatern på kursen, och den läses från `meta['post_id']`.

```php
<?php
/**
 * Plugin Name: Kursanmälan – webhook
 */

const KURS_FORM_ID = 123;

add_filter( 'relativt_form_webhook_payload', function ( $data, $form_id, $values, $meta ) {
	if ( KURS_FORM_ID !== (int) $form_id ) {
		return $data;
	}

	$value = (string) ( $data['fields']['tillfalle'] ?? '' );
	if ( ! preg_match( '/^(\d{8})_(webbaserad|plats)$/', $value, $m ) ) {
		return $data; // testinskicket, eller ett formulär utan tillfälle
	}

	$from = $m[1];
	$to   = '';

	// Slutdatumet ur kursens repeater, via sidkontexten – aldrig get_the_ID().
	$post_id = (int) ( $data['meta']['post_id'] ?? 0 );
	$rows    = $post_id ? get_field( 'kurs_tillfallen', $post_id ) : null;
	foreach ( is_array( $rows ) ? $rows : [] as $row ) {
		if ( (string) ( $row['datum_fran'] ?? '' ) === $from && ( $row['plats_typ'] ?? '' ) === $m[2] ) {
			$to = (string) ( $row['datum_till'] ?? '' );
			break;
		}
	}

	$iso = static fn( string $ymd ): string => preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $ymd, $d ) ? "{$d[1]}-{$d[2]}-{$d[3]}" : '';

	$data['tillfalle_start']     = $iso( $from );
	$data['tillfalle_slut']      = $iso( '' !== $to ? $to : $from ); // endagskurs: samma dag
	$data['tillfalle_plats_typ'] = $m[2];

	return $data;
}, 10, 4 );
```

I Zapier: *Webhooks by Zapier → Catch Hook*. Klistra in adressen i formuläret, klicka *Skicka testinskick* och välj testinskicket som exempel i zappen. Nycklarna i `fields` och de egna nycklarna (`tillfalle_start` m.fl.) blir valbara fält i zappens nästa steg.

---

## Säkerhet

- Bara `https`, och anropet görs med `wp_safe_remote_post()`, som vägrar adresser på det interna nätet (localhost, 10.x, 192.168.x m.fl.).
- Adressen och hemligheten finns aldrig i formulärets markup, i skriptets konfiguration eller i `GET /form`.
- Huvuden som `Content-Type`, `Host` och `User-Agent` går inte att sätta via inställningen.
- Hemligheten ligger i databasen som en vanlig inställning och syns för den som kan redigera formuläret.

---

## Flytta formuläret mellan miljöer

JSON-exporten tar med **adressen och huvudets namn**, men aldrig **av/på** eller **hemligheten**. Ett importerat formulär skickar alltså ingenting förrän någon slagit på webhooken och fyllt i hemligheten. Det är avsiktligt: ett formulär som flyttas från produktion till staging ska inte skicka testinskick till kundens riktiga system.

Efter import, eller efter en databaskopia:

- [ ] Är adressen rätt för miljön? Använd gärna en egen zap för staging.
- [ ] Slå på webhooken och fyll i hemligheten, om det behövs.
- [ ] Klicka *Skicka testinskick*.
- [ ] Har sajten `DISABLE_WP_CRON`? Lägg till ett cron-jobb (se ovan).

En databaskopia tar med allt, även av/på och hemligheten. Kopierar du produktion till staging: **stäng av webhooken på staging** direkt efteråt, eller byt adress.

---

## Integritet

När något formulär har webhooken på lägger pluginet till ett avsnitt i WordPress förslag till integritetspolicy (Inställningar → Integritet). Avsnittet säger att inskicken skickas vidare och vilka uppgifter det gäller. Komplettera med vilken tjänst som tar emot uppgifterna och var den behandlar dem.

WordPress integritetsverktyg raderar inskicket i WordPress, men inte det som redan skickats vidare. Den mottagande tjänsten måste hanteras för sig.

---

## Begränsningar

- Ett automatiskt omförsök. Därefter krävs *Skicka om*.
- Omförsöket kräver WP-Cron, alltså trafik eller ett riktigt cron-jobb.
- Utan lagring: ett försök, inget omförsök, ingen status.
- Flerval byggs om till en lista genom att dela vid `", "`. Ett värde som självt innehåller `", "` blir fel. Det fungerar inte heller i villkor eller statistiken.
- Avaktiveras pluginet tas väntande försök bort ur WP-Cron. Inskicken står kvar på *Väntar* och kan skickas om för hand.
