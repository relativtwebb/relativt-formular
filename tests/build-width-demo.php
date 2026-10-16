<?php
/**
 * Genererar demo-width.html: ett formulär renderat av den RIKTIGA renderaren
 * med fält i alla tre bredderna, ovanpå pluginets egen CSS. Playwright mäter
 * sedan var fälten hamnar, så att rutnätet bevisas i en riktig webbläsare och
 * inte bara i PHP:s klassnamn.
 *
 * Raderna, uppifrån och ned:
 *   A  tredjedel × 3
 *   B  halv × 2
 *   C  hel
 *   D  halv + tredjedel (fem av sex kolumner, ska ändå dela rad)
 *
 * Körs: php tests/build-width-demo.php
 */

require __DIR__ . '/harness.php';

$field = static function ( string $key, string $width ): array {
	return [ 'type' => 'text', 'key' => $key, 'label' => strtoupper( $key ), 'width' => $width ];
};

$GLOBALS['__form']['xf_fields'] = [
	$field( 'a1', 'third' ), $field( 'a2', 'third' ), $field( 'a3', 'third' ),
	$field( 'b1', 'half' ), $field( 'b2', 'half' ),
	$field( 'c1', 'full' ),
	$field( 'd1', 'half' ), $field( 'd2', 'third' ),
];
$GLOBALS['__form']['xf_rules'] = [];

$engine     = Relativt_Form::instance();
$reflection = new ReflectionClass( $engine );
$render     = $reflection->getMethod( 'render_form' );
$render->setAccessible( true );
$form = $render->invoke( $engine, 12, [], '' );

$css = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – bredder</title>
<style>
body { margin: 0; padding: 24px; font-family: -apple-system, "Segoe UI", sans-serif; }
#wrap { max-width: 900px; }
</style>
<style id="plugin-css">
$css
</style>
</head>
<body>
<div id="wrap">
$form
</div>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/../demo-width.html', $html );
echo "demo-width.html skriven (" . strlen( $html ) . " byte)\n";
