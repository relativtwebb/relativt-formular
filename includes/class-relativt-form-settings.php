<?php
/**
 * Globala standardvärden.
 *
 * Ett formulär som lämnar ett fält tomt ärver värdet härifrån. Det gör att
 * ett nytt formulär på en ny sajt inte behöver börja från noll – avsändare,
 * tacktexter och samtyckestext sätts en gång per sajt.
 *
 * Formulärets eget värde vinner alltid. Det här är golvet, inte taket.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Relativt_Form_Settings', false ) ) :

final class Relativt_Form_Settings {

	public const OPTION = 'relativt_form_defaults';

	private static ?self $instance = null;

	/** name => [etikett, typ, beskrivning] */
	private const FIELDS = [
		'xf_to'           => [ 'Standardmottagare', 'text', 'Adressen inskicken går till när formuläret inte anger någon egen. Flera adresser separeras med komma.' ],
		'xf_from_name'    => [ 'Avsändarnamn', 'text', 'Namnet som visas som avsändare i notismailen.' ],
		'xf_from_email'   => [ 'Avsändaradress', 'text', 'Måste ligga på en domän som är verifierad hos er e-postleverantör, annars fastnar mailen i skräpposten.' ],
		'xf_subject'      => [ 'Standardämne', 'text', '' ],
		'xf_submit_text'  => [ 'Knapptext', 'text', '' ],
		'xf_sending_text' => [ 'Knapptext under skick', 'text', '' ],
		'xf_thanks_title' => [ 'Tackrubrik', 'text', '' ],
		'xf_thanks_text'  => [ 'Tacktext', 'textarea', '' ],
		'xf_redirect'     => [ 'Tack-sida (URL)', 'text', 'Skickar besökaren hit efter lyckat inskick i stället för att visa tack-rutan. Formulärets eget värde vinner alltid.' ],
		'xf_error_text'   => [ 'Felmeddelande', 'textarea', 'Visas om inskicket inte går fram.' ],
		'xf_consent'      => [ 'Samtyckestext', 'html', 'Visas under knappen. Länka till integritetspolicyn här.' ],
	];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
	}

	/** Hämtar ett standardvärde. Returnerar alltid sträng. */
	public static function get( string $name ): string {
		if ( ! isset( self::FIELDS[ $name ] ) ) {
			return '';
		}

		$all = get_option( self::OPTION, [] );

		return is_array( $all ) ? (string) ( $all[ $name ] ?? '' ) : '';
	}

	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Relativt_Form::CPT_FORM,
			'Standardvärden',
			'Standardvärden',
			'manage_options',
			'relativt-form-defaults',
			[ $this, 'render' ]
		);
	}

	public function register(): void {
		register_setting(
			'relativt_form_defaults_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function sanitize( $input ): array {
		$out = [];

		foreach ( self::FIELDS as $name => $spec ) {
			$value = is_array( $input ) ? ( $input[ $name ] ?? '' ) : '';

			switch ( $spec[1] ) {
				case 'html':
					$out[ $name ] = wp_kses_post( (string) $value );
					break;
				case 'textarea':
					$out[ $name ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$out[ $name ] = sanitize_text_field( (string) $value );
			}
		}

		return $out;
	}

	public function render(): void {
		$values = get_option( self::OPTION, [] );
		$values = is_array( $values ) ? $values : [];
		?>
		<div class="wrap">
			<h1>Standardvärden</h1>
			<p>Värden här används av alla formulär som lämnar motsvarande fält tomt. Sätter formuläret ett eget värde vinner det alltid.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'relativt_form_defaults_group' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( self::FIELDS as $name => $spec ) : ?>
						<?php
						list( $label, $type, $description ) = $spec;
						$value = (string) ( $values[ $name ] ?? '' );
						$id    = 'rf-' . $name;
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<?php if ( 'text' === $type ) : ?>
									<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>"
										name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $name ); ?>]"
										value="<?php echo esc_attr( $value ); ?>">
								<?php else : ?>
									<textarea rows="<?php echo 'html' === $type ? 4 : 3; ?>" class="large-text" id="<?php echo esc_attr( $id ); ?>"
										name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $name ); ?>]"><?php echo esc_textarea( $value ); ?></textarea>
								<?php endif; ?>

								<?php if ( '' !== $description ) : ?>
									<p class="description"><?php echo esc_html( $description ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

endif;
