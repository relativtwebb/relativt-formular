<?php
/**
 * Facit för "befintliga formulär påverkas inte" (1.14.0).
 *
 * Renderar ett antal formulär som de ser ut på kundsajter i dag och
 * returnerar markupen. tests/fixtures/render-1.13.0.json genererades av
 * EXAKT den här filen mot 1.13.0-koden, innan flerstegsstödet fanns.
 * server-test.php renderar samma formulär med dagens kod och kräver att
 * resultatet är byte för byte identiskt.
 *
 * Ändras markupen avsiktligt i en framtida version: generera om facit med
 *   php tests/render-snapshots.php > tests/fixtures/render-<version>.json
 * och peka testet på den nya filen – och skriv i CHANGELOG varför.
 *
 * Kräver att harness.php redan är laddad (eller laddar den själv när filen
 * körs direkt).
 */

if ( ! function_exists( 'xf_test_form' ) ) {
	require __DIR__ . '/harness.php';
}

/** @return array<string,string> namn => markup */
function xf_render_snapshots(): array {
	$engine = Relativt_Form::instance();
	$render = new ReflectionMethod( $engine, 'render_form' );
	$render->setAccessible( true );

	$saved_form    = $GLOBALS['__form'];
	$saved_options = $GLOBALS['__options'];
	$out           = [];

	$run = static function ( string $name, array $form, array $presets = [], array $options = [] ) use ( $engine, $render, &$out ): void {
		$GLOBALS['__form']    = $form;
		$GLOBALS['__options'] = $options;
		Relativt_Form::flush_fields_cache();
		// Nollställ löpnumren så att varje ögonblicksbild är oberoende av ordningen.
		$count = new ReflectionProperty( Relativt_Form::class, 'render_count' );
		$count->setAccessible( true );
		$count->setValue( null, [] );
		/*
		 * wp_rand() i riggen räknar upp per anrop, så uid:t beror på hur många
		 * formulär som renderats tidigare i processen. Det normaliseras bort –
		 * allt annat jämförs exakt.
		 */
		$html         = (string) $render->invoke( $engine, 12, $presets, '' );
		$out[ $name ] = (string) preg_replace( '/\bxf-12-\d+/', 'xf-12-UID', $html );
	};

	$base = xf_test_form();

	// 1. Kontaktformuläret precis som det är, med förval.
	$run( 'kontakt', $base, [ 'jagar' => 'kandidat', 'audit' => 'Webb' ] );

	// 2. Rubrik/avdelare, samtyckesruta, egen ikon och tack-texter.
	$form                   = $base;
	$form['xf_fields']      = array_merge(
		[ [ 'type' => 'heading', 'key' => '', 'label' => 'Om dig', 'help' => 'Visas inte' ] ],
		$base['xf_fields'],
		[
			[ 'type' => 'heading', 'key' => '', 'label' => 'Övrigt', 'show_label' => 0 ],
			[ 'type' => 'checkboxes', 'key' => 'intressen', 'label' => 'Intressen', 'choices' => "a : A\nb : B", 'required' => 1 ],
			[ 'type' => 'radio', 'key' => 'kanal', 'label' => 'Kanal', 'choices' => "Mejl\nTelefon", 'default' => 'Mejl' ],
			[ 'type' => 'checkbox', 'key' => 'nyhetsbrev', 'label' => 'Nyhetsbrev' ],
			[ 'type' => 'date', 'key' => 'datum', 'label' => 'Datum', 'width' => 'third' ],
			[ 'type' => 'number', 'key' => 'antal', 'label' => 'Antal', 'width' => 'third' ],
			[ 'type' => 'url', 'key' => 'webb', 'label' => 'Webbplats', 'width' => 'third' ],
			[ 'type' => 'confirm', 'key' => 'epost2', 'label' => 'Upprepa e-post', 'confirm' => 'epost' ],
			[ 'type' => 'tel', 'key' => 'mobil', 'label' => 'Mobil', 'phone_country' => 1, 'phone_default' => 'NO' ],
		]
	);
	$form['xf_consent_box'] = 1;
	$form['xf_submit_icon'] = 'send';
	$run( 'rubriker-och-samtycke', $form );

	// 3. Turnstile påslaget.
	$form                 = $base;
	$form['xf_turnstile'] = 1;
	$run( 'turnstile', $form, [], [ 'relativt_form_turnstile' => [ 'site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA' ] ] );

	// 4. Headless-definitionen (REST) för kontaktformuläret.
	$GLOBALS['__form']    = $base;
	$GLOBALS['__options'] = [];
	Relativt_Form::flush_fields_cache();
	$out['rest-kontakt'] = (string) json_encode( $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	$GLOBALS['__form']    = $saved_form;
	$GLOBALS['__options'] = $saved_options;
	Relativt_Form::flush_fields_cache();

	return $out;
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {
	echo json_encode( xf_render_snapshots(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
}
