<?php
/**
 * Genererar demo-webhook.html (1.17.0): kontaktformuläret med webhooken på,
 * adress och hemlighet ifyllda, renderat av den riktiga renderaren med
 * pluginets egen CSS och JS och en mockad server.
 *
 * Webhooken körs helt på servern. Sidan finns för att webbläsartesterna ska
 * kunna visa det från besökarens håll: att adressen och hemligheten aldrig
 * når webbläsaren, och att inskicket går till samma REST-rutt som förut.
 *
 * Körs: php tests/build-webhook-demo.php
 */

require __DIR__ . '/harness.php';

$GLOBALS['__form'] = array_merge( xf_test_form(), [
	'xf_webhook'              => 1,
	'xf_webhook_url'          => 'https://hooks.zapier.com/hooks/catch/1/hemlig-zap/',
	'xf_webhook_header_name'  => 'X-Webhook-Secret',
	'xf_webhook_header_value' => 'superhemligt-varde',
] );
Relativt_Form::flush_fields_cache();

$engine = Relativt_Form::instance();
$render = new ReflectionMethod( $engine, 'render_form' );
xf_accessible( $render );
$form = $render->invoke( $engine, 12, [], '' );

$GLOBALS['__assets'] = [];
$engine->register_assets();
$config = implode( "\n", $GLOBALS['__assets']['inline:relativt-formular'] ?? [] );

$css = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$js  = file_get_contents( __DIR__ . '/../assets/js/relativt-formular.js' );

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – webhook</title>
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
		window.__mockCalls.push({ url: href, body: JSON.parse(options.body || '{}') });
		return new Response(JSON.stringify({ ok: true, title: 'Tack!', text: '' }), {
			status: 200, headers: { 'Content-Type': 'application/json' },
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

file_put_contents( __DIR__ . '/../demo-webhook.html', $html );
echo "demo-webhook.html skriven (" . strlen( $html ) . " byte)\n";
