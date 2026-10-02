<?php
/**
 * Genererar demo-stats.html – statistiksidan renderad av den RIKTIGA
 * renderaren med påhittade men realistiska inskick, så att Playwright kan
 * prova sidan i en webbläsare (periodväljaren, verktygstips, mobilbredd).
 *
 * Körs: php tests/build-stats-demo.php
 */

require __DIR__ . '/harness.php';

mt_srand( 20261002 );

$today   = new DateTimeImmutable( current_time( 'Y-m-d' ) );
$uas     = [
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [LinkedInApp]/9.30',
];
$sources = [
	[ 6, [], 'https://www.google.se/' ],
	[ 4, [], '' ],
	[ 3, [ 'utm_source' => 'linkedin', 'utm_medium' => 'paid_social', 'utm_campaign' => 'host26-ledarskap' ], '' ],
	[ 2, [ 'gclid' => 'Cj0KCQ' ], 'https://www.google.se/' ],
	[ 2, [ 'utm_source' => 'nyhetsbrev', 'utm_medium' => 'email', 'utm_campaign' => 'september' ], '' ],
	[ 1, [], 'https://www.linkedin.com/' ],
	[ 1, [ 'utm_source' => 'chatgpt.com' ], '' ],
	[ 1, [], 'https://branschforum.se/artikel/123' ],
	[ 1, [ 'utm_source' => 'qr', 'utm_medium' => 'print', 'utm_campaign' => 'massa-elmia' ], '' ],
];
$weights = array_column( $sources, 0 );
$pages   = [ '/kontakt/', '/', '/tjanster/rekrytering/', '/om-oss/', '/kontakt/', '/en/contact/' ];
$lands   = [ '/', '/tjanster/rekrytering/', '/blogg/sa-lyckas-ni-med-interim/', '/kampanj/ledarskap/', '/kontakt/' ];

/** Viktat slumpval ur listan. */
$pick = static function ( array $weights ): int {
	$roll = mt_rand( 1, array_sum( $weights ) );
	foreach ( $weights as $i => $w ) {
		if ( ( $roll -= $w ) <= 0 ) {
			return $i;
		}
	}
	return 0;
};

$db = new Xf_Fake_Wpdb();
$id = 5000;

for ( $d = 89; $d >= 0; $d-- ) {
	$day     = $today->modify( "-{$d} days" );
	$weekend = (int) $day->format( 'N' ) >= 6;
	$n       = $weekend ? mt_rand( 0, 2 ) : mt_rand( 2, 8 ) + (int) ( ( 89 - $d ) / 30 );

	for ( $k = 0; $k < $n; $k++ ) {
		$src  = $sources[ $pick( $weights ) ];
		$hour = $weekend ? mt_rand( 9, 21 ) : [ 8, 9, 9, 10, 10, 11, 13, 14, 14, 15, 16, 20 ][ mt_rand( 0, 11 ) ];
		$id++;
		$db->entries[ $id ] = [
			'date' => $day->format( 'Y-m-d' ) . sprintf( ' %02d:%02d:00', $hour, mt_rand( 0, 59 ) ),
			'meta' => [
			'_xf_form_id' => mt_rand( 1, 4 ) === 1 ? 14 : 12,
			'_xf_mail_ok' => mt_rand( 1, 60 ) === 1 ? '0' : '1',
			'_xf_meta'    => [
				'page'     => 'https://exempel.se' . $pages[ mt_rand( 0, count( $pages ) - 1 ) ],
				'landing'  => 'https://exempel.se' . $lands[ mt_rand( 0, count( $lands ) - 1 ) ],
				'referrer' => $src[2],
				'ua'       => $uas[ mt_rand( 0, count( $uas ) - 1 ) ],
				'utm'      => $src[1],
			],
			],
		];
	}
}

// Formulär 14 finns inte längre – demon visar hur ett borttaget formulär redovisas.
$forms = [ new WP_Post( 12, 'Kontaktformulär' ) ];

$GLOBALS['wpdb']        = $db;
$GLOBALS['__get_posts'] = static fn( array $args ) => 'relativt_form' === ( $args['post_type'] ?? '' ) ? $forms : [];

$GLOBALS['__can'] = true;
$_GET             = [ 'period' => '90' ];

ob_start();
Relativt_Form_Stats::instance()->render();
$body = (string) ob_get_clean();

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statistik – demo</title>
<style>
/* Ungefärlig wp-admin-grund, så att demon ser ut som på riktigt. */
body { margin: 0; padding: 10px 20px; background: #f0f0f1; color: #3c434a; font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
h1 { font-size: 23px; font-weight: 400; margin: 0; padding: 9px 0 4px; }
.button { display: inline-block; padding: 0 10px; min-height: 30px; border: 1px solid #2271b1; border-radius: 3px; background: #f6f7f7; color: #2271b1; font: inherit; cursor: pointer; }
.button-primary { background: #2271b1; color: #fff; }
select, input[type=date] { min-height: 30px; border: 1px solid #8c8f94; border-radius: 4px; font: inherit; padding: 0 8px; }
a { color: #2271b1; }
.screen-reader-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(1px, 1px, 1px, 1px); }
@media (max-width: 782px) { body { padding: 10px; } }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/../demo-stats.html', $html );
echo 'demo-stats.html skriven (' . count( $db->entries ) . " påhittade inskick)\n";
