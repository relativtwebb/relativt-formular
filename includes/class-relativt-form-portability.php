<?php
/**
 * Export och import av formulärdefinitioner som JSON.
 *
 * Poängen: bygg ett formulär en gång, återanvänd det på nästa sajt. Exporten
 * innehåller bara definitionen – aldrig inskicken, som är personuppgifter och
 * hör hemma i CSV-exporten i stället.
 *
 * Importen läser ALDRIG in fältnamn rakt av. Allt passerar en vitlista, och
 * det som inte står i den kastas. En JSON-fil är en fil vem som helst kan
 * skriva; utan vitlistan hade importen varit en väg att skriva godtycklig
 * post meta till databasen.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Relativt_Form_Portability', false ) ) :

final class Relativt_Form_Portability {

	public const SCHEMA = 1;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_import_page' ] );
		add_action( 'admin_post_relativt_form_export_def', [ $this, 'handle_export' ] );
		add_action( 'admin_post_relativt_form_import', [ $this, 'handle_import' ] );
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Vitlistan
	 *
	 * Definierar samtidigt vad ett formulär ÄR. Läggs ett fält till i
	 * byggaren måste det läggas till här också, annars följer det inte med
	 * i en export – och det märks först på nästa sajt.
	 * ------------------------------------------------------------------ */

	private const TOP_FIELDS = [
		'xf_to'           => 'text',
		'xf_subject'      => 'text',
		'xf_from_name'    => 'text',
		'xf_from_email'   => 'text',
		'xf_submit_text'  => 'text',
		'xf_sending_text' => 'text',
		'xf_consent'      => 'html',
		'xf_consent_box'  => 'bool',
		'xf_thanks_title' => 'text',
		'xf_thanks_text'  => 'html',
		'xf_redirect'     => 'text',
		'xf_error_text'   => 'text',
		'xf_store'        => 'bool',
		'xf_retention'    => 'int',
		'xf_log_ip'       => 'bool',
	];

	private const FIELD_ROW = [
		'type'        => 'type',
		'key'         => 'key',
		'label'       => 'text',
		'placeholder' => 'text',
		'help'        => 'text',
		'choices'     => 'multiline',
		'default'     => 'text',
		'required'    => 'bool',
		'width'       => 'width',
		'cond_field'  => 'key',
		'cond_value'  => 'text',
	];

	private const RULE_ROW = [
		'field'   => 'key',
		'value'   => 'text',
		'email'   => 'text',
		'subject' => 'text',
	];

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/** Lägger en Exportera-länk bland radåtgärderna i formulärlistan. */
	public function row_action( array $actions, $post ): array {
		if ( ! $post || Relativt_Form::CPT_FORM !== $post->post_type || ! current_user_can( 'edit_pages' ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=relativt_form_export_def&form=' . (int) $post->ID ),
			'relativt_form_export_def_' . (int) $post->ID
		);

		$actions['relativt_form_export'] = sprintf( '<a href="%s">Exportera JSON</a>', esc_url( $url ) );

		return $actions;
	}

	public function handle_export(): void {
		$form_id = (int) ( $_GET['form'] ?? 0 );

		if ( ! current_user_can( 'edit_pages' )
			|| ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'relativt_form_export_def_' . $form_id )
			|| get_post_type( $form_id ) !== Relativt_Form::CPT_FORM ) {
			wp_die( 'Åtkomst nekad.' );
		}

		/*
		 * Motorn startar medvetet utan ACF (posttyper och inskick ska förbli
		 * åtkomliga), men exporten läser definitionen via get_field(). Utan
		 * vakten blir det en vit sida i stället för ett begripligt besked.
		 */
		if ( ! function_exists( 'get_field' ) ) {
			wp_die( 'Advanced Custom Fields Pro är inte aktiverat, så formulärdefinitionen kan inte läsas. Aktivera ACF Pro och försök igen.' );
		}

		$payload = $this->build_payload( $form_id );
		$slug    = sanitize_title( get_the_title( $form_id ) ) ?: 'formular';
		$name    = sprintf( 'formular-%s-%s.json', $slug, current_time( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function build_payload( int $form_id ): array {
		$settings = [];

		foreach ( array_keys( self::TOP_FIELDS ) as $name ) {
			$settings[ $name ] = get_field( $name, $form_id );
		}

		$settings['xf_fields'] = $this->rows( get_field( 'xf_fields', $form_id ), self::FIELD_ROW );
		$settings['xf_rules']  = $this->rows( get_field( 'xf_rules', $form_id ), self::RULE_ROW );

		return [
			'_type'           => 'relativt-formular',
			'_schema'         => self::SCHEMA,
			'_plugin_version' => defined( 'RELATIVT_FORM_VERSION' ) ? RELATIVT_FORM_VERSION : '0.0.0',
			'_exported'       => current_time( 'c' ),
			'title'           => get_the_title( $form_id ),
			'settings'        => $settings,
		];
	}

	/** Plockar ut enbart vitlistade kolumner ur en repeater. */
	private function rows( $value, array $allowed ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = [];
			foreach ( array_keys( $allowed ) as $column ) {
				$clean[ $column ] = $row[ $column ] ?? '';
			}
			$out[] = $clean;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	public function add_import_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Relativt_Form::CPT_FORM,
			'Importera formulär',
			'Importera',
			'edit_pages',
			'relativt-form-import',
			[ $this, 'render_import_page' ]
		);
	}

	public function render_import_page(): void {
		$notice = sanitize_text_field( (string) ( $_GET['rf_notice'] ?? '' ) );

		// Importen skriver via update_field() – utan ACF Pro finns inget att importera till.
		if ( ! function_exists( 'update_field' ) ) {
			?>
			<div class="wrap">
				<h1>Importera formulär</h1>
				<div class="notice notice-error"><p>Advanced Custom Fields Pro är inte aktiverat. Importen behöver det för att kunna skriva formulärdefinitionen.</p></div>
			</div>
			<?php
			return;
		}
		?>
		<div class="wrap">
			<h1>Importera formulär</h1>

			<?php if ( 'ok' === $notice ) : ?>
				<div class="notice notice-success"><p>Formuläret importerades som utkast. Öppna det, gå igenom mottagaradresserna och publicera.</p></div>
			<?php elseif ( '' !== $notice ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p>Välj en JSON-fil som exporterats från Relativt Formulär. Formuläret skapas som <strong>utkast</strong> med en ny shortcode – ingenting skrivs över.</p>

			<p><strong>Kontrollera alltid mottagaradresserna efter en import.</strong> De följer med från sajten filen kom ifrån, och pekar annars fel på den nya.</p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="relativt_form_import">
				<?php wp_nonce_field( 'relativt_form_import' ); ?>
				<p><input type="file" name="rf_file" accept="application/json,.json" required></p>
				<p><?php submit_button( 'Importera', 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	public function handle_import(): void {
		if ( ! current_user_can( 'edit_pages' ) || ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'relativt_form_import' ) ) {
			wp_die( 'Åtkomst nekad.' );
		}

		// Före wp_insert_post, så inget halvfärdigt utkast skapas när ACF saknas.
		if ( ! function_exists( 'update_field' ) ) {
			$this->back( 'Advanced Custom Fields Pro är inte aktiverat. Importen behöver det för att kunna skriva formulärdefinitionen.' );
		}

		$file = $_FILES['rf_file'] ?? null;

		if ( ! is_array( $file ) || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) {
			$this->back( 'Ingen fil togs emot. Försök igen.' );
		}

		// En formulärdefinition är några kilobyte. Är filen större är det inte en.
		if ( (int) ( $file['size'] ?? 0 ) > 512 * 1024 ) {
			$this->back( 'Filen är för stor för att vara en formulärexport.' );
		}

		$raw  = (string) file_get_contents( $file['tmp_name'] );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ( $data['_type'] ?? '' ) !== 'relativt-formular' ) {
			$this->back( 'Filen är inte en formulärexport från Relativt Formulär.' );
		}

		if ( (int) ( $data['_schema'] ?? 0 ) > self::SCHEMA ) {
			$this->back( 'Filen kommer från en nyare version av pluginet. Uppdatera Relativt Formulär först.' );
		}

		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : [];
		$title    = sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ?: 'Importerat formulär';

		$form_id = wp_insert_post(
			[
				'post_type'   => Relativt_Form::CPT_FORM,
				'post_title'  => $title,
				'post_status' => 'draft',
			],
			true
		);

		if ( is_wp_error( $form_id ) ) {
			$this->back( 'Formuläret kunde inte skapas: ' . $form_id->get_error_message() );
		}

		foreach ( self::TOP_FIELDS as $name => $type ) {
			if ( array_key_exists( $name, $settings ) ) {
				update_field( $name, $this->clean( $settings[ $name ], $type ), $form_id );
			}
		}

		update_field( 'xf_fields', $this->clean_rows( $settings['xf_fields'] ?? [], self::FIELD_ROW ), $form_id );
		update_field( 'xf_rules', $this->clean_rows( $settings['xf_rules'] ?? [], self::RULE_ROW ), $form_id );

		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $form_id . '&action=edit&rf_imported=1' ) );
		exit;
	}

	private function clean_rows( $rows, array $allowed ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = [];
			foreach ( $allowed as $column => $type ) {
				$clean[ $column ] = $this->clean( $row[ $column ] ?? '', $type );
			}
			$out[] = $clean;
		}

		return $out;
	}

	/** Enda vägen in för importerade värden. Okänd typ ger tom sträng. */
	private function clean( $value, string $type ) {
		switch ( $type ) {
			case 'bool':
				return $value ? 1 : 0;

			case 'int':
				return max( 0, (int) $value );

			case 'html':
				return wp_kses_post( (string) $value );

			case 'multiline':
				return sanitize_textarea_field( (string) $value );

			case 'key':
				return sanitize_key( (string) $value );

			case 'width':
				return in_array( $value, [ 'full', 'half' ], true ) ? $value : 'full';

			case 'type':
				$types = array_keys( Relativt_Form::field_type_labels() );
				return in_array( $value, $types, true ) ? $value : 'text';

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private function back( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'post_type' => Relativt_Form::CPT_FORM,
					'page'      => 'relativt-form-import',
					'rf_notice' => rawurlencode( $message ),
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}

endif;
