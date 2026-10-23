<?php
/**
 * Uppdateringar från ett publikt GitHub-repo.
 *
 * Kundsajterna får en vanlig uppdateringsknapp under Insticksprogram så fort
 * en ny release taggas. Ingen uppdateringsserver att driva, inget extra
 * plugin att installera, inga tokens att hålla reda på – repot är publikt.
 *
 * Uppdateraren är avsiktligt tyst. Går GitHub inte att nå, är repot borta
 * eller ändrar API:et form, händer ingenting alls: sajten fortsätter köra den
 * version den har. En uppdateringskontroll får aldrig vara det som fäller en
 * kundsajt.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Relativt_Form_Updater', false ) ) :

final class Relativt_Form_Updater {

	/** GitHub tillåter 60 anrop i timmen per IP. En halv dag räcker gott. */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Värdar ett paket får hämtas från. Adressen kommer ur ett API-svar, och
	 * den som kan påverka svaret ska inte kunna peka sajterna mot en annan
	 * server. Sökvägen måste dessutom börja med det egna repots.
	 */
	private const TRUSTED_HOSTS = [ 'github.com', 'api.github.com' ];

	private string $file;
	private string $basename;
	private string $slug;
	private string $repo;
	private string $version;
	private string $requires_php;

	/**
	 * $requires_php är minsta PHP-version som en NY release får installeras
	 * på. Skickas med till WordPress uppdaterare, som då vägrar erbjuda
	 * uppdateringen på en server under kravet – i stället för att installera
	 * en version som lägger sig inaktiv. Håll den i takt med version_compare-
	 * spärren i relativt-formular.php.
	 */
	public function __construct( string $file, string $repo, string $version, string $requires_php = '' ) {
		$this->file         = $file;
		$this->basename     = plugin_basename( $file );
		$this->slug         = dirname( $this->basename );
		$this->repo         = trim( $repo, '/ ' );
		$this->version      = $version;
		$this->requires_php = $requires_php;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'verify_package' ], 10, 4 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Hämtning
	 * ------------------------------------------------------------------ */

	/** @return array{version:string,zip:string,sha256:string,notes:string,published:string}|null */
	private function release(): ?array {
		$key    = 'relativt_form_release_' . md5( $this->repo );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			[
				'timeout' => 8,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'relativt-formular/' . $this->version,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Tomt värde cachas också, annars anropas GitHub vid varje sidladdning.
			set_transient( $key, [], self::CACHE_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( $key, [], self::CACHE_TTL );
			return null;
		}

		/*
		 * En bifogad zip föredras framför GitHubs zipball. Zipballen packar
		 * upp till "repo-1.0.0/" i stället för "relativt-formular/", vilket
		 * WordPress annars installerar som ett nytt, separat plugin.
		 * fix_folder_name() nedan räddar även det fallet, men en riktig
		 * release-zip är renare.
		 */
		$zip    = '';
		$digest = '';

		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && str_ends_with( (string) $asset['name'], '.zip' ) ) {
				$zip = (string) $asset['browser_download_url'];
				// GitHub räknar själv ut en kontrollsumma för varje tillgång.
				if ( preg_match( '/^sha256:([a-f0-9]{64})$/i', (string) ( $asset['digest'] ?? '' ), $m ) ) {
					$digest = strtolower( $m[1] );
				}
				break;
			}
		}

		$notes = (string) ( $body['body'] ?? '' );
		$sha   = $this->checksum( $notes, $digest );

		/*
		 * Utan bifogad zip, godkänd värd och kontrollsumma erbjuds ingen
		 * uppdatering alls. Det är tyst, som resten av uppdateraren, och
		 * hellre det än att installera kod som inte går att kontrollera.
		 * GitHubs zipball (zipball_url) används därför aldrig: den byggs
		 * om vid varje hämtning och har ingen kontrollsumma att jämföra med.
		 */
		if ( '' === $sha || ! $this->trusted_package( $zip ) ) {
			$zip = '';
			$sha = '';
		}

		$release = [
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'zip'       => $zip,
			'sha256'    => $sha,
			'notes'     => $notes,
			'published' => (string) ( $body['published_at'] ?? '' ),
		];

		set_transient( $key, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Kontrollsumman för release-zippen: raden "SHA256: <64 tecken>" i
	 * release-texten (skrivs dit av release.yml) och GitHubs egen summa för
	 * tillgången. Finns båda måste de vara lika, annars är svaret tomt och
	 * ingenting installeras.
	 */
	private function checksum( string $notes, string $digest ): string {
		$noted = '';
		if ( preg_match( '/^\s*SHA-?256:\s*`?([a-f0-9]{64})`?\s*$/mi', $notes, $m ) ) {
			$noted = strtolower( $m[1] );
		}

		if ( '' !== $noted && '' !== $digest && $noted !== $digest ) {
			return '';
		}

		return '' !== $noted ? $noted : $digest;
	}

	/** Är adressen en https-adress hos GitHub, under det egna repots sökväg? */
	private function trusted_package( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $host, self::TRUSTED_HOSTS, true ) ) {
			return false;
		}

		$path   = strtolower( (string) ( $parts['path'] ?? '' ) );
		$prefix = strtolower( 'github.com' === $host ? '/' . $this->repo . '/' : '/repos/' . $this->repo . '/' );

		return str_starts_with( $path, $prefix ) && ! str_contains( $path, '..' );
	}

	/**
	 * Hämtar paketet själv och jämför dess SHA-256 med den kontrollerade
	 * releasen innan WordPress packar upp det. Ett paket som inte stämmer
	 * raderas och uppdateringen avbryts med ett fel.
	 *
	 * Bara pluginets egna uppdateringar berörs. Returnerar sökvägen till den
	 * nedladdade filen, vilket är hur WordPress låter ett filter ta över
	 * nedladdningen.
	 *
	 * @param false|string|WP_Error $reply
	 * @param string                $package
	 * @param object                $upgrader
	 * @param array                 $hook_extra
	 * @return false|string|WP_Error
	 */
	public function verify_package( $reply, $package, $upgrader, $hook_extra = [] ) {
		if ( false !== $reply || ( is_array( $hook_extra ) ? ( $hook_extra['plugin'] ?? '' ) : '' ) !== $this->basename ) {
			return $reply;
		}

		$release = $this->release();

		if ( ! $release || '' === ( $release['sha256'] ?? '' ) || (string) $package !== $release['zip'] || ! $this->trusted_package( (string) $package ) ) {
			return new WP_Error( 'relativt_form_package', 'Uppdateringen avbröts: paketet går inte att kontrollera mot den senaste releasen. Sök efter uppdateringar igen och försök på nytt.' );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( (string) $package, 300 );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = hash_file( 'sha256', (string) $file );

		if ( ! is_string( $actual ) || ! hash_equals( $release['sha256'], $actual ) ) {
			@unlink( (string) $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'relativt_form_checksum', 'Uppdateringen avbröts: paketets kontrollsumma stämmer inte med releasen.' );
		}

		return (string) $file;
	}

	/* ---------------------------------------------------------------------
	 * WordPress-kopplingarna
	 * ------------------------------------------------------------------ */

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->release();

		if ( ! $release || '' === $release['zip'] || ! version_compare( $release['version'], $this->version, '>' ) ) {
			return $transient;
		}

		$update = [
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'package'     => $release['zip'],
			'url'         => 'https://github.com/' . $this->repo,
			'tested'      => get_bloginfo( 'version' ),
		];

		if ( '' !== $this->requires_php ) {
			$update['requires_php'] = $this->requires_php;
		}

		$transient->response[ $this->basename ] = (object) $update;

		return $transient;
	}

	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}

		$release = $this->release();

		if ( ! $release ) {
			return $result;
		}

		$details = [
			'name'          => 'Relativt Formulär',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://relativt.se">Relativt</a>',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => $release['zip'],
			'last_updated'  => $release['published'],
			'sections'      => [
				'changelog' => wpautop( wp_kses_post( $release['notes'] ) ),
			],
		];

		if ( '' !== $this->requires_php ) {
			$details['requires_php'] = $this->requires_php;
		}

		return (object) $details;
	}

	/**
	 * Döper om den uppackade mappen till pluginets slug.
	 *
	 * Utan det här installeras en GitHub-zipball som ett nytt plugin i mappen
	 * "relativt-formular-1.0.1", och sajten får plötsligt två kopior av
	 * motorn – varav den gamla fortfarande är den aktiva.
	 */
	public function fix_folder_name( $source, $remote_source, $upgrader, $args = [] ) {
		if ( ( $args['plugin'] ?? '' ) !== $this->basename ) {
			return $source;
		}

		global $wp_filesystem;

		$desired = trailingslashit( $remote_source ) . $this->slug;

		if ( ! $wp_filesystem || trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
			return new WP_Error( 'relativt_form_rename', 'Kunde inte döpa om den nedladdade mappen.' );
		}

		return trailingslashit( $desired );
	}

	public function flush_cache( $upgrader, $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_transient( 'relativt_form_release_' . md5( $this->repo ) );
		}
	}
}

endif;
