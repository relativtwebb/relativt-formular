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

check( 'hjälptextfältet är borttaget', null === sub_field_def( 'field_xf_f_help' ) );
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

// Kunden skriver etiketten i wp-admin istället för det tekniska värdet.
$GLOBALS['__form']['xf_fields'][5]['cond_value'] = 'Företag';
$label_cond = $engine->validate( 12, [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'a@b.se', 'behov' => 'Interim' ] );
check( 'villkor skrivet med den synliga etiketten fungerar', value_of( $label_cond['values'], 'behov' ) === 'Interim' );
$GLOBALS['__form'] = xf_test_form();

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
check( 'tack-rutan är dold från start', str_contains( $html, 'class="xf-thanks" role="status" aria-live="polite" hidden' ) );

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

echo "\nExport och import\n";

$port    = Relativt_Form_Portability::instance();
$payload = $port->build_payload( 12 );

check( 'exporten märks som en formulärexport', ( $payload['_type'] ?? '' ) === 'relativt-formular' );
check( 'schemaversion följer med', ( $payload['_schema'] ?? 0 ) === Relativt_Form_Portability::SCHEMA );
check( 'titeln följer med', ( $payload['title'] ?? '' ) === 'Kontaktformulär' );
check( 'fälten följer med', count( $payload['settings']['xf_fields'] ?? [] ) === 8 );
check( 'mottagarreglerna följer med', count( $payload['settings']['xf_rules'] ?? [] ) === 1 );
check( 'mottagaradressen följer med', ( $payload['settings']['xf_to'] ?? '' ) === 'info@exempel.se' );

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

echo "\n" . str_repeat( '─', 50 ) . "\n";
printf( "%d godkända, %d underkända\n\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
