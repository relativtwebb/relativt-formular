<?php
/**
 * Plugin Name:       Relativt Formulär
 * Plugin URI:        https://github.com/relativtwebb/relativt-formular
 * Description:       Formulärmotor för WordPress. Bygg formulär i wp-admin, varje formulär får en egen shortcode. Villkorliga fält, mottagarregler, spamskydd, inskickslagring och UTM-attribution.
 * Version:           1.1.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Relativt
 * Author URI:        https://relativt.se
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       relativt-formular
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * defined()-vakterna är inte kosmetiska. Ligger filen kvar som mu-plugin
 * samtidigt som pluginet aktiveras laddas den två gånger, och ett obevakat
 * define() ger då en PHP-varning per konstant vid varje sidladdning – i
 * värsta fall mitt i en HTTP-header och därmed en trasig sajt.
 */
if ( ! defined( 'RELATIVT_FORM_VERSION' ) ) {
	define( 'RELATIVT_FORM_VERSION', '1.1.2' );
	define( 'RELATIVT_FORM_FILE', __FILE__ );
	define( 'RELATIVT_FORM_DIR', plugin_dir_path( __FILE__ ) );
	define( 'RELATIVT_FORM_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Publikt GitHub-repo som uppdateringarna hämtas från, på formen ägare/repo.
 * Pekar konstanten fel eller är repot borta hittar uppdateraren ingenting –
 * den är tyst, aldrig fatal. (1.0.0 pekade på relativt/… som inte finns, så
 * uppdateringsflödet var dött utan att någon såg det. Release-kedjan bryr
 * sig inte om konstanten – det är därför felet inte fångades av CI.)
 */
if ( ! defined( 'RELATIVT_FORM_REPO' ) ) {
	define( 'RELATIVT_FORM_REPO', 'relativtwebb/relativt-formular' );
}

/*
 * PHP-spärr FÖRE require.
 *
 * Motorn använder str_starts_with(), str_contains() och match – allt PHP 8.0.
 * På en äldre server är det ett parsningsfel, och ett parsningsfel i en
 * inkluderad fil går inte att fånga: sajten blir vit. Därför måste kontrollen
 * ske innan filerna dras in, och den här filen får själv inte innehålla någon
 * 8.0-syntax.
 *
 * "Requires PHP" i headern ovan hindrar aktivering via wp-admin, men skyddar
 * inte om filerna kopieras in för hand eller läggs i mu-plugins.
 */
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p><strong>Relativt Formulär</strong> kräver PHP 8.0 eller senare. Servern kör %s, så pluginet är inaktivt.</p></div>',
					esc_html( PHP_VERSION )
				);
			}
		}
	);

	return;
}

require_once RELATIVT_FORM_DIR . 'includes/class-relativt-form.php';
require_once RELATIVT_FORM_DIR . 'includes/class-relativt-form-portability.php';
require_once RELATIVT_FORM_DIR . 'includes/class-relativt-form-settings.php';
require_once RELATIVT_FORM_DIR . 'includes/class-relativt-form-updater.php';

/* -----------------------------------------------------------------------------
 * Uppstart.
 *
 * Konstanten gör att en andra inläsning – filen kvar som mu-plugin, eller en
 * gammal Code Snippet som ligger och skräpar – varken startar motorn igen
 * eller dubblerar hookar.
 * -------------------------------------------------------------------------- */
if ( ! defined( 'RELATIVT_FORM_BOOTED' ) ) {
	define( 'RELATIVT_FORM_BOOTED', true );

	Relativt_Form::instance();
	Relativt_Form_Portability::instance();
	Relativt_Form_Settings::instance();

	if ( is_admin() ) {
		// '8.0' = samma golv som version_compare-spärren ovan. Höjs kravet:
		// ändra på båda ställena, annars erbjuds uppdateringen på servrar
		// där den nya versionen bara lägger sig inaktiv.
		new Relativt_Form_Updater( RELATIVT_FORM_FILE, RELATIVT_FORM_REPO, RELATIVT_FORM_VERSION, '8.0' );
	}

	add_action( 'admin_notices', 'relativt_form_dependency_notice' );
	register_activation_hook( __FILE__, 'relativt_form_activate' );
	register_deactivation_hook( __FILE__, 'relativt_form_deactivate' );
}

/**
 * Utan ACF Pro hände det tidigare ingenting alls – tyst, precis som den
 * boot-bugg som tog en halv dag att hitta. Nu står det i klartext vad som
 * saknas. Motorn startar ändå så länge ACF finns, så att posttyperna
 * registreras och redan sparade formulär och inskick förblir åtkomliga.
 */
if ( ! function_exists( 'relativt_form_dependency_notice' ) ) :

function relativt_form_dependency_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$missing = '';

	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		$missing = 'Advanced Custom Fields Pro är inte aktiverat. Formulärbyggaren visas inte förrän det är på plats.';
	} elseif ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( 'repeater' ) ) {
		$missing = 'Advanced Custom Fields hittades, men utan repeater-fältet – det ingår bara i Pro-versionen. Formulärbyggaren behöver Pro.';
	}

	if ( '' === $missing ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>Relativt Formulär:</strong> %s</p></div>',
		esc_html( $missing )
	);
}

function relativt_form_activate(): void {
	Relativt_Form::instance()->register_post_types();
	Relativt_Form::instance()->schedule_cleanup();
	flush_rewrite_rules();
}

function relativt_form_deactivate(): void {
	wp_clear_scheduled_hook( Relativt_Form::CRON_HOOK );
	flush_rewrite_rules();
}

endif; // function_exists-vakten ovan.
