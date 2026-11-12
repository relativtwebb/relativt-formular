<?php
/**
 * Genererar demo-multistep.html för webbläsartesterna av flerstegsformulär
 * (1.14.0) – med markup från den RIKTIGA renderaren.
 *
 * Formulären på sidan:
 *
 *   #ms-main      Fyra steg. Steg 2 ("Företaget") har ett stegvillkor
 *                 (kundtyp = företag), steg 3 ("Telefon") har bara ett
 *                 villkorsstyrt fält och hoppas över när det är dolt.
 *                 Samtyckesruta i sista steget, standardikoner och glid.
 *   #ms-custom    Egna knapptexter och ikoner, bara text som indikator,
 *                 ingen animation.
 *   #ms-turnstile Turnstile i sista steget, ingen indikator, tona.
 *   #ms-off       Stegrader men flerstegsläget avstängt – ett vanligt
 *                 formulär där stegraderna är rubriker.
 *
 * Körs: php tests/build-multistep-demo.php
 */

require __DIR__ . '/harness.php';

$engine     = Relativt_Form::instance();
$reflection = new ReflectionClass( $engine );
$render     = $reflection->getMethod( 'render_form' );
$render->setAccessible( true );

$base = xf_test_form();

$main_fields = [
	[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1, 'width' => 'half' ],
	[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1, 'width' => 'half' ],
	[ 'type' => 'buttons', 'key' => 'kundtyp', 'label' => 'Jag är', 'choices' => "foretag : Företag\nprivat : Privatperson", 'default' => 'foretag' ],
	[ 'type' => 'buttons', 'key' => 'kontakt', 'label' => 'Hur vill du bli kontaktad?', 'choices' => "mail : Mail\nring : Ring mig", 'default' => 'mail' ],
	[ 'type' => 'step', 'key' => 'steg_foretag', 'label' => 'Företaget', 'help' => 'Berätta lite om er.', 'cond_field' => 'kundtyp', 'cond_value' => 'foretag' ],
	[ 'type' => 'text', 'key' => 'foretag', 'label' => 'Företag', 'required' => 1, 'width' => 'half' ],
	[ 'type' => 'confirm', 'key' => 'epost2', 'label' => 'Bekräfta e-post', 'confirm' => 'epost', 'width' => 'half' ],
	[ 'type' => 'step', 'key' => 'steg_telefon', 'label' => 'Telefon' ],
	[ 'type' => 'tel', 'key' => 'mobil', 'label' => 'Mobil', 'required' => 1, 'cond_field' => 'kontakt', 'cond_value' => 'ring' ],
	[ 'type' => 'step', 'key' => 'steg_arende', 'label' => 'Ärendet' ],
	[ 'type' => 'textarea', 'key' => 'meddelande', 'label' => 'Meddelande', 'required' => 1 ],
	[ 'type' => 'hidden', 'key' => 'kalla', 'label' => 'Källa', 'default' => 'demo' ],
];

$forms = [];

/** Renderar ett formulär med $base + $over och återställer sedan definitionen. */
$make = static function ( array $over ) use ( $engine, $render, $base ): string {
	$GLOBALS['__form'] = array_merge( $base, [ 'xf_rules' => [] ], $over );
	Relativt_Form::flush_fields_cache();
	$html = (string) $render->invoke( $engine, 12, [], '' );
	$GLOBALS['__form'] = $base;
	Relativt_Form::flush_fields_cache();
	return $html;
};

$forms['main'] = $make( [
	'xf_fields'           => $main_fields,
	'xf_multistep'        => 1,
	'xf_step_first_title' => 'Om dig',
	'xf_consent_box'      => 1,
	'xf_consent'          => '<p>Jag godkänner att mina uppgifter behandlas.</p>',
] );

$forms['custom'] = $make( [
	'xf_fields'          => [
		[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1 ],
		[ 'type' => 'step', 'key' => 'steg2', 'label' => 'Steg två' ],
		[ 'type' => 'text', 'key' => 'ort', 'label' => 'Ort' ],
	],
	'xf_multistep'       => 1,
	'xf_step_indicator'  => 'text',
	'xf_step_animation'  => 'none',
	'xf_step_next_text'  => 'Fortsätt',
	'xf_step_prev_text'  => 'Bakåt',
	'xf_step_next_icon'  => 'arrow-right',
	'xf_step_prev_icon'  => 'none',
	'xf_submit_icon'     => 'send',
] );

$GLOBALS['__options']['relativt_form_turnstile'] = [ 'site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA' ];
$forms['turnstile'] = $make( [
	'xf_fields'         => [
		[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1 ],
		[ 'type' => 'step', 'key' => 'steg2', 'label' => 'Sista steget' ],
		[ 'type' => 'text', 'key' => 'ort', 'label' => 'Ort' ],
	],
	'xf_multistep'      => 1,
	'xf_step_indicator' => 'none',
	'xf_step_animation' => 'fade',
	'xf_turnstile'      => 1,
] );
$GLOBALS['__options'] = [];

$forms['off'] = $make( [
	'xf_fields' => [
		[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1 ],
		[ 'type' => 'step', 'key' => 'steg2', 'label' => 'Rubrik från en stegrad', 'help' => 'Ingress' ],
		[ 'type' => 'text', 'key' => 'ort', 'label' => 'Ort' ],
	],
] );

$css = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$js  = file_get_contents( __DIR__ . '/../assets/js/relativt-formular.js' );

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – flerstegsformulär</title>
<style>
body { margin: 0; padding: 24px; font-family: -apple-system, "Segoe UI", sans-serif; }
section { max-width: 720px; margin: 0 0 64px; }
h2 { font-size: 14px; color: #888; margin: 0 0 16px; }
/* Gör sidan lång nog för att stegbytet ska behöva scrolla tillbaka till formuläret. */
.spacer { height: 400px; }
</style>
<style id="plugin-css">
$css
</style>
</head>
<body>
<section id="ms-main"><h2>Fyra steg med villkor</h2>{$forms['main']}</section>
<section id="ms-custom"><h2>Egna texter och ikoner</h2>{$forms['custom']}</section>
<section id="ms-turnstile"><h2>Turnstile i sista steget</h2>{$forms['turnstile']}</section>
<section id="ms-off"><h2>Flerstegsläget av</h2>{$forms['off']}</section>
<div class="spacer"></div>

<script>
/* Mockad server: token och inskick. Testerna läser window.__mockCalls. */
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

/* Turnstile-stubbe med samma API som Cloudflares (se build-demo.php). */
window.turnstile = {
	render(el) {
		if (el.dataset.rendered) throw new Error('Turnstile: widgeten är redan renderad');
		el.dataset.rendered = '1';
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = 'cf-turnstile-response';
		input.value = 'XXXX.DUMMY.TOKEN.XXXX';
		el.appendChild(input);
		return 'widget-1';
	},
	getResponse(el) {
		return el.querySelector('[name="cf-turnstile-response"]')?.value ?? '';
	},
	reset() {},
};
/* Som Cloudflares implicita rendering: bara .cf-turnstile. */
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('.cf-turnstile').forEach((el) => window.turnstile.render(el));
});

/* Spelar in stegeventen åt testerna. */
window.__stepEvents = [];
document.addEventListener('relativt-form:step', (event) => {
	const { formId, step, total, title, direction } = event.detail;
	window.__stepEvents.push({ formId, step, total, title, direction });
});
</script>
<script>
$js
</script>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/../demo-multistep.html', $html );
echo "demo-multistep.html skriven (" . strlen( $html ) . " byte)\n";
