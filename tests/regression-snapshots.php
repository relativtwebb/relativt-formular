<?php
/**
 * Regressionsfacit: befintliga formulär ska bete sig exakt som i 1.15.0 (1.16.0).
 *
 * Kör ett antal formulär som de ser ut på kundsajter – kontaktformulär,
 * villkor, mottagarregler, flersteg, bekräftelsefält, Turnstile, samtyckesruta,
 * kampanjdata med och utan klick-id, Polylang – genom hela kedjan och samlar
 * det en besökare, en mottagare och en redaktör får se:
 *
 *   - renderad markup och konfigurationen till skriptet
 *   - svaren från /form, /token och /submit (lyckat och med valideringsfel)
 *   - notismejlet (mottagare, ämne, brödtext, headers)
 *   - det sparade inskicket (titel, värden, metadata)
 *   - raden i CSV-exporten
 *
 * tests/fixtures/regression-1.15.0.json genererades av EXAKT den här filen mot
 * 1.15.0-koden (git archive v1.15.0), innan något i 1.16.0 fanns.
 * server-test.php kör samma sak med dagens kod och kräver att allt är
 * identiskt. Generera om:
 *
 *   php tests/regression-snapshots.php > tests/fixtures/regression-<version>.json
 *
 * Polylang-fallen kräver en egen process (mocken definierar pll_*-funktioner
 * för resten av körningen) och körs med argumentet polylang. CSV-exporten
 * avslutar processen med exit och körs därför också i en egen process, med
 * argumentet csv.
 */

$xf_mode = $argv[1] ?? '';

if ( ! function_exists( 'xf_test_form' ) ) {
	require __DIR__ . '/harness.php';
}

/*
 * CSV-läget: läser inskicken ur en JSON-fil och skriver exporten till stdout.
 * export_csv() slutar med exit, precis som i WordPress.
 */
if ( 'csv' === $xf_mode && realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {
	$entries = json_decode( (string) file_get_contents( (string) ( $argv[2] ?? '' ) ), true );
	$posts   = [];
	foreach ( (array) $entries as $id => $meta ) {
		$GLOBALS['__postmeta'][ (int) $id ] = $meta;
		$posts[]                            = (object) [ 'ID' => (int) $id ];
	}
	$GLOBALS['__get_posts'] = static fn() => $posts;
	$GLOBALS['__can']       = true;
	$_GET                   = [ '_wpnonce' => 'giltig', 'xf_form' => '12' ];
	Relativt_Form::instance()->export_csv();
	exit;
}

/**
 * @param bool $polylang Kör Polylang-fallen (kräver att polylang-mock.php är laddad).
 * @return array<string,mixed>
 */
function xf_regression_snapshots( bool $polylang = false ): array {
	$engine = Relativt_Form::instance();
	$render = new ReflectionMethod( $engine, 'render_form' );
	xf_accessible( $render );
	$sign = new ReflectionMethod( $engine, 'sign' );
	xf_accessible( $sign );
	$privacy = new ReflectionMethod( $engine, 'privacy_data' );
	xf_accessible( $privacy );

	$saved = [
		'form'       => $GLOBALS['__form'],
		'options'    => $GLOBALS['__options'],
		'transients' => $GLOBALS['__transients'],
		'mail'       => $GLOBALS['__mail'],
		'posts'      => $GLOBALS['__posts'],
		'meta'       => $GLOBALS['__saved_meta'],
		'assets'     => $GLOBALS['__assets'],
		'remote'     => $GLOBALS['__remote'],
		'calls'      => $GLOBALS['__remote_calls'],
		'addr'       => $_SERVER['REMOTE_ADDR'] ?? null,
		'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? null,
	];
	$out = [];

	$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Regressionstest)';

	$use = static function ( array $form, array $options = [] ): void {
		$GLOBALS['__form']         = $form;
		$GLOBALS['__options']      = $options;
		$GLOBALS['__transients']   = [];
		$GLOBALS['__mail']         = [];
		$GLOBALS['__posts']        = [];
		$GLOBALS['__saved_meta']   = [];
		$GLOBALS['__remote_calls'] = [];
		Relativt_Form::flush_fields_cache();
		$count = new ReflectionProperty( Relativt_Form::class, 'render_count' );
		xf_accessible( $count );
		$count->setValue( null, [] );
	};

	/** Datum och klockslag beror på när testet körs; allt annat jämförs exakt. */
	$normalize = static function ( $data ) use ( &$normalize ) {
		if ( is_array( $data ) ) {
			return array_map( $normalize, $data );
		}
		if ( ! is_string( $data ) ) {
			return $data;
		}
		$data = (string) preg_replace( '/\b\d{4}-\d{2}-\d{2}\b/', 'DATUM', $data );
		$data = (string) preg_replace( '/\b\d{2}:\d{2}\b/', 'TID', $data );
		return (string) preg_replace( '/\bxf-12-\d+/', 'xf-12-UID', $data );
	};

	$markup = static function ( array $presets = [] ) use ( $engine, $render, $normalize ): string {
		return $normalize( (string) $render->invoke( $engine, 12, $presets, '' ) );
	};

	$reply = static function ( $res ): array {
		if ( $res instanceof WP_Error ) {
			return [ 'wp_error' => $res->code, 'message' => $res->message, 'data' => $res->data ];
		}
		$data = $res->data;
		if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
			$data['retry_after'] = 'N';
		}
		return [ 'status' => $res->status, 'data' => $data ];
	};

	/** Hela kedjan för ett inskick: svaret, mejlet, det sparade och CSV-raden. */
	$submit = static function ( array $fields, array $extra = [] ) use ( $engine, $sign, $privacy, $reply, $normalize ): array {
		$GLOBALS['__transients']   = [];
		$GLOBALS['__mail']         = [];
		$GLOBALS['__posts']        = [];
		$GLOBALS['__saved_meta']   = [];
		$ts                        = time() - 10;
		$body                      = array_merge( [
			'form'       => 12,
			'fields'     => $fields,
			'utm'        => [],
			'page'       => 'https://exempel.se/kontakt/',
			'nonce'      => 'giltig',
			'ts'         => $ts,
			'sig'        => $sign->invoke( $engine, '12|' . $ts ),
			'xf_website' => '',
			'xf_consent' => 0,
		], $extra );

		$res   = $engine->rest_submit( new WP_REST_Request( $body ) );
		$entry = $GLOBALS['__saved_meta'] ? array_key_first( $GLOBALS['__saved_meta'] ) : null;
		$saved = null !== $entry ? $GLOBALS['__saved_meta'][ $entry ] : [];

		// Det redaktören ser i wp-admin och det integritetsexporten lämnar ut.
		$view = null;
		$data = null;
		if ( null !== $entry ) {
			$GLOBALS['__postmeta'][ $entry ] = $saved;
			ob_start();
			$engine->render_entry_box( (object) [ 'ID' => $entry ] );
			$view = (string) ob_get_clean();
			$data = $privacy->invoke( $engine, $entry );
			unset( $GLOBALS['__postmeta'][ $entry ] );
		}

		return $normalize( [
			'svar'    => $reply( $res ),
			'mejl'    => $GLOBALS['__mail'],
			'inskick' => [
				'post' => $GLOBALS['__posts'],
				'meta' => $saved,
			],
			'csv'     => null !== $entry ? xf_regression_csv( [ $entry => $saved ] ) : null,
			'vy'      => $view,
			'integritet' => $data,
		] );
	};

	$base = xf_test_form();

	/* -- 1. Kontaktformuläret: villkor, mottagarregel, dolt fält ----------- */
	$use( $base );
	$GLOBALS['__assets'] = [];
	$engine->register_assets();
	$out['config']                 = $GLOBALS['__assets']['inline:relativt-formular'] ?? [];
	$out['kontakt/render']         = $markup( [ 'jagar' => 'kandidat', 'audit' => 'Webb' ] );
	$out['kontakt/render-standard'] = $markup();
	$out['kontakt/rest-form']      = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$token                         = $engine->rest_token( new WP_REST_Request( [ 'form' => 12 ] ) );
	$out['kontakt/rest-token']     = [ 'status' => $token->status, 'keys' => array_keys( (array) $token->data ), 'headers' => $token->get_headers() ];
	// JSON-exporten av formulärdefinitionen. Versionen och tidpunkten skiljer sig per definition.
	$export = Relativt_Form_Portability::instance()->build_payload( 12 );
	unset( $export['_plugin_version'], $export['_exported'] );
	$out['kontakt/json-export']    = $export;
	$out['kontakt/foretag']        = $submit( [ 'jagar' => 'foretag', 'namn' => 'Anna Andersson', 'foretag' => 'Exempel AB', 'epost' => 'anna@exempel.se', 'telefon' => '070-123 45 67', 'behov' => 'Bemanning', 'meddelande' => "Hej!\nVi behöver hjälp.", 'audit' => 'Webb' ] );
	// Mottagarregeln (jagar = Kandidat) och villkoret som visar onskemal i stället för behov.
	$out['kontakt/kandidat']       = $submit( [ 'jagar' => 'kandidat', 'namn' => 'Bo Berg', 'epost' => 'bo@exempel.se', 'onskemal' => 'Spontanansökan', 'behov' => 'Bemanning' ] );
	$out['kontakt/fel']            = $submit( [ 'jagar' => 'foretag', 'epost' => 'inte-en-adress', 'telefon' => '12', 'behov' => 'Påhittat', 'meddelande' => 'a https://a.se b https://b.se c https://c.se d https://d.se' ] );
	$out['kontakt/ogiltigt-val']   = $submit( [ 'jagar' => 'okand', 'namn' => 'Cecilia', 'epost' => 'c@exempel.se' ] );

	/* -- 2. Alla fälttyper, samtyckesruta och egen ikon -------------------- */
	$all                   = $base;
	$all['xf_fields']      = array_merge(
		[ [ 'type' => 'heading', 'key' => '', 'label' => 'Om dig', 'help' => 'Ingress' ] ],
		$base['xf_fields'],
		[
			[ 'type' => 'checkboxes', 'key' => 'intressen', 'label' => 'Intressen', 'choices' => "a : A\nb : B\nc : C", 'required' => 1 ],
			[ 'type' => 'radio', 'key' => 'kanal', 'label' => 'Kanal', 'choices' => "Mejl\nTelefon", 'default' => 'Mejl' ],
			[ 'type' => 'checkbox', 'key' => 'nyhetsbrev', 'label' => 'Nyhetsbrev' ],
			[ 'type' => 'text', 'key' => 'nyhetsadress', 'label' => 'Adress för nyhetsbrev', 'cond_field' => 'nyhetsbrev', 'cond_value' => '' ],
			[ 'type' => 'date', 'key' => 'datum', 'label' => 'Datum', 'width' => 'third' ],
			[ 'type' => 'number', 'key' => 'antal', 'label' => 'Antal', 'width' => 'third' ],
			[ 'type' => 'url', 'key' => 'webb', 'label' => 'Webbplats', 'width' => 'third' ],
			[ 'type' => 'tel', 'key' => 'mobil', 'label' => 'Mobil', 'phone_country' => 1, 'phone_default' => 'NO' ],
		]
	);
	$all['xf_consent_box'] = 1;
	$all['xf_submit_icon'] = 'send';
	$use( $all );
	$out['alla/render']     = $markup( [ 'intressen' => 'a, c', 'kanal' => 'Telefon' ] );
	$out['alla/rest-form']  = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$all_fields             = [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se', 'behov' => 'Interim', 'intressen' => [ 'a', 'c', 'okand' ], 'kanal' => 'Telefon', 'nyhetsbrev' => '1', 'nyhetsadress' => 'Storgatan 1', 'datum' => '2027-02-02', 'antal' => '3', 'webb' => 'exempel.se', 'mobil' => '+4791234567' ];
	$out['alla/ok']         = $submit( $all_fields, [ 'xf_consent' => 1 ] );
	$out['alla/utan-samtycke'] = $submit( $all_fields );
	$out['alla/fel']        = $submit( [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se', 'intressen' => [], 'datum' => '2026-13-45', 'antal' => 'tre', 'webb' => 'inte en adress', 'mobil' => '+47 1' ], [ 'xf_consent' => 1 ] );

	/* -- 3. Flersteg med villkor på ett steg ------------------------------- */
	$steps = array_merge( $base, [
		'xf_fields'           => [
			[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1 ],
			[ 'type' => 'buttons', 'key' => 'typ', 'label' => 'Jag är', 'choices' => "foretag : Företag\nprivat : Privatperson", 'required' => 1 ],
			[ 'type' => 'step', 'key' => 'steg-foretag', 'label' => 'Om företaget', 'help' => 'Ingress', 'cond_field' => 'typ', 'cond_value' => 'foretag' ],
			[ 'type' => 'text', 'key' => 'foretag', 'label' => 'Företag', 'required' => 1 ],
			[ 'type' => 'select', 'key' => 'storlek', 'label' => 'Antal anställda', 'choices' => "1-9\n10-49\n50+", 'placeholder' => 'Välj' ],
			[ 'type' => 'step', 'key' => 'steg-arende', 'label' => 'Ditt ärende' ],
			[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1 ],
			[ 'type' => 'textarea', 'key' => 'meddelande', 'label' => 'Meddelande' ],
		],
		'xf_rules'            => [],
		'xf_multistep'        => 1,
		'xf_step_first_title' => 'Om dig',
	] );
	$use( $steps );
	$out['flersteg/render']    = $markup();
	$out['flersteg/rest-form'] = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$out['flersteg/foretag']   = $submit( [ 'namn' => 'Anna', 'typ' => 'foretag', 'foretag' => 'Exempel AB', 'storlek' => '10-49', 'epost' => 'anna@exempel.se' ] );
	// Privatperson: företagssteget hoppas över, även om fälten skickas med.
	$out['flersteg/privat']    = $submit( [ 'namn' => 'Bo', 'typ' => 'privat', 'foretag' => 'Ska inte med', 'storlek' => '50+', 'epost' => 'bo@exempel.se' ] );
	$out['flersteg/fel']       = $submit( [ 'namn' => 'Bo', 'typ' => 'foretag', 'epost' => 'bo@exempel.se' ] );

	/* -- 4. Bekräftelsefält ------------------------------------------------ */
	$confirm              = $base;
	$confirm['xf_fields'] = array_merge( $base['xf_fields'], [ [ 'type' => 'confirm', 'key' => 'epost2', 'label' => 'Upprepa e-post', 'confirm' => 'epost' ] ] );
	$use( $confirm );
	$out['bekraftelse/render']   = $markup();
	$out['bekraftelse/ok']       = $submit( [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'Anna@Exempel.se', 'epost2' => ' anna@exempel.se ' ] );
	$out['bekraftelse/fel']      = $submit( [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se', 'epost2' => 'anna@exempel.com' ] );

	/* -- 5. Turnstile på --------------------------------------------------- */
	$turnstile_keys = [ 'relativt_form_turnstile' => [ 'site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA' ] ];
	$use( array_merge( $base, [ 'xf_turnstile' => 1 ] ), $turnstile_keys );
	$GLOBALS['__remote']        = [ 'code' => 200, 'body' => '{"success":true}' ];
	$out['turnstile/render']    = $markup();
	$out['turnstile/rest-form'] = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$out['turnstile/ok']        = $submit( [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se' ], [ 'turnstile' => 'token-ok' ] );
	$GLOBALS['__remote']        = [ 'code' => 200, 'body' => '{"success":false}' ];
	$out['turnstile/nekad']     = $submit( [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se' ], [ 'turnstile' => 'token-fel' ] );
	$GLOBALS['__remote']        = $saved['remote'];

	/* -- 6. Kampanjdata ------------------------------------------------------ */
	$use( $base );
	$campaign = [
		'utm_source'   => 'linkedin',
		'utm_medium'   => 'paid',
		'utm_campaign' => 'host-2026',
		'utm_term'     => 'rekrytering',
		'utm_content'  => 'bild-a',
		'landing'      => 'https://exempel.se/kampanj/?utm_source=linkedin',
		'referrer'     => 'https://www.linkedin.com/',
	];
	$person = [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se' ];
	// Med samtycke till marknadsföring följer klick-id med; utan stannar de i webbläsaren.
	$out['kampanj/med-samtycke']  = $submit( $person, [ 'utm' => $campaign + [ 'gclid' => 'Cj0KCQ-abc', 'fbclid' => 'IwAR-xyz' ] ] );
	$out['kampanj/utan-samtycke'] = $submit( $person, [ 'utm' => $campaign ] );
	$out['kampanj/ingen']         = $submit( $person );

	/* -- 7. Lagring av, IP av, tack-sida ---------------------------------- */
	$use( array_merge( $base, [ 'xf_store' => 0, 'xf_log_ip' => 0, 'xf_redirect' => 'https://exempel.se/tack/' ] ) );
	$out['ingen-lagring/ok'] = $submit( $person );

	/* -- 8. Polylang (egen process) --------------------------------------- */
	if ( $polylang ) {
		$GLOBALS['__pll']['translations'] = [
			'en' => [
				'Fyll i detta fält.'          => 'Please fill in this field.',
				'Kontrollera e-postadressen.' => 'Please check the email address.',
				'Skicka'                      => 'Send',
			],
		];
		$use( $base );
		$GLOBALS['__assets'] = [];
		$engine->register_assets();
		$out['polylang/config']       = $GLOBALS['__assets']['inline:relativt-formular'] ?? [];
		$out['polylang/render']       = $markup();
		$out['polylang/rest-form-en'] = $engine->rest_form( new WP_REST_Request( [ 'id' => 12, 'lang' => 'en' ] ) );
		$out['polylang/ok-en']        = $submit( $person, [ 'lang' => 'en' ] );
		$out['polylang/fel-en']       = $submit( [ 'jagar' => 'foretag', 'epost' => 'fel' ], [ 'lang' => 'en' ] );
		$out['polylang/fel-sv']       = $submit( [ 'jagar' => 'foretag', 'epost' => 'fel' ], [ 'lang' => 'sv' ] );
	}

	$GLOBALS['__form']         = $saved['form'];
	$GLOBALS['__options']      = $saved['options'];
	$GLOBALS['__transients']   = $saved['transients'];
	$GLOBALS['__mail']         = $saved['mail'];
	$GLOBALS['__posts']        = $saved['posts'];
	$GLOBALS['__saved_meta']   = $saved['meta'];
	$GLOBALS['__assets']       = $saved['assets'];
	$GLOBALS['__remote_calls'] = $saved['calls'];
	foreach ( [ 'REMOTE_ADDR' => 'addr', 'HTTP_USER_AGENT' => 'ua' ] as $server => $key ) {
		if ( null === $saved[ $key ] ) {
			unset( $_SERVER[ $server ] );
		} else {
			$_SERVER[ $server ] = $saved[ $key ];
		}
	}
	Relativt_Form::flush_fields_cache();

	// Genom JSON, så att facit och dagens körning jämförs i samma form.
	return json_decode( (string) json_encode( $normalize( $out ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
}

/**
 * CSV-exporten för ett antal sparade inskick, körd i en egen process eftersom
 * export_csv() slutar med exit.
 *
 * @param array<int,array<string,mixed>> $entries id => sparad metadata
 */
function xf_regression_csv( array $entries ): string {
	$file = tempnam( sys_get_temp_dir(), 'xfcsv' );
	file_put_contents( $file, json_encode( $entries ) );
	$csv = (string) shell_exec(
		escapeshellarg( PHP_BINARY ) . ' -d display_errors=stderr ' . escapeshellarg( __FILE__ ) . ' csv ' . escapeshellarg( $file )
	);
	unlink( $file );
	return $csv;
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {
	$polylang = 'polylang' === $xf_mode;
	if ( $polylang ) {
		require_once __DIR__ . '/polylang-mock.php';
	}
	echo json_encode( xf_regression_snapshots( $polylang ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
}
