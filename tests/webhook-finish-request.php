<?php
/**
 * Webhooken på en server med litespeed_finish_request() (1.17.0).
 *
 * Körs i en egen process av server-test.php, eftersom funktionen bara kan
 * finnas om den definieras innan pluginet laddas – precis som på en
 * LiteSpeed-server, där PHP har den inbyggd. Skriver ut ett JSON-objekt med
 * det som hände, i ordning.
 */

$GLOBALS['__order'] = [];

function litespeed_finish_request() {
	$GLOBALS['__order'][] = 'finish';
	return true;
}

require __DIR__ . '/harness.php';

$GLOBALS['__form'] = array_merge( xf_test_form(), [
	'xf_webhook'     => 1,
	'xf_webhook_url' => 'https://exempel.se/hook',
] );
Relativt_Form::flush_fields_cache();

add_filter( 'relativt_form_webhook_args', static function ( $args ) {
	$GLOBALS['__order'][] = 'send';
	return $args;
} );

$engine = Relativt_Form::instance();
$sign   = new ReflectionMethod( $engine, 'sign' );
xf_accessible( $sign );
$ts = time() - 10;
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

$res = $engine->rest_submit( new WP_REST_Request( [
	'form'       => 12,
	'fields'     => [ 'jagar' => 'foretag', 'namn' => 'Anna', 'epost' => 'anna@exempel.se' ],
	'nonce'      => 'giltig',
	'ts'         => $ts,
	'sig'        => $sign->invoke( $engine, '12|' . $ts ),
	'xf_website' => '',
	'utm'        => [],
	'page'       => 'https://exempel.se/',
] ) );
$GLOBALS['__order'][] = 'response:' . $res->status;

$before = [
	'calls'     => count( $GLOBALS['__safe_calls'] ),
	'cron'      => count( $GLOBALS['__cron'] ),
	'shutdowns' => count( array_filter( $GLOBALS['__hooks'], static fn( $h ) => 'shutdown' === $h['hook'] ) ),
];

do_action( 'shutdown' );

$entry = (int) array_key_last( $GLOBALS['__saved_meta'] );
echo json_encode( [
	'can_finish' => Relativt_Form_Webhook::can_finish_request(),
	'before'     => $before,
	'order'      => $GLOBALS['__order'],
	'calls'      => count( $GLOBALS['__safe_calls'] ),
	'status'     => $GLOBALS['__saved_meta'][ $entry ][ Relativt_Form_Webhook::META_STATUS ] ?? null,
] ) . "\n";
