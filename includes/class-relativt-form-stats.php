<?php
/**
 * Statistik över inskickens metadata.
 *
 * Formulär → Statistik sammanställer det som redan sparas med varje inskick –
 * tidpunkt, kampanjparametrar, hänvisande sida, landningssida, sidan
 * formuläret skickades från och webbläsarens user agent – till en översikt:
 * inskick över tid, kanaler, topplistor, enhet och veckodag × timme.
 *
 * Ingenting nytt samlas in. Statistiken räknar på sparade inskick, så den
 * följer gallringen: ett inskick som raderats finns inte längre med, och ett
 * formulär som inte sparar inskick syns inte alls. Sidan säger det i klartext
 * i stället för att låta siffrorna se fullständiga ut.
 *
 * Inga personuppgifter visas. E-post och fritext läses aldrig ur databasen,
 * IP-adressen i metadatan släpps direkt vid inläsningen, och user agent
 * används bara för att räkna enhetstyp och webbläsare. Fältvärden läses bara
 * för fält som uttryckligen har "Visa i statistiken" påslaget – och det går
 * bara att slå på för val-fält och dolda fält, aldrig för fritext.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Relativt_Form_Stats', false ) ) :

final class Relativt_Form_Stats {

	public const PAGE = 'relativt-form-stats';

	/** Byts när rapportens format ändras, så att gamla cachade rapporter ignoreras. */
	private const CACHE_VERSION = 4;

	/** Hur länge en sammanställning cachas. Nya inskick bryter cachen direkt. */
	private const CACHE_TTL = 6 * 3600;

	/** Antal inskick som läses per databasfråga. */
	private const BATCH = 500;

	/** Hur många rader varje topplista sparar i cachen. Sidan visar färre. */
	private const KEEP = 50;

	/** Rader som visas per topplista. */
	private const SHOW = 10;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Relativt_Form::CPT_FORM,
			'Statistik',
			'Statistik',
			'edit_pages',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/* ---------------------------------------------------------------------
	 * Klassning – rena funktioner, testade utan WordPress
	 * ------------------------------------------------------------------ */

	/** Kanalernas svenska namn, i den ordning de förklaras på sidan. */
	public static function channel_labels(): array {
		return [
			'paid_search' => 'Betald sök',
			'paid_social' => 'Betald social',
			'paid_other'  => 'Övriga annonser',
			'search'      => 'Organisk sök',
			'social'      => 'Social',
			'ai'          => 'AI-assistenter',
			'email'       => 'E-post',
			'campaign'    => 'Övriga kampanjer',
			'referral'    => 'Hänvisning',
			'direct'      => 'Direkt / okänd',
		];
	}

	/**
	 * Värdnamnet ur en URL, i gemener och utan www.
	 */
	public static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( trim( $url ), PHP_URL_HOST ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * En sida som topplistorna kan gruppera på: sökvägen utan frågesträng och
	 * avslutande snedstreck. Sidor på en annan värd (headless-frontend,
	 * flerspråkig sajt på egen domän) behåller värdnamnet, så att /kontakt på
	 * två olika domäner inte räknas som samma sida.
	 */
	public static function page_key( string $url, string $site_host = '' ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$host = self::host( $url );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );

		if ( '' === $host || $host === $site_host ) {
			return $path;
		}

		return '/' === $path ? $host : $host . $path;
	}

	/** Sajten själv eller en underdomän till den – intern trafik, inte hänvisning. */
	private static function is_own_host( string $host, string $site_host ): bool {
		return '' !== $host && '' !== $site_host && ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) );
	}

	/** Matchar värdnamnet mot en lista domäner, underdomäner inräknade. */
	private static function host_in( string $host, array $domains ): bool {
		foreach ( $domains as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/** Sökmotorer – google.* och yandex.* med valfri toppdomän. */
	private static function is_search_host( string $host ): bool {
		// Bara själva sökmotorn – docs.google.com eller accounts.google.com är inte sök.
		return (bool) preg_match( '/^(www\.)?(google|yandex)\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/', $host )
			|| self::host_in( $host, [ 'bing.com', 'duckduckgo.com', 'search.yahoo.com', 'ecosia.org', 'baidu.com', 'search.brave.com', 'qwant.com', 'startpage.com', 'yahoo.com' ] );
	}

	private static function is_social_host( string $host ): bool {
		return self::host_in( $host, [
			'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'linkedin.com', 'lnkd.in',
			't.co', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be', 'tiktok.com',
			'pinterest.com', 'pinterest.se', 'reddit.com', 'threads.net', 'threads.com', 'snapchat.com',
		] );
	}

	private static function is_ai_host( string $host ): bool {
		return self::host_in( $host, [
			'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com',
			'copilot.microsoft.com', 'you.com', 'chat.mistral.ai', 'deepseek.com',
		] );
	}

	private static function is_webmail_host( string $host ): bool {
		return self::host_in( $host, [ 'mail.google.com', 'outlook.live.com', 'outlook.office.com', 'outlook.office365.com', 'mail.yahoo.com' ] );
	}

	/** utm_source som pekar på en social plattform, t.ex. "linkedin" eller "fb". */
	private static function is_social_source( string $source ): bool {
		return (bool) preg_match( '/^(facebook|fb|instagram|ig|meta|linkedin|lnkd|twitter|x|tiktok|youtube|pinterest|snapchat|reddit|threads)(\.com)?([._ -]|$)/', $source );
	}

	private static function is_search_source( string $source ): bool {
		return (bool) preg_match( '/^(google|bing|yahoo|duckduckgo|ecosia|yandex|baidu|brave|qwant|startpage)(\.[a-z.]+)?$/', $source );
	}

	/**
	 * Klassar ett inskick i en kanal utifrån kampanjparametrar och hänvisande
	 * sida. Ordningen är medveten: en uttrycklig annonsmarkering vinner över
	 * allt annat, sedan kommer taggade kampanjer och sist den hänvisande
	 * sidan. Ett fbclid utan annonsparametrar räknas som social – Facebook
	 * lägger det på alla utgående länkar, även i vanliga inlägg.
	 *
	 * Sajter med egna utm-konventioner justerar via filtret
	 * relativt_form_stats_channel( $channel, $meta ).
	 */
	public static function channel( array $meta, string $site_host = '' ): string {
		$utm    = is_array( $meta['utm'] ?? null ) ? $meta['utm'] : [];
		$medium = strtolower( trim( (string) ( $utm['utm_medium'] ?? '' ) ) );
		$source = strtolower( trim( (string) ( $utm['utm_source'] ?? '' ) ) );
		$ref    = self::host( (string) ( $meta['referrer'] ?? '' ) );

		if ( self::is_own_host( $ref, $site_host ) ) {
			$ref = '';
		}

		$paid   = (bool) preg_match( '/(^|[-_ ])(cpc|ppc|cpm|cpv|cpa|paid\w*|ads?|display|banner|retargeting|sponsored)($|[-_ ])/', $medium );
		$social = self::is_social_source( $source ) || ( '' !== $ref && self::is_social_host( $ref ) ) || ! empty( $utm['fbclid'] );

		if ( $paid && $social ) {
			$channel = 'paid_social';
		} elseif ( ! empty( $utm['gclid'] ) || ( $paid && ( self::is_search_source( $source ) || preg_match( '/(cpc|ppc|search)/', $medium ) ) ) ) {
			$channel = 'paid_search';
		} elseif ( $paid ) {
			$channel = 'paid_other';
		} elseif ( ( '' !== $ref && self::is_ai_host( $ref ) ) || self::is_ai_host( $source ) || preg_match( '/^(chatgpt|perplexity|claude|gemini|copilot)$/', $source ) ) {
			$channel = 'ai';
		} elseif ( preg_match( '/^(e-?mail|newsletter|nyhetsbrev|mail|edm)$/', $medium ) || preg_match( '/(mailchimp|newsletter|nyhetsbrev|apsis|klaviyo)/', $source ) || ( '' !== $ref && self::is_webmail_host( $ref ) ) ) {
			$channel = 'email';
		} elseif ( preg_match( '/^(social|social[-_ ]?media|sm|some|organic[-_ ]?social)$/', $medium ) || $social ) {
			$channel = 'social';
		} elseif ( 'organic' === $medium || ( '' !== $ref && self::is_search_host( $ref ) ) || ( self::is_search_source( $source ) && '' === $medium ) ) {
			$channel = 'search';
		} elseif ( '' !== $source || '' !== $medium || '' !== (string) ( $utm['utm_campaign'] ?? '' ) ) {
			$channel = 'campaign';
		} elseif ( '' !== $ref ) {
			$channel = 'referral';
		} else {
			$channel = 'direct';
		}

		$filtered = (string) apply_filters( 'relativt_form_stats_channel', $channel, $meta );

		return isset( self::channel_labels()[ $filtered ] ) ? $filtered : $channel;
	}

	/** Enhetstyp ur user agent. iPad med iPadOS utger sig för att vara en Mac och räknas som dator. */
	public static function device( string $ua ): string {
		if ( '' === trim( $ua ) ) {
			return 'Okänd';
		}
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)/i', $ua ) ) {
			return 'Surfplatta';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini/i', $ua ) ) {
			return 'Mobil';
		}
		return 'Dator';
	}

	/** Webbläsare ur user agent. Inbyggda appwebbläsare räknas för sig – de säger var besökaren kom ifrån. */
	public static function browser( string $ua ): string {
		$rules = [
			'LinkedIn-appen'   => '/LinkedInApp/i',
			'Facebook-appen'   => '/FBAN|FBAV|FB_IAB/',
			'Instagram-appen'  => '/Instagram/',
			'Edge'             => '/Edg(e|A|iOS)?\//',
			'Opera'            => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Firefox'          => '/Firefox\/|FxiOS/',
			'Chrome'           => '/Chrome\/|CriOS/',
			'Safari'           => '/Version\/[\d.]+.*Safari/',
		];

		if ( '' === trim( $ua ) ) {
			return 'Okänd';
		}

		foreach ( $rules as $name => $pattern ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $name;
			}
		}

		return 'Övrig';
	}

	/* ---------------------------------------------------------------------
	 * Period
	 * ------------------------------------------------------------------ */

	/** Förvalda perioder. */
	public static function periods(): array {
		return [
			'7'      => 'Senaste 7 dagarna',
			'30'     => 'Senaste 30 dagarna',
			'90'     => 'Senaste 90 dagarna',
			'365'    => 'Senaste 12 månaderna',
			'ytd'    => 'I år',
			'all'    => 'Alla sparade inskick',
			'custom' => 'Eget intervall',
		];
	}

	/** Inget inskick kan vara äldre än pluginet. Golvet stoppar också orimliga intervall i URL:en. */
	private const MIN_DATE = '2000-01-01';

	/** Längsta intervall sidan räknar på – tio år, eller 120 månadsstaplar. */
	private const MAX_DAYS = 3653;

	/*
	 * Alla datum räknas i UTC. Inskickens datum är redan i sajtens tidszon
	 * (post_date), så det här handlar bara om kalenderaritmetiken: under en
	 * annan standardtidszon i PHP kunde ett sommartidsskifte annars tappa
	 * eller dubblera en dag.
	 */
	private static function day( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( substr( $date, 0, 10 ), new DateTimeZone( 'UTC' ) );
	}

	/** Samma datum ett år tidigare; 29 februari blir 28 februari, inte 1 mars. */
	private static function year_before( DateTimeImmutable $d ): DateTimeImmutable {
		$y   = (int) $d->format( 'Y' ) - 1;
		$m   = (int) $d->format( 'n' );
		$max = (int) $d->setDate( $y, $m, 1 )->format( 't' );
		return $d->setDate( $y, $m, min( (int) $d->format( 'j' ), $max ) );
	}

	private static function valid_date( string $date ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Tolkar periodvalet till ett datumintervall (båda ändar inräknade) och
	 * jämförelseperioden före. "I år" jämförs med samma datum året innan –
	 * det är den jämförelsen en månadsrapport brukar vilja ha.
	 *
	 * @return array{key:string,label:string,from:string,to:string,days:int,prev_from:string,prev_to:string,granularity:string}
	 */
	public static function resolve_range( array $query, string $today, string $earliest = '' ): array {
		$key  = (string) ( $query['period'] ?? '30' );
		$key  = isset( self::periods()[ $key ] ) ? $key : '30';
		$day  = self::day( $today );
		$from = $day;
		$to   = $day;
		$prev = null;

		switch ( $key ) {
			case 'ytd':
				$from = $day->setDate( (int) $day->format( 'Y' ), 1, 1 );
				$prev = [ self::year_before( $from ), self::year_before( $to ) ];
				break;

			case 'all':
				$from = self::valid_date( $earliest ) && $earliest < $today ? self::day( max( $earliest, self::MIN_DATE ) ) : $day;
				break;

			case 'custom':
				$a = (string) ( $query['from'] ?? '' );
				$b = (string) ( $query['to'] ?? '' );
				if ( ! self::valid_date( $a ) || ! self::valid_date( $b ) ) {
					return self::resolve_range( [ 'period' => '30' ], $today, $earliest );
				}
				if ( $a > $b ) {
					[ $a, $b ] = [ $b, $a ];
				}
				// Inom golvet och i dag, och högst MAX_DAYS långt räknat bakåt från slutet.
				$b    = min( $b, $today );
				$a    = max( min( $a, $b ), self::MIN_DATE );
				$to   = self::day( max( $b, $a ) );
				$from = max( self::day( $a ), $to->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' ) );
				break;

			default:
				$from = $day->modify( '-' . ( (int) $key - 1 ) . ' days' );
		}

		if ( (int) $from->diff( $to )->days >= self::MAX_DAYS ) {
			$from = $to->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' );
		}

		$days = (int) $from->diff( $to )->days + 1;

		if ( null === $prev && 'all' !== $key ) {
			$prev = [ $from->modify( '-' . $days . ' days' ), $from->modify( '-1 day' ) ];
		}

		return [
			'key'         => $key,
			'label'       => 'custom' === $key ? self::date_label( $from ) . ' – ' . self::date_label( $to, true ) : self::periods()[ $key ],
			'from'        => $from->format( 'Y-m-d' ),
			'to'          => $to->format( 'Y-m-d' ),
			'days'        => $days,
			'prev_from'   => $prev ? $prev[0]->format( 'Y-m-d' ) : '',
			'prev_to'     => $prev ? $prev[1]->format( 'Y-m-d' ) : '',
			'granularity' => $days <= 62 ? 'day' : ( $days <= 182 ? 'week' : 'month' ),
		];
	}

	private const MONTHS = [ 'jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec' ];

	private const WEEKDAYS = [ 1 => 'Måndag', 'Tisdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lördag', 'Söndag' ];

	private static function date_label( DateTimeImmutable $d, bool $year = false ): string {
		return $d->format( 'j' ) . ' ' . self::MONTHS[ (int) $d->format( 'n' ) - 1 ] . ( $year ? ' ' . $d->format( 'Y' ) : '' );
	}

	/** Nyckeln för den stapel ett datum hamnar i. */
	public static function bucket( string $date, string $granularity ): string {
		$d = self::day( $date );

		return match ( $granularity ) {
			'week'  => $d->format( 'o-\WW' ),
			'month' => $d->format( 'Y-m' ),
			default => $d->format( 'Y-m-d' ),
		};
	}

	/**
	 * Alla staplar i perioden, i ordning och med noll som startvärde – en
	 * vecka utan inskick ska synas som en lucka, inte försvinna ur grafen.
	 *
	 * @return array<string,array{label:string,title:string,count:int}>
	 */
	public static function buckets( string $from, string $to, string $granularity ): array {
		$out  = [];
		$day  = self::day( $from );
		$end  = self::day( $to );

		while ( $day <= $end ) {
			$key = self::bucket( $day->format( 'Y-m-d' ), $granularity );

			if ( ! isset( $out[ $key ] ) ) {
				$month = self::MONTHS[ (int) $day->format( 'n' ) - 1 ];
				$out[ $key ] = match ( $granularity ) {
					'week'  => [ 'label' => 'v. ' . (int) $day->format( 'W' ), 'title' => 'Vecka ' . (int) $day->format( 'W' ) . ', ' . $day->format( 'o' ), 'count' => 0 ],
					'month' => [ 'label' => $month, 'title' => ucfirst( $month ) . ' ' . $day->format( 'Y' ), 'count' => 0 ],
					default => [ 'label' => self::date_label( $day ), 'title' => self::WEEKDAYS[ (int) $day->format( 'N' ) ] . ' ' . self::date_label( $day, true ), 'count' => 0 ],
				};
				$out[ $key ]['days'] = 0;
				$out[ $key ]['full'] = 'week' === $granularity ? 7 : ( 'month' === $granularity ? (int) $day->format( 't' ) : 1 );
			}

			$out[ $key ]['days']++;

			$day = $day->modify( '+1 day' );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Sammanställning
	 * ------------------------------------------------------------------ */

	/**
	 * Räknar ihop inskicken. Tar emot vilken iterable som helst – i drift en
	 * generator som läser databasen batchvis, i testerna en vanlig array –
	 * så att minnet inte växer med antalet inskick.
	 *
	 * Varje rad: [ 'date' => 'Y-m-d H:i:s', 'form' => int, 'meta' => array, 'mail_ok' => '1'|'0'|'' ].
	 */
	public static function aggregate( iterable $rows, array $range, string $site_host = '', array $tracked = [] ): array {
		$r = [
			'total'         => 0,
			'with_campaign' => 0,
			'with_utm'      => 0,
			'click_only'    => 0,
			'clicks'        => [],
			'with_click'    => 0,
			'mail_failed'   => 0,
			'series'        => self::buckets( $range['from'], $range['to'], $range['granularity'] ),
			'forms'         => [],
			'channels'      => [],
			'utm_source'    => [],
			'utm_medium'    => [],
			'utm_campaign'  => [],
			'referrers'     => [],
			'landing'       => [],
			'pages'         => [],
			'devices'       => [],
			'browsers'      => [],
			'heat'          => [],
			'answers'       => [],
		];

		// Ett tomt resultat per följt fält, så att ett fält utan svar ändå får sitt kort.
		foreach ( $tracked as $fid => $form ) {
			foreach ( $form['fields'] as $key => $def ) {
				$r['answers'][ $fid ][ $key ] = [ 'base' => 0, 'answered' => 0, 'values' => [] ];
			}
		}

		foreach ( $rows as $row ) {
			$meta = is_array( $row['meta'] ?? null ) ? $row['meta'] : [];
			$utm  = is_array( $meta['utm'] ?? null ) ? $meta['utm'] : [];
			$date = (string) ( $row['date'] ?? '' );

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ) {
				continue;
			}

			$r['total']++;

			$bucket = self::bucket( $date, $range['granularity'] );
			if ( isset( $r['series'][ $bucket ] ) ) {
				$r['series'][ $bucket ]['count']++;
			}

			self::bump( $r['forms'], (string) (int) ( $row['form'] ?? 0 ) );
			self::bump( $r['channels'], self::channel( $meta, $site_host ) );

			/*
			 * Kampanjdata = UTM-taggar ELLER klick-id. Klick-id:t läggs på av
			 * plattformen själv (Google Ads automatiska taggning, Facebooks
			 * fbclid på alla utgående länkar), så ett inskick kan ha kampanjdata
			 * utan en enda UTM-tagg. Det räknas för sig, annars ser UTM-korten
			 * tomma ut trots att andelen med kampanjdata är hög.
			 */
			$has_utm = false;
			foreach ( [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ] as $k ) {
				$has_utm = $has_utm || '' !== trim( (string) ( $utm[ $k ] ?? '' ) );
			}
			$has_click = false;
			foreach ( [ 'gclid', 'fbclid' ] as $k ) {
				if ( '' !== trim( (string) ( $utm[ $k ] ?? '' ) ) ) {
					self::bump( $r['clicks'], $k );
					$has_click = true;
				}
			}
			if ( $has_click ) {
				$r['with_click']++;
			}
			if ( $has_utm || $has_click ) {
				$r['with_campaign']++;
			}
			if ( $has_utm ) {
				$r['with_utm']++;
			} elseif ( $has_click ) {
				$r['click_only']++;
			}
			foreach ( [ 'utm_source', 'utm_medium', 'utm_campaign' ] as $k ) {
				$v = strtolower( trim( (string) ( $utm[ $k ] ?? '' ) ) );
				if ( '' !== $v ) {
					self::bump( $r[ $k ], $v );
				}
			}

			$ref = self::host( (string) ( $meta['referrer'] ?? '' ) );
			if ( '' !== $ref && ! self::is_own_host( $ref, $site_host ) ) {
				self::bump( $r['referrers'], $ref );
			}
			$landing = self::page_key( (string) ( $meta['landing'] ?? '' ), $site_host );
			if ( '' !== $landing ) {
				self::bump( $r['landing'], $landing );
			}
			$page = self::page_key( (string) ( $meta['page'] ?? '' ), $site_host );
			if ( '' !== $page ) {
				self::bump( $r['pages'], $page );
			}

			$ua = (string) ( $meta['ua'] ?? '' );
			self::bump( $r['devices'], self::device( $ua ) );
			self::bump( $r['browsers'], self::browser( $ua ) );

			if ( '0' === (string) ( $row['mail_ok'] ?? '' ) ) {
				$r['mail_failed']++;
			}

			$fid = (int) ( $row['form'] ?? 0 );
			foreach ( $tracked[ $fid ]['fields'] ?? [] as $key => $def ) {
				$slot = &$r['answers'][ $fid ][ $key ];
				$slot['base']++;
				$labels = self::answer_labels( $def, (array) ( $row['answers'][ $key ] ?? [] ) );
				if ( $labels ) {
					$slot['answered']++;
					foreach ( $labels as $label ) {
						self::bump( $slot['values'], $label );
					}
				}
				unset( $slot );
			}

			// Klockslaget tas ur inskickets datum, som WordPress stämplar i sajtens tidszon.
			// Läses som text, inte via strtotime(), så att PHP:s egen tidszon inte flyttar timmen.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T](\d{2})/', $date, $hm ) ) {
				$wd = (int) self::day( $date )->format( 'N' );
				$h  = (int) $hm[1];
				$r['heat'][ $wd ][ $h ] = ( $r['heat'][ $wd ][ $h ] ?? 0 ) + 1;
			}
		}

		foreach ( [ 'forms', 'channels', 'utm_source', 'utm_medium', 'utm_campaign', 'referrers', 'landing', 'pages', 'devices', 'browsers' ] as $k ) {
			arsort( $r[ $k ] );
			$r[ $k . '_distinct' ] = count( $r[ $k ] );
			// Antal inskick med ett värde, räknat före kapningen – resten visas som en egen rad.
			$r[ $k . '_n' ]        = array_sum( $r[ $k ] );
			$r[ $k ]               = array_slice( $r[ $k ], 0, self::KEEP, true );
		}

		foreach ( $r['answers'] as $fid => $fields ) {
			foreach ( $fields as $key => $slot ) {
				arsort( $slot['values'] );
				$slot['distinct']               = count( $slot['values'] );
				$slot['values']                 = array_slice( $slot['values'], 0, self::KEEP, true );
				$r['answers'][ $fid ][ $key ]   = $slot;
			}
		}

		return $r;
	}

	/**
	 * Svaret i ett följt fält som etiketter att räkna på.
	 *
	 * Val-fält räknas på det tekniska värdet och visas med den etikett fältet
	 * har NU – byter någon "Företag" till "Företagskund" slås gamla och nya
	 * inskick ihop. Finns värdet inte längre bland valen används etiketten
	 * som sparades med inskicket. Flerval ger en etikett per ikryssat val.
	 *
	 * @param array $def    [ 'type' => …, 'choices' => [ värde => etikett ] ]
	 * @param array $answer [ 'value' => sparad etikett, 'raw' => tekniskt värde ]
	 * @return string[]
	 */
	public static function answer_labels( array $def, array $answer ): array {
		$choices = is_array( $def['choices'] ?? null ) ? $def['choices'] : [];
		$value   = trim( (string) ( $answer['value'] ?? '' ) );
		$raw     = trim( (string) ( $answer['raw'] ?? '' ) );

		switch ( $def['type'] ?? '' ) {
			case 'checkboxes':
				$pieces = '' !== $raw ? explode( ', ', $raw ) : [];
				$labels = array_map( static fn( $p ) => $choices[ $p ] ?? null, $pieces );
				if ( ! $pieces || in_array( null, $labels, true ) ) {
					$labels = '' !== $value ? explode( ', ', $value ) : [];
				}
				return array_values( array_filter( array_map( 'trim', $labels ), 'strlen' ) );

			case 'select':
			case 'buttons':
			case 'radio':
				if ( '' !== $raw && isset( $choices[ $raw ] ) ) {
					return [ (string) $choices[ $raw ] ];
				}
				return '' !== $value ? [ $value ] : [];

			default:
				// Kryssruta (Ja/Nej) och dolda fält: det sparade värdet, kapat.
				return '' !== $value ? [ mb_substr( $value, 0, 100 ) ] : [];
		}
	}

	private static function bump( array &$list, string $key ): void {
		$list[ $key ] = ( $list[ $key ] ?? 0 ) + 1;
	}

	/* ---------------------------------------------------------------------
	 * Databas
	 * ------------------------------------------------------------------ */

	/*
	 * Läses med egna frågor i stället för get_posts(). Två skäl:
	 *
	 * 1. get_posts() fyller metacachen med ALLA nycklar per inskick – även
	 *    fältvärdena och e-postadressen. Här hämtas bara de nycklar sidan
	 *    behöver, och fältvärdena bara när något fält följs.
	 * 2. get_posts() bläddrar med OFFSET, som sorterar om hela urvalet för
	 *    varje sida och hoppar över rader om gallringen raderar mitt i.
	 *    Här bläddras det på id (ID > senaste), som går rakt på primärnyckeln.
	 */

	/** WHERE-villkoren för inskick i perioden, ev. för ett formulär. @return array{0:string,1:string,2:array} */
	private static function entry_sql( string $from, string $to, int $form_id ): array {
		global $wpdb;

		$join = '';
		$args = [];
		if ( $form_id ) {
			$join   = " INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_xf_form_id' AND f.meta_value = %s";
			$args[] = (string) $form_id;
		}

		$where  = " WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date >= %s AND p.post_date <= %s";
		$args[] = Relativt_Form::CPT_ENTRY;
		$args[] = $from . ' 00:00:00';
		$args[] = $to . ' 23:59:59';

		return [ "{$wpdb->posts} p" . $join, $where, $args ];
	}

	private static function has_db(): bool {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb );
	}

	/**
	 * Inskicken i perioden, batchvis.
	 *
	 * Fältvärdena (_xf_values) hämtas BARA när något fält följs, och reduceras
	 * direkt till de följda fälten – namn, e-post och fritext lämnar aldrig
	 * den här metoden.
	 *
	 * @param array<int,string[]> $tracked_keys formulär-id => följda fältnycklar
	 */
	private function rows( string $from, string $to, int $form_id, array $tracked_keys = [] ): Generator {
		global $wpdb;

		if ( ! self::has_db() ) {
			return;
		}

		[ $table, $where, $args ] = self::entry_sql( $from, $to, $form_id );
		$last = 0;

		do {
			$posts = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_date FROM {$table}{$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
				...array_merge( $args, [ $last, self::BATCH ] )
			) );

			if ( ! $posts ) {
				return;
			}

			$ids  = array_map( static fn( $p ) => (int) $p->ID, $posts );
			$last = max( $ids );
			$meta = [];

			// Id:na är heltal ur frågan ovan, så IN-listan behöver ingen prepare.
			$keys  = "'_xf_form_id', '_xf_meta', '_xf_mail_ok'" . ( $tracked_keys ? ", '_xf_values'" : '' );
			$found = (array) $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key IN ({$keys}) AND post_id IN (" . implode( ',', $ids ) . ')'
			);
			foreach ( $found as $m ) {
				$meta[ (int) $m->post_id ][ $m->meta_key ] = $m->meta_value;
			}

			foreach ( $posts as $post ) {
				$m    = $meta[ (int) $post->ID ] ?? [];
				$data = maybe_unserialize( (string) ( $m['_xf_meta'] ?? '' ) );

				// IP-adressen ligger i samma metafält men används aldrig här – släpp den direkt.
				if ( is_array( $data ) ) {
					unset( $data['ip'] );
				}

				$fid     = (int) ( $m['_xf_form_id'] ?? 0 );
				$answers = [];
				if ( isset( $tracked_keys[ $fid ] ) ) {
					$values = maybe_unserialize( (string) ( $m['_xf_values'] ?? '' ) );
					foreach ( is_array( $values ) ? $values : [] as $v ) {
						$k = (string) ( $v['key'] ?? '' );
						if ( in_array( $k, $tracked_keys[ $fid ], true ) ) {
							$answers[ $k ] = [ 'value' => (string) ( $v['value'] ?? '' ), 'raw' => (string) ( $v['raw'] ?? '' ) ];
						}
					}
				}

				yield [
					'date'    => (string) $post->post_date,
					'form'    => $fid,
					'meta'    => $data,
					'mail_ok' => (string) ( $m['_xf_mail_ok'] ?? '' ),
					'answers' => $answers,
				];
			}
		} while ( count( $posts ) === self::BATCH );
	}

	private function count_entries( string $from, string $to, int $form_id ): int {
		global $wpdb;

		if ( ! self::has_db() ) {
			return 0;
		}

		[ $table, $where, $args ] = self::entry_sql( $from, $to, $form_id );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where}", ...$args ) );
	}

	/** Datum för det äldsta sparade inskicket, för "Alla sparade inskick". */
	private function earliest( int $form_id ): string {
		global $wpdb;

		if ( ! self::has_db() ) {
			return '';
		}

		[ $table, $where, $args ] = self::entry_sql( self::MIN_DATE, '9999-12-31', $form_id );

		return substr( (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(p.post_date) FROM {$table}{$where}", ...$args ) ), 0, 10 );
	}

	private static function site_host(): string {
		return function_exists( 'home_url' ) ? self::host( (string) home_url() ) : '';
	}

	/**
	 * Fingeravtryck för de sparade inskicken: antal, högsta id och senast
	 * skrivna mailstatus. Ändras när ett inskick skapas eller raderas – även
	 * när gallringen raderar direkt, vilket wp_count_posts() inte märker på en
	 * sajt med beständig objektcache – och när mailstatusen skrivs. Den
	 * skrivs först när wp_mail() svarat, och utan den biten kunde en rapport
	 * som byggdes under tiden frysa ett halvfärdigt inskick i sex timmar.
	 */
	private static function fingerprint(): string {
		global $wpdb;

		if ( ! self::has_db() ) {
			return '';
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS n, MAX(ID) AS m, (SELECT MAX(meta_id) FROM {$wpdb->postmeta} WHERE meta_key = '_xf_mail_ok') AS s
			FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			Relativt_Form::CPT_ENTRY
		) );

		return $row ? $row->n . ':' . $row->m . ':' . $row->s : '';
	}

	/**
	 * Rapporten, cachad. Cachenyckeln innehåller fingeravtrycket ovan, så ett
	 * nytt eller raderat inskick syns direkt i stället för först när cachen
	 * löper ut.
	 */
	public function report( array $range, int $form_id, array $tracked = [] ): array {
		// De följda fälten ingår i nyckeln – kryssar någon i ett fält till syns det direkt.
		$key    = 'xf_stats_' . md5( wp_json_encode( [ self::CACHE_VERSION, $range, $form_id, self::fingerprint(), self::site_host(), $tracked ] ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$keys           = array_map( static fn( $f ) => array_map( 'strval', array_keys( $f['fields'] ) ), $tracked );
		$report         = self::aggregate( $this->rows( $range['from'], $range['to'], $form_id, $keys ), $range, self::site_host(), $tracked );
		$report['prev'] = '' !== $range['prev_from'] ? $this->count_entries( $range['prev_from'], $range['prev_to'], $form_id ) : null;

		set_transient( $key, $report, self::CACHE_TTL );

		return $report;
	}

	/* ---------------------------------------------------------------------
	 * Sidan
	 * ------------------------------------------------------------------ */

	public function render(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'Åtkomst nekad.' );
		}

		$form_id = max( 0, (int) ( $_GET['xf_form'] ?? 0 ) );
		$query   = array_map( 'sanitize_text_field', array_intersect_key( wp_unslash( $_GET ), [ 'period' => 1, 'from' => 1, 'to' => 1 ] ) );
		$today   = current_time( 'Y-m-d' );
		$range   = self::resolve_range( $query, $today, 'all' === ( $query['period'] ?? '' ) ? $this->earliest( $form_id ) : '' );
		$forms   = get_posts( [ 'post_type' => Relativt_Form::CPT_FORM, 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );

		echo '<div class="wrap xf-stats">';
		echo '<h1>Statistik</h1>';
		echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- statisk CSS.
		echo $this->filter_form( $range, $form_id, $forms ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapas i metoden.
		echo $this->coverage_notice( $range, $form_id, $forms, $today ); // phpcs:ignore WordPress.Security.EscapeOutput
		$tracked = $this->tracked_fields( $form_id, $forms );
		echo $this->render_report( $this->report( $range, $form_id, $tracked ), $range, $form_id, $tracked ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	/**
	 * Fälten som har "Visa i statistiken" påslaget, i de formulär som ingår i
	 * urvalet.
	 *
	 * @return array<int,array{title:string,fields:array<string,array{label:string,type:string,choices:array}>}>
	 */
	public function tracked_fields( int $form_id, array $forms ): array {
		$engine  = Relativt_Form::instance();
		$tracked = [];

		foreach ( $forms as $f ) {
			if ( $form_id && (int) $f->ID !== $form_id ) {
				continue;
			}
			foreach ( $engine->get_fields( (int) $f->ID ) as $field ) {
				if ( ! empty( $field['stats'] ) && '' !== $field['key'] ) {
					$tracked[ (int) $f->ID ]['title']                    = (string) ( $f->post_title ?: '#' . $f->ID );
					$tracked[ (int) $f->ID ]['fields'][ $field['key'] ] = [
						'label'   => '' !== $field['label'] ? $field['label'] : $field['key'],
						'type'    => $field['type'],
						'choices' => $field['choices'],
					];
				}
			}
		}

		return $tracked;
	}

	private function filter_form( array $range, int $form_id, array $forms ): string {
		$period = '<select name="period" id="xf-stats-period">';
		foreach ( self::periods() as $key => $label ) {
			$period .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $range['key'], $key, false ), esc_html( $label ) );
		}
		$period .= '</select>';

		$form = '<select name="xf_form" id="xf-stats-form"><option value="0">Alla formulär</option>';
		foreach ( $forms as $f ) {
			$form .= sprintf( '<option value="%d"%s>%s</option>', (int) $f->ID, selected( $form_id, (int) $f->ID, false ), esc_html( $f->post_title ?: '(utan rubrik)' ) );
		}
		$form .= '</select>';

		return sprintf(
			'<form method="get" class="xf-stats-filter">
				<input type="hidden" name="post_type" value="%s"><input type="hidden" name="page" value="%s">
				<label for="xf-stats-period">Period</label> %s
				<span class="xf-custom"><label>Från <input type="date" name="from" value="%s"></label> <label>Till <input type="date" name="to" value="%s"></label></span>
				<label for="xf-stats-form">Formulär</label> %s
				<button type="submit" class="button button-primary">Visa</button>
			</form>',
			esc_attr( Relativt_Form::CPT_FORM ),
			esc_attr( self::PAGE ),
			$period,
			esc_attr( $range['from'] ),
			esc_attr( $range['to'] ),
			$form
		);
	}

	/**
	 * Säger vad siffrorna INTE täcker: formulär som inte sparar inskick, och
	 * gallring som redan tagit bort början av perioden.
	 */
	private function coverage_notice( array $range, int $form_id, array $forms, string $today ): string {
		$engine     = Relativt_Form::instance();
		$not_stored = [];
		$pruned     = [];

		foreach ( $forms as $f ) {
			if ( $form_id && (int) $f->ID !== $form_id ) {
				continue;
			}
			if ( ! $engine->stores_entries( (int) $f->ID ) ) {
				$not_stored[] = $f->post_title ?: '#' . $f->ID;
				continue;
			}
			$days = $engine->retention_days( (int) $f->ID );
			if ( $days > 0 && $range['from'] < self::day( $today )->modify( '-' . $days . ' days' )->format( 'Y-m-d' ) ) {
				$pruned[] = sprintf( '%s (%d dagar)', $f->post_title ?: '#' . $f->ID, $days );
			}
		}

		$lines = [ 'Statistiken räknar på sparade inskick och visar inga personuppgifter.' ];
		if ( $not_stored ) {
			$lines[] = 'Sparar inte inskick och räknas därför inte: <strong>' . esc_html( implode( ', ', $not_stored ) ) . '</strong>.';
		}
		if ( $pruned ) {
			$lines[] = 'Perioden börjar före gallringsgränsen för ' . esc_html( implode( ', ', $pruned ) ) . ' – de äldsta inskicken är redan raderade.';
		}

		return '<p class="xf-stats-coverage">' . implode( ' ', $lines ) . '</p>';
	}

	public function render_report( array $r, array $range, int $form_id, array $tracked = [] ): string {
		if ( 0 === $r['total'] ) {
			$out = $this->tiles( $r, $range, $form_id );
			return $out . '<div class="xf-card xf-empty"><p>Inga sparade inskick under ' . esc_html( mb_strtolower( $range['label'] ) ) . '.</p></div>';
		}

		$out  = $this->tiles( $r, $range, $form_id );
		$out .= $this->card( 'Inskick över tid', self::column_chart( $r['series'], $range['granularity'] ), 'xf-wide' );

		$out .= '<div class="xf-grid">';
		$out .= $this->card( 'Kanaler', $this->bar_list( $r['channels'], $r['total'], 'Kanal', self::channel_labels() ) . '<p class="xf-note">Kanal räknas ur kampanjparametrar och hänvisande sida. Har besökaren inte godkänt kakor och klickat sig vidare innan formuläret skickades blir källan okänd.</p>' );
		if ( ! $form_id ) {
			$out .= $this->card( 'Per formulär', $this->form_list( $r ) );
		}
		$out .= $this->card( 'Enhet och webbläsare', $this->bar_list( $r['devices'], $r['total'], 'Enhet' ) . '<div class="xf-gap"></div>' . $this->bar_list( $r['browsers'], $r['total'], 'Webbläsare' ) . '<p class="xf-note">iPad med iPadOS utger sig för att vara en Mac och räknas som dator.</p>' );
		$out .= '</div>';

		$out .= $this->answers_section( $r, $tracked );

		$out .= '<h2 class="xf-section">Kampanjer</h2>';
		$out .= '<p class="xf-note">' . self::campaign_summary( $r ) . '</p>';

		// Tomma UTM-kort när det ändå finns annonsklick ska säga varför, inte bara "inga uppgifter".
		$utm_empty = $r['click_only']
			? sprintf( 'Inga UTM-taggade inskick. %s inskick har bara annonsklick-id – se Annonsklick.', self::num( $r['click_only'] ) )
			: '';

		$out .= '<div class="xf-grid xf-grid-4">';
		foreach ( [ 'utm_source' => [ 'Kampanjkälla', 'Källa' ], 'utm_medium' => [ 'Medium', 'Medium' ], 'utm_campaign' => [ 'Kampanj', 'Kampanj' ] ] as $k => [ $title, $heading ] ) {
			$out .= $this->card(
				"{$title} ({$k})",
				$this->bar_list( $r[ $k ], $r['total'], $heading, distinct: $r[ $k . '_distinct' ], empty: $utm_empty, rest: $r[ $k ] ? "Utan {$k}" : '', covered: $r[ $k . '_n' ] )
			);
		}
		$out .= $this->card(
			'Annonsklick',
			$this->bar_list( $r['clicks'], $r['total'], 'Klick-id', [ 'gclid' => 'Google Ads (gclid)', 'fbclid' => 'Facebook/Instagram (fbclid)' ], empty: 'Inga annonsklick under perioden.', rest: $r['clicks'] ? 'Utan annonsklick' : '', covered: $r['with_click'] )
				. '<p class="xf-note">Läggs på av plattformen, inte av den som taggat länken. fbclid följer med alla länkar från Facebook och Instagram, även vanliga inlägg – det är inte nödvändigtvis en annons.</p>'
		);
		$out .= '</div>';

		$out .= '<h2 class="xf-section">Sidor</h2>';
		$out .= '<div class="xf-grid xf-grid-3">';
		$out .= $this->card(
			'Hänvisande webbplatser',
			$this->bar_list( $r['referrers'], $r['total'], 'Webbplats', distinct: $r['referrers_distinct'], rest: 'Ingen extern webbplats', covered: $r['referrers_n'] )
				. ( $r['total'] > $r['referrers_n'] ? '<p class="xf-note">Ingen extern webbplats = direkttrafik, okänd källa eller trafik från sajten själv. De flesta syns som Direkt / okänd under Kanaler.</p>' : '' )
		);
		$out .= $this->card( 'Landningssidor', $this->bar_list( $r['landing'], $r['total'], 'Sida', distinct: $r['landing_distinct'], rest: 'Uppgift saknas', covered: $r['landing_n'] ) );
		$out .= $this->card( 'Skickat från', $this->bar_list( $r['pages'], $r['total'], 'Sida', distinct: $r['pages_distinct'], rest: 'Uppgift saknas', covered: $r['pages_n'] ) );
		$out .= '</div>';

		$out .= $this->card( 'Veckodag och klockslag', self::heatmap( $r['heat'] ), 'xf-wide' );

		return $out;
	}

	/** Formulärsvar: ett kort per fält med "Visa i statistiken" påslaget. */
	private function answers_section( array $r, array $tracked ): string {
		$out = '<h2 class="xf-section">Formulärsvar</h2>';

		if ( ! $tracked ) {
			return $out . '<p class="xf-note">Inga fält följs. Slå på <strong>Visa i statistiken</strong> på ett fält i formulärbyggaren – rullista, val-knappar, radioknappar, flerval, kryssruta eller dolt fält – så visas fördelningen av svaren här.</p>';
		}

		$many = count( $tracked ) > 1;
		$out .= '<div class="xf-grid">';

		foreach ( $tracked as $fid => $form ) {
			foreach ( $form['fields'] as $key => $def ) {
				$slot   = $r['answers'][ $fid ][ $key ] ?? [ 'base' => 0, 'answered' => 0, 'values' => [], 'distinct' => 0 ];
				$none   = $slot['base'] - $slot['answered'];

				$notes = [];
				if ( 'checkboxes' === $def['type'] ) {
					$notes[] = 'Flera val möjliga – andelarna kan bli mer än 100 % tillsammans.';
				}
				if ( $none > 0 ) {
					$notes[] = 'Ej besvarat = fältet lämnades tomt eller var dolt av ett villkor.';
				}

				$title = $def['label'] . ( $many ? ' · ' . $form['title'] : '' );
				$body  = $this->bar_list(
					$slot['values'],
					(int) $slot['base'],
					'Svar',
					distinct: (int) ( $slot['distinct'] ?? 0 ),
					empty: 'Inga inskick från formuläret under perioden.',
					rest: 'Ej besvarat',
					covered: (int) $slot['answered']
				);
				$body .= $notes ? '<p class="xf-note">' . esc_html( implode( ' ', $notes ) ) . '</p>' : '';

				$out .= $this->card( $title, $body );
			}
		}

		return $out . '</div>';
	}

	private function card( string $title, string $body, string $class = '' ): string {
		return sprintf( '<section class="xf-card %s"><h3>%s</h3>%s</section>', esc_attr( $class ), esc_html( $title ), $body );
	}

	private function tiles( array $r, array $range, int $form_id ): string {
		$total = (int) $r['total'];
		$prev  = $r['prev'] ?? null;
		$delta = '';

		if ( null !== $prev ) {
			$period = 'ytd' === $range['key'] ? 'samma period i fjol' : 'föregående period';
			if ( $prev > 0 ) {
				$pct   = (int) round( ( $total - $prev ) / $prev * 100 );
				$dir   = $pct > 0 ? 'up' : ( $pct < 0 ? 'down' : 'flat' );
				$arrow = [ 'up' => '▲', 'down' => '▼', 'flat' => '●' ][ $dir ];
				$delta = sprintf( '<span class="xf-delta xf-%s">%s %s%d %%</span> mot %s (%s)', $dir, $arrow, $pct > 0 ? '+' : '', $pct, esc_html( $period ), self::num( $prev ) );
			} else {
				$delta = 'Inga sparade inskick ' . esc_html( $period );
			}
		}

		$per_week = $range['days'] >= 14
			? [ self::num( $total / $range['days'] * 7, 1 ), 'Snitt per vecka' ]
			: [ self::num( $total / $range['days'], 1 ), 'Snitt per dag' ];

		$campaign = $total ? (int) round( $r['with_campaign'] / $total * 100 ) . ' %' : '–';

		$failed_url = admin_url( 'edit.php?post_type=' . Relativt_Form::CPT_ENTRY . '&xf_mail=failed' . ( $form_id ? '&xf_form=' . $form_id : '' ) );
		$failed     = $r['mail_failed']
			? sprintf( '<a href="%s">Visa alla misslyckade</a>', esc_url( $failed_url ) )
			: 'Alla notismail gick iväg';

		$tiles = [
			[ 'Inskick', self::num( $total ), $delta ?: esc_html( $range['label'] ) ],
			[ $per_week[1], $per_week[0], esc_html( $range['label'] ) ],
			[ 'Med kampanjdata', $campaign, 'UTM-parametrar eller annonsklick' ],
			[ 'Misslyckade mail', self::num( (int) $r['mail_failed'] ), $failed ],
		];

		$out = '<div class="xf-tiles">';
		foreach ( $tiles as [ $label, $value, $sub ] ) {
			$out .= sprintf( '<div class="xf-tile"><div class="xf-tile-label">%s</div><div class="xf-tile-value">%s</div><div class="xf-tile-sub">%s</div></div>', esc_html( $label ), $value, $sub );
		}
		return $out . '</div>';
	}

	/**
	 * Topplista med stapel per rad. Stapeln är relativ till listans största
	 * värde, andelen till alla inskick i perioden.
	 */
	/**
	 * $rest + $covered: listor där inte alla inskick har ett värde (hänvisare,
	 * UTM-taggar, svar i följda fält) får en grå sista rad med resten, så att
	 * andelarna går jämnt upp i 100 % i stället för att lämna en oförklarad
	 * lucka. $covered = antal inskick MED ett värde; resten = $total − $covered.
	 * Raden står alltid sist och kapas aldrig bort.
	 */
	private function bar_list( array $counts, int $total, string $heading, array $labels = [], int $distinct = 0, string $empty = '', string $rest = '', int $covered = 0 ): string {
		$remaining = '' !== $rest ? max( 0, $total - $covered ) : 0;

		// Utan värden och utan rest finns inget att visa. Med rest blir det en
		// ensam grå rad på 100 % – t.ex. ett följt fält som ingen besvarat.
		if ( ! $counts && ! $remaining ) {
			return '<p class="xf-none">' . esc_html( '' !== $empty ? $empty : 'Inga uppgifter under perioden.' ) . '</p>';
		}

		$shown     = array_slice( $counts, 0, $remaining ? self::SHOW - 1 : self::SHOW, true );
		$more      = $distinct > count( $shown ) ? sprintf( '<p class="xf-note">Visar %d av %s.</p>', count( $shown ), self::num( $distinct ) ) : '';

		if ( $remaining ) {
			$shown['__xf_none']  = $remaining;
			$labels['__xf_none'] = $rest;
		}

		/*
		 * Staplarna skalas mot listans största RIKTIGA värde. Restraden får
		 * ingen stapel alls: den är ingen kategori bland de andra, och fick den
		 * vara med i skalan blev de riktiga staplarna små streck bredvid en
		 * stor grå "Ingen extern webbplats".
		 */
		$real = array_diff_key( $shown, [ '__xf_none' => 1 ] );
		$max  = $real ? max( $real ) : 1;
		$rows = '';
		foreach ( $shown as $key => $n ) {
			$is_rest = '__xf_none' === (string) $key;
			$label   = $labels[ $key ] ?? (string) $key;
			$rows   .= sprintf(
				'<tr%5$s><td><span class="xf-label" title="%1$s">%1$s</span>%2$s</td><td class="num">%3$s</td><td class="num">%4$s</td></tr>',
				esc_html( $label ),
				$is_rest ? '' : sprintf( '<span class="xf-bar" aria-hidden="true"><i style="width:%s%%"></i></span>', esc_attr( (string) max( 1, round( $n / $max * 100, 1 ) ) ) ),
				self::num( $n ),
				$total ? self::num( $n / $total * 100, $n / $total < 0.1 ? 1 : 0 ) . ' %' : '',
				$is_rest ? ' class="xf-muted"' : ''
			);
		}

		return sprintf(
			'<table class="xf-list"><thead><tr><th scope="col">%s</th><th scope="col" class="num">Inskick</th><th scope="col" class="num">Andel</th></tr></thead><tbody>%s</tbody></table>%s',
			esc_html( $heading ),
			$rows,
			$more
		);
	}

	/** "38 av 100 inskick har kampanjdata – 12 med UTM-taggar och 26 med bara annonsklick-id." */
	private static function campaign_summary( array $r ): string {
		if ( ! $r['with_campaign'] ) {
			return sprintf( 'Inget av %s inskick har kampanjdata.', self::num( $r['total'] ) );
		}

		$parts = [];
		if ( $r['with_utm'] ) {
			$parts[] = sprintf( '%s med UTM-taggar', self::num( $r['with_utm'] ) );
		}
		if ( $r['click_only'] ) {
			$parts[] = sprintf( '%s med bara annonsklick-id (gclid/fbclid)', self::num( $r['click_only'] ) );
		}

		return sprintf( '%s av %s inskick har kampanjdata – %s.', self::num( $r['with_campaign'] ), self::num( $r['total'] ), implode( ' och ', $parts ) );
	}

	private function form_list( array $r ): string {
		$labels = [];
		foreach ( array_keys( $r['forms'] ) as $id ) {
			$title         = Relativt_Form::CPT_FORM === get_post_type( (int) $id ) ? (string) get_the_title( (int) $id ) : '';
			$labels[ $id ] = '' !== $title ? $title : 'Borttaget formulär (#' . (int) $id . ')';
		}
		return $this->bar_list( $r['forms'], $r['total'], 'Formulär', $labels, $r['forms_distinct'] );
	}

	/** Svenska tal: mellanslag som tusentalsavgränsare, komma som decimaltecken. */
	private static function num( $n, int $decimals = 0 ): string {
		return str_replace( ' ', "\u{00A0}", number_format( (float) $n, $decimals, ',', ' ' ) );
	}

	/** Jämna skalsteg: 1, 2, 5 × 10^n. */
	private static function nice_max( int $max ): int {
		if ( $max <= 4 ) {
			return 4;
		}
		$step = $max / 4;
		$mag  = 10 ** floor( log10( $step ) );
		foreach ( [ 1, 2, 2.5, 3, 5, 10 ] as $m ) {
			// 2,5 ger skalsteg som 2,5 / 7,5 när $mag är 1 – axeln visar heltal, så de hoppas över.
			if ( $m * $mag >= $step && floor( $m * $mag ) == $m * $mag ) {
				return (int) ( $m * $mag * 4 );
			}
		}
		return (int) ( 10 * $mag * 4 );
	}

	/**
	 * Stapeldiagram som inline-SVG. Inga externa bibliotek – wp-admin ska inte
	 * hämta skript från en CDN för att visa fyra staplar. Varje stapel har en
	 * osynlig träffyta över hela kolumnen med <title> som verktygstips, och
	 * siffrorna finns också som tabell under diagrammet.
	 */
	public static function column_chart( array $series, string $granularity ): string {
		$w = 960;
		$h = 240;
		$l = 44;
		$b = 26;
		$t = 10;
		$pw = $w - $l - 8;
		$ph = $h - $b - $t;

		$n    = max( 1, count( $series ) );
		$max  = self::nice_max( (int) max( array_column( $series, 'count' ) ?: [ 0 ] ) );
		$slot = $pw / $n;
		$bw   = max( 2, min( 24, $slot * 0.7 ) );
		$step = (int) ceil( $n / 12 );
		$unit = [ 'day' => 'Per dag', 'week' => 'Per vecka', 'month' => 'Per månad' ][ $granularity ] ?? '';

		$svg = sprintf( '<svg class="xf-chart" viewBox="0 0 %d %d" role="img" aria-label="Inskick %s">', $w, $h, esc_attr( mb_strtolower( $unit ) ) );

		for ( $i = 0; $i <= 4; $i++ ) {
			$y    = $t + $ph - $ph * $i / 4;
			$svg .= sprintf( '<line class="xf-grid-line" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', $l, $w - 8, $y, $y );
			$svg .= sprintf( '<text class="xf-axis" x="%d" y="%.1f" text-anchor="end">%s</text>', $l - 8, $y + 4, self::num( $max * $i / 4 ) );
		}

		$i = 0;
		foreach ( $series as $bucket ) {
			$x  = $l + $i * $slot;
			$cx = $x + $slot / 2;
			$bh = $max ? $bucket['count'] / $max * $ph : 0;
			$svg .= '<g class="xf-col">';
			$svg .= sprintf( '<rect class="xf-hit" x="%.1f" y="%d" width="%.1f" height="%d"><title>%s: %s inskick%s</title></rect>', $x, $t, $slot, $ph, esc_html( $bucket['title'] ), self::num( $bucket['count'] ), self::partial( $bucket ) );

			if ( $bh > 0 ) {
				$rad  = min( 4, $bw / 2, $bh );
				$x0   = $cx - $bw / 2;
				$y0   = $t + $ph - $bh;
				$svg .= sprintf(
					'<path class="xf-bar-mark" d="M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ"/>',
					$x0, $t + $ph, $y0 + $rad, $x0, $y0, $x0 + $rad, $y0,
					$x0 + $bw - $rad, $x0 + $bw, $y0, $x0 + $bw, $y0 + $rad, $t + $ph
				);
			}
			if ( 0 === $i % $step ) {
				$svg .= sprintf( '<text class="xf-axis" x="%.1f" y="%d" text-anchor="middle">%s</text>', $cx, $h - 6, esc_html( $bucket['label'] ) );
			}
			$svg .= '</g>';
			$i++;
		}
		$svg .= sprintf( '<line class="xf-base-line" x1="%d" x2="%d" y1="%d" y2="%d"/>', $l, $w - 8, $t + $ph, $t + $ph );
		$svg .= '</svg>';

		$rows = '';
		foreach ( $series as $bucket ) {
			$rows .= sprintf( '<tr><td>%s%s</td><td class="num">%s</td></tr>', esc_html( $bucket['title'] ), self::partial( $bucket ), self::num( $bucket['count'] ) );
		}

		return sprintf(
			'<p class="xf-note">%s</p><div class="xf-chart-wrap">%s</div><details class="xf-table"><summary>Visa som tabell</summary><table class="xf-list"><thead><tr><th scope="col">Period</th><th scope="col" class="num">Inskick</th></tr></thead><tbody>%s</tbody></table></details>',
			esc_html( $unit ),
			$svg,
			$rows
		);
	}

	/** En vecka eller månad som perioden bara delvis täcker – annars ser första stapeln ut som ett ras. */
	private static function partial( array $bucket ): string {
		$days = (int) ( $bucket['days'] ?? 1 );
		return $days < (int) ( $bucket['full'] ?? 1 ) ? sprintf( ' (%d %s i perioden)', $days, 1 === $days ? 'dag' : 'dagar' ) : '';
	}

	/** Veckodag × timme. Sex steg i en blå skala, noll är ytans egen ton. */
	public static function heatmap( array $heat ): string {
		$max = 0;
		foreach ( $heat as $hours ) {
			$max = max( $max, ...array_values( $hours ) );
		}

		$head = '<th scope="col"><span class="screen-reader-text">Veckodag</span></th>';
		for ( $h = 0; $h < 24; $h++ ) {
			$head .= sprintf( '<th scope="col">%s</th>', 0 === $h % 3 ? sprintf( '%02d', $h ) : '' );
		}

		$body = '';
		foreach ( self::WEEKDAYS as $wd => $name ) {
			$body .= sprintf( '<tr><th scope="row">%s</th>', esc_html( mb_substr( $name, 0, 3 ) ) );
			for ( $h = 0; $h < 24; $h++ ) {
				$v      = (int) ( $heat[ $wd ][ $h ] ?? 0 );
				$level  = $v && $max ? (int) ceil( $v / $max * 6 ) : 0;
				$body  .= sprintf(
					'<td class="xf-l%d" title="%s %02d–%02d: %s inskick"><span class="screen-reader-text">%s</span></td>',
					$level,
					esc_attr( $name ),
					$h,
					( $h + 1 ) % 24,
					self::num( $v ),
					self::num( $v )
				);
			}
			$body .= '</tr>';
		}

		$legend = '<span>Färre</span>';
		for ( $i = 0; $i <= 6; $i++ ) {
			$legend .= sprintf( '<i class="xf-l%d"></i>', $i );
		}
		$legend .= '<span>Fler</span>';

		return sprintf(
			'<div class="xf-heat-wrap"><table class="xf-heat"><thead><tr>%s</tr></thead><tbody>%s</tbody></table></div><p class="xf-heat-legend" aria-hidden="true">%s</p><p class="xf-note">Klockslag enligt sajtens tidszon. Flest inskick på en timme: %s.</p>',
			$head,
			$body,
			$legend,
			self::num( $max )
		);
	}

	private static function styles(): string {
		return '<style>
		.xf-stats { --xf-accent: #2a78d6; --xf-accent-hover: #1c5cab; --xf-ink: #1d2327; --xf-muted: #646970; --xf-line: #e4e6e8; --xf-tint: #f3f5f6; max-width: 1400px; }
		.xf-stats .xf-stats-filter { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; margin: 14px 0 6px; }
		.xf-stats .xf-stats-filter label { font-weight: 600; }
		.xf-stats .xf-custom label { font-weight: 400; }
		.xf-stats .xf-stats-filter:has(#xf-stats-period option[value="custom"]:not(:checked)) .xf-custom { display: none; }
		.xf-stats .xf-stats-coverage { color: var(--xf-muted); margin: 6px 0 18px; }
		.xf-stats .xf-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 12px; }
		.xf-stats .xf-tile, .xf-stats .xf-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 14px 16px; }
		.xf-stats .xf-tile-label { color: var(--xf-muted); font-size: 13px; }
		.xf-stats .xf-tile-value { font-size: 30px; font-weight: 600; line-height: 1.25; margin: 4px 0; color: var(--xf-ink); font-variant-numeric: tabular-nums; }
		.xf-stats .xf-tile-sub { color: var(--xf-muted); font-size: 12px; }
		.xf-stats .xf-delta { font-weight: 600; }
		.xf-stats .xf-delta.xf-up { color: #007017; }
		.xf-stats .xf-delta.xf-down { color: #b32d2e; }
		.xf-stats .xf-card { margin-bottom: 12px; min-width: 0; }
		.xf-stats .xf-card h3 { margin: 0 0 10px; font-size: 14px; }
		.xf-stats .xf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap: 12px; }
		.xf-stats .xf-grid > .xf-card { margin-bottom: 0; }
		.xf-stats .xf-grid { margin-bottom: 12px; }
		.xf-stats .xf-grid-4 { grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); }
		.xf-stats .xf-section { margin: 24px 0 4px; font-size: 16px; }
		.xf-stats .xf-note, .xf-stats .xf-none { color: var(--xf-muted); font-size: 12px; margin: 6px 0 0; }
		.xf-stats .xf-section + .xf-note { margin: 0 0 10px; }
		.xf-stats .xf-list { width: 100%; border-collapse: collapse; table-layout: fixed; }
		.xf-stats .xf-list th { text-align: left; font-weight: 400; color: var(--xf-muted); font-size: 12px; padding: 0 0 6px; border-bottom: 1px solid var(--xf-line); }
		.xf-stats .xf-list td { padding: 6px 0; border-bottom: 1px solid var(--xf-line); vertical-align: top; }
		.xf-stats .xf-list tr:last-child td { border-bottom: 0; }
		.xf-stats .xf-list .num { text-align: right; width: 64px; font-variant-numeric: tabular-nums; }
		.xf-stats .xf-label { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 8px; }
		.xf-stats .xf-bar { display: block; height: 6px; margin: 4px 8px 0 0; }
		.xf-stats .xf-bar i { display: block; height: 100%; background: var(--xf-accent); border-radius: 0 3px 3px 0; }
		.xf-stats tr.xf-muted td { color: var(--xf-muted); }
		.xf-stats .xf-chart-wrap { overflow-x: auto; }
		.xf-stats .xf-chart { display: block; width: 100%; min-width: 640px; height: auto; margin-top: 4px; }
		.xf-stats .xf-gap { height: 16px; }
		.xf-stats .xf-axis { font-size: 12px; fill: var(--xf-muted); font-variant-numeric: tabular-nums; }
		.xf-stats .xf-grid-line { stroke: var(--xf-line); stroke-width: 1; }
		.xf-stats .xf-base-line { stroke: #c3c4c7; stroke-width: 1; }
		.xf-stats .xf-bar-mark { fill: var(--xf-accent); }
		.xf-stats .xf-hit { fill: transparent; }
		.xf-stats .xf-col:hover .xf-hit { fill: var(--xf-tint); }
		.xf-stats .xf-col:hover .xf-bar-mark { fill: var(--xf-accent-hover); }
		.xf-stats .xf-table { margin-top: 8px; }
		.xf-stats .xf-table summary { cursor: pointer; color: #2271b1; }
		.xf-stats .xf-table .xf-list { max-width: 420px; margin-top: 8px; }
		.xf-stats .xf-heat-wrap { overflow-x: auto; position: relative; }
		.xf-stats .xf-heat { border-collapse: separate; border-spacing: 2px; width: 100%; min-width: 620px; table-layout: fixed; }
		.xf-stats .xf-heat thead th:first-child { width: 36px; }
		.xf-stats .xf-heat thead th { overflow: visible; white-space: nowrap; }
		.xf-stats .xf-heat th { font-weight: 400; font-size: 11px; color: var(--xf-muted); text-align: left; padding: 0 4px 0 0; }
		.xf-stats .xf-heat td { height: 22px; border-radius: 3px; position: relative; }
		.xf-stats .xf-l0 { background: var(--xf-tint); }
		.xf-stats .xf-l1 { background: #cde2fb; } .xf-stats .xf-l2 { background: #9ec5f4; } .xf-stats .xf-l3 { background: #6da7ec; }
		.xf-stats .xf-l4 { background: #3987e5; } .xf-stats .xf-l5 { background: #256abf; } .xf-stats .xf-l6 { background: #184f95; }
		.xf-stats .xf-heat-legend { display: flex; align-items: center; gap: 3px; font-size: 11px; color: var(--xf-muted); margin: 8px 0 0; }
		.xf-stats .xf-heat-legend i { width: 16px; height: 10px; border-radius: 2px; display: inline-block; }
		.xf-stats .xf-heat-legend span { margin: 0 4px; }
		.xf-stats .xf-empty p { margin: 0; color: var(--xf-muted); }
		</style>';
	}
}

endif;
