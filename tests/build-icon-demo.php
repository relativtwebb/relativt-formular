<?php
/**
 * Genererar demo-icons.html:
 *
 *  1. Skicka-knappen renderad av den RIKTIGA renderaren för varje ikon i
 *     biblioteket, så att Playwright kan prova hur de ritas med pluginets
 *     egen CSS (linjer vs fylld pil, färg, storlek, tjocklek).
 *  2. Ikonväljaren i byggaren: ACF:s radiomarkup (som ACF skriver ut den) med
 *     väljarens CSS, ovanpå en approximation av WordPress egna radioknappsstil
 *     – den som väljaren måste skriva över.
 *
 * Väljaren går inte att prova mot en riktig wp-admin här (ACF Pro är
 * licensierat och finns inte i CI), så markupen nedan är en modell av ACF 6:s
 * radiolista, inte ACF självt. Ändrar ACF sin markup syns det först i en
 * riktig wp-admin.
 *
 * Körs: php tests/build-icon-demo.php
 */

require __DIR__ . '/harness.php';

$engine     = Relativt_Form::instance();
$reflection = new ReflectionClass( $engine );
$render     = $reflection->getMethod( 'render_form' );
$render->setAccessible( true );

$cells = '';
foreach ( Relativt_Form_Icons::keys() as $key ) {
	$GLOBALS['__form']['xf_submit_icon'] = $key;
	$form = $render->invoke( $engine, 12, [], '' );
	if ( ! preg_match( '#<div class="xf-actions">.*?</button>\s*</div>#s', $form, $m ) ) {
		fwrite( STDERR, "Hittade ingen knapp för {$key}\n" );
		exit( 1 );
	}
	$cells .= '<div class="cell" data-icon="' . esc_attr( $key ) . '"><div class="relativt-form">' . $m[0] . '</div><code>' . esc_html( $key ) . "</code></div>\n";
}

$items = '';
foreach ( Relativt_Form_Icons::choices() as $key => $label ) {
	$checked = Relativt_Form_Icons::STANDARD === $key;
	$items  .= sprintf(
		'<li><label%s><input type="radio" id="acf-field_xf_submit_icon-%s" name="acf[field_xf_submit_icon]" value="%s"%s> %s</label></li>' . "\n",
		$checked ? ' class="selected"' : '',
		esc_attr( $key ),
		esc_attr( $key ),
		$checked ? ' checked="checked"' : '',
		esc_html( $label )
	);
}

$css    = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$picker = Relativt_Form_Icons::picker_css( Relativt_Form::ICON_FIELD_KEY );

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – ikoner</title>
<style>
body { margin: 0; padding: 24px; font-family: -apple-system, "Segoe UI", sans-serif; background: #f0f0f1; color: #1d2327; }
h2 { font-size: 16px; margin: 32px 0 12px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.cell { background: #fff; padding: 16px; border-radius: 6px; }
.cell code { display: block; margin-top: 8px; font-size: 11px; color: #646970; }
.cell .xf-actions { margin-top: 0; }
.cell .xf-submit { width: auto; }
</style>
<style id="plugin-css">
$css
</style>

<style id="wp-admin-approximation">
/* Approximation av WordPress forms.css för radioknappar – det väljaren skriver över. */
input[type=radio] { border: 1px solid #8c8f94; border-radius: 50%; background: #fff; color: #50575e; clear: none; cursor: pointer; display: inline-block; line-height: 0; height: 1rem; margin: -.25rem .25rem 0 0; outline: 0; padding: 0; text-align: center; vertical-align: middle; width: 1rem; min-width: 1rem; -webkit-appearance: none; appearance: none; box-shadow: inset 0 1px 2px rgba(0,0,0,.1); }
input[type=radio]:checked::before { content: ""; border-radius: 50%; width: .5rem; height: .5rem; margin: .1875rem; background-color: #2271b1; display: inline-block; vertical-align: middle; line-height: 1.14285714; }
input[type=radio]:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: 2px solid transparent; }
/* Approximation av ACF:s radiolista. */
.acf-field { max-width: 760px; padding: 15px 12px; background: #fff; border-radius: 6px; }
.acf-label label { font-weight: 600; display: block; margin: 0 0 3px; }
.acf-label p.description { margin: 0 0 10px; color: #646970; font-size: 13px; }
.acf-radio-list { margin: 0; padding: 0; list-style: none; }
.acf-radio-list li { margin: 0 0 4px; }
.acf-radio-list.acf-hl > li { float: left; margin-right: 20px; }
.acf-radio-list li label { display: block; }
.acf-radio-list li input { margin: 0 3px 0 0; }
.acf-radio-list::after { content: ""; display: block; clear: both; }
</style>
<style id="picker-css">
$picker
</style>
</head>
<body>

<h2>Knappen, en cell per ikon (riktig renderare + pluginets CSS)</h2>
<div class="grid" id="buttons">
$cells</div>

<h2>Ikonväljaren i byggaren (modell av ACF:s markup)</h2>
<div class="acf-field acf-field-radio" data-name="xf_submit_icon" data-type="radio" data-key="field_xf_submit_icon" id="picker">
	<div class="acf-label"><label>Ikon i knappen</label><p class="description">Visas efter knapptexten.</p></div>
	<div class="acf-input"><ul class="acf-radio-list acf-hl" data-allow_null="0" data-other_choice="0">
$items	</ul></div>
</div>

<script>
/* Det ACF gör: flyttar .selected till den markerade etiketten. */
document.querySelectorAll('#picker input').forEach((input) => {
	input.addEventListener('change', () => {
		document.querySelectorAll('#picker label').forEach((l) => l.classList.remove('selected'));
		input.closest('label').classList.add('selected');
	});
});
</script>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/../demo-icons.html', $html );
echo "demo-icons.html skriven (" . strlen( $html ) . " byte)\n";
