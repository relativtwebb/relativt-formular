<?php
/**
 * Genererar demo-confirm.html: ett formulär med bekräftelsefält (1.13.0),
 * renderat av den RIKTIGA renderaren med pluginets egen CSS och JS och en
 * mockad server. Ett eget formulär, så att demo-form.html och dess tester
 * står kvar som de var.
 *
 * Fälten, uppifrån och ned:
 *   epost / epost2       obligatorisk e-post med bekräftelse
 *   kundnr2 / kundnr     valfritt textfält, bekräftelsen står FÖRE originalet
 *   kontakt              val-knappar som styr telefonfältet
 *   mobil / mobil2       telefon med landsväljare, synligt bara vid "Ring mig"
 *
 * Körs: php tests/build-confirm-demo.php
 */

require __DIR__ . '/harness.php';

$GLOBALS['__form']['xf_fields'] = [
	[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1, 'width' => 'half' ],
	[ 'type' => 'confirm', 'key' => 'epost2', 'label' => 'Bekräfta e-post', 'confirm' => 'epost', 'width' => 'half', 'help' => 'Skriv adressen en gång till.' ],
	[ 'type' => 'confirm', 'key' => 'kundnr2', 'label' => 'Kundnummer igen', 'confirm' => 'kundnr', 'width' => 'half' ],
	[ 'type' => 'text', 'key' => 'kundnr', 'label' => 'Kundnummer', 'width' => 'half' ],
	[ 'type' => 'buttons', 'key' => 'kontakt', 'label' => 'Hur vill du bli kontaktad?', 'choices' => "mail : Mail\nring : Ring mig", 'default' => 'mail' ],
	[ 'type' => 'tel', 'key' => 'mobil', 'label' => 'Mobil', 'required' => 1, 'phone_country' => 1, 'width' => 'half', 'cond_field' => 'kontakt', 'cond_value' => 'ring' ],
	[ 'type' => 'confirm', 'key' => 'mobil2', 'label' => 'Mobil igen', 'confirm' => 'mobil', 'width' => 'half' ],
];
$GLOBALS['__form']['xf_rules'] = [];
Relativt_Form::flush_fields_cache();

$engine     = Relativt_Form::instance();
$reflection = new ReflectionClass( $engine );
$render     = $reflection->getMethod( 'render_form' );
$render->setAccessible( true );
$form = $render->invoke( $engine, 12, [], '' );

$css = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$js  = file_get_contents( __DIR__ . '/../assets/js/relativt-formular.js' );

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – bekräftelsefält</title>
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
			return new Response(JSON.stringify({ ok: false, errors: window.__mockErrors }), {
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
$js
</script>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/../demo-confirm.html', $html );
echo "demo-confirm.html skriven (" . strlen( $html ) . " byte)\n";
