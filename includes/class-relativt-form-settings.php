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

	/*
	 * Turnstile-nycklarna ligger i ett EGET alternativ, inte bland
	 * standardvärdena ovan. Standardvärdena läses av setting()-kedjan i
	 * motorn, och en secret ska inte kunna nås den vägen av misstag.
	 */
	public const TURNSTILE_OPTION = 'relativt_form_turnstile';

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

	/**
	 * Turnstile-nycklarna. Konstanterna i wp-config.php vinner över
	 * databasen – då kan secret hållas utanför databasen helt, och en
	 * databaskopia från produktion till staging tar inte med den.
	 *
	 * @return array{site_key:string,secret:string,site_key_const:bool,secret_const:bool}
	 */
	public static function turnstile_keys(): array {
		$stored = get_option( self::TURNSTILE_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$site_const   = defined( 'RELATIVT_FORM_TURNSTILE_SITE_KEY' );
		$secret_const = defined( 'RELATIVT_FORM_TURNSTILE_SECRET' );

		return [
			'site_key'       => trim( (string) ( $site_const ? constant( 'RELATIVT_FORM_TURNSTILE_SITE_KEY' ) : ( $stored['site_key'] ?? '' ) ) ),
			'secret'         => trim( (string) ( $secret_const ? constant( 'RELATIVT_FORM_TURNSTILE_SECRET' ) : ( $stored['secret'] ?? '' ) ) ),
			'site_key_const' => $site_const,
			'secret_const'   => $secret_const,
		];
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

		// Samma grupp, så att båda sparas av samma formulär och knapp.
		register_setting(
			'relativt_form_defaults_group',
			self::TURNSTILE_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_turnstile' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Secret-fältet renderas alltid tomt, så att nyckeln aldrig hamnar i
	 * sidans HTML. Ett tomt fält vid sparning betyder därför "behåll den
	 * sparade" – annars skulle varje sparning av standardvärdena radera den.
	 * Att ta bort den kräver ett uttryckligt kryss.
	 */
	public function sanitize_turnstile( $input ): array {
		$current = get_option( self::TURNSTILE_OPTION, [] );
		$current = is_array( $current ) ? $current : [];
		$input   = is_array( $input ) ? $input : [];

		$site_key = array_key_exists( 'site_key', $input )
			? sanitize_text_field( (string) $input['site_key'] )
			: (string) ( $current['site_key'] ?? '' );

		$secret = trim( sanitize_text_field( (string) ( $input['secret'] ?? '' ) ) );
		if ( ! empty( $input['secret_clear'] ) ) {
			$secret = '';
		} elseif ( '' === $secret ) {
			$secret = (string) ( $current['secret'] ?? '' );
		}

		return [ 'site_key' => $site_key, 'secret' => $secret ];
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

				<?php $this->render_turnstile(); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** Turnstile-nycklarna. Secret skrivs aldrig ut, varken från databasen eller konstanten. */
	private function render_turnstile(): void {
		$keys   = self::turnstile_keys();
		$name   = self::TURNSTILE_OPTION;
		$stored = get_option( $name, [] );
		$stored = is_array( $stored ) ? $stored : [];
		?>
		<h2>Spamskydd: Cloudflare Turnstile</h2>
		<p>Används bara av formulär där <strong>Kräv Turnstile</strong> är påslaget under fliken Skydd. Saknas någon av nycklarna skickas formulären som vanligt, utan Turnstile. Secret läggs helst i <code>wp-config.php</code>: <code>define( 'RELATIVT_FORM_TURNSTILE_SECRET', '…' );</code></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rf-turnstile-site-key">Turnstile site key</label></th>
				<td>
					<?php if ( $keys['site_key_const'] ) : ?>
						<input type="text" class="regular-text" id="rf-turnstile-site-key" value="<?php echo esc_attr( $keys['site_key'] ); ?>" readonly>
						<p class="description">Satt i wp-config.php (<code>RELATIVT_FORM_TURNSTILE_SITE_KEY</code>) och vinner över fältet här.</p>
					<?php else : ?>
						<input type="text" class="regular-text" id="rf-turnstile-site-key"
							name="<?php echo esc_attr( $name ); ?>[site_key]"
							value="<?php echo esc_attr( (string) ( $stored['site_key'] ?? '' ) ); ?>">
						<p class="description">Publik nyckel, syns i sidans källkod.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rf-turnstile-secret">Turnstile secret key</label></th>
				<td>
					<?php if ( $keys['secret_const'] ) : ?>
						<input type="password" class="regular-text" id="rf-turnstile-secret" value="" placeholder="Satt i wp-config.php" disabled>
						<p class="description">Satt i wp-config.php (<code>RELATIVT_FORM_TURNSTILE_SECRET</code>) och vinner över fältet här.</p>
					<?php else : ?>
						<?php $has_secret = '' !== (string) ( $stored['secret'] ?? '' ); ?>
						<input type="password" class="regular-text" id="rf-turnstile-secret" autocomplete="new-password"
							name="<?php echo esc_attr( $name ); ?>[secret]" value=""
							placeholder="<?php echo esc_attr( $has_secret ? 'Sparad – lämna tomt för att behålla' : '' ); ?>">
						<?php if ( $has_secret ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[secret_clear]" value="1"> Ta bort sparad secret</label>
						<?php endif; ?>
						<p class="description">Visas aldrig efter att den sparats.</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}
}

endif;
