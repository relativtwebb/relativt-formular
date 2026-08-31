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
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Hämtning
	 * ------------------------------------------------------------------ */

	/** @return array{version:string,zip:string,notes:string,published:string}|null */
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
		$zip = (string) ( $body['zipball_url'] ?? '' );

		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && str_ends_with( (string) $asset['name'], '.zip' ) ) {
				$zip = (string) $asset['browser_download_url'];
				break;
			}
		}

		$release = [
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'zip'       => $zip,
			'notes'     => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		];

		set_transient( $key, $release, self::CACHE_TTL );

		return $release;
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
