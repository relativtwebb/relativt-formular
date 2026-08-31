<?php
/**
 * Motorn i Relativt Formulär.
 *
 * Registrerar posttyper, fältgrupper, shortcode och REST-rutter, renderar
 * formulären och tar emot inskicken. Laddas av relativt-formular.php – den
 * här filen startar ingenting på egen hand.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Skydd mot dubbel inläsning. Laddas filen två gånger – pluginet aktivt
 * samtidigt som en kopia ligger kvar i mu-plugins – skulle
 * klassdeklarationen annars ge ett fatalt fel.
 *
 * OBS: skyddet MÅSTE se ut så här. PHP tidigbinder klassdeklarationer på
 * toppnivå redan vid kompileringen, alltså innan en enda rad i filen körts.
 * En vakt av typen `if (class_exists(...)) return;` FÖRE klassen ser därför
 * alltid sin egen klass som redan definierad, filen returnerar direkt och
 * raden längst ned som startar motorn nås aldrig. Klassen finns – men
 * ingenting händer. Genom att i stället göra själva deklarationen villkorlig
 * blir den runtime-bunden, och då fungerar både skyddet och uppstarten.
 */
if ( ! class_exists( 'Relativt_Form', false ) ) :

final class Relativt_Form {

	public const CPT_FORM     = 'relativt_form';
	public const CPT_ENTRY    = 'relativt_entry';
	public const REST_NS      = 'relativt-form/v1';
	public const CRON_HOOK    = 'relativt_form_cleanup';
	public const NONCE_ACTION = 'relativt_form_submit';

	/** Minsta antal sekunder mellan att token hämtas och formuläret skickas. */
	private const MIN_SECONDS = 3;

	/** Max antal inskick per IP inom fönstret nedan. */
	private const RATE_LIMIT   = 5;
	private const RATE_WINDOW  = 600; // 10 minuter.

	/** Fälttyper som har valbara alternativ. */
	private const CHOICE_TYPES = [ 'select', 'buttons', 'radio', 'checkboxes' ];

	/** Fälttyper som inte tar emot användarinmatning. */
	private const STATIC_TYPES = [ 'heading' ];

	/**
	 * Max antal länkar i en textruta innan inskicket avvisas. Länkspam är den
	 * vanligaste sortens skräp som tar sig förbi honungsfälla och tidsspärr.
	 * Justeras med filtret relativt_form_max_links; 0 stänger av kontrollen.
	 */
	private const MAX_LINKS = 3;

	/** Formulärdefinitioner per formulär-id, cachade för sidladdningen. */
	private static array $fields_cache = [];

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_post_types' ] );
		add_action( 'init', [ $this, 'register_shortcode' ] );
		add_action( 'acf/init', [ $this, 'register_fields' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

		// Admin.
		add_action( 'pre_get_posts', [ $this, 'filter_entry_list' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'admin_head', [ $this, 'admin_styles' ] );
		add_action( 'acf/save_post', [ $this, 'lock_field_keys' ], 5 );
		add_filter( 'acf/prepare_field/key=field_xf_f_cond_field', [ $this, 'populate_field_choices' ] );
		add_filter( 'acf/prepare_field/key=field_xf_r_field', [ $this, 'populate_field_choices' ] );

		// Fångar felstavade mottagaradresser redan när kunden sparar, i stället
		// för som ett tyst misslyckat mail veckan efter.
		add_filter( 'acf/validate_value/key=field_xf_to', [ $this, 'validate_recipients' ], 10, 2 );
		add_filter( 'acf/validate_value/key=field_xf_r_email', [ $this, 'validate_recipients' ], 10, 2 );
		add_filter( 'acf/validate_value/key=field_xf_from_email', [ $this, 'validate_recipients' ], 10, 2 );
		add_filter( 'manage_' . self::CPT_FORM . '_posts_columns', [ $this, 'form_columns' ] );
		add_action( 'manage_' . self::CPT_FORM . '_posts_custom_column', [ $this, 'form_column' ], 10, 2 );
		add_filter( 'manage_' . self::CPT_ENTRY . '_posts_columns', [ $this, 'entry_columns' ] );
		add_action( 'manage_' . self::CPT_ENTRY . '_posts_custom_column', [ $this, 'entry_column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'entry_filter_dropdown' ] );
		add_action( 'admin_post_relativt_form_export', [ $this, 'export_csv' ] );
		add_action( 'admin_notices', [ $this, 'mail_failure_notice' ] );

		// Gallring.
		add_action( 'after_switch_theme', [ $this, 'schedule_cleanup' ] );
		add_action( 'init', [ $this, 'schedule_cleanup' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_cleanup' ] );
	}

	/* ---------------------------------------------------------------------
	 * Stilmall och skript
	 *
	 * Ligger i pluginet, inte i temat. Det är hela poängen med att paketera
	 * motorn: filerna följer med installationen i stället för att behöva
	 * kopieras in i varje ny sajt.
	 * ------------------------------------------------------------------ */

	public function register_assets(): void {
		$url = defined( 'RELATIVT_FORM_URL' ) ? RELATIVT_FORM_URL : '';

		/*
		 * Versionen kommer från huvudfilens konstant – EN källa, inte en egen
		 * klasskonstant som kan glömmas vid release. Den cache-bustar CSS/JS:
		 * släpar den efter serveras gammal JS ur webbläsarcachen efter en
		 * uppdatering. release.yml vägrar tagga om konstanten inte stämmer.
		 */
		$version = defined( 'RELATIVT_FORM_VERSION' ) ? RELATIVT_FORM_VERSION : null;

		if ( apply_filters( 'relativt_form_enqueue_css', true ) ) {
			wp_register_style( 'relativt-formular', $url . 'assets/css/relativt-formular.css', [], $version );
		}
		wp_register_script( 'relativt-formular', $url . 'assets/js/relativt-formular.js', [], $version, true );

		/*
		 * Konfiguration till skriptet. Meddelandena delas med PHP-valideringen
		 * så att klient och server aldrig säger olika saker, och maxLinks
		 * speglar serverns länkspärr av samma skäl.
		 *
		 * rccCookie skickas bara med när Relativt Cookie Consent är aktivt på
		 * sajten. Namnet på samtyckescookien är filtrerbart där, så JS kan inte
		 * gissa det – och att avgöra "finns samtyckesverktyget?" i PHP slipper
		 * kapplöpningen om vilken skriptfil som råkar köras först.
		 */
		$config = [
			'utmCookie' => (string) apply_filters( 'relativt_form_utm_cookie', 'auto' ),
			'maxLinks'  => (int) apply_filters( 'relativt_form_max_links', self::MAX_LINKS ),
			'messages'  => $this->messages(),
		];

		if ( defined( 'RCC_VERSION' ) ) {
			$config['rccCookie'] = function_exists( 'rcc_cookie_name' )
				? (string) rcc_cookie_name()
				: 'relativt_cookie_consent';
		}

		wp_add_inline_script(
			'relativt-formular',
			'window.relativtFormConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		/*
		 * Standard är att ladda överallt. Renderas formuläret i en modal som
		 * byggs i sidfoten – vilket är fallet i de flesta sidbyggare – hinner
		 * en villkorlig laddning inte med, och stilmallen skulle då hamna
		 * efter sidan ritats. Sajter som vet att formuläret bara finns i
		 * innehållet kan filtrera till false och spara två anrop.
		 */
		if ( apply_filters( 'relativt_form_always_enqueue', true ) ) {
			$this->enqueue_assets();
		}
	}

	/** Idempotent – anropas även från shortcoden, för villkorlig laddning. */
	public function enqueue_assets(): void {
		if ( apply_filters( 'relativt_form_enqueue_css', true ) ) {
			wp_enqueue_style( 'relativt-formular' );
		}
		wp_enqueue_script( 'relativt-formular' );
	}

	/** Filtrerar inskickslistan i wp-admin på valt formulär och/eller mailstatus. */
	public function filter_entry_list( $query ): void {
		if ( ! is_admin() || ! method_exists( $query, 'is_main_query' ) || ! $query->is_main_query() ) {
			return;
		}
		if ( ( $query->get( 'post_type' ) ?: '' ) !== self::CPT_ENTRY ) {
			return;
		}

		$meta    = [];
		$form_id = (int) ( $_GET['xf_form'] ?? 0 );
		if ( $form_id ) {
			$meta[] = [ 'key' => '_xf_form_id', 'value' => $form_id ];
		}
		// Notisen om misslyckade mail länkar hit.
		if ( 'failed' === (string) ( $_GET['xf_mail'] ?? '' ) ) {
			$meta[] = [ 'key' => '_xf_mail_ok', 'value' => '0' ];
		}
		if ( $meta ) {
			$query->set( 'meta_query', $meta );
		}
	}

	/**
	 * Alla besökartexter som motorn kan behöva säga, på ett ställe. Samma
	 * lista skickas till JS via relativtFormConfig, så klient och server
	 * använder ordagrant samma formuleringar. Filtret relativt_form_messages
	 * låter en sajt byta ut dem – t.ex. till engelska – utan språkfiler.
	 *
	 * @return array<string,string>
	 */
	public function messages(): array {
		$defaults = [
			'required' => 'Fyll i detta fält.',
			'email'    => 'Kontrollera e-postadressen.',
			'tel'      => 'Ange ett giltigt telefonnummer, t.ex. 070-123 45 67.',
			'number'   => 'Ange ett nummer.',
			'date'     => 'Kontrollera datumet.',
			'choice'   => 'Ogiltigt val.',
			'links'    => 'Meddelandet innehåller för många länkar.',
			'consent'  => 'Du behöver godkänna villkoren.',
			'toofast'  => 'Det gick lite för snabbt. Vänta en sekund och försök igen.',
			'nonce'    => 'Sessionen har gått ut. Ladda om sidan och försök igen.',
			'rate'     => 'Du har skickat flera meddelanden på kort tid. Vänta en stund och försök igen.',
			'generic'  => 'Något gick fel. Försök igen om en liten stund.',
		];

		$filtered = apply_filters( 'relativt_form_messages', $defaults );

		// Bara kända nycklar, och alltid strängar – ett halvtrasigt filter ska
		// inte kunna tysta ett felmeddelande.
		$out = $defaults;
		foreach ( is_array( $filtered ) ? $filtered : [] as $key => $text ) {
			if ( isset( $defaults[ $key ] ) && is_string( $text ) && '' !== $text ) {
				$out[ $key ] = $text;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Posttyper
	 * ------------------------------------------------------------------ */

	public function register_post_types(): void {
		register_post_type(
			self::CPT_FORM,
			[
				'labels'       => [
					'name'          => 'Formulär',
					'singular_name' => 'Formulär',
					'add_new_item'  => 'Nytt formulär',
					'edit_item'     => 'Redigera formulär',
					'menu_name'     => 'Formulär',
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-feedback',
				'menu_position'=> 26,
				'supports'     => [ 'title' ],
				/*
				 * map_meta_cap MÅSTE vara true tillsammans med capability_type.
				 * Utan den mappas aldrig meta-cap:en (edit_page, delete_page) om
				 * till de riktiga rättigheterna, och då syns menyn men varje
				 * försök att öppna ett formulär svarar "du har inte behörighet".
				 */
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			]
		);

		register_post_type(
			self::CPT_ENTRY,
			[
				'labels'        => [
					'name'          => 'Inskick',
					'singular_name' => 'Inskick',
					'edit_item'     => 'Inskick',
					'menu_name'     => 'Inskick',
				],
				'public'        => false,
				'show_ui'       => true,
				'show_in_menu'  => 'edit.php?post_type=' . self::CPT_FORM,
				'supports'      => [ 'title' ],
				'capability_type' => 'page',
				'capabilities'  => [ 'create_posts' => 'do_not_allow' ],
				'map_meta_cap'  => true,
			]
		);
	}

	/* ---------------------------------------------------------------------
	 * ACF-fältgrupper
	 * ------------------------------------------------------------------ */

	/** Fälttypernas svenska namn. Delas av byggaren och fältkartan. */
	public static function field_type_labels(): array {
		return [
			'text'       => 'Text',
			'email'      => 'E-post',
			'tel'        => 'Telefon',
			'number'     => 'Nummer',
			'date'       => 'Datum',
			'textarea'   => 'Textruta',
			'select'     => 'Rullista',
			'buttons'    => 'Val-knappar',
			'radio'      => 'Radioknappar',
			'checkboxes' => 'Flerval',
			'checkbox'   => 'Kryssruta',
			'hidden'     => 'Dolt fält',
			'heading'    => 'Rubrik / avdelare',
		];
	}

	public function register_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$location = [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => self::CPT_FORM ] ] ];

		/* -- Fältbyggaren ------------------------------------------------ */
		acf_add_local_field_group( [
			'key'      => 'group_xf_fields',
			'title'    => 'Fält',
			'location' => $location,
			'menu_order' => 0,
			'fields'   => [
				[
					'key'          => 'field_xf_fields',
					'label'        => '',
					'name'         => 'xf_fields',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => 'Lägg till fält',
					// Raderna fälls ihop till sin etikett, så hela formuläret
					// syns som en lista i stället för en vägg av inmatningsfält.
					'collapsed'    => 'field_xf_f_label',
					'sub_fields'   => [
						[
							'key'     => 'field_xf_f_type',
							'label'   => 'Typ',
							'name'    => 'type',
							'type'    => 'select',
							'required'=> 1,
							'default_value' => 'text',
							'wrapper' => [ 'width' => '30' ],
							'choices' => self::field_type_labels(),
						],
						[
							'key'      => 'field_xf_f_label',
							'label'    => 'Etikett',
							'name'     => 'label',
							'type'     => 'text',
							'required' => 1,
							'wrapper'  => [ 'width' => '70' ],
						],
						/*
						 * Nyckeln döljs med CSS men MÅSTE ligga kvar i DOM:en.
						 * Tas fältet bort helt skickas det inte med i POST, och
						 * då genererar lock_field_keys() en ny nyckel utifrån
						 * etiketten vid varje sparning – döper kunden om ett
						 * fält tappar alla gamla inskick sina värden.
						 * Nyckeln syns i stället i fältkartan i sidokolumnen.
						 */
						[
							'key'      => 'field_xf_f_key',
							'label'    => 'Nyckel',
							'name'     => 'key',
							'type'     => 'text',
							'readonly' => 1,
							'wrapper'  => [ 'class' => 'xf-hidden-key' ],
						],
						[
							'key'     => 'field_xf_f_placeholder',
							'label'   => 'Platshållare',
							'name'    => 'placeholder',
							'type'    => 'text',
							'wrapper' => [ 'width' => '50' ],
							'conditional_logic' => [ [
								[ 'field' => 'field_xf_f_type', 'operator' => '!=', 'value' => 'heading' ],
							] ],
						],
						/*
						 * Hjälptexten fanns i renderaren redan i 1.0 men saknade
						 * sitt byggarfält. Under fältet på sajten; under
						 * rubriken för typen Rubrik / avdelare.
						 */
						[
							'key'          => 'field_xf_f_help',
							'label'        => 'Hjälptext',
							'name'         => 'help',
							'type'         => 'text',
							'wrapper'      => [ 'width' => '50' ],
							'instructions' => 'Valfri. Visas i mindre stil under fältet.',
						],
						[
							'key'          => 'field_xf_f_choices',
							'label'        => 'Alternativ',
							'name'         => 'choices',
							'type'         => 'textarea',
							'rows'         => 4,
							'instructions' => 'Ett alternativ per rad. Skriv "varde : Etikett" om värdet i mailet ska skilja sig från texten besökaren ser.',
							'conditional_logic' => [ [
								[ 'field' => 'field_xf_f_type', 'operator' => '==', 'value' => 'select' ],
							], [
								[ 'field' => 'field_xf_f_type', 'operator' => '==', 'value' => 'buttons' ],
							], [
								[ 'field' => 'field_xf_f_type', 'operator' => '==', 'value' => 'radio' ],
							], [
								[ 'field' => 'field_xf_f_type', 'operator' => '==', 'value' => 'checkboxes' ],
							] ],
						],
						[
							'key'     => 'field_xf_f_default',
							'label'   => 'Förvalt värde',
							'name'    => 'default',
							'type'    => 'text',
							'wrapper' => [ 'width' => '34' ],
						],
						[
							'key'     => 'field_xf_f_required',
							'label'   => 'Obligatoriskt',
							'name'    => 'required',
							'type'    => 'true_false',
							'ui'      => 1,
							'wrapper' => [ 'width' => '33' ],
						],
						[
							'key'     => 'field_xf_f_width',
							'label'   => 'Bredd',
							'name'    => 'width',
							'type'    => 'select',
							'default_value' => 'full',
							'wrapper' => [ 'width' => '33' ],
							'choices' => [ 'full' => 'Hel bredd', 'half' => 'Halv bredd' ],
						],
						[
							'key'          => 'field_xf_f_cond_field',
							'label'        => 'Visa endast om',
							'name'         => 'cond_field',
							'type'         => 'select',
							'allow_null'   => 1,
							'ui'           => 1,
							'wrapper'      => [ 'width' => '50' ],
							'instructions' => 'Lämna tomt för att alltid visa fältet.',
							'choices'      => [],
						],
						[
							'key'     => 'field_xf_f_cond_value',
							'label'   => 'har värdet',
							'name'    => 'cond_value',
							'type'    => 'text',
							'wrapper' => [ 'width' => '50' ],
							'conditional_logic' => [ [
								[ 'field' => 'field_xf_f_cond_field', 'operator' => '!=empty' ],
							] ],
						],
					],
				],
			],
		] );

		/* -- Inställningar ----------------------------------------------- */
		acf_add_local_field_group( [
			'key'        => 'group_xf_settings',
			'title'      => 'Inställningar',
			'location'   => $location,
			'menu_order' => 1,
			'fields'     => [
				[
					'key'     => 'field_xf_tab_mail',
					'label'   => 'Mottagare',
					'type'    => 'tab',
				],
				[
					'key'          => 'field_xf_to',
					'label'        => 'Standardmottagare',
					'name'         => 'xf_to',
					'type'         => 'text',
					'required'     => 1,
					'default_value'=> get_option( 'admin_email' ),
					'instructions' => 'Hit går inskicket om ingen regel nedan matchar. Flera adresser separeras med komma.',
				],
				[
					'key'          => 'field_xf_subject',
					'label'        => 'Ämnesrad',
					'name'         => 'xf_subject',
					'type'         => 'text',
					'default_value'=> 'Nytt meddelande från webbplatsen',
					'instructions' => 'Du kan använda fältnycklar inom klammer, t.ex. Nytt meddelande från {namn}.',
				],
				[
					'key'          => 'field_xf_rules',
					'label'        => 'Regler',
					'name'         => 'xf_rules',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Lägg till regel',
					'instructions' => 'Första regel som matchar vinner. Matchar ingen används standardmottagaren.',
					'sub_fields'   => [
						[
							'key'        => 'field_xf_r_field',
							'label'      => 'Om fältet',
							'name'       => 'field',
							'type'       => 'select',
							'allow_null' => 1,
							'ui'         => 1,
							'choices'    => [],
						],
						[ 'key' => 'field_xf_r_value', 'label' => 'har värdet', 'name' => 'value', 'type' => 'text' ],
						[ 'key' => 'field_xf_r_email', 'label' => 'skicka till', 'name' => 'email', 'type' => 'text' ],
						[ 'key' => 'field_xf_r_subject', 'label' => 'med ämnesrad', 'name' => 'subject', 'type' => 'text' ],
					],
				],
				[
					'key'          => 'field_xf_from_name',
					'label'        => 'Från-namn',
					'name'         => 'xf_from_name',
					'type'         => 'text',
					'default_value'=> get_bloginfo( 'name' ),
					'wrapper'      => [ 'width' => '50' ],
				],
				[
					'key'          => 'field_xf_from_email',
					'label'        => 'Från-adress',
					'name'         => 'xf_from_email',
					'type'         => 'text',
					'wrapper'      => [ 'width' => '50' ],
					'instructions' => 'Måste ligga på webbplatsens egen domän. Aldrig besökarens adress – då studsar mailet. Svara-till sätts automatiskt till besökaren.',
				],

				[ 'key' => 'field_xf_tab_texts', 'label' => 'Texter', 'type' => 'tab' ],
				[
					'key'          => 'field_xf_submit_text',
					'label'        => 'Knapptext',
					'name'         => 'xf_submit_text',
					'type'         => 'text',
					'default_value'=> 'Skicka',
					'wrapper'      => [ 'width' => '50' ],
				],
				[
					'key'          => 'field_xf_sending_text',
					'label'        => 'Text under sändning',
					'name'         => 'xf_sending_text',
					'type'         => 'text',
					'default_value'=> 'Skickar…',
					'wrapper'      => [ 'width' => '50' ],
				],
				[
					'key'   => 'field_xf_consent',
					'label' => 'Samtyckestext',
					'name'  => 'xf_consent',
					'type'  => 'wysiwyg',
					'media_upload' => 0,
					'toolbar'      => 'basic',
					'instructions' => 'Visas under knappen. Länka till integritetspolicyn här.',
				],
				[
					'key'          => 'field_xf_consent_box',
					'label'        => 'Kräv ikryssad ruta',
					'name'         => 'xf_consent_box',
					'type'         => 'true_false',
					'ui'           => 1,
					'instructions' => 'Av som standard. Slå på om ni vill ha ett aktivt samtycke istället för texten ovan.',
				],
				[
					'key'          => 'field_xf_thanks_title',
					'label'        => 'Tack-rubrik',
					'name'         => 'xf_thanks_title',
					'type'         => 'text',
					'default_value'=> 'Tack för ditt meddelande!',
				],
				[
					'key'          => 'field_xf_thanks_text',
					'label'        => 'Tack-text',
					'name'         => 'xf_thanks_text',
					'type'         => 'textarea',
					'rows'         => 3,
					'default_value'=> 'Vi återkommer till dig så snart vi kan.',
				],
				[
					'key'          => 'field_xf_error_text',
					'label'        => 'Felmeddelande',
					'name'         => 'xf_error_text',
					'type'         => 'text',
					'default_value'=> 'Något gick fel. Försök igen, eller mejla oss direkt.',
				],

				[ 'key' => 'field_xf_tab_data', 'label' => 'Lagring', 'type' => 'tab' ],
				[
					'key'          => 'field_xf_store',
					'label'        => 'Spara inskick i WordPress',
					'name'         => 'xf_store',
					'type'         => 'true_false',
					'ui'           => 1,
					'default_value'=> 1,
					'instructions' => 'Säkerhetsnät om ett mail skulle studsa.',
				],
				[
					'key'          => 'field_xf_retention',
					'label'        => 'Radera inskick efter (dagar)',
					'name'         => 'xf_retention',
					'type'         => 'number',
					'default_value'=> 365,
					'min'          => 0,
					'instructions' => '0 = radera aldrig. Gallringen körs en gång per dygn.',
				],
				[
					'key'          => 'field_xf_log_ip',
					'label'        => 'Spara IP-adress',
					'name'         => 'xf_log_ip',
					'type'         => 'true_false',
					'ui'           => 1,
					'default_value'=> 1,
					'instructions' => 'IP-adress är en personuppgift. Stäng av om ni inte behöver den.',
				],
			],
		] );
	}

	/**
	 * Autogenererar och låser fältnycklar. Nyckeln sätts en gång och ändras
	 * aldrig – det är den som binder ihop inskick, mail och villkor.
	 */
	public function lock_field_keys( $post_id ): void {
		if ( ! is_numeric( $post_id ) || get_post_type( $post_id ) !== self::CPT_FORM ) {
			return;
		}

		$rows = $_POST['acf']['field_xf_fields'] ?? null;
		if ( ! is_array( $rows ) ) {
			return;
		}

		$used = [];
		foreach ( $rows as $i => $row ) {
			$key = sanitize_key( $row['field_xf_f_key'] ?? '' );

			if ( '' === $key ) {
				$base = sanitize_key( $this->slugify( $row['field_xf_f_label'] ?? '' ) );
				$base = '' !== $base ? $base : 'falt';
				$key  = $base;
				$n    = 2;
				while ( in_array( $key, $used, true ) ) {
					$key = $base . '_' . $n++;
				}
			}

			$used[] = $key;
			$_POST['acf']['field_xf_fields'][ $i ]['field_xf_f_key'] = $key;
		}
	}

	/** Fyller villkors- och regelväljarna med formulärets egna fält. */
	public function populate_field_choices( $field ) {
		$post_id = $this->current_admin_post_id();
		if ( ! $post_id ) {
			return $field;
		}

		$choices = [];
		foreach ( $this->get_fields( $post_id ) as $f ) {
			if ( in_array( $f['type'], self::STATIC_TYPES, true ) || '' === $f['key'] ) {
				continue;
			}
			$choices[ $f['key'] ] = sprintf( '%s (%s)', $f['label'], $f['key'] );
		}

		$field['choices'] = $choices;
		return $field;
	}

	/**
	 * Validerar mottagar- och avsändaradresser i wp-admin. Tillåter flera
	 * adresser separerade med komma.
	 *
	 * @param bool|string $valid
	 * @param mixed       $value
	 * @return bool|string true, eller ett felmeddelande som ACF visar.
	 */
	public function validate_recipients( $valid, $value ) {
		if ( true !== $valid ) {
			return $valid; // Någon annan regel har redan sagt ifrån.
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $valid;
		}

		$bad = [];
		foreach ( array_map( 'trim', explode( ',', $value ) ) as $address ) {
			if ( '' !== $address && ! $this->valid_email( $address ) ) {
				$bad[] = $address;
			}
		}

		if ( ! $bad ) {
			return true;
		}

		return sprintf(
			1 === count( $bad ) ? 'Ogiltig e-postadress: %s' : 'Ogiltiga e-postadresser: %s',
			implode( ', ', $bad )
		);
	}

	private function current_admin_post_id(): int {
		$id = (int) ( $_GET['post'] ?? $_POST['post_ID'] ?? 0 );
		return $id > 0 && get_post_type( $id ) === self::CPT_FORM ? $id : 0;
	}

	/* ---------------------------------------------------------------------
	 * Läsning av formulärdefinitionen
	 * ------------------------------------------------------------------ */

	/**
	 * Tömmer definitionscachen. Behövs när en definition ändras under samma
	 * körning – i praktiken av testerna, som annars mäter cachen i stället
	 * för koden.
	 */
	public static function flush_fields_cache(): void {
		self::$fields_cache = [];
	}

	/** @return array<int,array<string,mixed>> */
	public function get_fields( int $form_id ): array {
		$cache = &self::$fields_cache;
		if ( isset( $cache[ $form_id ] ) ) {
			return $cache[ $form_id ];
		}

		$rows   = function_exists( 'get_field' ) ? get_field( 'xf_fields', $form_id ) : null;
		$fields = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$type = (string) ( $row['type'] ?? 'text' );
			$key  = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key && ! in_array( $type, self::STATIC_TYPES, true ) ) {
				continue;
			}

			$fields[] = [
				'type'        => $type,
				'key'         => $key,
				'label'       => (string) ( $row['label'] ?? '' ),
				'placeholder' => (string) ( $row['placeholder'] ?? '' ),
				'help'        => (string) ( $row['help'] ?? '' ),
				'choices'     => $this->parse_choices( (string) ( $row['choices'] ?? '' ) ),
				'default'     => (string) ( $row['default'] ?? '' ),
				'required'    => ! empty( $row['required'] ),
				'width'       => ( $row['width'] ?? 'full' ) === 'half' ? 'half' : 'full',
				'cond_field'  => sanitize_key( (string) ( $row['cond_field'] ?? '' ) ),
				'cond_value'  => (string) ( $row['cond_value'] ?? '' ),
			];
		}

		return $cache[ $form_id ] = $fields;
	}

	/**
	 * "varde : Etikett" eller bara "Etikett" per rad.
	 *
	 * @return array<string,string> värde => etikett
	 */
	private function parse_choices( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( str_contains( $line, ':' ) ) {
				[ $value, $label ] = array_map( 'trim', explode( ':', $line, 2 ) );
			} else {
				$value = $label = $line;
			}
			if ( '' !== $value ) {
				$out[ $value ] = $label;
			}
		}
		return $out;
	}

	/**
	 * Hämtar en inställning för ett formulär.
	 *
	 * Kedjan är formulärets eget värde → sajtens standardvärde → koden's
	 * fallback. Standardvärdena gör att ett nytt formulär inte börjar tomt
	 * på varje ny sajt: avsändarnamn, tackrutans texter och samtyckestexten
	 * sätts en gång under Formulär → Standardvärden och ärvs sedan.
	 */
	private function setting( int $form_id, string $name, $fallback = '' ) {
		$value = function_exists( 'get_field' ) ? get_field( $name, $form_id ) : null;

		if ( null !== $value && '' !== $value ) {
			return $value;
		}

		if ( class_exists( 'Relativt_Form_Settings', false ) ) {
			$default = Relativt_Form_Settings::get( $name );
			if ( '' !== $default ) {
				return $default;
			}
		}

		return $fallback;
	}

	/* ---------------------------------------------------------------------
	 * Shortcode och rendering
	 * ------------------------------------------------------------------ */

	public function register_shortcode(): void {
		add_shortcode( 'relativt_formular', [ $this, 'render_shortcode' ] );
	}

	public function render_shortcode( $atts ): string {
		$atts    = is_array( $atts ) ? $atts : [];
		$form_id = (int) ( $atts['id'] ?? 0 );

		if ( ! $form_id || get_post_type( $form_id ) !== self::CPT_FORM ) {
			return current_user_can( 'edit_posts' )
				? '<p class="relativt-form-notice">Formuläret hittades inte. Kontrollera id i shortcoden.</p>'
				: '';
		}

		$fields = $this->get_fields( $form_id );
		if ( ! $fields ) {
			return current_user_can( 'edit_posts' )
				? '<p class="relativt-form-notice">Formuläret saknar fält.</p>'
				: '';
		}

		// Idempotent: har relativt_form_always_enqueue stängts av laddas
		// filerna först här, när vi vet att ett formulär faktiskt renderas.
		if ( function_exists( 'wp_enqueue_style' ) ) {
			$this->enqueue_assets();
		}

		// Alla shortcode-attribut som matchar en fältnyckel blir förvalt värde.
		$presets = [];
		foreach ( $fields as $f ) {
			if ( '' !== $f['key'] && isset( $atts[ $f['key'] ] ) ) {
				$presets[ $f['key'] ] = (string) $atts[ $f['key'] ];
			}
		}

		return $this->render_form( $form_id, $presets, (string) ( $atts['class'] ?? '' ) );
	}

	private function render_form( int $form_id, array $presets, string $extra_class = '' ): string {
		$fields = $this->get_fields( $form_id );
		$uid    = 'xf-' . $form_id . '-' . wp_rand( 1000, 9999 );

		/*
		 * Villkoren utvärderas redan här på servern, utifrån förval och
		 * standardvärden. Rätt fält är alltså synligt direkt vid första
		 * målningen – ingen blink, och formuläret fungerar även om JS inte
		 * hinner eller lyckas laddas. JS tar sedan över vid varje ändring.
		 */
		$resolved = [];
		foreach ( $fields as $f ) {
			if ( '' === $f['key'] ) {
				continue;
			}
			$value                 = $presets[ $f['key'] ] ?? $f['default'];
			$resolved[ $f['key'] ] = [ $value, $f['choices'][ $value ] ?? $value ];
		}

		$classes = [ 'relativt-form' ];
		foreach ( preg_split( '/\s+/', trim( $extra_class ) ) ?: [] as $c ) {
			if ( '' !== $c ) {
				$classes[] = sanitize_html_class( $c );
			}
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-xf-form="<?php echo esc_attr( (string) $form_id ); ?>"
			data-xf-rest="<?php echo esc_url( rest_url( self::REST_NS . '/' ) ); ?>">

			<form class="xf-form" novalidate autocomplete="on">
				<div class="xf-grid">
					<?php foreach ( $fields as $field ) : ?>
						<?php echo $this->render_field( $field, $uid, $presets, $resolved ); // phpcs:ignore ?>
					<?php endforeach; ?>
				</div>

				<?php if ( $this->setting( $form_id, 'xf_consent_box' ) ) : ?>
					<div class="xf-field xf-field--full xf-type-consent">
						<label class="xf-check">
							<input type="checkbox" name="xf_consent" value="1" class="xf-check-input" required>
							<span class="xf-check-box" aria-hidden="true"></span>
							<span class="xf-check-text"><?php echo wp_kses_post( $this->setting( $form_id, 'xf_consent', '' ) ); ?></span>
						</label>
						<p class="xf-error" data-xf-error="xf_consent" role="alert"></p>
					</div>
				<?php endif; ?>

				<div class="xf-actions">
					<?php
					/*
					 * Knappen bär inga temaklasser som standard – motorn ska
					 * fungera lika bra på en sajt utan sidbyggare. Sajter som
					 * vill ärva sitt eget knapputseende filtrerar in sina
					 * klasser i stället; på en Oxygen-sajt är det typiskt
					 * 'btn' på knappen, 'ct-text-block' på texten och
					 * 'ct-fancy-icon' på ikonen, eftersom temats
					 * knappanimation letar efter just dem.
					 *
					 * Ikonen är icon-right ur Material Symbols. fill är
					 * currentColor så den ärver knappens textfärg – med en
					 * hårdkodad färg blir den fel så fort knappen byter kulör.
					 * Storleken styrs av --xf-icon-size i CSS:en.
					 */
					$submit_classes = trim( 'xf-submit ' . (string) apply_filters( 'relativt_form_submit_class', '', $form_id ) );
					$text_classes   = trim( 'xf-submit-text ' . (string) apply_filters( 'relativt_form_submit_text_class', '', $form_id ) );
					$icon_classes   = trim( 'xf-submit-icon ' . (string) apply_filters( 'relativt_form_submit_icon_class', '', $form_id ) );
					$icon           = (string) apply_filters(
						'relativt_form_submit_icon',
						'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="m531.69-480-184-184L376-692.31 588.31-480 376-267.69 347.69-296l184-184Z"/></svg>',
						$form_id
					);
					?>
					<button type="submit" class="<?php echo esc_attr( $submit_classes ); ?>"
						data-xf-label="<?php echo esc_attr( $this->setting( $form_id, 'xf_submit_text', 'Skicka' ) ); ?>"
						data-xf-sending="<?php echo esc_attr( $this->setting( $form_id, 'xf_sending_text', 'Skickar…' ) ); ?>">
						<span class="<?php echo esc_attr( $text_classes ); ?>"><?php echo esc_html( $this->setting( $form_id, 'xf_submit_text', 'Skicka' ) ); ?></span>
						<?php if ( '' !== $icon ) : ?>
							<span class="<?php echo esc_attr( $icon_classes ); ?>" aria-hidden="true"><?php echo wp_kses( $icon, self::svg_kses() ); ?></span>
						<?php endif; ?>
					</button>
				</div>

				<?php if ( ! $this->setting( $form_id, 'xf_consent_box' ) && $this->setting( $form_id, 'xf_consent', '' ) ) : ?>
					<div class="xf-consent"><?php echo wp_kses_post( $this->setting( $form_id, 'xf_consent', '' ) ); ?></div>
				<?php endif; ?>

				<p class="xf-form-error" role="alert" aria-live="polite"></p>

				<?php // Honungsfälla. Osynlig för människor, ifylld av bottar. ?>
				<div class="xf-hp" aria-hidden="true">
					<label>Lämna detta fält tomt
						<input type="text" name="xf_website" tabindex="-1" autocomplete="off">
					</label>
				</div>
			</form>

			<?php // tabindex="-1" så att JS kan flytta fokus hit vid tack-läget – utan det är focus() en tyst no-op på en div. ?>
			<div class="xf-thanks" role="status" aria-live="polite" tabindex="-1" hidden>
				<p class="xf-thanks-title"><?php echo esc_html( $this->setting( $form_id, 'xf_thanks_title', 'Tack!' ) ); ?></p>
				<p class="xf-thanks-text"><?php echo esc_html( $this->setting( $form_id, 'xf_thanks_text', '' ) ); ?></p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_field( array $f, string $uid, array $presets, array $resolved = [] ): string {
		$type  = $f['type'];
		$key   = $f['key'];
		$id    = $uid . '-' . ( '' !== $key ? $key : 'x' );
		$name  = 'fields[' . $key . ']';
		$value = $presets[ $key ] ?? $f['default'];

		$wrap_classes = [ 'xf-field', 'xf-field--' . $f['width'], 'xf-type-' . sanitize_html_class( $type ) ];

		$attrs = [
			'class'      => implode( ' ', $wrap_classes ),
			'data-xf-key'=> $key,
		];
		/*
		 * JS läser flaggan för klientvalideringen. required-ATTRIBUTET räcker
		 * inte: en flervalsgrupp kan inte bära det (då kräver webbläsaren ALLA
		 * rutor), så utan flaggan validerades obligatoriska flerval bara på
		 * servern – en extra rundresa för besökaren.
		 */
		if ( $f['required'] && ! in_array( $type, self::STATIC_TYPES, true ) ) {
			$attrs['data-xf-required'] = '1';
		}
		if ( '' !== $f['cond_field'] ) {
			$attrs['data-xf-cond-field'] = $f['cond_field'];
			$attrs['data-xf-cond-value'] = $f['cond_value'];

			// Dölj bara om villkoret INTE är uppfyllt redan vid renderingen.
			if ( ! $this->matches_any( $resolved[ $f['cond_field'] ] ?? [], $f['cond_value'] ) ) {
				$attrs['hidden'] = 'hidden';
			}
		}
		if ( 'hidden' === $type ) {
			$attrs['hidden'] = 'hidden';
		}

		$attr_str = '';
		foreach ( $attrs as $k => $v ) {
			$attr_str .= ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
		}

		ob_start();

		if ( 'heading' === $type ) {
			echo '<div' . $attr_str . '>'; // phpcs:ignore
			if ( '' !== $f['label'] ) {
				echo '<p class="xf-heading">' . esc_html( $f['label'] ) . '</p>';
			}
			if ( '' !== $f['help'] ) {
				echo '<p class="xf-help">' . esc_html( $f['help'] ) . '</p>';
			}
			echo '</div>';
			return (string) ob_get_clean();
		}

		if ( 'hidden' === $type ) {
			echo '<div' . $attr_str . '><input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></div>'; // phpcs:ignore
			return (string) ob_get_clean();
		}

		$required = $f['required'] ? ' required' : '';
		$ph       = '' !== $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';

		echo '<div' . $attr_str . '>'; // phpcs:ignore

		/*
		 * Gruppfälten (val-knappar, radio, flerval) kan inte använda <label for>
		 * för själva gruppen. Etiketten får därför ett id, och gruppens wrapper
		 * pekar tillbaka med aria-labelledby – annars presenterar skärmläsaren
		 * en namnlös grupp.
		 */
		$group_label = in_array( $type, [ 'buttons', 'radio', 'checkboxes' ], true );
		$label_id    = $group_label && '' !== $f['label'] ? $id . '-label' : '';
		$labelledby  = '' !== $label_id ? ' aria-labelledby="' . esc_attr( $label_id ) . '"' : '';

		if ( 'checkbox' !== $type && '' !== $f['label'] ) {
			echo $group_label // phpcs:ignore
				? '<span class="xf-label" id="' . esc_attr( $label_id ) . '">' . esc_html( $f['label'] ) . ( $f['required'] ? '<span class="xf-req" aria-hidden="true">*</span>' : '' ) . '</span>'
				: '<label class="xf-label" for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . ( $f['required'] ? '<span class="xf-req" aria-hidden="true">*</span>' : '' ) . '</label>';
		}

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea class="xf-input xf-textarea" id="%s" name="%s" rows="6"%s%s>%s</textarea>',
					esc_attr( $id ), esc_attr( $name ), $ph, $required, esc_textarea( $value ) // phpcs:ignore
				);
				break;

			case 'select':
				echo '<div class="xf-select-wrap">';
				printf( '<select class="xf-input xf-select" id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $required ); // phpcs:ignore
				if ( '' !== $f['placeholder'] ) {
					printf( '<option value=""%s>%s</option>', selected( $value, '', false ), esc_html( $f['placeholder'] ) );
				}
				foreach ( $f['choices'] as $val => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $value, $val, false ), esc_html( $label ) );
				}
				echo '</select><span class="xf-select-arrow" aria-hidden="true"></span></div>';
				break;

			case 'buttons':
			case 'radio':
				$wrapper = 'buttons' === $type ? 'xf-buttons' : 'xf-radios';
				echo '<div class="' . esc_attr( $wrapper ) . '" role="radiogroup"' . $labelledby . '>'; // phpcs:ignore
				$i = 0;
				foreach ( $f['choices'] as $val => $label ) {
					$oid = $id . '-' . $i++;
					printf(
						'<input class="xf-choice-input" type="radio" id="%s" name="%s" value="%s"%s%s><label class="xf-choice-label" for="%s">%s</label>',
						esc_attr( $oid ), esc_attr( $name ), esc_attr( $val ),
						checked( $value, $val, false ), $required, // phpcs:ignore
						esc_attr( $oid ), esc_html( $label )
					);
				}
				echo '</div>';
				break;

			case 'checkboxes':
				echo '<div class="xf-checks" role="group"' . $labelledby . '>'; // phpcs:ignore
				$selected = array_map( 'trim', explode( ',', $value ) );
				$i        = 0;
				foreach ( $f['choices'] as $val => $label ) {
					$oid = $id . '-' . $i++;
					printf(
						'<label class="xf-check" for="%s"><input class="xf-check-input" type="checkbox" id="%s" name="%s[]" value="%s"%s><span class="xf-check-box" aria-hidden="true"></span><span class="xf-check-text">%s</span></label>',
						esc_attr( $oid ), esc_attr( $oid ), esc_attr( $name ), esc_attr( $val ),
						checked( in_array( (string) $val, $selected, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '</div>';
				break;

			case 'checkbox':
				printf(
					'<label class="xf-check" for="%s"><input class="xf-check-input" type="checkbox" id="%s" name="%s" value="1"%s%s><span class="xf-check-box" aria-hidden="true"></span><span class="xf-check-text">%s</span></label>',
					esc_attr( $id ), esc_attr( $id ), esc_attr( $name ),
					checked( $value, '1', false ), $required, // phpcs:ignore
					esc_html( $f['label'] )
				);
				break;

			default:
				$input_type = in_array( $type, [ 'email', 'tel', 'number', 'date' ], true ) ? $type : 'text';

				// Rätt tangentbord på mobil och ifyllnadshjälp från webbläsaren.
				$hints = [
					'email' => ' inputmode="email" autocomplete="email" spellcheck="false"',
					'tel'   => ' inputmode="tel" autocomplete="tel"',
				][ $type ] ?? '';

				printf(
					'<input class="xf-input" type="%s" id="%s" name="%s" value="%s"%s%s%s>',
					esc_attr( $input_type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), $ph, $hints, $required // phpcs:ignore
				);
		}

		/*
		 * Hjälptexten och felraden bär id:n så att JS kan koppla ihop dem med
		 * fältet via aria-describedby – skärmläsaren läser då upp både hjälpen
		 * och felet i samband med fältet, inte som lösryckta rader.
		 */
		if ( '' !== $f['help'] && 'heading' !== $type ) {
			echo '<p class="xf-help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $f['help'] ) . '</p>';
		}

		echo '<p class="xf-error" id="' . esc_attr( $id . '-error' ) . '" data-xf-error="' . esc_attr( $key ) . '" role="alert"></p>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public function register_routes(): void {
		register_rest_route( self::REST_NS, '/token', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_token' ],
			'args'                => [ 'form' => [ 'required' => true ] ],
		] );

		register_rest_route( self::REST_NS, '/submit', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_submit' ],
		] );
	}

	/**
	 * Färsk nonce och signerad tidsstämpel. Hämtas av JS vid första
	 * interaktionen, så att sidcachning inte serverar utgångna nonces.
	 */
	public function rest_token( WP_REST_Request $request ) {
		$form_id = (int) $request->get_param( 'form' );
		if ( get_post_type( $form_id ) !== self::CPT_FORM ) {
			return new WP_Error( 'xf_no_form', 'Okänt formulär.', [ 'status' => 404 ] );
		}

		$ts = time();
		return [
			'nonce' => wp_create_nonce( self::NONCE_ACTION . '_' . $form_id ),
			'ts'    => $ts,
			'sig'   => $this->sign( $form_id . '|' . $ts ),
		];
	}

	public function rest_submit( WP_REST_Request $request ) {
		$body    = $request->get_json_params() ?: $request->get_params();
		$form_id = (int) ( $body['form'] ?? 0 );
		$msg     = $this->messages();

		if ( get_post_type( $form_id ) !== self::CPT_FORM ) {
			return $this->fail( 'Okänt formulär.', 404 );
		}

		// 1. Honungsfälla.
		if ( '' !== trim( (string) ( $body['xf_website'] ?? '' ) ) ) {
			return $this->fake_success( $form_id );
		}

		// 2. Nonce.
		$nonce = (string) ( $body['nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $form_id ) ) {
			return $this->fail( $msg['nonce'], 403, [ 'code' => 'nonce' ] );
		}

		// 3. Tidsspärr – signerad så den inte kan förfalskas.
		$ts  = (int) ( $body['ts'] ?? 0 );
		$sig = (string) ( $body['sig'] ?? '' );
		if ( ! hash_equals( $this->sign( $form_id . '|' . $ts ), $sig ) ) {
			return $this->fail( 'Ogiltig begäran.', 400, [ 'code' => 'sig' ] );
		}

		/*
		 * För snabbt inskick. I 1.0 svarade den här spärren med fejkad succé,
		 * som honungsfällan – men det åt riktiga inskick: token hämtas vid
		 * första fokus, och en besökare med autofyll fyller allt på en sekund
		 * och klickar Skicka. Då försvann leadet SPÅRLÖST, med "Tack!" på
		 * skärmen. Nu svarar spärren med ett mjukt fel som JS tyst gör om
		 * efter väntetiden – besökaren märker ingenting, medan en bot som
		 * postar direkt mot REST-rutten får ett fel i stället för en succé.
		 */
		$elapsed = time() - $ts;
		if ( $elapsed < self::MIN_SECONDS ) {
			return $this->fail( $msg['toofast'], 425, [
				'code'        => 'toofast',
				'retry_after' => max( 1, self::MIN_SECONDS - $elapsed ),
			] );
		}

		// 4. Frekvensspärr.
		if ( $this->rate_limited() ) {
			return $this->fail( $msg['rate'], 429, [ 'code' => 'rate' ] );
		}

		// 5. Validering.
		$raw    = is_array( $body['fields'] ?? null ) ? $body['fields'] : [];
		$result = $this->validate( $form_id, $raw );

		if ( $result['errors'] ) {
			return new WP_REST_Response( [ 'ok' => false, 'errors' => $result['errors'] ], 422 );
		}

		if ( $this->setting( $form_id, 'xf_consent_box' ) && empty( $body['xf_consent'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'errors' => [ 'xf_consent' => $msg['consent'] ] ], 422 );
		}

		$values = $result['values'];
		$meta   = $this->collect_meta( $form_id, $body );

		$entry_id = $this->setting( $form_id, 'xf_store', true )
			? $this->store_entry( $form_id, $values, $meta )
			: 0;

		$sent = $this->send_mail( $form_id, $values, $meta );

		if ( $entry_id ) {
			update_post_meta( $entry_id, '_xf_mail_ok', $sent ? '1' : '0' );
		}

		if ( ! $sent && ! $entry_id ) {
			return $this->fail( (string) $this->setting( $form_id, 'xf_error_text', 'Något gick fel.' ), 500, [ 'code' => 'mail' ] );
		}

		return new WP_REST_Response( [
			'ok'     => true,
			'title'  => (string) $this->setting( $form_id, 'xf_thanks_title', 'Tack!' ),
			'text'   => (string) $this->setting( $form_id, 'xf_thanks_text', '' ),
		], 200 );
	}

	private function fail( string $message, int $status, array $extra = [] ): WP_REST_Response {
		return new WP_REST_Response( array_merge( [ 'ok' => false, 'message' => $message ], $extra ), $status );
	}

	/** Bottar ska tro att det gick bra. */
	private function fake_success( int $form_id ): WP_REST_Response {
		return new WP_REST_Response( [
			'ok'    => true,
			'title' => (string) $this->setting( $form_id, 'xf_thanks_title', 'Tack!' ),
			'text'  => (string) $this->setting( $form_id, 'xf_thanks_text', '' ),
		], 200 );
	}

	private function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, wp_salt( 'relativt_form' ) );
	}

	private function rate_limited(): bool {
		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return false;
		}
		$key   = 'xf_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Validering
	 * ------------------------------------------------------------------ */

	/**
	 * Validerar och sanerar. Fält som är dolda av sitt villkor valideras inte
	 * och tas inte med i resultatet.
	 *
	 * @return array{values:array<int,array>,errors:array<string,string>}
	 */
	public function validate( int $form_id, array $raw ): array {
		$fields  = $this->get_fields( $form_id );
		$msg     = $this->messages();
		$flat    = [];
		$errors  = [];
		$values  = [];

		/*
		 * Första passet: platt karta av inskickade värden för villkorstesterna.
		 * Både det tekniska värdet och den synliga etiketten sparas, eftersom
		 * kunden i wp-admin skriver det den SER ("Kandidat") minst lika ofta
		 * som det som ligger bakom ("kandidat").
		 */
		foreach ( $fields as $f ) {
			if ( '' === $f['key'] ) {
				continue;
			}
			$submitted         = $this->flatten( $raw[ $f['key'] ] ?? '' );
			$flat[ $f['key'] ] = [ $submitted, $f['choices'][ $submitted ] ?? $submitted ];
		}

		foreach ( $fields as $f ) {
			$key = $f['key'];
			if ( in_array( $f['type'], self::STATIC_TYPES, true ) || '' === $key ) {
				continue;
			}

			// Villkor – dolda fält hoppas över helt.
			if ( '' !== $f['cond_field'] ) {
				if ( ! $this->matches_any( $flat[ $f['cond_field'] ] ?? [], $f['cond_value'] ) ) {
					continue;
				}
			}

			$value = $raw[ $key ] ?? '';
			$type  = $f['type'];

			// Sanering per typ.
			if ( in_array( $type, [ 'checkboxes' ], true ) ) {
				$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [];
				$value = array_values( array_intersect( $value, array_keys( $f['choices'] ) ) );
				$store = implode( ', ', array_map( fn( $v ) => $f['choices'][ $v ] ?? $v, $value ) );
			} elseif ( 'textarea' === $type ) {
				$store = sanitize_textarea_field( (string) $this->flatten( $value ) );
				$store = mb_substr( $store, 0, 5000 );
			} elseif ( 'email' === $type ) {
				$store = sanitize_email( (string) $this->flatten( $value ) );
			} elseif ( in_array( $type, self::CHOICE_TYPES, true ) ) {
				$store = (string) $this->flatten( $value );
				if ( '' !== $store && ! isset( $f['choices'][ $store ] ) ) {
					$errors[ $key ] = $msg['choice'];
					continue;
				}
				$store = '' !== $store ? ( $f['choices'][ $store ] ?? $store ) : '';
			} elseif ( 'checkbox' === $type ) {
				$store = ! empty( $value ) ? 'Ja' : 'Nej';
			} else {
				$store = sanitize_text_field( (string) $this->flatten( $value ) );
				$store = mb_substr( $store, 0, 500 );
			}

			$empty = is_array( $value ) ? ! $value : ( '' === trim( (string) $store ) );
			if ( 'checkbox' === $type ) {
				$empty = empty( $raw[ $key ] );
			}

			if ( $f['required'] && $empty ) {
				$errors[ $key ] = $msg['required'];
				continue;
			}

			if ( ! $empty ) {
				if ( 'email' === $type && ! $this->valid_email( $store ) ) {
					$errors[ $key ] = $msg['email'];
					continue;
				}
				if ( 'tel' === $type ) {
					$normalized = $this->normalize_phone( $store );
					if ( null === $normalized ) {
						$errors[ $key ] = $msg['tel'];
						continue;
					}
					// Sparas normaliserat så numret går att ringa rakt ur mailet.
					$store = $normalized;
				}
				if ( 'number' === $type && ! is_numeric( $store ) ) {
					$errors[ $key ] = $msg['number'];
					continue;
				}
				// Formatet räcker inte: 2026-13-45 matchar regexen. checkdate()
				// avgör om datumet faktiskt finns i kalendern.
				if ( 'date' === $type ) {
					$is_date = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $store, $dm )
						&& checkdate( (int) $dm[2], (int) $dm[3], (int) $dm[1] );
					if ( ! $is_date ) {
						$errors[ $key ] = $msg['date'];
						continue;
					}
				}
				/*
				 * Länkspärren. Länkspam är den vanligaste sortens skräp som tar
				 * sig förbi honungsfälla och tidsspärr – ett riktigt B2B-ärende
				 * innehåller sällan mer än någon enstaka länk. Speglas i JS.
				 */
				if ( 'textarea' === $type ) {
					$max_links = (int) apply_filters( 'relativt_form_max_links', self::MAX_LINKS );
					if ( $max_links > 0 && preg_match_all( '/https?:\/\/|www\./i', $store ) > $max_links ) {
						$errors[ $key ] = $msg['links'];
						continue;
					}
				}
			}

			$values[] = [
				'key'   => $key,
				'label' => $f['label'],
				'type'  => $type,
				'value' => $store,
				'raw'   => is_array( $value ) ? implode( ', ', $value ) : (string) $this->flatten( $value ),
			];
		}

		return [ 'values' => $values, 'errors' => $errors ];
	}

	/* ---------------------------------------------------------------------
	 * E-post och telefon
	 *
	 * Reglerna finns i identisk form i relativt-formular.js. Ändras den ena MÅSTE
	 * den andra ändras – annars säger webbläsaren en sak och servern en annan.
	 * ------------------------------------------------------------------ */

	/**
	 * Strängare än is_email(): fångar också sådant WordPress släpper igenom,
	 * som dubbla punkter, punkt intill snabel-a och toppdomäner på en bokstav.
	 */
	public function valid_email( string $email ): bool {
		$email = trim( $email );

		/*
		 * Svenska IDN-domäner (räksmörgås.se) skrivs med å ä ö men lagras som
		 * punycode. Översätt domändelen innan is_email() får se den, annars
		 * avvisas en fullt fungerande adress.
		 */
		if ( preg_match( '/[^\x00-\x7F]/', $email ) && function_exists( 'idn_to_ascii' ) ) {
			$at = strrpos( $email, '@' );
			if ( false !== $at ) {
				$ascii = idn_to_ascii( substr( $email, $at + 1 ), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( $ascii ) {
					$email = substr( $email, 0, $at + 1 ) . $ascii;
				}
			}
		}

		if ( ! is_email( $email ) ) {
			return false;
		}
		if ( str_contains( $email, '..' ) || str_contains( $email, '.@' ) || str_contains( $email, '@.' ) ) {
			return false;
		}

		$domain = substr( strrchr( $email, '@' ) ?: '', 1 );
		if ( ! str_contains( $domain, '.' ) ) {
			return false;
		}

		$tld = substr( strrchr( $domain, '.' ) ?: '', 1 );
		return (bool) preg_match( '/^[a-z]{2,}$/i', $tld );
	}

	/**
	 * Normaliserar ett telefonnummer och returnerar null om det inte kan vara
	 * ett riktigt nummer. Svenska format i första hand, men internationella
	 * nummer släpps igenom – formulär kan ta emot avsändare utanför Sverige.
	 *
	 * Godkänner   070-123 45 67 · 0701234567 · +46 70 123 45 67 · +46(0)70…
	 *             0046701234567 · 08-12 34 56 · 701234567 · +44 20 7946 0958
	 * Avvisar     bokstäver · för få eller för många siffror · 0000000000
	 */
	public function normalize_phone( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		// Bara siffror kvar, plus minnet av ett inledande plus.
		$plus   = str_starts_with( $raw, '+' );
		$digits = preg_replace( '/\D+/', '', $raw ) ?? '';

		if ( '' === $digits ) {
			return null;
		}

		// 0046… är samma sak som +46…
		if ( ! $plus && str_starts_with( $digits, '00' ) ) {
			$plus   = true;
			$digits = substr( $digits, 2 );
		}

		if ( $plus ) {
			if ( str_starts_with( $digits, '46' ) ) {
				// Svenskt nummer i internationell form. Både +4670… och +46(0)70…
				$national = '0' . ltrim( substr( $digits, 2 ), '0' );
				return $this->valid_national( $national ) ? $national : null;
			}

			// Landsnummer börjar aldrig med noll. Utan den kontrollen tolkas
			// t.ex. 0000000000 som "+00000000" och släpps igenom.
			if ( str_starts_with( $digits, '0' ) ) {
				return null;
			}

			$length = strlen( $digits );
			return ( $length >= 8 && $length <= 15 ) ? '+' . $digits : null;
		}

		if ( str_starts_with( $digits, '0' ) ) {
			return $this->valid_national( $digits ) ? $digits : null;
		}

		// Mobilnummer utan inledande nolla, t.ex. "701234567".
		if ( 9 === strlen( $digits ) && str_starts_with( $digits, '7' ) ) {
			return '0' . $digits;
		}

		return null;
	}

	/** Svenskt nummer med inledande nolla: 8–12 siffror, inte samma siffra rakt igenom. */
	private function valid_national( string $digits ): bool {
		$length = strlen( $digits );
		if ( $length < 8 || $length > 12 ) {
			return false;
		}
		return ! preg_match( '/^(\d)\1+$/', $digits );
	}

	private function flatten( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Villkorsjämförelse. Tomt villkorsvärde = fältet måste bara ha ett värde. */
	private function matches( string $actual, string $expected ): bool {
		if ( '' === trim( $expected ) ) {
			return '' !== trim( $actual );
		}
		foreach ( array_map( 'trim', explode( ',', $expected ) ) as $candidate ) {
			if ( '' !== $candidate && 0 === strcasecmp( trim( $actual ), $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Som matches(), men testar flera kandidater – typiskt det tekniska värdet
	 * och den synliga etiketten för samma val.
	 *
	 * @param array<int,string> $candidates
	 */
	private function matches_any( array $candidates, string $expected ): bool {
		if ( ! $candidates ) {
			return false;
		}
		if ( '' === trim( $expected ) ) {
			return '' !== trim( (string) $candidates[0] );
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( $this->matches( (string) $candidate, $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Metadata och UTM
	 * ------------------------------------------------------------------ */

	/**
	 * Svenska etiketter för metadatan. Delas av mailet, adminvyn och
	 * CSV-exporten så att samma sak alltid heter samma sak.
	 */
	public function meta_labels(): array {
		return [
			'date'     => 'Datum',
			'time'     => 'Tid',
			'page'     => 'Skickat från',
			'landing'  => 'Landningssida',
			'referrer' => 'Hänvisande sida',
			'ip'       => 'IP-adress',
			'ua'       => 'Webbläsare',
		];
	}

	/** Svenska etiketter för kampanjparametrarna. */
	public function utm_labels(): array {
		return [
			'utm_source'   => 'Kampanjkälla',
			'utm_medium'   => 'Kanal',
			'utm_campaign' => 'Kampanj',
			'utm_term'     => 'Sökord',
			'utm_content'  => 'Annonsvariant',
			'gclid'        => 'Google Ads-klick',
			'fbclid'       => 'Facebook-klick',
		];
	}

	public function meta_label( string $key ): string {
		return $this->meta_labels()[ $key ] ?? $this->utm_labels()[ $key ] ?? $key;
	}

	private function collect_meta( int $form_id, array $body ): array {
		$utm_keys = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' ];
		$utm      = [];

		foreach ( $utm_keys as $k ) {
			$v = sanitize_text_field( (string) ( $body['utm'][ $k ] ?? '' ) );
			if ( '' !== $v ) {
				$utm[ $k ] = mb_substr( $v, 0, 200 );
			}
		}

		return [
			'date'     => current_time( 'Y-m-d' ),
			'time'     => current_time( 'H:i' ),
			'page'     => esc_url_raw( (string) ( $body['page'] ?? '' ) ),
			'landing'  => esc_url_raw( (string) ( $body['utm']['landing'] ?? '' ) ),
			'referrer' => esc_url_raw( (string) ( $body['utm']['referrer'] ?? '' ) ),
			'ua'       => mb_substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 300 ),
			'ip'       => $this->setting( $form_id, 'xf_log_ip', true ) ? $this->client_ip() : '',
			'utm'      => $utm,
		];
	}

	/**
	 * Besökarens IP. Bakom Cloudflare eller annan proxy är REMOTE_ADDR
	 * proxyns adress – då delar ALLA besökare samma frekvensspärr (fem inskick
	 * per tio minuter för hela sajten) och IP-loggen blir meningslös. Sådana
	 * sajter pekar om via filtret:
	 *
	 *     add_filter( 'relativt_form_client_ip',
	 *         fn( $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );
	 *
	 * Filtrera ALDRIG in X-Forwarded-For rakt av på en sajt utan betrodd
	 * proxy – den headern kan vem som helst skicka, och då väljer spammaren
	 * sin egen frekvensspärr.
	 */
	private function client_ip(): string {
		$ip = (string) apply_filters( 'relativt_form_client_ip', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/* ---------------------------------------------------------------------
	 * Mail
	 * ------------------------------------------------------------------ */

	private function send_mail( int $form_id, array $values, array $meta ): bool {
		[ $to, $subject ] = $this->resolve_recipient( $form_id, $values );

		if ( '' === $to ) {
			return false;
		}

		$from_email = trim( (string) $this->setting( $form_id, 'xf_from_email', '' ) );
		$from_name  = trim( (string) $this->setting( $form_id, 'xf_from_name', get_bloginfo( 'name' ) ) );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		// Svara-till = besökaren, aldrig avsändardomänen.
		$reply = $this->find_reply_to( $values );
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$recipients = array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' );
		if ( ! $recipients ) {
			return false;
		}

		return (bool) wp_mail( $recipients, $subject, $this->mail_body( $form_id, $values, $meta ), $headers );
	}

	/** @return array{0:string,1:string} */
	private function resolve_recipient( int $form_id, array $values ): array {
		// Både tekniskt värde och synlig etikett, så en regel fungerar oavsett
		// vilket av dem kunden skrev in i wp-admin.
		$map = [];
		foreach ( $values as $v ) {
			$map[ $v['key'] ] = [ $v['raw'], $v['value'] ];
		}

		$to      = (string) $this->setting( $form_id, 'xf_to', get_option( 'admin_email' ) );
		$subject = (string) $this->setting( $form_id, 'xf_subject', 'Nytt meddelande från webbplatsen' );

		$rules = function_exists( 'get_field' ) ? get_field( 'xf_rules', $form_id ) : null;
		foreach ( is_array( $rules ) ? $rules : [] as $rule ) {
			$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
			$email = trim( (string) ( $rule['email'] ?? '' ) );

			if ( '' === $field || '' === $email || ! isset( $map[ $field ] ) ) {
				continue;
			}
			if ( ! $this->matches_any( $map[ $field ], (string) ( $rule['value'] ?? '' ) ) ) {
				continue;
			}

			$to = $email;
			if ( '' !== trim( (string) ( $rule['subject'] ?? '' ) ) ) {
				$subject = (string) $rule['subject'];
			}
			break; // Första matchande regel vinner.
		}

		return [ $to, $this->interpolate( $subject, $values ) ];
	}

	/** Byter {faltnyckel} mot inskickat värde. */
	private function interpolate( string $text, array $values ): string {
		$map = [];
		foreach ( $values as $v ) {
			$map[ '{' . $v['key'] . '}' ] = $v['value'];
		}
		return strtr( $text, $map );
	}

	private function find_reply_to( array $values ): string {
		foreach ( $values as $v ) {
			if ( 'email' === $v['type'] && is_email( $v['value'] ) ) {
				return $v['value'];
			}
		}
		return '';
	}

	private function mail_body( int $form_id, array $values, array $meta ): string {
		$rows = '';
		foreach ( $values as $v ) {
			if ( '' === trim( (string) $v['value'] ) ) {
				continue;
			}
			$rows .= sprintf(
				'<tr><th align="left" valign="top" style="padding:8px 16px 8px 0;font:600 14px/1.5 Arial,sans-serif;color:#555;white-space:nowrap;">%s</th>'
				. '<td valign="top" style="padding:8px 0;font:400 14px/1.6 Arial,sans-serif;color:#111;">%s</td></tr>',
				esc_html( $v['label'] ),
				nl2br( esc_html( $v['value'] ) )
			);
		}

		$metarows = '';
		foreach ( $this->meta_labels() as $k => $label ) {
			if ( '' === (string) ( $meta[ $k ] ?? '' ) ) {
				continue;
			}
			$metarows .= sprintf(
				'<tr><th align="left" valign="top" style="padding:4px 16px 4px 0;font:400 12px/1.5 Arial,sans-serif;color:#888;white-space:nowrap;">%s</th>'
				. '<td valign="top" style="padding:4px 0;font:400 12px/1.5 Arial,sans-serif;color:#888;word-break:break-all;">%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $meta[ $k ] )
			);
		}
		foreach ( $meta['utm'] ?? [] as $k => $v ) {
			if ( in_array( $k, [ 'landing', 'referrer' ], true ) ) {
				continue; // Redovisas redan ovan.
			}
			$metarows .= sprintf(
				'<tr><th align="left" valign="top" style="padding:4px 16px 4px 0;font:400 12px/1.5 Arial,sans-serif;color:#888;">%s</th>'
				. '<td valign="top" style="padding:4px 0;font:400 12px/1.5 Arial,sans-serif;color:#888;">%s</td></tr>',
				esc_html( $this->meta_label( $k ) ),
				esc_html( (string) $v )
			);
		}

		return sprintf(
			'<!doctype html><html lang="sv"><body style="margin:0;padding:24px;background:#f6f6f6;">'
			. '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:8px;">'
			. '<tr><td style="padding:28px 28px 8px;">'
			. '<p style="margin:0 0 4px;font:600 18px/1.4 Arial,sans-serif;color:#111;">%s</p>'
			. '<p style="margin:0 0 20px;font:400 13px/1.5 Arial,sans-serif;color:#888;">%s</p>'
			. '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0">%s</table>'
			. '</td></tr>'
			. '<tr><td style="padding:8px 28px 28px;border-top:1px solid #eee;">'
			. '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0">%s</table>'
			. '</td></tr></table></body></html>',
			esc_html( get_the_title( $form_id ) ),
			esc_html( get_bloginfo( 'name' ) ),
			$rows,
			$metarows
		);
	}

	/* ---------------------------------------------------------------------
	 * Inskick
	 * ------------------------------------------------------------------ */

	private function store_entry( int $form_id, array $values, array $meta ): int {
		$name  = '';
		$email = '';
		foreach ( $values as $v ) {
			if ( '' === $email && 'email' === $v['type'] ) {
				$email = $v['value'];
			}
			if ( '' === $name && 'text' === $v['type'] ) {
				$name = $v['value'];
			}
		}

		$title = trim( $name ) !== '' ? $name : ( $email !== '' ? $email : 'Inskick' );

		$entry_id = wp_insert_post( [
			'post_type'   => self::CPT_ENTRY,
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( $title . ' – ' . get_the_title( $form_id ) ),
		], true );

		if ( is_wp_error( $entry_id ) ) {
			return 0;
		}

		update_post_meta( $entry_id, '_xf_form_id', $form_id );
		update_post_meta( $entry_id, '_xf_values', wp_slash( $values ) );
		update_post_meta( $entry_id, '_xf_meta', wp_slash( $meta ) );
		update_post_meta( $entry_id, '_xf_email', $email );

		return (int) $entry_id;
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Byggarens utseende. Ligger inline i den här filen så att installationen
	 * fortfarande bara är en PHP-fil – inga extra filer att ladda upp.
	 */
	public function admin_styles(): void {
		if ( defined( 'RELATIVT_FORM_NO_ADMIN_UI' ) && RELATIVT_FORM_NO_ADMIN_UI ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::CPT_FORM !== ( $screen->post_type ?? '' ) ) {
			return;
		}
		?>
		<style id="relativt-form-admin">
		/* ===== JUSTERA HÄR ===== */
		#xf-fields-wrap { --xf-row-gap: 16px; --xf-border: #c9c9cf; --xf-tint: #f6f7f8; }

		/* Nyckeln är låst och styrs av koden – syns i fältkartan i stället. */
		.acf-field[data-key="field_xf_f_key"] { display: none !important; }

		/* Tydlig avgränsning: varje fält blir ett eget kort med luft omkring. */
		[data-key="field_xf_fields"] .acf-table {
			border: 0 !important;
			border-collapse: separate !important;
			border-spacing: 0 var(--xf-row-gap, 16px) !important;
			background: transparent !important;
		}
		[data-key="field_xf_fields"] .acf-table > tbody > tr.acf-row > td {
			background: #fff;
			border-top: 1px solid var(--xf-border, #c9c9cf) !important;
			border-bottom: 1px solid var(--xf-border, #c9c9cf) !important;
			box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
		}
		[data-key="field_xf_fields"] .acf-table > tbody > tr.acf-row > td:first-child {
			border-left: 1px solid var(--xf-border, #c9c9cf) !important;
			border-radius: 6px 0 0 6px;
			background: var(--xf-tint, #f6f7f8);
			font-weight: 600;
			color: #50575e;
		}
		[data-key="field_xf_fields"] .acf-table > tbody > tr.acf-row > td:last-child {
			border-right: 1px solid var(--xf-border, #c9c9cf) !important;
			border-radius: 0 6px 6px 0;
		}

		/* Ihopfällda rader: kompakta, med etiketten som rubrik. */
		[data-key="field_xf_fields"] .acf-row.-collapsed > td { background: var(--xf-tint, #f6f7f8); }
		[data-key="field_xf_fields"] .acf-row.-collapsed .acf-fields { padding: 4px 0; }

		/* Villkorsraden avvikande, så det syns direkt vilka fält som är styrda. */
		.acf-field[data-key="field_xf_f_cond_field"],
		.acf-field[data-key="field_xf_f_cond_value"] { background: #fbf9f2; }

		/* Fältkartan i sidokolumnen */
		.xf-map { width: 100%; border-collapse: collapse; font-size: 12px; }
		.xf-map th { text-align: left; padding: 4px 8px 4px 0; font-weight: 600; }
		.xf-map td { padding: 4px 8px 4px 0; vertical-align: top; border-top: 1px solid #f0f0f1; }
		.xf-map code { font-size: 11px; padding: 1px 4px; background: #f0f0f1; border-radius: 3px; }
		.xf-map .xf-map-cond { color: #8a8880; }
		.xf-copy { width: 100%; font-family: monospace; padding: 6px; }
		</style>
		<script>
		// Ger repeatern ett id som CSS-variablerna ovan kan hänga på.
		document.addEventListener('DOMContentLoaded', () => {
			document.querySelector('[data-key="field_xf_fields"]')?.setAttribute('id', 'xf-fields-wrap');
		});
		</script>
		<?php
	}

	/**
	 * Varnar i formulär- och inskicksvyerna när mail inte kunnat skickas.
	 *
	 * Kolumnen "Misslyckades" fanns redan i 1.0, men ingen tittar i en lista
	 * man inte vet att man borde öppna. Ett trasigt SMTP ska synas samma dag,
	 * inte upptäckas veckan efter när kunden undrar var alla leads tog vägen.
	 */
	public function mail_failure_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== ( $screen->base ?? '' )
			|| ! in_array( $screen->post_type ?? '', [ self::CPT_FORM, self::CPT_ENTRY ], true ) ) {
			return;
		}

		$failed = new WP_Query( [
			'post_type'      => self::CPT_ENTRY,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_xf_mail_ok',
			'meta_value'     => '0',
			'no_found_rows'  => false,
		] );

		$count = (int) $failed->found_posts;
		if ( $count < 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>Relativt Formulär:</strong> %s <a href="%s">Visa %s</a> – inskicken finns sparade, men mottagaren har inte fått något mail. Kontrollera SMTP-inställningarna.</p></div>',
			esc_html( 1 === $count ? 'Ett inskick har ett mail som inte kunde skickas.' : sprintf( '%d inskick har mail som inte kunde skickas.', $count ) ),
			esc_url( admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&xf_mail=failed' ) ),
			esc_html( 1 === $count ? 'inskicket' : 'inskicken' )
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'xf_shortcode',
			'Shortcode',
			[ $this, 'render_shortcode_box' ],
			self::CPT_FORM,
			'side',
			'high'
		);

		add_meta_box(
			'xf_map',
			'Fältkarta',
			[ $this, 'render_map_box' ],
			self::CPT_FORM,
			'side',
			'default'
		);

		add_meta_box(
			'xf_entry',
			'Inskick',
			[ $this, 'render_entry_box' ],
			self::CPT_ENTRY,
			'normal',
			'high'
		);
	}

	public function render_shortcode_box( $post ): void {
		$fields = $this->get_fields( $post->ID );
		$choice = null;
		foreach ( $fields as $f ) {
			if ( in_array( $f['type'], [ 'buttons', 'radio', 'select' ], true ) && $f['choices'] ) {
				$choice = $f;
				break;
			}
		}

		echo '<p style="margin-top:0">Klistra in shortcoden där formuläret ska visas:</p>';
		printf(
			'<input type="text" readonly value="[relativt_formular id=&quot;%d&quot;]" onclick="this.select()" class="xf-copy">',
			$post->ID
		);

		if ( $choice ) {
			$first = array_key_first( $choice['choices'] );
			echo '<p style="margin-bottom:4px">Med förvalt värde:</p>';
			printf(
				'<input type="text" readonly value="[relativt_formular id=&quot;%d&quot; %s=&quot;%s&quot;]" onclick="this.select()" class="xf-copy">',
				$post->ID,
				esc_attr( $choice['key'] ),
				esc_attr( (string) $first )
			);
			printf(
				'<p class="description">Vilken fältnyckel som helst fungerar som attribut. Samma sak via URL: <code>?%s=%s</code></p>',
				esc_html( $choice['key'] ),
				esc_html( (string) $first )
			);
		}
	}

	/**
	 * Överblick över formuläret: alla fält, deras nycklar och villkor på ett
	 * ställe. Ersätter Nyckel-rutan som togs bort ur byggaren, och gör det
	 * möjligt att se hela formulärets struktur utan att fälla ut varje rad.
	 */
	public function render_map_box( $post ): void {
		/*
		 * Hela kartan är en bekvämlighet. Går något fel här ska det ALDRIG
		 * kunna ta ned redigeringsvyn – då står kunden utan formulärbyggare
		 * för en sidokolumns skull.
		 */
		try {
			$this->print_map( (int) ( is_object( $post ) ? $post->ID : $post ) );
		} catch ( Throwable $e ) {
			printf(
				'<p class="description">Fältkartan kunde inte visas: %s</p>',
				esc_html( $e->getMessage() )
			);
		}
	}

	private function print_map( int $form_id ): void {
		$fields = $this->get_fields( $form_id );

		if ( ! $fields ) {
			echo '<p class="description">Lägg till fält så visas de här.</p>';
			return;
		}

		$types  = self::field_type_labels();
		$labels = [];
		foreach ( $fields as $f ) {
			$labels[ $f['key'] ] = $f['label'];
		}

		echo '<table class="xf-map"><tbody>';
		foreach ( $fields as $f ) {
			$cond = '';
			if ( '' !== $f['cond_field'] ) {
				$cond = sprintf(
					'<div class="xf-map-cond">visas om %s = %s</div>',
					esc_html( $labels[ $f['cond_field'] ] ?? $f['cond_field'] ),
					esc_html( '' !== $f['cond_value'] ? $f['cond_value'] : 'ifyllt' )
				);
			}

			printf(
				'<tr><td><strong>%s</strong>%s%s<br><code>%s</code></td><td>%s</td></tr>',
				esc_html( '' !== $f['label'] ? $f['label'] : '(utan etikett)' ),
				$f['required'] ? ' <span style="color:#b32d2e" title="Obligatoriskt">*</span>' : '',
				$cond, // phpcs:ignore
				esc_html( $f['key'] ),
				esc_html( $types[ $f['type'] ] ?? $f['type'] )
			);
		}
		echo '</tbody></table>';

		echo '<p class="description" style="margin-top:10px">Nyckeln i grått används i mottagarreglerna, i villkoren och som attribut i shortcoden. Den är låst och ändras aldrig, även om du byter etikett.</p>';
	}

	public function render_entry_box( $post ): void {
		$values = get_post_meta( $post->ID, '_xf_values', true );
		$meta   = get_post_meta( $post->ID, '_xf_meta', true );
		$ok     = get_post_meta( $post->ID, '_xf_mail_ok', true );

		if ( '0' === $ok ) {
			echo '<div class="notice notice-error inline"><p><strong>Mailet kunde inte skickas.</strong> Kontrollera SMTP-inställningarna.</p></div>';
		}

		echo '<table class="widefat striped"><tbody>';
		foreach ( is_array( $values ) ? $values : [] as $v ) {
			printf(
				'<tr><th style="width:200px">%s</th><td>%s</td></tr>',
				esc_html( (string) ( $v['label'] ?? '' ) ),
				nl2br( esc_html( (string) ( $v['value'] ?? '' ) ) )
			);
		}
		echo '</tbody></table>';

		if ( is_array( $meta ) ) {
			echo '<h4>Metadata</h4><table class="widefat striped"><tbody>';

			// Fast ordning enligt etikettlistan, så vyn ser likadan ut varje gång.
			foreach ( $this->meta_labels() as $k => $label ) {
				if ( '' === (string) ( $meta[ $k ] ?? '' ) ) {
					continue;
				}
				printf(
					'<tr><th style="width:200px">%s</th><td>%s</td></tr>',
					esc_html( $label ),
					esc_html( (string) $meta[ $k ] )
				);
			}

			$utm = array_diff_key( $meta['utm'] ?? [], [ 'landing' => 1, 'referrer' => 1 ] );
			if ( $utm ) {
				echo '<tr><th colspan="2" style="padding-top:12px">Kampanj</th></tr>';
				foreach ( $utm as $k => $v ) {
					printf(
						'<tr><th style="width:200px">%s</th><td>%s</td></tr>',
						esc_html( $this->meta_label( $k ) ),
						esc_html( (string) $v )
					);
				}
			}

			echo '</tbody></table>';
		}
	}

	public function form_columns( array $cols ): array {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['xf_shortcode'] = 'Shortcode';
				$new['xf_count']     = 'Inskick';
			}
		}
		return $new;
	}

	public function form_column( string $col, int $post_id ): void {
		if ( 'xf_shortcode' === $col ) {
			printf( '<code>[relativt_formular id="%d"]</code>', $post_id );
		}
		if ( 'xf_count' === $col ) {
			$q = new WP_Query( [
				'post_type'      => self::CPT_ENTRY,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_xf_form_id',
				'meta_value'     => $post_id,
				'no_found_rows'  => false,
			] );
			printf(
				'<a href="%s">%d</a>',
				esc_url( admin_url( 'edit.php?post_type=' . self::CPT_ENTRY . '&xf_form=' . $post_id ) ),
				(int) $q->found_posts
			);
		}
	}

	public function entry_columns( array $cols ): array {
		return [
			'cb'        => $cols['cb'] ?? '',
			'title'     => 'Inskick',
			'xf_form'   => 'Formulär',
			'xf_email'  => 'E-post',
			'xf_source' => 'Källa',
			'xf_mail'   => 'Mail',
			'date'      => 'Datum',
		];
	}

	public function entry_column( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'xf_form':
				$fid = (int) get_post_meta( $post_id, '_xf_form_id', true );
				echo esc_html( $fid ? (string) get_the_title( $fid ) : '—' );
				break;

			case 'xf_email':
				$email = (string) get_post_meta( $post_id, '_xf_email', true );
				echo $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '—';
				break;

			case 'xf_source':
				$meta = get_post_meta( $post_id, '_xf_meta', true );
				$utm  = is_array( $meta ) ? ( $meta['utm'] ?? [] ) : [];
				if ( $utm ) {
					echo esc_html( implode( ' / ', array_filter( [ $utm['utm_source'] ?? '', $utm['utm_medium'] ?? '', $utm['utm_campaign'] ?? '' ] ) ) );
				} else {
					$ref = is_array( $meta ) ? ( $meta['referrer'] ?? '' ) : '';
					echo $ref ? esc_html( (string) wp_parse_url( $ref, PHP_URL_HOST ) ) : 'Direkt';
				}
				break;

			case 'xf_mail':
				echo '0' === get_post_meta( $post_id, '_xf_mail_ok', true )
					? '<span style="color:#b32d2e">Misslyckades</span>'
					: '<span style="color:#008a20">Skickat</span>';
				break;
		}
	}

	public function entry_filter_dropdown(): void {
		global $typenow;
		if ( self::CPT_ENTRY !== $typenow ) {
			return;
		}

		$forms   = get_posts( [ 'post_type' => self::CPT_FORM, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$current = (int) ( $_GET['xf_form'] ?? 0 );

		echo '<select name="xf_form"><option value="">Alla formulär</option>';
		foreach ( $forms as $form ) {
			printf(
				'<option value="%d"%s>%s</option>',
				$form->ID,
				selected( $current, $form->ID, false ),
				esc_html( $form->post_title )
			);
		}
		echo '</select>';

		printf(
			' <a class="button" href="%s">Exportera CSV</a>',
			esc_url( wp_nonce_url(
				admin_url( 'admin-post.php?action=relativt_form_export&xf_form=' . $current ),
				'relativt_form_export'
			) )
		);
	}

	public function export_csv(): void {
		if ( ! current_user_can( 'edit_pages' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'relativt_form_export' ) ) {
			wp_die( 'Åtkomst nekad.' );
		}

		$form_id = (int) ( $_GET['xf_form'] ?? 0 );
		$args    = [
			'post_type'      => self::CPT_ENTRY,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( $form_id ) {
			$args['meta_key']   = '_xf_form_id';
			$args['meta_value'] = $form_id;
		}

		$entries = get_posts( $args );
		$columns = [ 'Datum', 'Formulär' ];
		$rows    = [];

		foreach ( $entries as $entry ) {
			$values = get_post_meta( $entry->ID, '_xf_values', true );
			$meta   = get_post_meta( $entry->ID, '_xf_meta', true );
			$fid    = (int) get_post_meta( $entry->ID, '_xf_form_id', true );

			$row = [ 'Datum' => get_the_date( 'Y-m-d H:i', $entry ), 'Formulär' => (string) get_the_title( $fid ) ];

			foreach ( is_array( $values ) ? $values : [] as $v ) {
				$label = (string) ( $v['label'] ?? '' );
				$row[ $label ] = (string) ( $v['value'] ?? '' );
				if ( ! in_array( $label, $columns, true ) ) {
					$columns[] = $label;
				}
			}
			foreach ( is_array( $meta ) ? ( $meta['utm'] ?? [] ) : [] as $k => $v ) {
				$label         = $this->meta_label( $k );
				$row[ $label ] = (string) $v;
				if ( ! in_array( $label, $columns, true ) ) {
					$columns[] = $label;
				}
			}

			$rows[] = $row;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=inskick-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM så Excel läser å ä ö.
		fputcsv( $out, array_map( [ self::class, 'csv_guard' ], $columns ), ';' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( fn( $c ) => self::csv_guard( (string) ( $row[ $c ] ?? '' ) ), $columns ), ';' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Skydd mot formelinjektion i kalkylprogram. Ett inskick som börjar med
	 * = + - eller @ tolkas som FORMEL när kunden öppnar CSV:n i Excel –
	 * =HYPERLINK() och värre. Cellen är besökardata och ska aldrig exekveras;
	 * den inledande apostrofen tvingar Excel att läsa den som text.
	 */
	private static function csv_guard( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/* ---------------------------------------------------------------------
	 * Gallring
	 * ------------------------------------------------------------------ */

	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public function run_cleanup(): void {
		$forms = get_posts( [ 'post_type' => self::CPT_FORM, 'numberposts' => -1, 'fields' => 'ids' ] );

		foreach ( $forms as $form_id ) {
			$days = (int) $this->setting( $form_id, 'xf_retention', 0 );
			if ( $days < 1 ) {
				continue;
			}

			/*
			 * Batchvis tills det är tomt, med ett tak. Ett fast tak på 200 per
			 * dygn lät en stor backlog – t.ex. gallring som slås på för ett
			 * formulär med tusentals gamla inskick – ta veckor att beta av.
			 * Taket finns kvar som skyddsnät mot en skenande cron-körning.
			 */
			for ( $batch = 0; $batch < 25; $batch++ ) {
				$old = get_posts( [
					'post_type'      => self::CPT_ENTRY,
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'meta_key'       => '_xf_form_id',
					'meta_value'     => $form_id,
					'date_query'     => [ [ 'before' => $days . ' days ago' ] ],
				] );

				foreach ( $old as $entry_id ) {
					wp_delete_post( $entry_id, true );
				}

				if ( count( $old ) < 200 ) {
					break;
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Hjälpare
	 * ------------------------------------------------------------------ */

	/**
	 * Tillåtna taggar för knappikonen. wp_kses_post() släpper inte igenom
	 * <svg>, så filtret relativt_form_submit_icon behöver en egen lista.
	 */
	private static function svg_kses(): array {
		return [
			'svg'  => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'aria-hidden' => true, 'focusable' => true, 'class' => true ],
			'path' => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ],
			'g'    => [ 'fill' => true, 'stroke' => true, 'transform' => true ],
			'circle' => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
			'rect' => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'stroke' => true ],
			'line' => [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true ],
			'polyline' => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ],
			'polygon'  => [ 'points' => true, 'fill' => true, 'stroke' => true ],
			'title'    => [],
		];
	}

	private function slugify( string $text ): string {
		$map  = [ 'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'Å' => 'a', 'Ä' => 'a', 'Ö' => 'o', 'é' => 'e', 'è' => 'e', 'ü' => 'u' ];
		$text = strtr( mb_strtolower( trim( $text ) ), $map );
		$text = preg_replace( '/[^a-z0-9]+/', '_', $text ) ?? '';
		return trim( $text, '_' );
	}
}

endif; // class_exists-vakten överst.
