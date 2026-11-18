<?php
/**
 * Genererar demosidorna för dynamiska alternativ och sidkontext (1.16.0) –
 * med markup från den RIKTIGA renderaren, pluginets egen CSS och en mockad
 * server.
 *
 *   demo-dynamic.html        Kursformuläret på kurssidan med post-ID 82:
 *                            "Koppla inskicket till sidan" på, och ett filter
 *                            som fyller tillfällen och spärrar ett alternativ i
 *                            varje fälttyp. Dagens JS.
 *   demo-dynamic-115.html    Samma markup med JS:en från 1.15.0 – en besökare
 *                            vars webbläsare eller sidcache fortfarande har det
 *                            gamla skriptet.
 *   demo-dynamic-steps.html  Flerstegsformulär: namn och tillfälle i steg 1,
 *                            e-post i steg 2.
 *
 * Fälten i kursformuläret:
 *   namn, epost     obligatoriska
 *   tillfalle       rullista ur filtret: öppet, fullbokat (spärrat), fåtal platser
 *   resa            visas bara när tillfalle = 20270202_plats
 *   plats           radio, b spärrat
 *   niva            val-knappar, pro spärrat
 *   tillval         flerval, middag spärrat
 *
 * Körs: php tests/build-dynamic-demo.php
 */

require __DIR__ . '/harness.php';

$engine     = Relativt_Form::instance();
$reflection = new ReflectionClass( $engine );
$render     = $reflection->getMethod( 'render_form' );
xf_accessible( $render );

$GLOBALS['__public_posts'] = [ 82 => true ];
$GLOBALS['__titles']       = [ 82 => 'Test 1' ];
$GLOBALS['__queried']      = 82;

add_filter( 'relativt_form_field_choices', static function ( $list, $field, $form_id, $context ) {
	if ( 'tillfalle' === $field['key'] ) {
		return 82 === $context['post_id'] ? [
			[ 'value' => '20270202_plats', 'label' => '2–3 februari 2027 · På plats', 'disabled' => false ],
			[ 'value' => '20270301_webbaserad', 'label' => '1 mars 2027 · Distans (Fullbokat)', 'disabled' => true ],
			[ 'value' => '20270410_plats', 'label' => '10 april 2027 · På plats (Fåtal platser kvar)', 'disabled' => false ],
		] : [];
	}
	if ( in_array( $field['key'], [ 'plats', 'niva', 'tillval' ], true ) ) {
		$list[1]['disabled'] = true;
	}
	return $list;
}, 10, 4 );

$course = [
	[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1, 'width' => 'half' ],
	[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1, 'width' => 'half' ],
	[ 'type' => 'select', 'key' => 'tillfalle', 'label' => 'Tillfälle', 'placeholder' => 'Välj tillfälle', 'required' => 1 ],
	[ 'type' => 'text', 'key' => 'resa', 'label' => 'Resväg', 'cond_field' => 'tillfalle', 'cond_value' => '20270202_plats' ],
	[ 'type' => 'radio', 'key' => 'plats', 'label' => 'Plats', 'choices' => "a : A\nb : B" ],
	[ 'type' => 'buttons', 'key' => 'niva', 'label' => 'Nivå', 'choices' => "bas : Bas\npro : Pro" ],
	[ 'type' => 'checkboxes', 'key' => 'tillval', 'label' => 'Tillval', 'choices' => "lunch : Lunch\nmiddag : Middag" ],
];

$GLOBALS['__form'] = array_merge( xf_test_form(), [ 'xf_fields' => $course, 'xf_rules' => [], 'xf_post_context' => 1 ] );
Relativt_Form::flush_fields_cache();
$single = $render->invoke( $engine, 12, [], '' );

// Tillfället i steg 1, e-posten i steg 2: ett serverfel på tillfället ska ta besökaren tillbaka.
$steps = [
	$course[0],
	$course[2],
	$course[3],
	[ 'type' => 'step', 'key' => 'steg2', 'label' => 'Kontakt' ],
	$course[1],
];
$GLOBALS['__form'] = array_merge( xf_test_form(), [ 'xf_fields' => $steps, 'xf_rules' => [], 'xf_post_context' => 1, 'xf_multistep' => 1, 'xf_step_first_title' => 'Om dig' ] );
Relativt_Form::flush_fields_cache();
$multi = $render->invoke( $engine, 12, [], '' );

$css    = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$js     = file_get_contents( __DIR__ . '/../assets/js/relativt-formular.js' );
// JS:en ur 1.15.0, ordagrant (git show v1.15.0:assets/js/relativt-formular.js).
$js_115 = file_get_contents( __DIR__ . '/fixtures/relativt-formular-1.15.0.js' );

$page = static function ( string $title, string $form, string $script ) use ( $css ): string {
	return <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – {$title}</title>
<style>
body { margin: 0; padding: 24px; font-family: -apple-system, "Segoe UI", sans-serif; }
#wrap { max-width: 720px; }
</style>
<style id="plugin-css">
$css
</style>
</head>
<body>
<div id="wrap">
$form
</div>

<script>
/* Mockad server: token och inskick. Testerna läser window.__mockCalls och sätter window.__mockErrors. */
window.__mockCalls = [];
window.__mockErrors = null;
const realFetch = window.fetch.bind(window);
window.fetch = async (url, options = {}) => {
	const href = String(url);
	if (href.includes('/token')) {
		const ts = Math.floor(Date.now() / 1000) - 60;
		return new Response(JSON.stringify({ nonce: 'test-nonce', ts, sig: 'test-sig' }), {
			status: 200, headers: { 'Content-Type': 'application/json' },
		});
	}
	if (href.includes('/submit')) {
		window.__mockCalls.push(JSON.parse(options.body || '{}'));
		if (window.__mockErrors) {
			const errors = window.__mockErrors;
			window.__mockErrors = null;
			return new Response(JSON.stringify({ ok: false, errors }), {
				status: 422, headers: { 'Content-Type': 'application/json' },
			});
		}
		return new Response(JSON.stringify({ ok: true, title: 'Tack!', text: '' }), {
			status: 200, headers: { 'Content-Type': 'application/json' },
		});
	}
	return realFetch(url, options);
};
</script>
<script>
$script
</script>
</body>
</html>
HTML;
};

$out = [
	'demo-dynamic.html'       => $page( 'dynamiska alternativ', $single, $js ),
	'demo-dynamic-115.html'   => $page( 'dynamiska alternativ med JS från 1.15.0', $single, $js_115 ),
	'demo-dynamic-steps.html' => $page( 'dynamiska alternativ i flersteg', $multi, $js ),
];
foreach ( $out as $name => $html ) {
	file_put_contents( __DIR__ . '/../' . $name, $html );
	echo "{$name} skriven (" . strlen( $html ) . " byte)\n";
}
