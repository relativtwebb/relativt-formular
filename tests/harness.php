<?php
/**
 * Testrigg: stubbar de WordPress-funktioner class-relativt-form.php rör, så att
 * renderare och validering kan köras utan WordPress.
 *
 * Poängen är att demo-filen genereras av SAMMA kod som körs live – då kan
 * demon aldrig glida ifrån verkligheten.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

// --- No-ops -----------------------------------------------------------------
foreach ( [ 'add_shortcode', 'add_meta_box', 'wp_schedule_event', 'update_post_meta', 'nocache_headers' ] as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}

/*
 * Hookar och posttyper spelas in i stället för att kastas bort. Det är så vi
 * kan testa att motorn faktiskt STARTAR när filen laddas – buggen 2026-08-12
 * var att klassen deklarerades men att uppstarten aldrig nåddes.
 */
$GLOBALS['__hooks']      = [];
$GLOBALS['__post_types'] = [];

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['__hooks'][] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function register_post_type( $type, $args = [] ) {
	$GLOBALS['__post_types'][ $type ] = $args;
	return (object) $args;
}

/** Hämtar metodnamnen som hängts på en viss hook. @return array<int,string> */
function hooked_methods( string $hook ): array {
	$out = [];
	foreach ( $GLOBALS['__hooks'] as $entry ) {
		if ( $entry['hook'] !== $hook ) {
			continue;
		}
		$cb = $entry['callback'];
		if ( is_array( $cb ) && is_object( $cb[0] ) ) {
			$out[] = $cb[1];
		}
	}
	return $out;
}

function wp_next_scheduled() { return time(); }
function current_user_can() { return false; }
function is_admin() { return false; }
// Deterministiskt men räknar upp, så två formulär på samma sida inte delar id:n.
function wp_rand( $min = 0, $max = 9999 ) {
	static $n = 1000;
	return ++$n;
}
function wp_salt( $scheme = '' ) { return 'testsalt-' . $scheme; }
function get_option( $name ) { return 'info@exempel.se'; }
function get_bloginfo( $what = 'name' ) { return 'Exempel AB'; }
function rest_url( $path = '' ) { return '/__mock__/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return '/wp-admin/' . ltrim( $path, '/' ); }
function current_time( $format ) { return gmdate( $format ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_slash( $v ) { return $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

// --- Escaping ---------------------------------------------------------------
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $s ) { return (string) $s; }
/*
 * Förenklad wp_kses_post. Riktiga wp_kses_post gör betydligt mer, men en ren
 * genomsläpp-stub hade gjort importtestet meningslöst: det hade mätt stubben
 * i stället för koden. Det här räcker för att visa att värdet FAKTISKT
 * passerar en saneringsfunktion på vägen in.
 */
function wp_kses_post( $s ) {
	$s = preg_replace( '#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', (string) $s ) ?? '';
	$s = preg_replace( '#<(script|style|iframe|object|embed)\b[^>]*/?>#i', '', $s ) ?? '';
	return preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $s ) ?? '';
}
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return trim( (string) $s ); }
function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }

function selected( $a, $b, $echo = true ) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function checked( $a, $b = true, $echo = true ) {
	$match = is_bool( $a ) ? ( $a === $b ) : ( (string) $a === (string) $b );
	return $match ? ' checked="checked"' : '';
}

// --- Transienter (frekvensspärren) ------------------------------------------
$GLOBALS['__transients'] = [];
function get_transient( $key ) { return $GLOBALS['__transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['__transients'][ $key ] = $value; return true; }

// --- ACF och wp-admin -------------------------------------------------------
$GLOBALS['__field_groups'] = [];
function acf_add_local_field_group( array $group ) {
	$GLOBALS['__field_groups'][ $group['key'] ] = $group;
	return true;
}

class WP_Post {
	public $ID;
	public $post_title;
	public function __construct( $id = 12, $title = 'Kontaktformulär' ) {
		$this->ID         = $id;
		$this->post_title = $title;
	}
}

/** Plockar ut ett underfält ur fältbyggaren. */
function sub_field_def( string $key ): ?array {
	$fields = $GLOBALS['__field_groups']['group_xf_fields']['fields'][0]['sub_fields'] ?? [];
	foreach ( $fields as $field ) {
		if ( ( $field['key'] ?? '' ) === $key ) {
			return $field;
		}
	}
	return null;
}

// --- Mail -------------------------------------------------------------------
$GLOBALS['__mail'] = [];
function wp_mail( $to, $subject, $body, $headers = [] ) {
	$GLOBALS['__mail'][] = compact( 'to', 'subject', 'body', 'headers' );
	return true;
}

// --- Formulärdefinitionen (kontaktformuläret ur designen) -------------------
function xf_test_form(): array {
	return [
		'xf_fields' => [
			[
				'type' => 'buttons', 'key' => 'jagar', 'label' => 'Jag är',
				'choices' => "foretag : Företag\nkandidat : Kandidat",
				'default' => 'foretag', 'required' => 1, 'width' => 'full',
			],
			[
				'type' => 'text', 'key' => 'namn', 'label' => 'Namn',
				'placeholder' => 'För- och efternamn', 'required' => 1, 'width' => 'half',
			],
			[
				'type' => 'text', 'key' => 'foretag', 'label' => 'Företag',
				'placeholder' => 'Företagsnamn', 'width' => 'half',
			],
			[
				'type' => 'email', 'key' => 'epost', 'label' => 'E-post',
				'placeholder' => 'namn@företag.se', 'required' => 1, 'width' => 'half',
			],
			[
				'type' => 'tel', 'key' => 'telefon', 'label' => 'Telefon',
				'placeholder' => '07X-XXX XX XX', 'width' => 'half',
			],
			[
				'type' => 'select', 'key' => 'behov', 'label' => 'Vad behöver ni hjälp med?',
				'choices' => "Rekrytering\nBemanning\nInterim\nAnnat",
				'default' => 'Rekrytering', 'width' => 'full',
				'cond_field' => 'jagar', 'cond_value' => 'foretag',
			],
			[
				'type' => 'select', 'key' => 'onskemal', 'label' => 'Vad gäller det?',
				'choices' => "Söker jobb\nSpontanansökan\nAnnat",
				'default' => 'Söker jobb', 'width' => 'full',
				'cond_field' => 'jagar', 'cond_value' => 'kandidat',
			],
			[
				'type' => 'textarea', 'key' => 'meddelande', 'label' => 'Meddelande',
				'placeholder' => 'Skriv gärna några rader om vad ni söker…', 'width' => 'full',
			],
		],
		'xf_rules' => [
			[ 'field' => 'jagar', 'value' => 'Kandidat', 'email' => 'rekrytering@exempel.se', 'subject' => 'Ny kandidat från webbplatsen' ],
		],
		'xf_to'           => 'info@exempel.se',
		'xf_subject'      => 'Nytt meddelande från "Exempel AB"',
		'xf_from_name'    => 'Exempel AB',
		'xf_from_email'   => 'info@exempel.se',
		'xf_submit_text'  => 'Skicka',
		'xf_sending_text' => 'Skickar…',
		'xf_consent'      => '<p>Genom att klicka på Skicka meddelande godkänner jag att mina personuppgifter behandlas i enlighet med Exempel AB:s <a href="/integritetspolicy/">integritetspolicy</a>.</p>',
		'xf_consent_box'  => 0,
		'xf_thanks_title' => 'Tack för ditt meddelande!',
		'xf_thanks_text'  => 'Vi återkommer till dig så snart vi kan.',
		'xf_error_text'   => 'Något gick fel. Försök igen, eller mejla oss direkt.',
		'xf_store'        => 1,
		'xf_retention'    => 365,
		'xf_log_ip'       => 1,
	];
}

$GLOBALS['__form'] = xf_test_form();

function get_field( $name, $post_id = 0 ) {
	return $GLOBALS['__form'][ $name ] ?? null;
}
function get_post_type( $id ) { return 12 === (int) $id ? 'relativt_form' : false; }
function get_the_title( $id ) { return 'Kontaktformulär'; }
function get_posts() { return []; }

// --- Pluginskal ---------------------------------------------------------------
/*
 * Testerna laddar HUVUDFILEN, inte motorklassen direkt. Det är den vägen
 * WordPress tar, och det är där uppstarten ligger – laddade vi bara klassen
 * skulle testet "motorn STARTAS när filen laddas" mäta ingenting.
 */
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return '/wp-content/plugins/relativt-formular/'; }
function plugin_basename( $file ) { return 'relativt-formular/relativt-formular.php'; }
function register_activation_hook( $file, $cb ) { return true; }
function register_deactivation_hook( $file, $cb ) { return true; }
function flush_rewrite_rules() { return true; }
function wp_clear_scheduled_hook( $hook ) { return true; }
/*
 * Riktig filterkörning, inte en genomsläpp-stub. Motorn har numera filter för
 * knappklasser och för att stänga av stilmallen, och de är värdelösa om de
 * inte testas – en filterkrok som inte anropas ser exakt likadan ut som en
 * som fungerar, ända tills någon försöker använda den.
 */
function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );
	foreach ( $GLOBALS['__hooks'] as $entry ) {
		if ( $entry['hook'] === $hook && is_callable( $entry['callback'] ) ) {
			$value = call_user_func_array( $entry['callback'], array_merge( [ $value ], $args ) );
		}
	}
	return $value;
}
function remove_all_filters( $hook ) {
	$GLOBALS['__hooks'] = array_values( array_filter(
		$GLOBALS['__hooks'],
		static fn( $entry ) => $entry['hook'] !== $hook
	) );
}
function wp_kses( $html, $allowed = [] ) { return (string) $html; }
function wpautop( $s ) { return (string) $s; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function delete_transient( $key ) { unset( $GLOBALS['__transients'][ $key ] ); return true; }
function add_submenu_page() { return ''; }
function register_setting() { return true; }
function get_option_default( $name, $default = false ) { return $default; }
function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }

// Assets: registreringen ska gå att köra, men laddar såklart ingenting här.
$GLOBALS['__assets'] = [];
function wp_register_style( $handle, $src = '', $deps = [], $ver = null ) { $GLOBALS['__assets'][ 'style:' . $handle ] = $src; return true; }
function wp_register_script( $handle, $src = '', $deps = [], $ver = null, $footer = false ) { $GLOBALS['__assets'][ 'script:' . $handle ] = [ 'src' => $src, 'footer' => $footer ]; return true; }
function wp_enqueue_style( $handle ) { $GLOBALS['__assets'][ 'enq:style:' . $handle ] = true; return true; }
function wp_enqueue_script( $handle ) { $GLOBALS['__assets'][ 'enq:script:' . $handle ] = true; return true; }

// Byggtestet laddar den GENERERADE filen i stället, och sätter konstanten
// innan riggen dras in.
if ( ! defined( 'XF_TEST_NO_AUTOLOAD' ) ) {
	require __DIR__ . '/../relativt-formular.php';
}
