<?php
/**
 * Servertester för class-relativt-form.php – validering, villkor, routing, mail.
 * Körs: php tests/server-test.php
 *
 * Ingen testram, bara assert-funktioner. Poängen är att fånga de fel som
 * faktiskt kan uppstå: att ett dolt fält kräver ifyllnad, att en regel inte
 * träffar för att kunden skrev etiketten, att ett förfalskat värde släpps in.
 */

require __DIR__ . '/harness.php';

$engine = Relativt_Form::instance();
$ref    = new ReflectionClass( $engine );

/** Kör en privat/skyddad metod. */
function call( object $object, string $method, array $args = [] ) {
	$m = ( new ReflectionClass( $object ) )->getMethod( $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $object, $args );
}

$passed = 0;
$failed = 0;

function check( string $name, bool $condition, string $detail = '' ): void {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  ok   {$name}\n";
	} else {
		$failed++;
		echo "  FEL  {$name}" . ( $detail ? " – {$detail}" : '' ) . "\n";
	}
}

function value_of( array $values, string $key ): ?string {
	foreach ( $values as $v ) {
		if ( $v['key'] === $key ) {
			return $v['value'];
		}
	}
	return null;
}

echo "\nUppstart\n";

/*
 * Regression 2026-08-12. Vakten mot dubbel inläsning låg som
 * `if (class_exists(...)) return;` FÖRE klassdeklarationen. PHP tidigbinder
 * klasser på toppnivå redan vid kompilering, så vakten såg alltid sin egen
 * klass och filen returnerade innan uppstarten. Resultat: klassen fanns i
 * minnet, men inte en enda hook registrerades och posttyperna dök aldrig upp
 * i wp-admin. De här tre testerna hade fångat det direkt.
 */
check( 'klassen deklareras', class_exists( 'Relativt_Form', false ) );
check( 'motorn STARTAS när filen laddas', hooked_methods( 'init' ) !== [], 'inga init-hookar – uppstarten nåddes aldrig' );
check( 'posttyperna hängs på init', in_array( 'register_post_types', hooked_methods( 'init' ), true ) );
check( 'shortcoden hängs på init', in_array( 'register_shortcode', hooked_methods( 'init' ), true ) );
check( 'fältgrupperna hängs på acf/init', in_array( 'register_fields', hooked_methods( 'acf/init' ), true ) );
check( 'REST-rutterna hängs på rest_api_init', in_array( 'register_routes', hooked_methods( 'rest_api_init' ), true ) );

// Dubbel inläsning ska inte krascha – vakten måste fortfarande fungera.
$before = count( $GLOBALS['__hooks'] );
require __DIR__ . '/../relativt-formular.php';
check( 'filen tål att laddas två gånger', true );
check( 'andra inläsningen dubblerar inga hookar', count( $GLOBALS['__hooks'] ) === $before );

$engine->register_post_types();
$types = $GLOBALS['__post_types'];
check( 'posttypen relativt_form registreras', isset( $types['relativt_form'] ) );
check( 'posttypen relativt_entry registreras', isset( $types['relativt_entry'] ) );
check( 'map_meta_cap är på (annars nekas admin att öppna formulär)', ! empty( $types['relativt_form']['map_meta_cap'] ) );
check( 'inskick kan inte skapas för hand', ( $types['relativt_entry']['capabilities']['create_posts'] ?? '' ) === 'do_not_allow' );

echo "\nFormulärbyggaren i wp-admin\n";

$engine->register_fields();
$builder = $GLOBALS['__field_groups']['group_xf_fields']['fields'][0] ?? [];

check( 'fältbyggaren registreras', ( $builder['type'] ?? '' ) === 'repeater' );
check( 'raderna fälls ihop till sin etikett', ( $builder['collapsed'] ?? '' ) === 'field_xf_f_label' );

/*
 * Nyckelfältet döljs med CSS men får ALDRIG tas bort ur fältgruppen. Utan det
 * i DOM:en skickas ingen nyckel med i POST, och lock_field_keys() genererar då
 * en ny utifrån etiketten vid varje sparning – döper kunden om ett fält tappar
 * alla gamla inskick kopplingen till sina värden.
 */
$key_field = sub_field_def( 'field_xf_f_key' );
check( 'nyckelfältet finns kvar i fältgruppen', null !== $key_field );
check( 'nyckelfältet är dolt via wrapper-klass', str_contains( $key_field['wrapper']['class'] ?? '', 'xf-hidden-key' ) );
check( 'nyckelfältet är skrivskyddat', ! empty( $key_field['readonly'] ) );

/*
 * 1.1.0: hjälptexten är TILLBAKA. Renderaren stödde den hela tiden – 1.0
 * tog bara bort byggarfältet, och det här testet låste fast borttagningen.
 */
$help_field = sub_field_def( 'field_xf_f_help' );
check( 'hjälptextfältet finns i byggaren', null !== $help_field );
check( 'och skriver till kolumnen help', ( $help_field['name'] ?? '' ) === 'help' );
check( 'etikett och typ finns kvar', null !== sub_field_def( 'field_xf_f_label' ) && null !== sub_field_def( 'field_xf_f_type' ) );
check( 'fälttyperna delas mellan byggaren och kartan', sub_field_def( 'field_xf_f_type' )['choices'] === Relativt_Form::field_type_labels() );

ob_start();
$engine->render_map_box( new WP_Post( 12 ) );
$map = (string) ob_get_clean();

check( 'fältkartan listar alla fält', substr_count( $map, '<tr>' ) === count( $engine->get_fields( 12 ) ) );
check( 'fältkartan visar nyckeln', str_contains( $map, '<code>jagar</code>' ) );
check( 'fältkartan visar fälttypen på svenska', str_contains( $map, 'Val-knappar' ) );
check( 'fältkartan visar villkoret i klartext', str_contains( $map, 'visas om Jag är = foretag' ), $map );
check( 'fältkartan markerar obligatoriska fält', str_contains( $map, 'title="Obligatoriskt"' ) );

echo "\nValidering\n";

$ok = $engine->validate( 12, [
	'jagar'      => 'foretag',
	'namn'       => 'Anna Andersson',
	'foretag'    => 'Exempel AB',
	'epost'      => 'anna@exempel.se',
	'telefon'    => '070-123 45 67',
	'behov'      => 'Rekrytering',
	'meddelande' => "Hej!\nVi söker en konstruktör.",
] );

check( 'giltigt inskick ger inga fel', $ok['errors'] === [], json_encode( $ok['errors'], JSON_UNESCAPED_UNICODE ) );
check( 'val-knappen lagras med sin etikett', value_of( $ok['values'], 'jagar' ) === 'Företag', var_export( value_of( $ok['values'], 'jagar' ), true ) );
check( 'radbrytningar i meddelandet bevaras', str_contains( (string) value_of( $ok['values'], 'meddelande' ), "\n" ) );

$missing = $engine->validate( 12, [ 'jagar' => 'foretag' ] );
check( 'obligatoriska fält flaggas', isset( $missing['errors']['namn'], $missing['errors']['epost'] ) );
check( 'valfria fält flaggas inte', ! isset( $missing['errors']['telefon'], $missing['errors']['foretag'] ) );

$bad_email = $engine->validate( 12, [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@' ] );
check( 'felaktig e-post fångas', isset( $bad_email['errors']['epost'] ) );

$bad_tel = $engine->validate( 12, [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se', 'telefon' => 'ring mig' ] );
check( 'felaktigt telefonnummer fångas', isset( $bad_tel['errors']['telefon'] ) );

echo "\nE-postvalidering\n";

$good_emails = [ 'anna@exempel.se', 'anna.andersson@sub.exempel.co.uk', 'a+tagg@exempel.nu', 'Anna.Andersson@Exempel.SE' ];

/*
 * IDN-adressen kräver PHP-tillägget intl. Motorn hoppar över punycode-
 * översättningen när tillägget saknas, alltså ska testet göra det också –
 * annars mäter det serverns uppsättning i stället för koden. CI installerar
 * intl, så den riktiga vägen testas där.
 */
if ( function_exists( 'idn_to_ascii' ) ) {
	$good_emails[] = 'kontakt@räksmörgås.se';
} else {
	echo "  --   hoppar över IDN-test: PHP-tillägget intl saknas\n";
}
$bad_emails  = [ 'anna@', '@exempel.se', 'anna@exempel', 'anna..a@exempel.se', 'anna.@exempel.se', 'anna@.exempel.se', 'anna@exempel.s', 'anna exempel.se', 'anna@exempel..se', '' ];

foreach ( $good_emails as $email ) {
	check( "godkänner {$email}", $engine->valid_email( $email ) );
}
foreach ( $bad_emails as $email ) {
	check( 'avvisar ' . ( '' === $email ? '(tom)' : $email ), ! $engine->valid_email( $email ) );
}

echo "\nTelefonvalidering\n";

// [inmatning, förväntat normaliserat värde]
$phones = [
	[ '070-123 45 67', '0701234567' ],
	[ '0701234567', '0701234567' ],
	[ '070 123 45 67', '0701234567' ],
	[ '+46 70 123 45 67', '0701234567' ],
	[ '+46701234567', '0701234567' ],
	[ '+46(0)70 123 45 67', '0701234567' ],
	[ '0046701234567', '0701234567' ],
	[ '701234567', '0701234567' ],
	[ '08-12 34 56', '0812 3456' ],
	[ '018-12 34 56', '018123456' ],
	[ '+44 20 7946 0958', '+442079460958' ],
	[ ' 070.123.45.67 ', '0701234567' ],
];

foreach ( $phones as [ $input, $expected ] ) {
	$expected = preg_replace( '/\s+/', '', $expected );
	$actual   = $engine->normalize_phone( $input );
	check( "godkänner {$input}", $actual === $expected, 'fick ' . var_export( $actual, true ) );
}

$bad_phones = [ 'ring mig', '070-ABC', '123', '0', '0000000000', '1111111111', '+4', '070123456789012345', '--', '' ];
foreach ( $bad_phones as $phone ) {
	check( 'avvisar ' . ( '' === $phone ? '(tom)' : $phone ), null === $engine->normalize_phone( $phone ) );
}

$normalised = $engine->validate( 12, [
	'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se', 'telefon' => '+46 (0)70 123 45 67',
] );
check( 'numret sparas normaliserat', value_of( $normalised['values'], 'telefon' ) === '0701234567', (string) value_of( $normalised['values'], 'telefon' ) );

echo "\nURL-validering\n";

// [inmatning, förväntat normaliserat värde]
$urls = [
	[ 'https://exempel.se', 'https://exempel.se' ],
	[ 'http://exempel.se/sida', 'http://exempel.se/sida' ],
	[ 'exempel.se', 'https://exempel.se' ],
	[ 'www.exempel.se', 'https://www.exempel.se' ],
	[ 'linkedin.com/in/anna-andersson', 'https://linkedin.com/in/anna-andersson' ],
	[ '  exempel.se  ', 'https://exempel.se' ],
];

foreach ( $urls as [ $input, $expected ] ) {
	$actual = $engine->normalize_url( $input );
	check( "godkänner {$input}", $actual === $expected, 'fick ' . var_export( $actual, true ) );
}

$bad_urls = [ 'inte en url', 'https://', 'https://bara-ord', 'ftp:///saknar-host', '' ];
foreach ( $bad_urls as $url ) {
	check( 'avvisar ' . ( '' === $url ? '(tom)' : $url ), null === $engine->normalize_url( $url ) );
}

echo "\nMottagaradresser i wp-admin\n";

check( 'godkänner en adress', true === $engine->validate_recipients( true, 'info@exempel.se' ) );
check( 'godkänner flera adresser', true === $engine->validate_recipients( true, 'info@exempel.se, rekrytering@exempel.se' ) );
check( 'godkänner tomt fält', true === $engine->validate_recipients( true, '' ) );
check( 'avvisar adress utan toppdomän', is_string( $engine->validate_recipients( true, 'info@exempel' ) ) );
check( 'pekar ut vilken adress som är fel', str_contains( (string) $engine->validate_recipients( true, 'info@exempel.se, trasig@' ), 'trasig@' ) );

echo "\nVillkorlig visning på servern\n";

$foretag = $engine->validate( 12, [
	'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se',
	'behov' => 'Bemanning', 'onskemal' => 'Söker jobb',
] );
check( 'synligt villkorsfält tas med', value_of( $foretag['values'], 'behov' ) === 'Bemanning' );
check( 'dolt villkorsfält tas INTE med, även om det skickas in', value_of( $foretag['values'], 'onskemal' ) === null );

$kandidat = $engine->validate( 12, [
	'jagar' => 'kandidat', 'namn' => 'Bo', 'epost' => 'bo@b.se',
	'behov' => 'Bemanning', 'onskemal' => 'Spontanansökan',
] );
check( 'omvänt villkor släpper igenom rätt fält', value_of( $kandidat['values'], 'onskemal' ) === 'Spontanansökan' );
check( 'och stänger ute det andra', value_of( $kandidat['values'], 'behov' ) === null );

/*
 * Kunden skriver etiketten i wp-admin istället för det tekniska värdet.
 * OBS flush: utan den mäter testet definitionscachen från tidigare anrop,
 * inte den ändrade definitionen – och går grönt av fel skäl.
 */
$GLOBALS['__form']['xf_fields'][5]['cond_value'] = 'Företag';
Relativt_Form::flush_fields_cache();
$label_cond = $engine->validate( 12, [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se', 'behov' => 'Interim' ] );
check( 'villkor skrivet med den synliga etiketten fungerar', value_of( $label_cond['values'], 'behov' ) === 'Interim' );
$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

echo "\nSäkerhet\n";

$injected = $engine->validate( 12, [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se', 'behov' => 'Gratis lån' ] );
check( 'påhittat värde i rullistan avvisas', isset( $injected['errors']['behov'] ) );

$xss = $engine->validate( 12, [
	'jagar' => 'foretag', 'namn' => '<script>alert(1)</script>Anna',
	'epost' => 'a@b.se', 'meddelande' => '<img src=x onerror=alert(1)>',
] );
check( 'html i namnet saneras bort', ! str_contains( (string) value_of( $xss['values'], 'namn' ), '<script' ) );
check( 'html i meddelandet saneras bort', ! str_contains( (string) value_of( $xss['values'], 'meddelande' ), '<img' ) );

$long = $engine->validate( 12, [ 'jagar' => 'foretag', 'namn' => str_repeat( 'a', 2000 ), 'epost' => 'a@b.se' ] );
check( 'orimligt långa textfält kapas', mb_strlen( (string) value_of( $long['values'], 'namn' ) ) === 500 );

$sig_a = call( $engine, 'sign', [ '12|1000' ] );
$sig_b = call( $engine, 'sign', [ '12|1001' ] );
check( 'signaturen skiljer per tidsstämpel', $sig_a !== $sig_b );
check( 'signaturen är stabil', $sig_a === call( $engine, 'sign', [ '12|1000' ] ) );

$GLOBALS['__transients'] = [];
$_SERVER['REMOTE_ADDR']  = '198.51.100.7';
$blocked = 0;
for ( $i = 0; $i < 8; $i++ ) {
	if ( call( $engine, 'rate_limited' ) ) {
		$blocked++;
	}
}
check( 'frekvensspärren slår till efter fem försök', 3 === $blocked, "blockerade {$blocked}" );

echo "\nMottagarrouting\n";

[ $to, $subject ] = call( $engine, 'resolve_recipient', [ 12, $foretag['values'] ] );
check( 'företag går till standardmottagaren', $to === 'info@exempel.se', $to );
check( 'och får standardämnet', $subject === 'Nytt meddelande från "Exempel AB"', $subject );

[ $to2, $subject2 ] = call( $engine, 'resolve_recipient', [ 12, $kandidat['values'] ] );
check( 'kandidat routas om av regeln', $to2 === 'rekrytering@exempel.se', $to2 );
check( 'och får regelns ämnesrad', $subject2 === 'Ny kandidat från webbplatsen', $subject2 );

// Regel skriven med det tekniska värdet istället för etiketten.
$GLOBALS['__form']['xf_rules'][0]['value'] = 'kandidat';
[ $to3 ] = call( $engine, 'resolve_recipient', [ 12, $kandidat['values'] ] );
check( 'regel skriven med tekniskt värde träffar också', $to3 === 'rekrytering@exempel.se', $to3 );
$GLOBALS['__form'] = xf_test_form();

// Ämnesrad med fältnyckel.
$GLOBALS['__form']['xf_subject'] = 'Nytt från {namn} ({jagar})';
[ , $subject4 ] = call( $engine, 'resolve_recipient', [ 12, $foretag['values'] ] );
check( 'fältnycklar i ämnesraden byts ut', $subject4 === 'Nytt från Anna (Företag)', $subject4 );
$GLOBALS['__form'] = xf_test_form();

echo "\nMail\n";

$GLOBALS['__mail'] = [];
$meta = call( $engine, 'collect_meta', [ 12, [
	'page' => 'https://exempel.se/kontakt/',
	'utm'  => [ 'utm_source' => 'google', 'utm_medium' => 'cpc', 'landing' => 'https://exempel.se/', 'referrer' => '' ],
] ] );
call( $engine, 'send_mail', [ 12, $foretag['values'], $meta ] );

$mail = $GLOBALS['__mail'][0] ?? null;
check( 'ett mail skickas', null !== $mail );
check( 'till rätt mottagare', $mail && $mail['to'] === [ 'info@exempel.se' ] );

$headers = implode( "\n", $mail['headers'] ?? [] );
check( 'Från-adressen ligger på egen domän', str_contains( $headers, 'From: Exempel AB <info@exempel.se>' ) );
check( 'Svara-till pekar på BESÖKAREN, inte på oss', str_contains( $headers, 'Reply-To: a@b.se' ), $headers );
check( 'mailet skickas som HTML', str_contains( $headers, 'text/html' ) );

$body = $mail['body'] ?? '';
check( 'ifyllda fälts etiketter finns i mailet', str_contains( $body, 'Namn' ) && str_contains( $body, 'E-post' ) );
check( 'tomma fälts etiketter utelämnas', ! str_contains( $body, 'Meddelande' ) );
check( 'värden finns i mailet', str_contains( $body, 'Anna' ) );
check( 'tomma fält utelämnas', ! str_contains( $body, 'Telefon' ) );
check( 'kampanjkällan följer med', str_contains( $body, 'google' ) && str_contains( $body, 'cpc' ) );
check( 'metadatan är på svenska, inte råa nycklar', str_contains( $body, 'Kampanjkälla' ) && str_contains( $body, 'Kanal' ) );
check( 'inga utm_-nycklar läcker ut i mailet', ! str_contains( $body, 'utm_source' ) && ! str_contains( $body, 'utm_medium' ) );
check( 'landningssida dubbleras inte', substr_count( $body, 'Landningssida' ) === 1 );
check( 'datum och tid har svenska etiketter', str_contains( $body, 'Datum' ) && str_contains( $body, 'Tid' ) );

// URL-fält ska renderas som en klickbar länk, inte som ren text.
$url_values   = array_merge( $foretag['values'], [
	[ 'key' => 'linkedin', 'label' => 'LinkedIn-profil', 'type' => 'url', 'value' => 'https://linkedin.com/in/anna' ],
] );
$url_body     = call( $engine, 'mail_body', [ 12, $url_values, $meta ] );
check( 'URL-fältet blir en klickbar länk', str_contains( $url_body, '<a href="https://linkedin.com/in/anna"' ), $url_body );
check( 'länktexten visar hela adressen', str_contains( $url_body, '>https://linkedin.com/in/anna</a>' ) );
check( 'användaragenten heter Webbläsare', $engine->meta_label( 'ua' ) === 'Webbläsare' );
check( 'okänd nyckel faller tillbaka på sig själv', $engine->meta_label( 'nagot_okant' ) === 'nagot_okant' );
check( 'skickat-från-sidan följer med', str_contains( $body, 'exempel.se/kontakt' ) );

$GLOBALS['__form']['xf_log_ip'] = 0;
$_SERVER['REMOTE_ADDR']         = '198.51.100.7';
$meta_no_ip = call( $engine, 'collect_meta', [ 12, [] ] );
check( 'IP-loggning kan stängas av', '' === $meta_no_ip['ip'] );
$GLOBALS['__form'] = xf_test_form();

echo "\nRendering\n";

$render = $ref->getMethod( 'render_form' );
$render->setAccessible( true );
$html = $render->invoke( $engine, 12, [ 'jagar' => 'kandidat' ], '' );

check( 'shortcode-attributet förväljer rätt radio', str_contains( $html, 'value="kandidat" checked="checked"' ) );

/*
 * Regression 2026-08-12. Villkorsfälten renderades ALLTID dolda och gjordes
 * synliga först av JS. Laddades inte JS syntes de aldrig – och kunden såg ett
 * formulär med en rullista som spårlöst försvunnit. Villkoren utvärderas nu
 * på servern utifrån förval, så rätt fält är synligt redan vid första
 * målningen. JS behövs bara när besökaren BYTER val.
 */
check(
	'villkorsfält som matchar förvalet renderas SYNLIGT utan JS',
	str_contains( $html, 'data-xf-cond-field="jagar" data-xf-cond-value="kandidat"><label' ),
	'fältet för kandidat borde vara synligt när förvalet är kandidat'
);
check(
	'villkorsfält som inte matchar renderas dolt',
	str_contains( $html, 'data-xf-cond-field="jagar" data-xf-cond-value="foretag" hidden' )
);

$html_foretag = $render->invoke( $engine, 12, [ 'jagar' => 'foretag' ], '' );
check(
	'omvänt förval vänder på vilket fält som är dolt',
	str_contains( $html_foretag, 'data-xf-cond-value="kandidat" hidden' )
	&& ! str_contains( $html_foretag, 'data-xf-cond-value="foretag" hidden' )
);

$html_default = $render->invoke( $engine, 12, [], '' );
check(
	'utan förval styr fältets standardvärde vad som visas',
	str_contains( $html_default, 'data-xf-cond-value="kandidat" hidden' ),
	'standardvärdet för Jag är är foretag, alltså ska kandidatfältet vara dolt'
);
check( 'honungsfällan finns med', str_contains( $html, 'name="xf_website"' ) );
/*
 * Knappen ska INTE bära temaklasser som standard. Motorn måste fungera på en
 * sajt utan sidbyggare; sajter som vill ärva sitt eget knapputseende skjuter
 * in sina klasser via filtren i stället.
 */
check( 'knappen bär bara sin egen klass som standard', str_contains( $html, 'class="xf-submit"' ) );
check( 'inga temaklasser läcker in i standardmarkeringen', ! str_contains( $html, 'ct-text-block' ) && ! str_contains( $html, 'ct-fancy-icon' ) );
check( 'ikonen ritas ut', str_contains( $html, '<svg' ) && str_contains( $html, 'xf-submit-icon' ) );
check( 'REST-roten ligger på roten', str_contains( $html, 'data-xf-rest=' ) );
check( 'tack-rutan är dold från start och fokuserbar för JS', str_contains( $html, 'class="xf-thanks" role="status" aria-live="polite" tabindex="-1" hidden' ) );

echo "\nTillgänglighet i markupen\n";

check( 'obligatoriska fält bär data-xf-required', str_contains( $html, 'data-xf-key="namn" data-xf-required="1"' ) );
check( 'valfria fält gör det inte', ! str_contains( $html, 'data-xf-key="foretag" data-xf-required' ) );
check( 'val-knappsgruppen får sitt namn via aria-labelledby', (bool) preg_match( '/role="radiogroup" aria-labelledby="[^"]+-jagar-label"/', $html ) );
check( 'hjälptexten renderas med id', (bool) preg_match( '/class="xf-help" id="[^"]+-meddelande-help">Berätta gärna kort/', $html ) );
check( 'felraden renderas med id', (bool) preg_match( '/class="xf-error" id="[^"]+-namn-error"/', $html ) );

echo "\nVisa etikett\n";

/*
 * Nytt i 1.3.0. "Visa etikett" döljer etiketten VISUELLT – den ska aldrig
 * försvinna ur markupen, annars tappar fältet sitt tillgängliga namn för
 * skärmläsare (och gruppfältens aria-labelledby pekar på ingenting).
 */
check( 'formulär utan show_label-nyckel visar etiketterna som förut', ! str_contains( $html, 'xf-sr-only' ) );

$GLOBALS['__form']['xf_fields'][] = [
	'type' => 'text', 'key' => 'smeknamn', 'label' => 'Smeknamn', 'show_label' => 0,
];
$GLOBALS['__form']['xf_fields'][] = [
	'type' => 'checkbox', 'key' => 'nyhetsbrev', 'label' => 'Jag vill ha nyhetsbrevet', 'show_label' => 0,
];
$GLOBALS['__form']['xf_fields'][] = [
	'type' => 'radio', 'key' => 'sprak', 'label' => 'Språk', 'choices' => "sv : Svenska\nen : Engelska", 'show_label' => 0,
];
Relativt_Form::flush_fields_cache();
$html_no_labels = $render->invoke( $engine, 12, [], '' );

check(
	'textfält: etiketten får xf-sr-only men ligger kvar med sitt for-attribut',
	(bool) preg_match( '/<label class="xf-label xf-sr-only" for="[^"]+-smeknamn">Smeknamn<\/label>/', $html_no_labels )
);
check(
	'kryssruta: etikettexten döljs visuellt men finns kvar i klickytan',
	str_contains( $html_no_labels, '<span class="xf-check-text xf-sr-only">Jag vill ha nyhetsbrevet</span>' )
);
check(
	'gruppfält (radio): aria-labelledby pekar fortfarande på den nu dolda etiketten',
	(bool) preg_match( '/role="radiogroup" aria-labelledby="([^"]+-sprak-label)"/', $html_no_labels, $m )
		&& str_contains( $html_no_labels, '<span class="xf-label xf-sr-only" id="' . $m[1] . '">Språk' )
);

$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

// get_fields(): bakåtkompatibel standard + explicit av/på.
$GLOBALS['__form']['xf_fields'][] = [ 'type' => 'text', 'key' => 'utan_falt', 'label' => 'Utan fält' ];
$GLOBALS['__form']['xf_fields'][] = [ 'type' => 'text', 'key' => 'pa', 'label' => 'På', 'show_label' => 1 ];
$GLOBALS['__form']['xf_fields'][] = [ 'type' => 'text', 'key' => 'av', 'label' => 'Av', 'show_label' => 0 ];
Relativt_Form::flush_fields_cache();

$by_key = [];
foreach ( $engine->get_fields( 12 ) as $f ) {
	$by_key[ $f['key'] ] = $f;
}
check( 'fält sparade före 1.3.0 (ingen nyckel alls) default:ar till visad etikett', $by_key['utan_falt']['show_label'] === true );
check( 'explicit påslagen etikett', $by_key['pa']['show_label'] === true );
check( 'explicit avslagen etikett', $by_key['av']['show_label'] === false );

$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

// Export/import: inställningen ska följa med i vitlistan, inte tystas ner.
$GLOBALS['__form']['xf_fields'][] = [ 'type' => 'text', 'key' => 'av2', 'label' => 'Av2', 'show_label' => 0 ];
Relativt_Form::flush_fields_cache();
$payload_sl = Relativt_Form_Portability::instance()->build_payload( 12 );
$row_av2    = null;
foreach ( $payload_sl['settings']['xf_fields'] as $row ) {
	if ( ( $row['key'] ?? '' ) === 'av2' ) {
		$row_av2 = $row;
	}
}
check( 'show_label finns med i exportens vitlista', null !== $row_av2 && array_key_exists( 'show_label', $row_av2 ) );
check( 'och värdet är avstängt', 0 === (int) ( $row_av2['show_label'] ?? 1 ) );

$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

/* =============================================================================
 * Paketeringen
 *
 * Det som skiljer ett plugin från en lös fil: att stilmall och skript följer
 * med, att temakopplingen går att styra utifrån, och att ett formulär kan
 * flyttas till nästa sajt.
 * ========================================================================== */

echo "\nStilmall och skript\n";

$engine->register_assets();
$assets = $GLOBALS['__assets'];

check( 'stilmallen registreras ur pluginmappen', str_contains( (string) ( $assets['style:relativt-formular'] ?? '' ), 'assets/css/relativt-formular.css' ) );
check( 'skriptet registreras ur pluginmappen', str_contains( (string) ( $assets['script:relativt-formular']['src'] ?? '' ), 'assets/js/relativt-formular.js' ) );
check( 'skriptet läggs i sidfoten', true === ( $assets['script:relativt-formular']['footer'] ?? false ) );
check( 'båda köas som standard', ! empty( $assets['enq:style:relativt-formular'] ) && ! empty( $assets['enq:script:relativt-formular'] ) );

echo "\nFilter mot temat\n";

add_filter( 'relativt_form_submit_class', static fn( $c ) => trim( $c . ' btn' ) );
add_filter( 'relativt_form_submit_text_class', static fn( $c ) => trim( $c . ' ct-text-block' ) );
$themed = $render->invoke( $engine, 12, [], '' );

check( 'sajten kan skjuta in sin knappklass', str_contains( $themed, 'class="xf-submit btn"' ) );
check( 'och sin klass på knapptexten', str_contains( $themed, 'class="xf-submit-text ct-text-block"' ) );
remove_all_filters( 'relativt_form_submit_class' );
remove_all_filters( 'relativt_form_submit_text_class' );

add_filter( 'relativt_form_submit_icon', static fn() => '' );
check( 'ikonen kan tas bort helt', ! str_contains( $render->invoke( $engine, 12, [], '' ), 'xf-submit-icon' ) );
remove_all_filters( 'relativt_form_submit_icon' );

echo "\nLänkspärr\n";

/*
 * Länkspam är den vanligaste sortens skräp som tar sig förbi honungsfälla
 * och tidsspärr. Tre länkar är okej – ett riktigt ärende kan innehålla någon
 * enstaka – men fyra avvisas. Filtret kan höja eller stänga av taket.
 */
$base = [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se' ];

$spam = $engine->validate( 12, $base + [ 'meddelande' => 'Kolla https://a.se https://b.se www.c.se och https://d.se' ] );
check( 'fyra länkar i meddelandet avvisas', isset( $spam['errors']['meddelande'] ) );

$ok_links = $engine->validate( 12, $base + [ 'meddelande' => 'Se https://a.se, https://b.se och www.c.se för exempel.' ] );
check( 'tre länkar släpps igenom', ! isset( $ok_links['errors']['meddelande'] ) );

add_filter( 'relativt_form_max_links', static fn() => 0 );
$off = $engine->validate( 12, $base + [ 'meddelande' => 'https://a.se https://b.se https://c.se https://d.se https://e.se' ] );
check( 'taket kan stängas av med filtret', ! isset( $off['errors']['meddelande'] ) );
remove_all_filters( 'relativt_form_max_links' );

echo "\nDatum\n";

/*
 * Formatet räcker inte: 2026-13-45 matchar \d{4}-\d{2}-\d{2}. Webbläsarens
 * datumfält skickar aldrig sådant, men REST-rutten är öppen för vem som helst.
 */
$GLOBALS['__form']['xf_fields'][] = [ 'type' => 'date', 'key' => 'datum', 'label' => 'Datum' ];
Relativt_Form::flush_fields_cache();

check( 'datum som inte finns i kalendern avvisas', isset( $engine->validate( 12, $base + [ 'datum' => '2026-13-45' ] )['errors']['datum'] ) );
check( '30 februari likaså', isset( $engine->validate( 12, $base + [ 'datum' => '2026-02-30' ] )['errors']['datum'] ) );
$ok_date = $engine->validate( 12, $base + [ 'datum' => '2026-02-27' ] );
check( 'riktiga datum släpps igenom', ! isset( $ok_date['errors']['datum'] ) && value_of( $ok_date['values'], 'datum' ) === '2026-02-27' );

$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

echo "\nBesökartexterna\n";

check( 'standardtexterna finns', $engine->messages()['required'] === 'Fyll i detta fält.' );

add_filter( 'relativt_form_messages', static fn( $m ) => array_merge( $m, [ 'required' => 'This field is required.' ] ) );
check( 'filtrerad text används i valideringen', ( $engine->validate( 12, [ 'jagar' => 'foretag' ] )['errors']['namn'] ?? '' ) === 'This field is required.' );

// Ett halvtrasigt filter får inte kunna tysta ett meddelande.
add_filter( 'relativt_form_messages', static fn( $m ) => array_merge( $m, [ 'email' => '', 'okand_nyckel' => 'x' ] ) );
$msgs = $engine->messages();
check( 'tom text ur filtret ignoreras', $msgs['email'] === 'Kontrollera e-postadressen.' );
check( 'okända nycklar släpps inte in', ! isset( $msgs['okand_nyckel'] ) );
remove_all_filters( 'relativt_form_messages' );

echo "\nCSV-exporten\n";

/*
 * Formelinjektion: en cell som börjar med = + - @ exekveras som formel när
 * kunden öppnar filen i Excel. Cellen är besökardata och ska läsas som text.
 */
$guard = new ReflectionMethod( Relativt_Form::class, 'csv_guard' );
$guard->setAccessible( true );

check( 'formelceller ofarliggörs', $guard->invoke( null, '=HYPERLINK("https://ond.se")' ) === "'" . '=HYPERLINK("https://ond.se")' );
check( 'plus, minus och snabel-a likaså', $guard->invoke( null, '+46701234567' ) === "'+46701234567" && $guard->invoke( null, '-1' ) === "'-1" && $guard->invoke( null, '@evil' ) === "'@evil" );
check( 'vanlig text lämnas orörd', $guard->invoke( null, 'Anna Andersson' ) === 'Anna Andersson' );
check( 'även svenska tecken', $guard->invoke( null, 'Örjan Ärlig' ) === 'Örjan Ärlig' );

echo "\nKonfiguration till JS\n";

/*
 * Skriptet får sina texter, sitt länktak och sitt samtyckesläge från PHP via
 * relativtFormConfig – det är så klient och server hålls i takt.
 */
/** Registrerar om och plockar ut den avkodade konfigurationen ur inline-skriptet. */
function xf_read_config(): array {
	$GLOBALS['__assets'] = [];
	Relativt_Form::instance()->register_assets();
	$inline = implode( "\n", $GLOBALS['__assets']['inline:relativt-formular'] ?? [] );
	preg_match( '/window\.relativtFormConfig = (\{.*\});/s', $inline, $m );
	$config = json_decode( $m[1] ?? '', true );
	return is_array( $config ) ? $config : [];
}

$config = xf_read_config();

check( 'konfigurationen skrivs före skriptet', [] !== $config );
check( 'meddelandena följer med till JS', ( $config['messages']['required'] ?? '' ) === 'Fyll i detta fält.' );
check( 'länktaket följer med', 3 === ( $config['maxLinks'] ?? 0 ) );
check( 'standardläget för kampanjkakan är auto', 'auto' === ( $config['utmCookie'] ?? '' ) );
check( 'utan cookie-plugin skickas ingen rccCookie', ! isset( $config['rccCookie'] ) );

add_filter( 'relativt_form_utm_cookie', static fn() => 'never' );
check( 'samtyckesläget kan filtreras', 'never' === ( xf_read_config()['utmCookie'] ?? '' ) );
remove_all_filters( 'relativt_form_utm_cookie' );

echo "\nREST-inskicksflödet\n";

/** En komplett, giltig nyttolast – testerna byter ut det de vill bryta. */
function xf_submit_body( array $extra = [] ): array {
	$engine = Relativt_Form::instance();
	$ts     = time() - 10;

	return array_merge( [
		'form'       => 12,
		'fields'     => [ 'jagar' => 'foretag', 'namn' => 'Anna Andersson', 'epost' => 'anna@exempel.se' ],
		'nonce'      => 'giltig',
		'ts'         => $ts,
		'sig'        => call( $engine, 'sign', [ '12|' . $ts ] ),
		'xf_website' => '',
		'utm'        => [ 'utm_source' => 'google' ],
		'page'       => 'https://exempel.se/kontakt/',
	], $extra );
}

$GLOBALS['__transients'] = [];
$GLOBALS['__mail']       = [];
$GLOBALS['__posts']      = [];
$_SERVER['REMOTE_ADDR']  = '203.0.113.9';

$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body() ) );
check( 'giltigt inskick svarar 200 ok', 200 === $res->status && true === ( $res->data['ok'] ?? false ) );
check( 'inskicket sparas', 1 === count( $GLOBALS['__posts'] ) );
check( 'mailet skickas', 1 === count( $GLOBALS['__mail'] ) );
check( 'tacktexten följer med i svaret', ( $res->data['title'] ?? '' ) === 'Tack för ditt meddelande!' );
check( 'utan tack-sida skickas ingen redirect', ! isset( $res->data['redirect'] ) );

/*
 * Regression 1.1.0: för snabbt inskick fick tidigare FEJKAD SUCCÉ – besökaren
 * såg "Tack!" men ingenting skickades och ingenting sparades. Med autofyll
 * var en riktig besökare lätt så snabb, och leadet försvann spårlöst. Nu ska
 * spärren svara med ett mjukt fel som JS tyst gör om – aldrig svälja data.
 */
$GLOBALS['__mail']  = [];
$GLOBALS['__posts'] = [];
$fast_ts = time() - 1;
$res     = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'ts' => $fast_ts, 'sig' => call( $engine, 'sign', [ '12|' . $fast_ts ] ) ] ) ) );
check( 'för snabbt inskick får ett FEL, ingen fejkad succé', 425 === $res->status && false === ( $res->data['ok'] ?? true ) );
check( 'felet bär koden toofast och en väntetid', 'toofast' === ( $res->data['code'] ?? '' ) && ( $res->data['retry_after'] ?? 0 ) >= 1 );
check( 'ingenting sparas och inget mail går ut', [] === $GLOBALS['__posts'] && [] === $GLOBALS['__mail'] );

// Honungsfällan ska däremot FORTFARANDE luras – en bot ska tro att det gick bra.
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'xf_website' => 'https://spam.example' ] ) ) );
check( 'honungsfällan får fejkad succé', 200 === $res->status && true === ( $res->data['ok'] ?? false ) );
check( 'men ingenting sparas och inget mail går ut', [] === $GLOBALS['__posts'] && [] === $GLOBALS['__mail'] );

$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'sig' => 'fejkad' ] ) ) );
check( 'förfalskad signatur avvisas', 400 === $res->status );

$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'nonce' => 'fel' ] ) ) );
check( 'ogiltig nonce svarar 403 med kod så JS kan hämta ny och göra om', 403 === $res->status && 'nonce' === ( $res->data['code'] ?? '' ) );

$GLOBALS['__transients'][ 'xf_rl_' . md5( '203.0.113.9' ) ] = 5;
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body() ) );
check( 'frekvensspärren svarar 429', 429 === $res->status && 'rate' === ( $res->data['code'] ?? '' ) );
$GLOBALS['__transients'] = [];

$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'fields' => [ 'jagar' => 'foretag' ] ] ) ) );
check( 'valideringsfel svarar 422 per fält', 422 === $res->status && isset( $res->data['errors']['namn'], $res->data['errors']['epost'] ) );

$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'form' => 999 ] ) ) );
check( 'okänt formulär svarar 404', 404 === $res->status );

echo "\nTack-sida\n";

/** Plockar ut ett fält ur inställningsgruppen. */
function xf_settings_field( string $key ): ?array {
	foreach ( $GLOBALS['__field_groups']['group_xf_settings']['fields'] ?? [] as $field ) {
		if ( ( $field['key'] ?? '' ) === $key ) {
			return $field;
		}
	}
	return null;
}

check( 'fältet Tack-sida finns i inställningarna', null !== xf_settings_field( 'field_xf_redirect' ) );

$GLOBALS['__form']['xf_redirect'] = '/tack/';
$GLOBALS['__transients'] = [];

$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body() ) );
check( 'tack-sidan följer med i svaret vid lyckat inskick', ( $res->data['redirect'] ?? '' ) === '/tack/' );

// Honungsfällans fejkade succé ska vara omöjlig att skilja från ett riktigt svar.
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'xf_website' => 'spam' ] ) ) );
check( 'fejkade succén bär samma tack-sida', ( $res->data['redirect'] ?? '' ) === '/tack/' );

check(
	'tack-sidan är med i exportens vitlista',
	array_key_exists( 'xf_redirect', Relativt_Form_Portability::instance()->build_payload( 12 )['settings'] )
);

$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

echo "\nDolda fält\n";

/*
 * Regression 1.1.3. Fälttypen Dolt fält renderades och fick sitt värde från
 * shortcode-attributet – men JS skickade aldrig med det, eftersom nyttolasten
 * hoppade över alla fält med dold wrapper (skyddet för villkorsdolda fält
 * träffade även typen Dolt fält). Serverledet testas här; webbläsarledet i
 * form.spec.js.
 */
$with_hidden = $engine->validate( 12, $base + [ 'audit' => 'Webb' ] );
check( 'dolt fälts värde valideras och följer med', value_of( $with_hidden['values'], 'audit' ) === 'Webb' );

$GLOBALS['__form']['xf_subject'] = 'Ny audit-förfrågan – {audit}';
[ , $audit_subject ] = call( $engine, 'resolve_recipient', [ 12, $with_hidden['values'] ] );
check( 'och kan användas i ämnesraden', 'Ny audit-förfrågan – Webb' === $audit_subject, $audit_subject );
$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

$without = $engine->validate( 12, $base );
check( 'tomt dolt fält ger tomt värde, inget fel', ! isset( $without['errors']['audit'] ) && '' === value_of( $without['values'], 'audit' ) );

echo "\nExport och import\n";

$port    = Relativt_Form_Portability::instance();
$payload = $port->build_payload( 12 );

check( 'exporten märks som en formulärexport', ( $payload['_type'] ?? '' ) === 'relativt-formular' );
check( 'schemaversion följer med', ( $payload['_schema'] ?? 0 ) === Relativt_Form_Portability::SCHEMA );
check( 'titeln följer med', ( $payload['title'] ?? '' ) === 'Kontaktformulär' );
check( 'fälten följer med', count( $payload['settings']['xf_fields'] ?? [] ) === 9 );
check( 'mottagarreglerna följer med', count( $payload['settings']['xf_rules'] ?? [] ) === 1 );
check( 'mottagaradressen följer med', ( $payload['settings']['xf_to'] ?? '' ) === 'info@exempel.se' );
check( 'hjälptexten följer med', ( $payload['settings']['xf_fields'][7]['help'] ?? '' ) === 'Berätta gärna kort vad det gäller.' );

/*
 * Inskicken är personuppgifter och får ALDRIG följa med en definitionsexport
 * som skickas mellan sajter. De hör hemma i CSV-exporten, bakom en egen nonce.
 */
check( 'inga inskick läcker med i exporten', ! str_contains( strtolower( wp_json_encode( $payload ) ), 'entry' ) );

// Vitlistan: en riggad fil ska inte kunna skriva vad som helst.
$clean = new ReflectionMethod( Relativt_Form_Portability::class, 'clean' );
$clean->setAccessible( true );

check( 'okänd fälttyp faller tillbaka på text', $clean->invoke( $port, 'javascript', 'type' ) === 'text' );
check( 'känd fälttyp släpps igenom', $clean->invoke( $port, 'textarea', 'type' ) === 'textarea' );
check( 'okänd bredd faller tillbaka på full', $clean->invoke( $port, '"><script>', 'width' ) === 'full' );
check( 'nycklar saneras', $clean->invoke( $port, 'Jag Är!', 'key' ) === 'jagr' );
check( 'kvarhållning kan inte bli negativ', $clean->invoke( $port, -50, 'int' ) === 0 );
check( 'skript stryps ur samtyckestexten', ! str_contains( (string) $clean->invoke( $port, '<p>Hej</p><script>fetch(1)</script>', 'html' ), '<script' ) );

$rows = new ReflectionMethod( Relativt_Form_Portability::class, 'clean_rows' );
$rows->setAccessible( true );
$smuggled = $rows->invoke( $port, [ [ 'label' => 'Namn', 'type' => 'text', 'skadlig_kolumn' => 'x' ] ], [ 'label' => 'text', 'type' => 'type' ] );

check( 'okända kolumner kastas vid import', ! array_key_exists( 'skadlig_kolumn', $smuggled[0] ) );
check( 'och de vitlistade behålls', ( $smuggled[0]['label'] ?? '' ) === 'Namn' );

echo "\nStandardvärden\n";

check( 'okänt namn ger tom sträng', Relativt_Form_Settings::get( 'hittepa' ) === '' );
check( 'kända namn finns i listan', Relativt_Form_Settings::get( 'xf_from_name' ) === '' );

echo "\nFormulärdefinitionen (headless)\n";

/*
 * GET /form/<id> är publikt och cachas av frontend. Det som får synas är
 * fälten och besökartexterna – aldrig vart inskicken går eller hur de sparas.
 */
$GLOBALS['__routes'] = [];
$engine->register_routes();
$form_route = $GLOBALS['__routes']['relativt-form/v1/form/(?P<id>\d+)'] ?? null;

check( 'definitionsrutten registreras', null !== $form_route );
check( 'och är en publik GET', 'GET' === ( $form_route['methods'] ?? '' ) && '__return_true' === ( $form_route['permission_callback'] ?? '' ) );
check( 'id tas emot som heltal', 'integer' === ( $form_route['args']['id']['type'] ?? '' ) );
check( 'token- och submit-rutterna är oförändrat publika',
	'__return_true' === ( $GLOBALS['__routes']['relativt-form/v1/token']['permission_callback'] ?? '' )
	&& '__return_true' === ( $GLOBALS['__routes']['relativt-form/v1/submit']['permission_callback'] ?? '' ) );

Relativt_Form::flush_fields_cache();
$def = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );

check( 'publicerat formulär svarar med en definition', is_array( $def ) && 12 === ( $def['id'] ?? 0 ) );
check( 'titeln följer med', 'Kontaktformulär' === ( $def['title'] ?? '' ) );
check( 'fälten är get_fields(), oförändrade i JSON', json_decode( wp_json_encode( $def['fields'] ?? null ), true ) === json_decode( wp_json_encode( $engine->get_fields( 12 ) ), true ) );
check( 'choices är alltid ett objekt i JSON, även tomt', str_contains( wp_json_encode( $def['fields'], JSON_UNESCAPED_UNICODE ), '"key":"namn","label":"Namn","show_label":true,"placeholder":"För- och efternamn","help":"","choices":{}' ) );
check( 'och värde => etikett behålls', '{"foretag":"Företag","kandidat":"Kandidat"}' === wp_json_encode( $def['fields'][0]['choices'] ?? null, JSON_UNESCAPED_UNICODE ) );
check( 'knapptexterna följer med', 'Skicka' === ( $def['texts']['submit'] ?? '' ) && 'Skickar…' === ( $def['texts']['sending'] ?? '' ) );
check( 'tacktexterna följer med', 'Tack för ditt meddelande!' === ( $def['texts']['thanks_title'] ?? '' ) && 'Vi återkommer till dig så snart vi kan.' === ( $def['texts']['thanks_text'] ?? '' ) );
check( 'samtyckestexten följer med som html', str_contains( (string) ( $def['texts']['consent'] ?? '' ), '<a href="/integritetspolicy/">' ) );
check( 'samtyckesrutan är ett boolvärde', false === ( $def['texts']['consent_box'] ?? null ) );
check( 'utan tack-sida är redirect tom', '' === ( $def['texts']['redirect'] ?? null ) );
check( 'felmeddelandet följer med', 'Något gick fel. Försök igen, eller mejla oss direkt.' === ( $def['texts']['error'] ?? '' ) );
check( 'honungsfältets namn följer med', 'xf_website' === ( $def['honeypot'] ?? '' ) );
check( 'REST-roten följer med', '/__mock__/relativt-form/v1/' === ( $def['rest'] ?? '' ) );
check( 'meddelandena följer med', $engine->messages() === ( $def['messages'] ?? null ) );
check( 'Turnstile är av som standard', [ 'enabled' => false, 'site_key' => '' ] === ( $def['turnstile'] ?? null ) );

// Fallback-kedjan: formulärets värde → Standardvärden → kodens fallback.
unset( $GLOBALS['__form']['xf_thanks_title'], $GLOBALS['__form']['xf_submit_text'] );
$GLOBALS['__options']['relativt_form_defaults'] = [ 'xf_thanks_title' => 'Tack från standardvärdena' ];
$def = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'tomt formulärvärde ärver Standardvärden', 'Tack från standardvärdena' === ( $def['texts']['thanks_title'] ?? '' ) );
check( 'och utan standardvärde gäller kodens fallback', 'Skicka' === ( $def['texts']['submit'] ?? '' ) );
unset( $GLOBALS['__options']['relativt_form_defaults'] );
$def = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'tack-rubrikens fallback är densamma som renderarens', 'Tack!' === ( $def['texts']['thanks_title'] ?? '' ) );
$GLOBALS['__form'] = xf_test_form();

$GLOBALS['__form']['xf_redirect'] = '/tack/';
$def = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'tack-sidan följer med', '/tack/' === ( $def['texts']['redirect'] ?? '' ) );
$GLOBALS['__form'] = xf_test_form();

// Rubriker behövs för layouten i frontend.
$GLOBALS['__form']['xf_fields'][] = [ 'type' => 'heading', 'key' => '', 'label' => 'Om dig' ];
Relativt_Form::flush_fields_cache();
$def   = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
$types = array_column( json_decode( wp_json_encode( $def['fields'] ?? [] ), true ), 'type' );
check( 'fält av typen Rubrik följer med', in_array( 'heading', $types, true ) );
$GLOBALS['__form'] = xf_test_form();
Relativt_Form::flush_fields_cache();

// En engelsk sajt filtrerar texterna – klienten ska få samma som serverns 422.
add_filter( 'relativt_form_messages', static fn( $m ) => array_merge( $m, [ 'required' => 'This field is required.', 'turnstile' => 'Please wait for the check.' ] ) );
$def = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'meddelandena följer med EFTER filtret', 'This field is required.' === ( $def['messages']['required'] ?? '' ) );
check( 'den nya nyckeln turnstile kan filtreras', 'Please wait for the check.' === ( $engine->messages()['turnstile'] ?? '' ) );
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'fields' => [ 'jagar' => 'foretag' ] ] ) ) );
check( 'och serverns 422 säger samma sak', 'This field is required.' === ( $res->data['errors']['namn'] ?? '' ) );
remove_all_filters( 'relativt_form_messages' );
$GLOBALS['__transients'] = [];

check( 'turnstile har en svensk standardtext', str_contains( $engine->messages()['turnstile'] ?? '', 'Säkerhetskontrollen' ) );

$GLOBALS['__post_status'][12] = 'draft';
$res = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'utkast svarar 404', $res instanceof WP_Error && 404 === ( $res->data['status'] ?? 0 ) );
$GLOBALS['__post_status'][12] = 'private';
$res = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'privat formulär svarar 404', $res instanceof WP_Error && 404 === ( $res->data['status'] ?? 0 ) );
$GLOBALS['__post_status'] = [];
$res = $engine->rest_form( new WP_REST_Request( [ 'id' => 999 ] ) );
check( 'okänt formulär svarar 404', $res instanceof WP_Error && 404 === ( $res->data['status'] ?? 0 ) );

/*
 * Läckagetestet. Slår på Turnstile med nycklar så att även secret finns i
 * omlopp, serialiserar hela svaret och letar efter både nycklarnas NAMN och
 * deras VÄRDEN – ett namnbyte i koden ska inte kunna smita förbi.
 */
$GLOBALS['__form']['xf_turnstile']               = 1;
$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => 'hemlig-secret-123' ];
$def  = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
$json = (string) wp_json_encode( $def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$form = xf_test_form();

$leaks = [];
foreach ( [ 'xf_to', 'xf_rules', 'xf_from_name', 'xf_from_email', 'xf_subject', 'xf_store', 'xf_retention', 'xf_log_ip', 'secret' ] as $needle ) {
	if ( str_contains( $json, $needle ) ) {
		$leaks[] = $needle;
	}
}
foreach ( [ $form['xf_to'], $form['xf_from_email'], $form['xf_subject'], $form['xf_rules'][0]['email'], $form['xf_rules'][0]['subject'], 'hemlig-secret-123' ] as $needle ) {
	if ( str_contains( $json, (string) $needle ) ) {
		$leaks[] = $needle;
	}
}
check( 'inga mottagare, avsändare, ämnen, lagringsval eller nycklar läcker', [] === $leaks, implode( ', ', $leaks ) );
check( 'aktiv Turnstile syns med site key', [ 'enabled' => true, 'site_key' => '1x00000000000000000000AA' ] === ( $def['turnstile'] ?? null ) );

$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => '' ];
$def = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
check( 'utan secret är Turnstile avstängt i definitionen', [ 'enabled' => false, 'site_key' => '' ] === ( $def['turnstile'] ?? null ) );
$GLOBALS['__form']    = xf_test_form();
$GLOBALS['__options'] = [];

echo "\nTurnstile: inställningar\n";

$settings_group = $GLOBALS['__field_groups']['group_xf_settings']['fields'] ?? [];
$ts_field       = null;
foreach ( $settings_group as $f ) {
	if ( 'field_xf_turnstile' === ( $f['key'] ?? '' ) ) {
		$ts_field = $f;
	}
}
check( 'formuläret har kryssrutan Kräv Turnstile', 'xf_turnstile' === ( $ts_field['name'] ?? '' ) && 'true_false' === ( $ts_field['type'] ?? '' ) );
check( 'och den är av som standard', 0 === ( $ts_field['default_value'] ?? null ) );
check( 'den ligger under fliken Skydd', in_array( 'Skydd', array_column( $settings_group, 'label' ), true ) );

$settings = Relativt_Form_Settings::instance();
$settings->register();
check( 'nycklarna registreras som eget alternativ', isset( $GLOBALS['__settings']['relativt_form_turnstile'] ) );
check( 'skilt från standardvärdena', '' === Relativt_Form_Settings::get( 'secret' ) && '' === Relativt_Form_Settings::get( 'site_key' ) );

$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => 'db-site', 'secret' => 'db-secret' ];
$keys = Relativt_Form_Settings::turnstile_keys();
check( 'nycklarna läses ur databasen', 'db-site' === $keys['site_key'] && 'db-secret' === $keys['secret'] && ! $keys['secret_const'] );

check( 'tomt secret-fält vid sparning behåller den sparade', [ 'site_key' => 'ny-site', 'secret' => 'db-secret' ] === $settings->sanitize_turnstile( [ 'site_key' => 'ny-site', 'secret' => '' ] ) );
check( 'ny secret ersätter den gamla', 'ny-secret' === $settings->sanitize_turnstile( [ 'site_key' => 'db-site', 'secret' => ' ny-secret ' ] )['secret'] );
check( 'krysset Ta bort rensar secret', '' === $settings->sanitize_turnstile( [ 'site_key' => 'db-site', 'secret' => '', 'secret_clear' => '1' ] )['secret'] );
check( 'saknas alternativet i POST behålls allt', [ 'site_key' => 'db-site', 'secret' => 'db-secret' ] === $settings->sanitize_turnstile( null ) );
check( 'site key saneras', 'abc' === $settings->sanitize_turnstile( [ 'site_key' => '<b>abc</b>' ] )['site_key'] );

ob_start();
$settings->render();
$page = (string) ob_get_clean();
check( 'inställningssidan visar Turnstile-fälten', str_contains( $page, 'Turnstile site key' ) && str_contains( $page, 'Turnstile secret key' ) );
check( 'secret renderas aldrig i HTML', ! str_contains( $page, 'db-secret' ) );
check( 'men det syns att en secret är sparad', str_contains( $page, 'Sparad – lämna tomt för att behålla' ) );
$GLOBALS['__options'] = [];

/*
 * Konstanterna går inte att avdefiniera, så de prövas i en egen PHP-process
 * med samma rigg. Databasen har andra värden – konstanterna ska vinna, och
 * konstantens secret får inte heller hamna i HTML:en.
 */
$probe = <<<'PHP'
define( 'RELATIVT_FORM_TURNSTILE_SITE_KEY', 'konst-site' );
define( 'RELATIVT_FORM_TURNSTILE_SECRET', 'konst-secret' );
require getenv( 'XF_HARNESS' );
$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => 'db-site', 'secret' => 'db-secret' ];
ob_start();
Relativt_Form_Settings::instance()->render();
$page = ob_get_clean();
echo json_encode( [ 'keys' => Relativt_Form_Settings::turnstile_keys(), 'leak' => str_contains( $page, 'konst-secret' ) || str_contains( $page, 'db-secret' ), 'note' => str_contains( $page, 'Satt i wp-config.php' ) ] );
PHP;
$cmd = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $probe );
$out = shell_exec( 'XF_HARNESS=' . escapeshellarg( __DIR__ . '/harness.php' ) . ' ' . $cmd );
$probe_result = json_decode( (string) $out, true );
check( 'konstanterna vinner över databasen',
	'konst-site' === ( $probe_result['keys']['site_key'] ?? '' ) && 'konst-secret' === ( $probe_result['keys']['secret'] ?? '' ),
	(string) $out );
check( 'inställningssidan säger att värdet kommer från wp-config.php', true === ( $probe_result['note'] ?? false ) );
check( 'och skriver varken konstantens eller databasens secret', false === ( $probe_result['leak'] ?? true ) );

check( 'avinstallationen rensar nycklarna', str_contains( (string) file_get_contents( __DIR__ . '/../uninstall.php' ), "delete_option( 'relativt_form_turnstile' )" ) );

$GLOBALS['__form']['xf_turnstile'] = 1;
$payload = $port->build_payload( 12 );
check( 'Kräv Turnstile följer med i exporten', 1 === ( $payload['settings']['xf_turnstile'] ?? null ) );
check( 'och importen tar emot det som bool', 1 === $clean->invoke( $port, 'ja', 'bool' ) );
$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => 'db-site', 'secret' => 'db-secret' ];
check( 'nycklarna följer aldrig med i exporten', ! str_contains( (string) wp_json_encode( $port->build_payload( 12 ) ), 'db-s' ) );
$GLOBALS['__form']    = xf_test_form();
$GLOBALS['__options'] = [];

echo "\nTurnstile: rendering\n";

$render = $ref->getMethod( 'render_form' );
$render->setAccessible( true );

$GLOBALS['__assets'] = [];
$plain = $render->invoke( $engine, 12, [], '' );
check( 'av som standard: ingen widget', ! str_contains( $plain, 'cf-turnstile' ) );
check( 'och inget skript från Cloudflare', ! isset( $GLOBALS['__assets']['enq:script:relativt-formular-turnstile'] ) );

$GLOBALS['__form']['xf_turnstile'] = 1;
$missing = $render->invoke( $engine, 12, [], '' );
check( 'påslaget utan nycklar: ingen widget, formuläret fungerar som förut', ! str_contains( $missing, 'cf-turnstile' ) && str_contains( $missing, 'xf-submit' ) );

$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => 'hemlig' ];
$with = $render->invoke( $engine, 12, [], '' );
check( 'påslaget med nycklar: widgeten renderas', str_contains( $with, 'class="xf-turnstile cf-turnstile" data-sitekey="1x00000000000000000000AA" data-xf-turnstile' ) );
check( 'ovanför knappen', strpos( $with, 'data-xf-turnstile' ) < strpos( $with, 'class="xf-actions"' ) );
check( 'med en egen felrad', str_contains( $with, 'data-xf-error="turnstile"' ) );
check( 'secret syns inte i markupen', ! str_contains( $with, 'hemlig' ) );
check( 'Cloudflares skript köas', isset( $GLOBALS['__assets']['enq:script:relativt-formular-turnstile'] ) );

$tag = $engine->turnstile_script_tag( "<script src='https://challenges.cloudflare.com/turnstile/v0/api.js' id='relativt-formular-turnstile-js'></script>", 'relativt-formular-turnstile' );
check( 'skriptet laddas async defer', str_contains( $tag, ' async defer src=' ) );
check( 'andra skript rörs inte', "<script src='x.js'></script>" === $engine->turnstile_script_tag( "<script src='x.js'></script>", 'relativt-formular' ) );
$GLOBALS['__form']    = xf_test_form();
$GLOBALS['__options'] = [];
$GLOBALS['__assets']  = [];

echo "\nTurnstile: inskick\n";

$GLOBALS['__transients'] = [];
$GLOBALS['__mail']       = [];
$GLOBALS['__posts']      = [];
$_SERVER['REMOTE_ADDR']  = '203.0.113.9';

/**
 * Nollställer räknarna mellan fallen.
 *
 * @param array|WP_Error $remote Siteverify-svaret som riggen ska ge.
 */
function xf_ts_reset( $remote = [ 'code' => 200, 'body' => '{"success":true}' ] ): void {
	$GLOBALS['__transients']   = [];
	$GLOBALS['__mail']         = [];
	$GLOBALS['__posts']        = [];
	$GLOBALS['__remote']       = $remote;
	$GLOBALS['__remote_calls'] = [];
}

xf_ts_reset();
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body() ) );
check( 'av som standard: inskick utan token går igenom', 200 === $res->status );
check( 'och siteverify anropas aldrig', [] === $GLOBALS['__remote_calls'] );

xf_ts_reset();
$GLOBALS['__form']['xf_turnstile'] = 1;
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body() ) );
check( 'påslaget utan nycklar blockerar inte', 200 === $res->status && [] === $GLOBALS['__remote_calls'] );

$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => 'hemlig' ];

xf_ts_reset();
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body() ) );
check( 'påslaget utan token svarar 403 turnstile', 403 === $res->status && 'turnstile' === ( $res->data['code'] ?? '' ) );
check( 'med meddelandet ur messages()', $engine->messages()['turnstile'] === ( $res->data['message'] ?? '' ) );
check( 'en tom token frågar inte Cloudflare', [] === $GLOBALS['__remote_calls'] );
check( 'ingenting sparas och inget mail går ut', [] === $GLOBALS['__posts'] && [] === $GLOBALS['__mail'] );

xf_ts_reset();
$res  = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
$call = $GLOBALS['__remote_calls'][0] ?? [];
check( 'giltig token går igenom', 200 === $res->status && true === ( $res->data['ok'] ?? false ) );
check( 'siteverify anropas en gång mot rätt adress', 1 === count( $GLOBALS['__remote_calls'] ) && 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === ( $call['url'] ?? '' ) );
check( 'med secret, token och besökarens IP', [ 'secret' => 'hemlig', 'response' => 'giltig-token', 'remoteip' => '203.0.113.9' ] === ( $call['args']['body'] ?? null ) );
check( 'och 5 sekunders timeout', 5 === ( $call['args']['timeout'] ?? 0 ) );
check( 'inskicket sparas och mailas', 1 === count( $GLOBALS['__posts'] ) && 1 === count( $GLOBALS['__mail'] ) );

xf_ts_reset();
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'cf-turnstile-response' => 'giltig-token' ] ) ) );
check( 'widgetens eget fältnamn cf-turnstile-response tas också emot', 200 === $res->status && 'giltig-token' === ( $GLOBALS['__remote_calls'][0]['args']['body']['response'] ?? '' ) );

xf_ts_reset( [ 'code' => 200, 'body' => '{"success":false,"error-codes":["invalid-input-response"]}' ] );
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'fel-token' ] ) ) );
check( 'ogiltig token svarar 403 turnstile', 403 === $res->status && 'turnstile' === ( $res->data['code'] ?? '' ) );
check( 'och ingenting sparas', [] === $GLOBALS['__posts'] && [] === $GLOBALS['__mail'] );
check( 'en vanlig ogiltig token är ingen driftvarning', false === get_transient( 'relativt_form_turnstile_issue' ) );

add_filter( 'relativt_form_client_ip', static fn() => '198.51.100.7' );
xf_ts_reset();
$engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
check( 'remoteip går genom relativt_form_client_ip', '198.51.100.7' === ( $GLOBALS['__remote_calls'][0]['args']['body']['remoteip'] ?? '' ) );
remove_all_filters( 'relativt_form_client_ip' );

/*
 * Ordningen. En Turnstile-token kan bara verifieras en gång. Spärrarna som
 * JS tyst gör om efter (toofast, nonce) MÅSTE därför slå till innan
 * siteverify, annars bränns token och omsändningen faller på Turnstile.
 */
xf_ts_reset();
$fast_ts = time() - 1;
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token', 'ts' => $fast_ts, 'sig' => call( $engine, 'sign', [ '12|' . $fast_ts ] ) ] ) ) );
check( 'toofast svarar 425 …', 425 === $res->status && 'toofast' === ( $res->data['code'] ?? '' ) );
check( '… utan att anropa siteverify (token bränns inte)', [] === $GLOBALS['__remote_calls'] );

xf_ts_reset();
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token', 'nonce' => 'fel' ] ) ) );
check( 'utgången nonce anropar inte heller siteverify', 403 === $res->status && 'nonce' === ( $res->data['code'] ?? '' ) && [] === $GLOBALS['__remote_calls'] );

xf_ts_reset();
$GLOBALS['__transients'][ 'xf_rl_' . md5( '203.0.113.9' ) ] = 5;
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
check( 'frekvensspärren slår till före siteverify', 429 === $res->status && [] === $GLOBALS['__remote_calls'] );

xf_ts_reset();
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token', 'xf_website' => 'spam' ] ) ) );
check( 'honungsfällan slår till före siteverify', 200 === $res->status && [] === $GLOBALS['__remote_calls'] && [] === $GLOBALS['__posts'] );

xf_ts_reset();
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token', 'fields' => [ 'jagar' => 'foretag' ] ] ) ) );
check( 'valideringen kommer efter Turnstile (token är förbrukad vid 422)', 422 === $res->status && 1 === count( $GLOBALS['__remote_calls'] ) );

/*
 * Fail open: Cloudflare går inte att nå. Inskicket ska gå igenom, felet
 * loggas och syns som admin-notis tills en verifiering lyckas igen.
 */
$log = tempnam( sys_get_temp_dir(), 'xf-log' );
$old_log = ini_set( 'error_log', $log );

xf_ts_reset( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
check( 'siteverify nere: inskicket släpps igenom (fail open)', 200 === $res->status && 1 === count( $GLOBALS['__posts'] ) );
check( 'och felet loggas', str_contains( (string) file_get_contents( $log ), 'Turnstile siteverify kunde inte nås (cURL error 28' ) );
$issue = get_transient( 'relativt_form_turnstile_issue' );
check( 'och sparas för admin-notisen', 'unreachable' === ( $issue['kind'] ?? '' ) );

$GLOBALS['__remote'] = [ 'code' => 502, 'body' => '<html>Bad gateway</html>' ];
$GLOBALS['__posts']  = [];
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
check( 'svar som inte är JSON behandlas likadant', 200 === $res->status && str_contains( (string) file_get_contents( $log ), 'HTTP 502 utan giltigt JSON-svar' ) );

$GLOBALS['__remote'] = [ 'code' => 200, 'body' => '{"success":true}' ];
$GLOBALS['__transients'][ 'relativt_form_turnstile_issue' ] = [ 'kind' => 'unreachable', 'time' => time() ];
$engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
check( 'nästa lyckade verifiering tar bort varningen', false === get_transient( 'relativt_form_turnstile_issue' ) );

xf_ts_reset( [ 'code' => 200, 'body' => '{"success":false,"error-codes":["invalid-input-secret"]}' ] );
$res = $engine->rest_submit( new WP_REST_Request( xf_submit_body( [ 'turnstile' => 'giltig-token' ] ) ) );
check( 'fel secret: Cloudflares uttryckliga nej gäller (403)', 403 === $res->status && 'turnstile' === ( $res->data['code'] ?? '' ) );
check( 'men felinställningen loggas och syns i admin', str_contains( (string) file_get_contents( $log ), 'avvisade Turnstile-secret' ) && 'secret' === ( get_transient( 'relativt_form_turnstile_issue' )['kind'] ?? '' ) );

ini_set( 'error_log', (string) $old_log );
@unlink( $log );

echo "\nTurnstile: admin-notiser\n";

$GLOBALS['__can'] = true;
$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => '' ];
$GLOBALS['__screen'] = (object) [ 'base' => 'post', 'post_type' => 'relativt_form' ];
$_GET['post'] = 12;
xf_ts_reset();

ob_start();
$engine->turnstile_notice();
$notice = (string) ob_get_clean();
check( 'formuläret varnar när en nyckel saknas', str_contains( $notice, 'Kräv Turnstile är påslaget, men secret key saknas' ) );
check( 'och säger att formuläret ändå skickas', str_contains( $notice, 'skickas utan Turnstile' ) );

$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => 'hemlig' ];
ob_start();
$engine->turnstile_notice();
check( 'ingen varning när allt är på plats', '' === (string) ob_get_clean() );

$GLOBALS['__screen'] = (object) [ 'base' => 'edit', 'post_type' => 'relativt_entry' ];
$GLOBALS['__transients']['relativt_form_turnstile_issue'] = [ 'kind' => 'unreachable', 'time' => time() ];
ob_start();
$engine->turnstile_notice();
check( 'driftvarningen syns i inskicksvyn', str_contains( (string) ob_get_clean(), 'Cloudflare Turnstile gick inte att nå' ) );

$GLOBALS['__screen'] = (object) [ 'base' => 'dashboard', 'post_type' => '' ];
ob_start();
$engine->turnstile_notice();
check( 'men inte på andra sidor i wp-admin', '' === (string) ob_get_clean() );

unset( $_GET['post'], $GLOBALS['__screen'] );
$GLOBALS['__can']     = false;
$GLOBALS['__form']    = xf_test_form();
$GLOBALS['__options'] = [];
xf_ts_reset();

echo "\nStatistik: uppstart\n";

/*
 * 1.5.1: statistikfilen läses bara in i wp-admin. Frontend-fallet är den här
 * processen (riggen laddas med is_admin() falskt). Admin-fallet provas i en
 * egen PHP-process, eftersom uppstarten bara kan köras en gång per process.
 */
check( 'statistikfilen läses inte in för besökare', ! class_exists( 'Relativt_Form_Stats', false ) );
$admin_boot = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
	'$GLOBALS["__is_admin"] = true; require ' . var_export( __DIR__ . '/harness.php', true ) . ';'
	. ' $s = class_exists( "Relativt_Form_Stats", false ) ? Relativt_Form_Stats::instance() : null;'
	. ' echo $s && in_array( [ $s, "add_page" ], array_column( $GLOBALS["__hooks"], "callback" ), true ) ? "startad" : "saknas";'
) );
check( 'men startas i wp-admin och hänger på admin_menu', 'startad' === trim( $admin_boot ), trim( $admin_boot ) );

// Resten av testerna kör klassen direkt.
require_once __DIR__ . '/../includes/class-relativt-form-stats.php';
$stats = Relativt_Form_Stats::instance();
check( 'och följer med i zip-filen', str_contains( (string) file_get_contents( __DIR__ . '/../build/build.php' ), "'includes/class-relativt-form-stats.php'" ) );

echo "\nStatistik: kanaler\n";

$ch = static fn( array $utm = [], string $ref = '' ) => Relativt_Form_Stats::channel( [ 'utm' => $utm, 'referrer' => $ref ], 'exempel.se' );
check( 'gclid är betald sök', 'paid_search' === $ch( [ 'gclid' => 'abc' ] ) );
check( 'google / cpc är betald sök', 'paid_search' === $ch( [ 'utm_source' => 'google', 'utm_medium' => 'cpc' ] ) );
check( 'linkedin / paid_social är betald social', 'paid_social' === $ch( [ 'utm_source' => 'linkedin', 'utm_medium' => 'paid_social' ] ) );
check( 'linkedin / cpc är betald social, inte sök', 'paid_social' === $ch( [ 'utm_source' => 'LinkedIn', 'utm_medium' => 'CPC' ] ) );
check( 'display utan källa är övriga annonser', 'paid_other' === $ch( [ 'utm_source' => 'adform', 'utm_medium' => 'display' ] ) );
check( 'fbclid utan annonsparametrar är social', 'social' === $ch( [ 'fbclid' => 'xyz' ] ) );
check( 'utm_medium social är social', 'social' === $ch( [ 'utm_source' => 'nyhetsinlagg', 'utm_medium' => 'social' ] ) );
check( 'hänvisning från lnkd.in är social', 'social' === $ch( [], 'https://lnkd.in/abc' ) );
check( 'nyhetsbrev via utm_medium email', 'email' === $ch( [ 'utm_source' => 'kundbrev', 'utm_medium' => 'email' ] ) );
check( 'mailchimp som källa är e-post', 'email' === $ch( [ 'utm_source' => 'mailchimp' ] ) );
check( 'webbmail som hänvisare är e-post', 'email' === $ch( [], 'https://mail.google.com/' ) );
check( 'chatgpt.com som utm_source är AI', 'ai' === $ch( [ 'utm_source' => 'chatgpt.com' ] ) );
check( 'perplexity som hänvisare är AI', 'ai' === $ch( [], 'https://www.perplexity.ai/search?q=x' ) );
check( 'gemini.google.com är AI, inte sök', 'ai' === $ch( [], 'https://gemini.google.com/app' ) );
check( 'google.se som hänvisare är organisk sök', 'search' === $ch( [], 'https://www.google.se/' ) );
check( 'google.co.uk också', 'search' === $ch( [], 'https://www.google.co.uk/' ) );
check( 'bing som hänvisare är organisk sök', 'search' === $ch( [], 'https://www.bing.com/search?q=x' ) );
check( 'utm_medium organic är organisk sök', 'search' === $ch( [ 'utm_source' => 'google', 'utm_medium' => 'organic' ] ) );
check( 'okänd taggad kampanj är övriga kampanjer', 'campaign' === $ch( [ 'utm_source' => 'qr', 'utm_medium' => 'print' ] ) );
check( 'extern sida utan taggar är hänvisning', 'referral' === $ch( [], 'https://branschforum.se/artikel' ) );
check( 'den egna sajten som hänvisare räknas som direkt', 'direct' === $ch( [], 'https://www.exempel.se/om-oss/' ) );
check( 'ingenting alls är direkt', 'direct' === $ch() );
check( 'trasig metadata ger direkt i stället för fel', 'direct' === Relativt_Form_Stats::channel( [ 'utm' => 'trasig' ] ) );
check( 'facebook_ads / cpc är betald social', 'paid_social' === $ch( [ 'utm_source' => 'facebook_ads', 'utm_medium' => 'cpc' ] ) );
check( '"LinkedIn Ads" / cpc är betald social', 'paid_social' === $ch( [ 'utm_source' => 'LinkedIn Ads', 'utm_medium' => 'cpc' ] ) );
check( 'paidsearch utan avgränsare är betald sök', 'paid_search' === $ch( [ 'utm_source' => 'bing', 'utm_medium' => 'paidsearch' ] ) );
check( 'docs.google.com är hänvisning, inte sök', 'referral' === $ch( [], 'https://docs.google.com/document/d/1' ) );
check( 'sajtens egen underdomän är intern', 'direct' === $ch( [], 'https://shop.exempel.se/kassa' ) );
check( 'men en domän som bara slutar likadant är det inte', 'referral' === $ch( [], 'https://inteexempel.se/' ) );

add_filter( 'relativt_form_stats_channel', static fn( $c, $meta ) => 'nyhetsbrevet' === ( $meta['utm']['utm_source'] ?? '' ) ? 'email' : $c, 10, 2 );
check( 'filtret kan klassa om', 'email' === $ch( [ 'utm_source' => 'nyhetsbrevet' ] ) );
add_filter( 'relativt_form_stats_channel', static fn( $c ) => 'pahittad' );
check( 'en okänd kanal från filtret ignoreras', 'campaign' === $ch( [ 'utm_source' => 'qr' ] ) );
remove_all_filters( 'relativt_form_stats_channel' );

echo "\nStatistik: enhet, webbläsare och sidor\n";

$ua = [
	'iphone'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
	'android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
	'tab'     => 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
	'edge'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0',
	'mac'     => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
	'firefox' => 'Mozilla/5.0 (Windows NT 10.0; rv:131.0) Gecko/20100101 Firefox/131.0',
	'li'      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [LinkedInApp]/9.30',
];
check( 'iPhone är mobil', 'Mobil' === Relativt_Form_Stats::device( $ua['iphone'] ) );
check( 'Android-telefon är mobil', 'Mobil' === Relativt_Form_Stats::device( $ua['android'] ) );
check( 'Android utan Mobile är surfplatta', 'Surfplatta' === Relativt_Form_Stats::device( $ua['tab'] ) );
check( 'Windows är dator', 'Dator' === Relativt_Form_Stats::device( $ua['edge'] ) );
check( 'tom user agent är okänd', 'Okänd' === Relativt_Form_Stats::device( '' ) );
check( 'Edge känns igen före Chrome', 'Edge' === Relativt_Form_Stats::browser( $ua['edge'] ) );
check( 'Chrome', 'Chrome' === Relativt_Form_Stats::browser( $ua['android'] ) );
check( 'Safari', 'Safari' === Relativt_Form_Stats::browser( $ua['mac'] ) );
check( 'Firefox', 'Firefox' === Relativt_Form_Stats::browser( $ua['firefox'] ) );
check( 'LinkedIns appwebbläsare räknas för sig', 'LinkedIn-appen' === Relativt_Form_Stats::browser( $ua['li'] ) );

check( 'egen sida blir sökväg utan frågesträng', '/kontakt' === Relativt_Form_Stats::page_key( 'https://www.exempel.se/kontakt/?utm_source=x', 'exempel.se' ) );
check( 'startsidan blir /', '/' === Relativt_Form_Stats::page_key( 'https://exempel.se/', 'exempel.se' ) );
check( 'annan värd behåller värdnamnet', 'app.exempel.com/kontakt' === Relativt_Form_Stats::page_key( 'https://app.exempel.com/kontakt', 'exempel.se' ) );
check( 'tom url ger tom nyckel', '' === Relativt_Form_Stats::page_key( '', 'exempel.se' ) );

echo "\nStatistik: perioder\n";

$r30 = Relativt_Form_Stats::resolve_range( [], '2026-10-02' );
check( 'standard är senaste 30 dagarna', '30' === $r30['key'] && '2026-09-03' === $r30['from'] && '2026-10-02' === $r30['to'] && 30 === $r30['days'] );
check( 'jämförs med de 30 dagarna före', '2026-08-04' === $r30['prev_from'] && '2026-09-02' === $r30['prev_to'], $r30['prev_from'] . '–' . $r30['prev_to'] );
check( '30 dagar visas per dag', 'day' === $r30['granularity'] );
$r90 = Relativt_Form_Stats::resolve_range( [ 'period' => '90' ], '2026-10-02' );
check( '90 dagar visas per vecka', 'week' === $r90['granularity'] );
$ytd = Relativt_Form_Stats::resolve_range( [ 'period' => 'ytd' ], '2026-10-02' );
check( 'i år börjar 1 januari', '2026-01-01' === $ytd['from'] && 'month' === $ytd['granularity'] );
check( 'och jämförs med samma datum i fjol', '2025-01-01' === $ytd['prev_from'] && '2025-10-02' === $ytd['prev_to'] );
$custom = Relativt_Form_Stats::resolve_range( [ 'period' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-01' ], '2026-10-02' );
check( 'eget intervall åt fel håll vänds', '2026-09-01' === $custom['from'] && '2026-09-30' === $custom['to'] );
check( 'och får en läsbar etikett', '1 sep – 30 sep 2026' === $custom['label'], $custom['label'] );
$bad = Relativt_Form_Stats::resolve_range( [ 'period' => 'custom', 'from' => '2026-02-30', 'to' => 'igår' ], '2026-10-02' );
check( 'ogiltigt datum faller tillbaka till 30 dagar', '30' === $bad['key'] );
$fake = Relativt_Form_Stats::resolve_range( [ 'period' => '<script>' ], '2026-10-02' );
check( 'okänt periodval faller tillbaka till 30 dagar', '30' === $fake['key'] );
$all = Relativt_Form_Stats::resolve_range( [ 'period' => 'all' ], '2026-10-02', '2025-03-15' );
check( 'alla inskick börjar vid det äldsta', '2025-03-15' === $all['from'] && '' === $all['prev_from'] );

$weeks = Relativt_Form_Stats::buckets( '2026-09-01', '2026-09-30', 'week' );
check( 'veckostaplarna är ISO-veckor', array_keys( $weeks ) === [ '2026-W36', '2026-W37', '2026-W38', '2026-W39', '2026-W40' ], implode( ',', array_keys( $weeks ) ) );
check( 'och börjar på noll', 0 === array_sum( array_column( $weeks, 'count' ) ) );
check( 'dagstaplar har svensk etikett', '1 sep' === Relativt_Form_Stats::buckets( '2026-09-01', '2026-09-01', 'day' )['2026-09-01']['label'] );
check( 'årsskiftet hamnar i rätt ISO-vecka', '2026-W53' === Relativt_Form_Stats::bucket( '2027-01-01', 'week' ) );

$huge = Relativt_Form_Stats::resolve_range( [ 'period' => 'custom', 'from' => '1000-01-01', 'to' => '9999-12-31' ], '2026-10-02' );
check( 'orimligt intervall slutar i dag', '2026-10-02' === $huge['to'] );
check( 'och kapas till tio år', $huge['days'] <= 3653 && '2016-10-02' <= $huge['from'], $huge['from'] );
check( 'jämförelseperioden hamnar aldrig före år 0', (int) substr( $huge['prev_from'], 0, 4 ) > 1990, $huge['prev_from'] );
$future = Relativt_Form_Stats::resolve_range( [ 'period' => 'custom', 'from' => '2030-01-01', 'to' => '2030-02-01' ], '2026-10-02' );
check( 'intervall i framtiden krymper till i dag', '2026-10-02' === $future['from'] && '2026-10-02' === $future['to'] );
$old = Relativt_Form_Stats::resolve_range( [ 'period' => 'all' ], '2026-10-02', '1970-01-01' );
check( 'alla inskick börjar tidigast år 2000', '2016-10-02' <= $old['from'] && 'month' === $old['granularity'] );
$leap = Relativt_Form_Stats::resolve_range( [ 'period' => 'ytd' ], '2028-02-29' );
check( 'i år på skottdagen jämförs med 28 februari', '2027-02-28' === $leap['prev_to'], $leap['prev_to'] );
$prev_tz = date_default_timezone_get();
date_default_timezone_set( 'America/Santiago' );
check( 'en annan PHP-tidszon tappar ingen dag', 30 === count( Relativt_Form_Stats::buckets( '2026-09-01', '2026-09-30', 'day' ) ) );
date_default_timezone_set( $prev_tz );

$axis = Relativt_Form_Stats::column_chart( Relativt_Form_Stats::buckets( '2026-09-01', '2026-09-03', 'day' ) + [ 'x' => [ 'label' => 'x', 'title' => 'x', 'count' => 9, 'days' => 1, 'full' => 1 ] ], 'day' );
preg_match_all( '/text-anchor="end">([^<]+)</', $axis, $ticks );
check( 'axeln visar jämna heltal', [ '0', '3', '6', '9', '12' ] === $ticks[1], implode( ',', $ticks[1] ) );

echo "\nStatistik: sammanställning\n";

$rows = [
	[ 'date' => '2026-09-28 09:15:00', 'form' => 12, 'mail_ok' => '1', 'meta' => [ 'page' => 'https://exempel.se/kontakt/', 'landing' => 'https://exempel.se/tjanster/', 'referrer' => 'https://www.google.se/', 'ua' => $ua['edge'], 'utm' => [] ] ],
	[ 'date' => '2026-09-28 09:40:00', 'form' => 12, 'mail_ok' => '0', 'meta' => [ 'page' => 'https://exempel.se/kontakt/', 'landing' => 'https://exempel.se/kampanj/?utm_source=LinkedIn', 'referrer' => '', 'ua' => $ua['iphone'], 'utm' => [ 'utm_source' => 'LinkedIn', 'utm_medium' => 'paid_social', 'utm_campaign' => 'Höst26' ] ] ],
	[ 'date' => '2026-09-30 14:05:00', 'form' => 99, 'mail_ok' => '', 'meta' => [ 'page' => 'https://exempel.se/', 'landing' => 'https://exempel.se/', 'referrer' => 'https://exempel.se/om/', 'ua' => '', 'utm' => [ 'gclid' => 'abc' ] ] ],
	[ 'date' => '2026-10-01 23:59:00', 'form' => 12, 'mail_ok' => '1', 'meta' => 'trasig' ],
	[ 'date' => 'inget datum', 'form' => 12, 'meta' => [] ],
];
$range = Relativt_Form_Stats::resolve_range( [ 'period' => '7' ], '2026-10-02' );
$agg   = Relativt_Form_Stats::aggregate( $rows, $range, 'exempel.se' );
check( 'rader utan datum hoppas över', 4 === $agg['total'] );
check( 'staplarna räknas per dag', 2 === $agg['series']['2026-09-28']['count'] && 1 === $agg['series']['2026-10-01']['count'] && 0 === $agg['series']['2026-09-29']['count'] );
check( 'alla dagar i perioden finns med', 7 === count( $agg['series'] ) );
check( 'per formulär', [ '12' => 3, '99' => 1 ] === $agg['forms'] );
check( 'kanalerna räknas', 1 === ( $agg['channels']['search'] ?? 0 ) && 1 === ( $agg['channels']['paid_social'] ?? 0 ) && 1 === ( $agg['channels']['paid_search'] ?? 0 ) && 1 === ( $agg['channels']['direct'] ?? 0 ) );
check( 'utm-värden slås ihop oavsett versaler', [ 'linkedin' => 1 ] === $agg['utm_source'] && [ 'höst26' => 1 ] === $agg['utm_campaign'] );
check( 'kampanjdata räknar även klick-id', 2 === $agg['with_campaign'] );
check( 'UTM-taggade och bara klick-id räknas för sig', 1 === $agg['with_utm'] && 1 === $agg['click_only'] );
check( 'klick-id:na räknas per typ', [ 'gclid' => 1 ] === $agg['clicks'] );
check( 'egna sajten räknas inte som hänvisare', [ 'google.se' => 1 ] === $agg['referrers'] );
check( 'landningssidor grupperas utan frågesträng', 1 === ( $agg['landing']['/kampanj'] ?? 0 ) && 1 === ( $agg['landing']['/'] ?? 0 ) );
check( 'skickat från grupperas', 2 === ( $agg['pages']['/kontakt'] ?? 0 ) );
check( 'enheter räknas', 1 === ( $agg['devices']['Mobil'] ?? 0 ) && 1 === ( $agg['devices']['Dator'] ?? 0 ) && 2 === ( $agg['devices']['Okänd'] ?? 0 ) );
check( 'misslyckade mail räknas, tom status räknas inte', 1 === $agg['mail_failed'] );
check( 'veckodag och timme', 2 === ( $agg['heat'][1][9] ?? 0 ) && 1 === ( $agg['heat'][4][23] ?? 0 ) && 1 === ( $agg['heat'][3][14] ?? 0 ) );
check( 'topplistorna sorteras fallande', 12 === array_key_first( $agg['forms'] ) );

echo "\nStatistik: databas och cache\n";

$db = new Xf_Fake_Wpdb();
for ( $i = 1; $i <= 1203; $i++ ) {
	$db->entries[ 5000 + $i ] = [
		'date' => 0 === $i % 2 ? '2026-09-20 10:00:00' : '2026-09-05 16:30:00',
		'meta' => [
			'_xf_form_id' => 0 === $i % 4 ? 14 : 12,
			'_xf_mail_ok' => '1',
			'_xf_values'  => [ [ 'key' => 'epost', 'value' => 'hemlig@exempel.se' ] ],
			'_xf_email'   => 'hemlig@exempel.se',
			'_xf_meta'    => [ 'ip' => '203.0.113.9', 'ua' => $ua['iphone'], 'utm' => [ 'utm_source' => 0 === $i % 3 ? 'nyhetsbrev' : '' ] ],
		],
	];
}
// Utanför perioden – ska varken räknas eller läsas.
$db->entries[4000] = [ 'date' => '2026-08-01 12:00:00', 'meta' => [ '_xf_form_id' => 12, '_xf_meta' => [] ] ];
$db->entries[4001] = [ 'date' => '2026-08-20 12:00:00', 'meta' => [ '_xf_form_id' => 12, '_xf_meta' => [] ] ];
$GLOBALS['wpdb']         = $db;
$GLOBALS['__transients'] = [];

$rows_seen = iterator_to_array( call( $stats, 'rows', [ '2026-09-03', '2026-10-02', 0 ] ), false );
check( 'alla inskick i perioden läses över flera batcher', 1203 === count( $rows_seen ), (string) count( $rows_seen ) );
$batches = array_values( array_filter( $db->queries, static fn( $q ) => str_contains( $q, 'SELECT p.ID, p.post_date' ) ) );
check( 'i batcher om 500 med id som bokmärke, inte OFFSET', 3 === count( $batches ) && str_contains( $batches[1], 'p.ID > 5500' ) && ! str_contains( implode( ' ', $batches ), 'OFFSET' ) );
check( 'bara publicerade inskick i perioden', str_contains( $batches[0], "p.post_status = 'publish'" ) && str_contains( $batches[0], "p.post_date >= '2026-09-03 00:00:00' AND p.post_date <= '2026-10-02 23:59:59'" ) );
$metaq = array_values( array_filter( $db->queries, static fn( $q ) => str_contains( $q, 'post_id IN' ) ) );
check( 'metadatan hämtas med en fråga per batch', 3 === count( $metaq ) );
check( 'och bara de tre nycklar statistiken behöver', str_contains( $metaq[0], "meta_key IN ('_xf_form_id', '_xf_meta', '_xf_mail_ok')" ) );
check( 'serialiserad metadata packas upp', 'Mobil' === Relativt_Form_Stats::device( (string) ( $rows_seen[0]['meta']['ua'] ?? '' ) ) && 12 === $rows_seen[0]['form'] );
check( 'IP-adressen släpps vid inläsningen', ! array_key_exists( 'ip', $rows_seen[0]['meta'] ) );
check( 'fältvärden och e-post följer aldrig med', ! str_contains( serialize( $rows_seen ), 'hemlig@' ) );

$db->queries = [];
$rep = $stats->report( $r30, 0 );
check( 'rapporten räknar perioden', 1203 === $rep['total'] && 1203 === array_sum( array_column( $rep['series'], 'count' ) ) );
check( 'jämförelseperioden räknas med COUNT', 1 === $rep['prev'] && [] !== array_filter( $db->queries, static fn( $q ) => str_starts_with( $q, 'SELECT COUNT(*) FROM wp_posts p' ) ) );

$db->queries = [];
$again = $stats->report( $r30, 0 );
check( 'andra visningen kommer ur cachen', 1 === count( $db->queries ) && 1203 === $again['total'] );
check( 'fingeravtrycket frågar bara efter publicerade inskick', str_contains( $db->queries[0], "post_type = 'relativt_entry' AND post_status = 'publish'" ) );

$db->entries[9999] = [ 'date' => '2026-10-02 08:00:00', 'meta' => [ '_xf_form_id' => 12, '_xf_meta' => [] ] ];
check( 'ett nytt inskick bryter cachen direkt', 1204 === $stats->report( $r30, 0 )['total'] );
$db->mail_rev++;
$db->queries = [];
$stats->report( $r30, 0 );
check( 'mailstatus som skrivs efteråt bryter cachen', count( $db->queries ) > 1 );
unset( $db->entries[5001], $db->entries[5003] );
check( 'gallrade inskick bryter cachen', 1202 === $stats->report( $r30, 0 )['total'] );

$only = $stats->report( $r30, 14 );
$joined = array_filter( $db->queries, static fn( $q ) => str_contains( $q, "f.meta_key = '_xf_form_id' AND f.meta_value = '14'" ) );
check( 'formulärfiltret joinar på _xf_form_id', 300 === $only['total'] && [] !== $joined, (string) $only['total'] );
check( 'äldsta inskicket hittas för "alla"', '2026-08-01' === call( $stats, 'earliest', [ 0 ] ) );

echo "\nStatistik: sidan\n";

$rows[1]['meta']['utm']['utm_campaign'] = '<script>alert(1)</script>';
$rows[1]['meta']['referrer']            = 'https://<img src=x onerror=alert(1)>.se/';
$agg         = Relativt_Form_Stats::aggregate( $rows, $range, 'exempel.se' );
$agg['prev'] = 2;
$html        = $stats->render_report( $agg, $range, 0 );
check( 'nyckeltalen visas', str_contains( $html, 'xf-tile-value">4<' ) );
check( 'ökningen mot föregående period', str_contains( $html, '+100 %' ) && str_contains( $html, '▲' ) );
check( 'misslyckade mail länkar till filtrerad lista', str_contains( $html, 'xf_mail=failed' ) );
check( 'kanaler med svenska namn', str_contains( $html, 'Betald social' ) && str_contains( $html, 'Organisk sök' ) );
check( 'formulär som inte finns kvar namnges', str_contains( $html, 'Borttaget formulär (#99)' ) );
check( 'diagrammet har tabellvy', str_contains( $html, '<svg class="xf-chart"' ) && str_contains( $html, 'Visa som tabell' ) );
check( 'utm-värden escapas', ! str_contains( $html, '<script>alert' ) && str_contains( $html, '&lt;script&gt;' ) );
check( 'inga rå taggar från hänvisaren', ! str_contains( $html, '<img src=x' ) );
check( 'värmekartan har sju rader', 7 === substr_count( $html, '<tr><th scope="row">' ) );
check( 'kampanjraden delar upp UTM och klick-id', str_contains( $html, '2 av 4 inskick har kampanjdata – 1 med UTM-taggar och 1 med bara annonsklick-id (gclid/fbclid).' ) );
check( 'annonsklicken har ett eget kort', str_contains( $html, '<h3>Annonsklick</h3>' ) && str_contains( $html, 'Google Ads (gclid)' ) );

/*
 * 1.5.2: skärmdumpen som visade felet – 38 av 100 med kampanjdata men tre
 * tomma UTM-kort, eftersom alla 38 bara hade gclid/fbclid.
 */
$clicks_only = [
	[ 'date' => '2026-09-28 10:00:00', 'form' => 12, 'meta' => [ 'utm' => [ 'gclid' => 'abc' ] ] ],
	[ 'date' => '2026-09-29 10:00:00', 'form' => 12, 'meta' => [ 'utm' => [ 'fbclid' => 'xyz' ] ] ],
	[ 'date' => '2026-09-30 10:00:00', 'form' => 12, 'meta' => [ 'utm' => [] ] ],
];
$co         = Relativt_Form_Stats::aggregate( $clicks_only, $range, 'exempel.se' );
$co['prev'] = null;
$co_html    = $stats->render_report( $co, $range, 0 );
check( 'bara klick-id: raden säger det', str_contains( $co_html, '2 av 3 inskick har kampanjdata – 2 med bara annonsklick-id' ) && ! str_contains( $co_html, 'med UTM-taggar' ) );
check( 'och de tomma UTM-korten förklarar varför', 3 === substr_count( $co_html, 'Inga UTM-taggade inskick. 2 inskick har bara annonsklick-id' ) );
check( 'medan annonsklicken visas', str_contains( $co_html, 'Facebook/Instagram (fbclid)' ) );
$none = Relativt_Form_Stats::aggregate( [ $clicks_only[2] ], $range );
$none['prev'] = null;
$none_html    = $stats->render_report( $none, $range, 0 );
check( 'utan kampanjdata alls blir det vanliga tomtexten', str_contains( $none_html, 'Inget av 1 inskick har kampanjdata.' ) && str_contains( $none_html, 'Inga annonsklick under perioden.' ) && ! str_contains( $none_html, 'Inga UTM-taggade' ) );

check( 'per formulär döljs när ett formulär är valt', ! str_contains( $stats->render_report( $agg, $range, 12 ), 'Per formulär' ) );

$empty = Relativt_Form_Stats::aggregate( [], $range );
$empty['prev'] = 0;
check( 'tom period säger det i klartext', str_contains( $stats->render_report( $empty, $range, 0 ), 'Inga sparade inskick under senaste 7 dagarna' ) );

$GLOBALS['__form']['xf_store']     = 0;
$coverage = call( $stats, 'coverage_notice', [ $r30, 0, [ new WP_Post( 12, 'Offert' ) ], '2026-10-02' ] );
check( 'formulär som inte sparar inskick pekas ut', str_contains( $coverage, 'räknas därför inte: <strong>Offert</strong>' ) );
$GLOBALS['__form']['xf_store']     = 1;
$GLOBALS['__form']['xf_retention'] = 14;
$coverage = call( $stats, 'coverage_notice', [ $r30, 0, [ new WP_Post( 12, 'Offert' ) ], '2026-10-02' ] );
check( 'gallring som klipper perioden pekas ut', str_contains( $coverage, 'Offert (14 dagar)' ) );
$GLOBALS['__form'] = xf_test_form();
$coverage = call( $stats, 'coverage_notice', [ $r30, 0, [ new WP_Post( 12, 'Offert' ) ], '2026-10-02' ] );
check( 'ingen varning när gallringen ligger utanför perioden', ! str_contains( $coverage, 'gallringsgränsen' ) );

unset( $GLOBALS['wpdb'] );
$GLOBALS['__transients'] = [];

echo "\n" . str_repeat( '─', 50 ) . "\n";
printf( "%d godkända, %d underkända\n\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
