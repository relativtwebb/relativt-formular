<?php
/**
 * Flerspråkiga sajter (1.15.0).
 *
 * Tre saker, alla utan hårda beroenden:
 *
 *  1. Vilket språk gäller just nu – och att språket följer med REST-anropen,
 *     så att ett fel från servern kommer på samma språk som sidan.
 *  2. Översättning av motorns egna texter via Polylangs strängöversättningar,
 *     när Polylang finns. Utan Polylang är t() en ren genomsläppning och
 *     ingenting i utdata ändras.
 *  3. Registrering av texterna i Polylang, så att de går att översätta under
 *     Språk → Översättningar utan en rad kod.
 *
 * Allt som rör Polylang ligger bakom function_exists(), så pluginet är
 * fortfarande fristående.
 *
 * Varför klassen rör Polylangs objekt direkt
 * ------------------------------------------
 * Polylang har ingen publik funktion för att byta aktuellt språk mitt i en
 * förfrågan. Det som finns (läst i Polylang 3.9.2 och 4.0-dev):
 *
 *  - pll__() slår upp texten i en katalog som laddats för ETT språk
 *    ($GLOBALS['l10n']['pll_string']). Den laddas när språket bestäms
 *    (pll_language_defined) och när WordPress byter språk (change_locale).
 *  - I REST-anrop sätter Polylang (3.3+) själv aktuellt språk från en
 *    parameter som heter just "lang". Därför heter vår parameter så.
 *  - switch_to_locale() får Polylang att ladda om katalogen – men efter
 *    locale, och två språk kan dela locale. Då vinner det första.
 *  - Åtgärden wpml_switch_language (Polylangs WPML-lager) byter aktuellt
 *    språk men laddar INTE om katalogen, så pll__() svarar på fel språk.
 *
 * Därför gör switch_to() tre saker i ordning: switch_to_locale() (WordPress
 * egna texter och alla som lyssnar på change_locale), sedan PLL()->curlang
 * (så att pll_current_language() och Polylangs egna filter stämmer) och sist
 * PLL()->load_strings_translations( $slug ) (så att pll__() svarar på rätt
 * språk även när två språk delar locale). Samma två egenskaper använder
 * Polylang själv i sitt WPML-lager och i PLL_Frontend::switch_blog(). Var
 * och en kontrolleras innan den rörs; saknas någon faller vi tillbaka på
 * det switch_to_locale() redan gjort.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Relativt_Form_I18n', false ) ) :

final class Relativt_Form_I18n {

	/** Gruppen texterna hamnar i under Språk → Översättningar. */
	public const GROUP = 'Relativt Formulär';

	/**
	 * Mallen för stegräknaren. {current} och {total} byts ut av motorn och
	 * ska stå kvar i översättningen.
	 */
	public const STEP_COUNTER = 'Steg {current} av {total}';

	/**
	 * Kodens egna fallbacks – det besökaren ser när varken formuläret eller
	 * sajtens standardvärden anger en text. Nyckeln är bara namnet texten
	 * får i Polylangs lista.
	 */
	public const FALLBACKS = [
		'Knapptext'                 => 'Skicka',
		'Knapptext under skick'     => 'Skickar…',
		'Tackrubrik'                => 'Tack!',
		'Felmeddelande'             => 'Något gick fel.',
		'Knapp: nästa steg'         => 'Nästa',
		'Knapp: föregående steg'    => 'Tillbaka',
		'Fel: okänt formulär'       => 'Okänt formulär.',
		'Fel: ogiltig begäran'      => 'Ogiltig begäran.',
	];

	/**
	 * De av sajtens standardvärden (Formulär → Standardvärden) som är text
	 * besökaren ser: nyckel => [ namn i listan, flerradig ].
	 *
	 * Mottagare, avsändare och ämnesrad hör till notismailet och är inte med.
	 * wpml-config.xml i pluginets rot listar SAMMA nycklar – ett test håller
	 * dem lika.
	 */
	public const SETTINGS_KEYS = [
		'xf_submit_text'  => [ 'Standardvärde: knapptext', false ],
		'xf_sending_text' => [ 'Standardvärde: knapptext under skick', false ],
		'xf_thanks_title' => [ 'Standardvärde: tackrubrik', false ],
		'xf_thanks_text'  => [ 'Standardvärde: tacktext', true ],
		'xf_redirect'     => [ 'Standardvärde: tack-sida (URL)', false ],
		'xf_error_text'   => [ 'Standardvärde: felmeddelande', true ],
		'xf_consent'      => [ 'Standardvärde: samtyckestext', true ],
	];

	/**
	 * Vad en språkuppgift får se ut som utan Polylang: en WordPress-locale.
	 * sv, sv_SE, de_DE_formal, pt_PT_ao90, es_419 – och ingenting annat.
	 */
	private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_(?:[A-Z]{2}|[0-9]{3}))?(?:_[a-z0-9]{2,12})?$/';

	/** Pågående språkväxlingar, innerst sist. */
	private static array $stack = [];

	/**
	 * Standardvärdena som de är sparade, lästa innan något översättnings-
	 * filter hunnit läggas på alternativet. null = inte lästa än.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $raw_settings = null;

	/**
	 * Körs en gång när pluginet laddas.
	 *
	 * Standardvärdena läses redan här. Polylang lägger – via wpml-config.xml –
	 * ett filter på alternativet som översätter det till aktuellt språk, och i
	 * wp-admin kan aktuellt språk vara ett annat än originalets. Registrerade
	 * vi det filtrerade värdet skulle en översättning hamna i listan som om
	 * den vore en originaltext.
	 */
	public static function boot(): void {
		self::raw_settings();
		add_action( 'init', [ self::class, 'register_strings' ], 20 );
	}

	/* ---------------------------------------------------------------------
	 * Aktuellt språk
	 * ------------------------------------------------------------------ */

	/** Finns de Polylang-funktioner översättningen behöver? */
	public static function has_polylang(): bool {
		return function_exists( 'pll__' ) && function_exists( 'pll_current_language' ) && function_exists( 'pll_languages_list' );
	}

	/**
	 * Språket som gäller just nu: Polylangs språkkod (sv, en) när Polylang
	 * finns och har ett aktuellt språk, annars WordPress locale (sv_SE).
	 * Under en växling i ett REST-anrop är det språket anropet bad om.
	 */
	public static function current(): string {
		if ( self::$stack ) {
			return (string) self::$stack[ count( self::$stack ) - 1 ]['lang'];
		}

		if ( self::has_polylang() ) {
			$slug = pll_current_language( 'slug' );
			if ( is_string( $slug ) && '' !== $slug ) {
				return $slug;
			}
		}

		return function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
	}

	/**
	 * Granskar en språkuppgift som kommit utifrån. Svarar med värdet om det
	 * är giltigt, annars med '' – och då beter sig anropet precis som ett
	 * anrop utan språkuppgift.
	 *
	 * Med Polylang: måste vara en av sajtens språkkoder. Utan: måste se ut
	 * som en locale. Värdet används aldrig i en sökväg eller en fråga, men
	 * det skickas vidare till filter och ska inte kunna vara vad som helst.
	 *
	 * @param mixed $raw
	 */
	public static function validate( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 24 ) {
			return '';
		}

		if ( self::has_polylang() ) {
			$known = pll_languages_list();
			return is_array( $known ) && in_array( $raw, $known, true ) ? $raw : '';
		}

		return 1 === preg_match( self::LOCALE_PATTERN, $raw ) ? $raw : '';
	}

	/* ---------------------------------------------------------------------
	 * Växla och återställ
	 * ------------------------------------------------------------------ */

	/**
	 * Växlar till ett språk som redan gått igenom validate(). Varje lyckat
	 * anrop ska följas av restore().
	 *
	 * @return bool false om $lang är tomt – då har ingenting ändrats och
	 *              restore() ska inte anropas.
	 */
	public static function switch_to( string $lang ): bool {
		if ( '' === $lang ) {
			return false;
		}

		$frame = [
			'lang'   => $lang,
			'locale' => false,
			'pll'    => false,
			'prev'   => null,
			'had_mo' => false,
			'mo'     => null,
		];

		$polylang = self::has_polylang();
		$locale   = $polylang ? self::polylang_locale( $lang ) : $lang;

		/*
		 * Katalogen sparas FÖRE switch_to_locale(): Polylang laddar om den på
		 * change_locale, och efteråt vore originalet redan överskrivet.
		 */
		if ( $polylang ) {
			$frame['had_mo'] = isset( $GLOBALS['l10n']['pll_string'] );
			$frame['mo']     = $GLOBALS['l10n']['pll_string'] ?? null;
		}

		// WordPress svarar false om språket redan gäller eller inte är installerat.
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) ) {
			$frame['locale'] = (bool) switch_to_locale( $locale );
		}

		if ( $polylang ) {
			$pll    = self::polylang_object();
			$target = $pll ? $pll->model->get_language( $lang ) : null;

			if ( $pll && is_object( $target ) ) {
				$frame['pll']  = true;
				$frame['prev'] = $pll->curlang ?? null;
				$pll->curlang  = $target;

				// Efter språkkod, inte locale: två språk kan dela locale.
				if ( is_callable( [ $pll, 'load_strings_translations' ] ) ) {
					$pll->load_strings_translations( $lang );
				}
			}
		}

		self::$stack[] = $frame;

		return true;
	}

	/** Återställer språket som gällde före motsvarande switch_to(). */
	public static function restore(): void {
		$frame = array_pop( self::$stack );
		if ( ! is_array( $frame ) ) {
			return;
		}

		if ( $frame['pll'] ) {
			$pll = self::polylang_object();
			if ( $pll ) {
				$pll->curlang = $frame['prev'];
			}
		}

		if ( $frame['locale'] && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}

		/*
		 * Sist, efter restore_previous_locale(): även den får Polylang att
		 * ladda om katalogen. Här läggs exakt den tillbaka som låg där före
		 * växlingen – eller ingen alls, om ingen var laddad.
		 */
		if ( self::has_polylang() ) {
			if ( $frame['had_mo'] ) {
				$GLOBALS['l10n']['pll_string'] = $frame['mo'];
			} else {
				unset( $GLOBALS['l10n']['pll_string'] );
			}
		}
	}

	/** Pågår en växling? Används av testerna för att se att allt återställts. */
	public static function depth(): int {
		return count( self::$stack );
	}

	/**
	 * Polylangs huvudobjekt, om det har det switch_to() behöver. Null annars –
	 * då nöjer vi oss med switch_to_locale().
	 */
	private static function polylang_object(): ?object {
		if ( ! function_exists( 'PLL' ) ) {
			return null;
		}

		$pll = PLL();

		/*
		 * is_callable(), inte method_exists(): sedan Polylang 3.7 är
		 * get_language() ingen egen metod på modellen utan skickas vidare
		 * genom __call() till språkregistret. method_exists() svarar då
		 * false, och växlingen uteblev tyst – hittat vid körning mot en
		 * riktig Polylang 3.9.2, inte i mocken.
		 */
		if ( ! is_object( $pll ) || ! isset( $pll->model ) || ! is_object( $pll->model ) || ! is_callable( [ $pll->model, 'get_language' ] ) ) {
			return null;
		}

		return $pll;
	}

	/** Locale för en av Polylangs språkkoder, eller '' om den inte går att slå upp. */
	private static function polylang_locale( string $lang ): string {
		$slugs   = pll_languages_list();
		$locales = pll_languages_list( [ 'fields' => 'locale' ] );

		if ( ! is_array( $slugs ) || ! is_array( $locales ) ) {
			return '';
		}

		$index = array_search( $lang, array_values( $slugs ), true );

		return false !== $index ? (string) ( array_values( $locales )[ $index ] ?? '' ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Översättning
	 * ------------------------------------------------------------------ */

	/**
	 * Översätter en av motorns texter till aktuellt språk. Utan Polylang,
	 * eller utan översättning, kommer texten tillbaka oförändrad.
	 */
	public static function t( string $text ): string {
		if ( '' === $text || ! function_exists( 'pll__' ) ) {
			return $text;
		}

		$translated = pll__( $text );

		return is_string( $translated ) && '' !== $translated ? $translated : $text;
	}

	/**
	 * Översätter ett av sajtens standardvärden, om det är en text besökaren
	 * ser. Formulärets egna värden går aldrig hit – de skrivs på formulärets
	 * språk.
	 */
	public static function setting( string $name, string $value ): string {
		return isset( self::SETTINGS_KEYS[ $name ] ) ? self::t( $value ) : $value;
	}

	/* ---------------------------------------------------------------------
	 * Registrering i Polylang
	 * ------------------------------------------------------------------ */

	/**
	 * Standardvärdena som de är sparade. Bara nycklarna i SETTINGS_KEYS, och
	 * bara de som har ett värde.
	 *
	 * @return array<string,string>
	 */
	public static function raw_settings( bool $refresh = false ): array {
		if ( null !== self::$raw_settings && ! $refresh ) {
			return self::$raw_settings;
		}

		$stored = get_option( 'relativt_form_defaults', [] );
		$out    = [];

		foreach ( self::SETTINGS_KEYS as $key => $spec ) {
			$value = is_array( $stored ) ? ( $stored[ $key ] ?? '' ) : '';
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[ $key ] = $value;
			}
		}

		return self::$raw_settings = $out;
	}

	/**
	 * Allt som registreras: [ namn, text, flerradig ].
	 *
	 * @return array<int,array{0:string,1:string,2:bool}>
	 */
	public static function strings(): array {
		$out = [];

		foreach ( Relativt_Form::message_defaults() as $key => $text ) {
			$out[] = [ 'Meddelande: ' . $key, $text, false ];
		}

		$out[] = [ 'Stegräknare', self::STEP_COUNTER, false ];

		foreach ( self::FALLBACKS as $name => $text ) {
			$out[] = [ $name, $text, false ];
		}

		foreach ( self::raw_settings() as $key => $text ) {
			$out[] = [ self::SETTINGS_KEYS[ $key ][0], $text, self::SETTINGS_KEYS[ $key ][1] ];
		}

		return $out;
	}

	/**
	 * Registrerar texterna i Polylang. Polylang tar bara emot registreringar
	 * i wp-admin; på framsidan är anropet en tom funktion.
	 */
	public static function register_strings(): void {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		foreach ( self::strings() as [ $name, $text, $multiline ] ) {
			pll_register_string( $name, $text, self::GROUP, $multiline );
		}
	}
}

endif;
