<?php
/**
 * Facit för "sajter utan Polylang beter sig exakt som förut" (1.15.0).
 *
 * Samlar allt en besökare kan få se från motorn: texterna i messages(),
 * konfigurationen till skriptet, renderad markup (med och utan sajtens
 * standardvärden, med och utan steg) och REST-svaren från /form, /token och
 * /submit – varje spärr för sig. tests/fixtures/i18n-1.14.0.json genererades
 * av EXAKT den här filen mot 1.14.0-koden, innan språkstödet fanns.
 * server-test.php kör samma sak med dagens kod, utan Polylang, och kräver
 * att resultatet är identiskt.
 *
 * Ändras något här avsiktligt i en framtida version: generera om facit med
 *   php tests/i18n-snapshots.php > tests/fixtures/i18n-<version>.json
 * och peka testet på den nya filen – och skriv i CHANGELOG varför.
 *
 * Kräver att harness.php redan är laddad (eller laddar den själv när filen
 * körs direkt).
 */

if ( ! function_exists( 'xf_test_form' ) ) {
	require __DIR__ . '/harness.php';
}

/** @return array<string,mixed> namn => utdata */
function xf_i18n_snapshots(): array {
	$engine = Relativt_Form::instance();
	$render = new ReflectionMethod( $engine, 'render_form' );
	$render->setAccessible( true );
	$sign = new ReflectionMethod( $engine, 'sign' );
	$sign->setAccessible( true );

	$saved = [
		'form'       => $GLOBALS['__form'],
		'options'    => $GLOBALS['__options'],
		'transients' => $GLOBALS['__transients'],
		'mail'       => $GLOBALS['__mail'],
		'posts'      => $GLOBALS['__posts'],
		'meta'       => $GLOBALS['__saved_meta'],
		'assets'     => $GLOBALS['__assets'],
		'addr'       => $_SERVER['REMOTE_ADDR'] ?? null,
	];
	$out = [];

	$use = static function ( array $form, array $options = [] ): void {
		$GLOBALS['__form']       = $form;
		$GLOBALS['__options']    = $options;
		$GLOBALS['__transients'] = [];
		Relativt_Form::flush_fields_cache();
		$count = new ReflectionProperty( Relativt_Form::class, 'render_count' );
		$count->setAccessible( true );
		$count->setValue( null, [] );
	};
	$markup = static function ( array $presets = [] ) use ( $engine, $render ): string {
		return (string) preg_replace( '/\bxf-12-\d+/', 'xf-12-UID', (string) $render->invoke( $engine, 12, $presets, '' ) );
	};
	$body = static function ( array $extra = [] ) use ( $engine, $sign ): array {
		$ts = time() - 10;
		return array_merge( [
			'form'       => 12,
			'fields'     => [ 'jagar' => 'foretag', 'namn' => 'Anna Andersson', 'epost' => 'anna@exempel.se' ],
			'nonce'      => 'giltig',
			'ts'         => $ts,
			'sig'        => $sign->invoke( $engine, '12|' . $ts ),
			'xf_website' => '',
		], $extra );
	};
	/** Status + data; retry_after beror på klockan och normaliseras. */
	$reply = static function ( $res ): array {
		if ( $res instanceof WP_Error ) {
			return [ 'wp_error' => $res->code, 'message' => $res->message, 'data' => $res->data ];
		}
		$data = $res->data;
		if ( isset( $data['retry_after'] ) ) {
			$data['retry_after'] = 'N';
		}
		return [ 'status' => $res->status, 'data' => $data ];
	};

	$base = xf_test_form();

	/*
	 * Ett formulär utan egna texter: då syns kodens egna fallbacks (Skicka,
	 * Skickar…, Tack!, Något gick fel.).
	 */
	$bare = $base;
	foreach ( [ 'xf_submit_text', 'xf_sending_text', 'xf_thanks_title', 'xf_thanks_text', 'xf_error_text', 'xf_consent' ] as $key ) {
		unset( $bare[ $key ] );
	}

	/** Sajtens standardvärden, som de sparas under Formulär → Standardvärden. */
	$defaults = [
		'relativt_form_defaults' => [
			'xf_to'           => 'info@exempel.se',
			'xf_submit_text'  => 'Skicka förfrågan',
			'xf_sending_text' => 'Skickar förfrågan…',
			'xf_thanks_title' => 'Tack för din förfrågan!',
			'xf_thanks_text'  => 'Vi hör av oss inom en arbetsdag.',
			'xf_redirect'     => 'https://exempel.se/tack/',
			'xf_error_text'   => 'Det gick inte att skicka. Ring oss i stället.',
			'xf_consent'      => '<p>Läs vår <a href="/integritetspolicy/">integritetspolicy</a>.</p>',
		],
	];

	$multistep = array_merge( $bare, [
		'xf_fields'    => [
			[ 'type' => 'text', 'key' => 'namn', 'label' => 'Namn', 'required' => 1 ],
			[ 'type' => 'step', 'key' => 'steg2', 'label' => '' ],
			[ 'type' => 'email', 'key' => 'epost', 'label' => 'E-post', 'required' => 1 ],
			[ 'type' => 'tel', 'key' => 'mobil', 'label' => 'Mobil', 'phone_country' => 1 ],
		],
		'xf_rules'     => [],
		'xf_multistep' => 1,
	] );

	// 1. Texterna.
	$use( $base );
	$out['messages'] = $engine->messages();

	// 2. Konfigurationen till skriptet.
	$GLOBALS['__assets'] = [];
	$engine->register_assets();
	preg_match( '/window\.relativtFormConfig = (\{.*\});/s', implode( "\n", $GLOBALS['__assets']['inline:relativt-formular'] ?? [] ), $m );
	$out['config'] = json_decode( $m[1] ?? '', true );

	// 3. Markup.
	$out['render-kontakt'] = $markup( [ 'jagar' => 'kandidat' ] );
	$use( $bare );
	$out['render-fallbacks'] = $markup();
	$use( $bare, $defaults );
	$out['render-standardvarden'] = $markup();
	$use( $multistep );
	$out['render-flersteg'] = $markup();

	// 4. /form (headless).
	$use( $bare );
	$out['rest-form-fallbacks'] = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$use( $bare, $defaults );
	$out['rest-form-standardvarden'] = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$use( $multistep );
	$out['rest-form-flersteg'] = $engine->rest_form( new WP_REST_Request( [ 'id' => 12 ] ) );
	$out['rest-form-okant']    = $reply( $engine->rest_form( new WP_REST_Request( [ 'id' => 999 ] ) ) );

	// 5. /token. Tidsstämpel och signatur beror på klockan; nycklar och headers jämförs.
	$use( $base );
	$token                    = $engine->rest_token( new WP_REST_Request( [ 'form' => 12 ] ) );
	$out['rest-token']        = [ 'status' => $token->status, 'keys' => array_keys( (array) $token->data ), 'headers' => $token->get_headers() ];
	$out['rest-token-okant']  = $reply( $engine->rest_token( new WP_REST_Request( [ 'form' => 999 ] ) ) );

	// 6. /submit, spärr för spärr.
	$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
	$submit = static function ( array $extra = [] ) use ( $engine, $body, $reply ): array {
		return $reply( $engine->rest_submit( new WP_REST_Request( $body( $extra ) ) ) );
	};

	$use( $base );
	$out['submit-ok']      = $submit();
	$out['submit-okant']   = $submit( [ 'form' => 999 ] );
	$out['submit-nonce']   = $submit( [ 'nonce' => 'fel' ] );
	$out['submit-sig']     = $submit( [ 'sig' => 'fejkad' ] );
	$fast                  = time();
	$out['submit-toofast'] = $submit( [ 'ts' => $fast, 'sig' => $sign->invoke( $engine, '12|' . $fast ) ] );
	$out['submit-fel']     = $submit( [ 'fields' => [ 'jagar' => 'foretag', 'epost' => 'inte-en-adress', 'telefon' => '12', 'meddelande' => 'a https://a.se b https://b.se c https://c.se d https://d.se' ] ] );
	$out['submit-honung']  = $submit( [ 'xf_website' => 'spam' ] );

	$GLOBALS['__transients'] = [];
	for ( $i = 0; $i < 5; $i++ ) {
		$submit();
	}
	$out['submit-rate'] = $submit();

	$use( array_merge( $base, [ 'xf_consent_box' => 1 ] ) );
	$out['submit-samtycke'] = $submit();

	$use( array_merge( $base, [ 'xf_turnstile' => 1 ] ), [ 'relativt_form_turnstile' => [ 'site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA' ] ] );
	$out['submit-turnstile'] = $submit();

	$use( $bare );
	$out['submit-ok-fallbacks'] = $submit();
	$use( $bare, $defaults );
	$out['submit-ok-standardvarden'] = $submit();

	$use( array_merge( $multistep, [ 'xf_fields' => array_merge( $multistep['xf_fields'], [ [ 'type' => 'confirm', 'key' => 'epost2', 'label' => 'E-post igen', 'confirm' => 'epost' ] ] ) ] ) );
	$out['submit-bekraftelse'] = $submit( [ 'fields' => [ 'namn' => 'Anna', 'epost' => 'anna@exempel.se', 'epost2' => 'annan@exempel.se' ] ] );

	$GLOBALS['__form']       = $saved['form'];
	$GLOBALS['__options']    = $saved['options'];
	$GLOBALS['__transients'] = $saved['transients'];
	$GLOBALS['__mail']       = $saved['mail'];
	$GLOBALS['__posts']      = $saved['posts'];
	$GLOBALS['__saved_meta'] = $saved['meta'];
	$GLOBALS['__assets']     = $saved['assets'];
	if ( null === $saved['addr'] ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $saved['addr'];
	}
	Relativt_Form::flush_fields_cache();

	// Genom JSON, så att facit och dagens körning jämförs i samma form.
	return json_decode( (string) json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {
	echo json_encode( xf_i18n_snapshots(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
}
