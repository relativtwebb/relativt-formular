<?php
/**
 * Genererar demo-i18n-sv.html och demo-i18n-en.html för webbläsartesterna av
 * flerspråkiga sajter (1.15.0): samma sajt i två språkversioner.
 *
 * Ingenting på sidorna är handskrivet:
 *
 *  - Markupen kommer från den riktiga renderaren, körd med sidans språk som
 *    aktuellt språk i Polylang (tests/polylang-mock.php).
 *  - window.relativtFormConfig är exakt det register_assets() skriver ut,
 *    med lang och de översatta texterna.
 *  - Serverns svar kommer från den riktiga rest_submit(). Varje spärr körs en
 *    gång per språkuppgift ('' = ingen, sv, en) och svaren bäddas in i sidan.
 *    Den mockade servern väljer svar efter det lang som skriptet FAKTISKT
 *    skickar – glömmer skriptet språket får den engelska sidan svenska fel,
 *    precis som på en riktig sajt, och testerna faller.
 *
 * Formulären saknar egna texter, så att motorns fallbacks och sajtens
 * standardvärden syns – det är de som översätts. På en riktig sajt har varje
 * språk sitt eget formulär med egna etiketter (se docs/flersprakiga-sajter.md);
 * här delar sidorna fält, eftersom testerna gäller motorns texter.
 *
 * Körs: php tests/build-i18n-demo.php
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/polylang-mock.php';

$GLOBALS['__pll']['translations']['en'] = [
	'Fyll i detta fält.'                                   => 'This field is required.',
	'Kontrollera e-postadressen.'                          => 'Please check the email address.',
	'Land och landsnummer'                                 => 'Country and dialling code',
	'Det gick lite för snabbt. Vänta en sekund och försök igen.' => 'That was a little too fast. Wait a second and try again.',
	'Du har skickat flera meddelanden på kort tid. Vänta en stund och försök igen.' => 'You have sent several messages in a short time. Please wait a while and try again.',
	'Något gick fel. Försök igen om en liten stund.'       => 'Something went wrong. Please try again in a moment.',
	'Steg {current} av {total}'                            => 'Step {current} of {total}',
	'Skicka'                                               => 'Send',
	'Skickar…'                                             => 'Sending…',
	'Nästa'                                                => 'Next',
	'Tillbaka'                                             => 'Back',
	'Tack för din förfrågan!'                              => 'Thanks for your enquiry!',
	'Vi hör av oss inom en arbetsdag.'                     => 'We will get back to you within one working day.',
];

/** Sajtens standardvärden – tackrutan översätts därifrån. */
$options = [
	'relativt_form_defaults' => [
		'xf_to'           => 'info@exempel.se',
		'xf_thanks_title' => 'Tack för din förfrågan!',
		'xf_thanks_text'  => 'Vi hör av oss inom en arbetsdag.',
	],
];

$base = xf_test_form();
foreach ( [ 'xf_submit_text', 'xf_sending_text', 'xf_thanks_title', 'xf_thanks_text', 'xf_error_text', 'xf_consent' ] as $key ) {
	unset( $base[ $key ] );
}
$base['xf_rules'] = [];

$simple = array_merge( $base, [
	'xf_fields' => [
		[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1, 'width' => 'half' ],
		[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1, 'width' => 'half' ],
		[ 'type' => 'tel', 'key' => 'mobil', 'label' => 'Mobil', 'phone_country' => 1 ],
	],
] );

$steps = array_merge( $base, [
	'xf_fields'    => [
		[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1 ],
		[ 'type' => 'step', 'key' => 'steg2', 'label' => '' ],
		[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1 ],
	],
	'xf_multistep' => 1,
] );

$engine = Relativt_Form::instance();
$render = new ReflectionMethod( $engine, 'render_form' );
xf_accessible( $render );
$sign = new ReflectionMethod( $engine, 'sign' );
xf_accessible( $sign );

$use = static function ( array $form ) use ( $options ): void {
	$GLOBALS['__form']       = $form;
	$GLOBALS['__options']    = $options;
	$GLOBALS['__transients'] = [];
	Relativt_Form::flush_fields_cache();
	Relativt_Form_I18n::raw_settings( true );
};

/*
 * Serverns svar. Ett REST-anrop har inget språk av sig självt; det kommer
 * bara ur parametern lang (se xf_pll_rest() i mocken).
 */
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';

$submit = static function ( string $lang, array $extra = [] ) use ( $engine, $sign ): array {
	xf_pll_rest( '' !== $lang ? $lang : null );
	$ts   = time() - 10;
	$body = array_merge( [
		'form'       => 12,
		'fields'     => [ 'namn' => 'Anna Andersson', 'epost' => 'anna@exempel.se' ],
		'nonce'      => 'giltig',
		'ts'         => $ts,
		'sig'        => $sign->invoke( $engine, '12|' . $ts ),
		'xf_website' => '',
	], '' !== $lang ? [ 'lang' => $lang ] : [], $extra );
	$res = $engine->rest_submit( new WP_REST_Request( $body ) );
	return [ 'status' => $res->status, 'data' => $res->data ];
};

$server = [];
foreach ( [ '', 'sv', 'en' ] as $lang ) {
	$use( $simple );
	$server[ $lang ]['ok']      = $submit( $lang );
	$server[ $lang ]['invalid'] = $submit( $lang, [ 'fields' => [ 'namn' => '', 'epost' => 'inte-en-adress' ] ] );

	$fast                       = time();
	$server[ $lang ]['toofast'] = $submit( $lang, [ 'ts' => $fast, 'sig' => $sign->invoke( $engine, '12|' . $fast ) ] );
	// En sekund i stället för tre, så att testet av de tysta omförsöken inte tar tio sekunder.
	$server[ $lang ]['toofast']['data']['retry_after'] = 1;

	$GLOBALS['__transients'] = [];
	for ( $i = 0; $i < 5; $i++ ) {
		$submit( $lang );
	}
	$server[ $lang ]['rate'] = $submit( $lang );
}

$css = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$js  = file_get_contents( __DIR__ . '/../assets/js/relativt-formular.js' );
$server_json = json_encode( $server, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );

foreach ( [ 'sv', 'en' ] as $lang ) {
	// En sidvisning på framsidan: språket är bestämt innan något renderas.
	xf_pll_frontend( $lang );

	$count = new ReflectionProperty( Relativt_Form::class, 'render_count' );
	xf_accessible( $count );
	$count->setValue( null, [] );

	$use( $simple );
	$simple_html = $render->invoke( $engine, 12, [], '' );
	$use( $steps );
	$steps_html = $render->invoke( $engine, 12, [], '' );

	$GLOBALS['__assets'] = [];
	$engine->register_assets();
	$config = implode( "\n", $GLOBALS['__assets']['inline:relativt-formular'] ?? [] );

	$html = <<<HTML
<!doctype html>
<html lang="$lang">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – flerspråkiga sajter ($lang)</title>
<style>
body { margin: 0; padding: 24px; font-family: -apple-system, "Segoe UI", sans-serif; }
.wrap { max-width: 720px; margin-bottom: 48px; }
</style>
<style id="plugin-css">
$css
</style>
</head>
<body>
<div class="wrap" id="i18n-simple">
$simple_html
</div>
<div class="wrap" id="i18n-steps">
$steps_html
</div>

<script>
/*
 * Mockad server. Svaren är inspelade från den riktiga rest_submit(), ett
 * per spärr och språkuppgift. Vilket som skickas tillbaka avgörs av det lang
 * skriptet skickar – ett okänt eller saknat värde ger svaren utan språk.
 * window.__mockScenario väljer spärr: ok, invalid, toofast eller rate.
 */
window.__server = $server_json;
window.__mockScenario = 'ok';
window.__mockCalls = [];
window.__tokenCalls = [];
const realFetch = window.fetch.bind(window);
window.fetch = async (url, options = {}) => {
	const href = String(url);
	if (href.includes('/token')) {
		window.__tokenCalls.push(href);
		const ts = Math.floor(Date.now() / 1000) - 60;
		return new Response(JSON.stringify({ nonce: 'test-nonce', ts, sig: 'test-sig' }), {
			status: 200, headers: { 'Content-Type': 'application/json' },
		});
	}
	if (href.includes('/submit')) {
		const body = JSON.parse(options.body || '{}');
		window.__mockCalls.push(body);
		const table = window.__server[typeof body.lang === 'string' ? body.lang : ''] ?? window.__server[''];
		const reply = table[window.__mockScenario] ?? table.ok;
		return new Response(JSON.stringify(reply.data), {
			status: reply.status, headers: { 'Content-Type': 'application/json' },
		});
	}
	return realFetch(url, options);
};
</script>
<script>
$config
</script>
<script>
$js
</script>
</body>
</html>
HTML;

	file_put_contents( __DIR__ . "/../demo-i18n-{$lang}.html", $html );
	echo "demo-i18n-{$lang}.html skriven (" . strlen( $html ) . " byte)\n";
}
