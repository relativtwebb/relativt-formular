<?php
/**
 * Polylang i miniatyr, för testerna av språkstödet (1.15.0).
 *
 * Laddas INTE av harness.php. server-test.php kör först allt utan Polylang –
 * så ser de flesta sajter ut – och drar in den här filen först i det sista
 * avsnittet. Från den raden finns pll_*-funktionerna i processen.
 *
 * Mocken följer Polylang 3.9.2 på de punkter språkstödet vilar på. Varje
 * punkt är läst i Polylangs källkod, inte gissad:
 *
 *  - pll__() slår upp texten i $GLOBALS['l10n']['pll_string'] och svarar med
 *    originalet om ingen katalog är laddad (src/api.php).
 *  - Katalogen laddas för ETT språk av load_strings_translations(), som tar
 *    en språkkod eller en locale. En locale som delas av två språk ger det
 *    första (src/base.php, PLL_Model::get_language()).
 *  - Polylang laddar om katalogen på change_locale, alltså vid
 *    switch_to_locale() och restore_previous_locale() (src/base.php).
 *  - I ett REST-anrop sätts aktuellt språk av parametern lang; ett okänt
 *    värde ger standardspråket och inget värde ger inget språk alls
 *    (PLL_REST_Request::set_language(), src/rest-request.php). Det
 *    efterliknas av xf_pll_rest().
 *  - PLL()->model->get_language() nås genom __call() (src/model.php), inte
 *    som en egen metod.
 *  - pll_register_string() tar bara emot registreringar i wp-admin, och
 *    listan har en rad per text: md5 av texten är nyckeln
 *    (PLL_Admin_Strings::register_string()).
 *
 * Styrs av $GLOBALS['__pll']:
 *   languages     språkkod => locale, i sajtens ordning
 *   default       standardspråkets kod
 *   translations  språkkod => [ original => översättning ]
 *   admin         om pll_register_string() ska ta emot registreringar
 *   registered    det som registrerats
 */

$GLOBALS['__pll'] = [
	'languages'    => [ 'sv' => 'sv_SE', 'en' => 'en_GB' ],
	'default'      => 'sv',
	'translations' => [],
	'admin'        => true,
	'registered'   => [],
];

class Xf_Pll_Language {
	public $slug;
	public $locale;
	public function __construct( string $slug, string $locale ) {
		$this->slug   = $slug;
		$this->locale = $locale;
	}
}

class Xf_Pll_Mo {
	public $slug;
	public function __construct( string $slug ) { $this->slug = $slug; }
	public function translate( $string ) {
		return $GLOBALS['__pll']['translations'][ $this->slug ][ $string ] ?? $string;
	}
}

class Xf_Pll_Model {
	/*
	 * get_language() finns INTE som egen metod. Sedan Polylang 3.7 skickar
	 * modellen den vidare genom __call() (src/model.php), så method_exists()
	 * svarar false för den. Mocken gör likadant – en kod som frågar med
	 * method_exists() ska falla här och inte först på en riktig sajt.
	 */
	public function __call( string $name, array $arguments ) {
		if ( 'get_language' === $name ) {
			return $this->find( $arguments[0] ?? '' );
		}
		throw new BadMethodCallException( $name );
	}

	/** Språkkod eller locale. Första träffen vinner, som i Polylang. */
	private function find( $value ) {
		foreach ( $GLOBALS['__pll']['languages'] as $slug => $locale ) {
			if ( $value === $slug || $value === $locale ) {
				return new Xf_Pll_Language( $slug, $locale );
			}
		}
		return false;
	}
}

class Xf_Pll {
	public $curlang = null;
	public $model;
	/** Varje laddning av katalogen, så att testerna kan se vad som hände. */
	public $loads = [];
	public function __construct() { $this->model = new Xf_Pll_Model(); }
	public function load_strings_translations( $locale = '' ) {
		if ( empty( $locale ) ) {
			$locale = get_locale();
		}
		$language      = $this->model->get_language( $locale );
		$this->loads[] = $language ? $language->slug : '';
		if ( $language ) {
			$GLOBALS['l10n']['pll_string'] = new Xf_Pll_Mo( $language->slug );
		} else {
			unset( $GLOBALS['l10n']['pll_string'] );
		}
	}
}

function PLL() { // phpcs:ignore
	static $pll = null;
	return $pll ??= new Xf_Pll();
}

function pll_current_language( $field = 'slug' ) {
	return empty( PLL()->curlang ) ? false : PLL()->curlang->$field;
}

function pll_languages_list( $args = [] ) {
	$field = $args['fields'] ?? 'slug';
	return 'locale' === $field ? array_values( $GLOBALS['__pll']['languages'] ) : array_keys( $GLOBALS['__pll']['languages'] );
}

function pll_register_string( $name, $string, $context = 'Polylang', $multiline = false ) {
	if ( $GLOBALS['__pll']['admin'] && $string && is_scalar( $string ) ) {
		$GLOBALS['__pll']['registered'][ md5( $string ) ] = compact( 'name', 'string', 'context', 'multiline' );
	}
}

function pll__( $string ) {
	if ( ! is_scalar( $string ) || '' === $string ) {
		return $string;
	}
	$mo = $GLOBALS['l10n']['pll_string'] ?? null;
	return $mo instanceof Xf_Pll_Mo ? $mo->translate( $string ) : $string;
}

add_action( 'change_locale', [ PLL(), 'load_strings_translations' ] );

/** Sidvisning på framsidan: språket är bestämt och katalogen laddad. */
function xf_pll_frontend( string $slug ): void {
	PLL()->curlang = PLL()->model->get_language( $slug ) ?: null;
	PLL()->load_strings_translations( $slug );
}

/**
 * Början av ett REST-anrop. $lang är parametern som Polylang 3.3+ själv
 * läser; null efterliknar ett anrop utan parameter, eller en äldre Polylang
 * som inte läser den.
 */
function xf_pll_rest( ?string $lang = null ): void {
	PLL()->curlang = null;
	unset( $GLOBALS['l10n']['pll_string'] );
	if ( null !== $lang && '' !== $lang ) {
		$found         = PLL()->model->get_language( $lang ) ?: PLL()->model->get_language( $GLOBALS['__pll']['default'] );
		PLL()->curlang = $found ?: null;
		if ( PLL()->curlang ) {
			PLL()->load_strings_translations( PLL()->curlang->slug );
		}
	}
	PLL()->loads = [];
}
