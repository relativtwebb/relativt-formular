<?php
/**
 * Webhook per formulär (1.17.0).
 *
 * Skickar ett inskick som JSON till en adress som formuläret anger, t.ex. en
 * Zapier-zap. Av som standard: formulär som inte slår på det påverkas inte,
 * och utan något formulär med webhook registrerar klassen bara sina krokar.
 *
 * Ordningen är spara → mejl → webhook. Ett webhookfel stoppar aldrig mejlet
 * och ger aldrig besökaren ett fel:
 *
 *  - Det första försöket görs EFTER att svaret skickats till besökaren: i
 *    shutdown, efter fastcgi_finish_request() (PHP-FPM) eller
 *    litespeed_finish_request() (LiteSpeed). Finns ingen av dem och
 *    inskicket är sparat, görs försöket i stället av WP-Cron.
 *  - Misslyckas det (nätverksfel eller annan svarskod än 2xx) görs ett nytt
 *    försök efter fem minuter, via WP-Cron. Sedan är statusen misslyckad
 *    och inskicket kan skickas om för hand.
 *  - Status, svarskod, antal försök och tidpunkt sparas på inskicket.
 *  - Sparar formuläret inte inskick görs ett försök, och ett misslyckande
 *    loggas till PHP:s fellogg. Inget omförsök: då skulle inskickets
 *    personuppgifter behöva ligga i WP-Cron-listan i wp_options, där ingen
 *    gallring når dem.
 *
 * @package Relativt_Formular
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Relativt_Form_Webhook', false ) ) :

final class Relativt_Form_Webhook {

	/** WP-Cron-kroken för försök som inte kunde göras direkt, och för omförsöket. Argument: inskickets id. */
	public const CRON_HOOK = 'relativt_form_webhook';

	/** Antal automatiska försök per inskick: ett direkt och ett omförsök. */
	public const MAX_ATTEMPTS = 2;

	/** Sekunder till omförsöket. */
	public const RETRY_DELAY = 300;

	/** Högsta tillåtna timeout i sekunder, även efter filtret. */
	public const MAX_TIMEOUT = 10;

	/** Metanycklar på inskicket. */
	public const META_STATUS  = '_xf_webhook';
	public const META_PAYLOAD = '_xf_webhook_payload';

	/** Status → text i wp-admin. */
	public const STATUSES = [
		'sent'    => 'Skickad',
		'pending' => 'Väntar',
		'failed'  => 'Misslyckades',
	];

	/** Huvuden som inte får sättas via inställningen. */
	private const RESERVED_HEADERS = [ 'content-type', 'content-length', 'host', 'user-agent', 'connection', 'transfer-encoding' ];

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled' ] );

		add_filter( 'acf/validate_value/key=field_xf_webhook_url', [ $this, 'validate_url' ], 10, 4 );
		add_filter( 'acf/validate_value/key=field_xf_webhook_header_name', [ $this, 'validate_header_name' ], 10, 2 );
		add_filter( 'acf/prepare_field/key=field_xf_webhook_test', [ $this, 'prepare_test_field' ] );

		add_action( 'admin_post_relativt_form_webhook_test', [ $this, 'handle_test' ] );
		add_action( 'admin_post_relativt_form_webhook_resend', [ $this, 'handle_resend' ] );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		add_action( 'admin_init', [ $this, 'privacy_policy' ] );
	}

	/* ---------------------------------------------------------------------
	 * Inställningar
	 * ------------------------------------------------------------------ */

	/** Är webhooken på för formuläret, med en giltig adress? */
	public function enabled( int $form_id ): bool {
		return $this->field( 'xf_webhook', $form_id ) && '' !== $this->url( $form_id );
	}

	/** Adressen, eller '' om den saknas eller inte är https. */
	public function url( int $form_id ): string {
		$url = trim( (string) $this->field( 'xf_webhook_url', $form_id ) );
		return self::valid_url( $url ) ? $url : '';
	}

	/** Bara https, med värdnamn. Interna adresser stoppas sedan av wp_safe_remote_post(). */
	public static function valid_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	/** Ett giltigt namn på ett eget huvud: bokstäver, siffror och bindestreck, och inget reserverat. */
	public static function valid_header_name( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $name ) && ! in_array( strtolower( $name ), self::RESERVED_HEADERS, true );
	}

	/** @return mixed */
	private function field( string $name, int $form_id ) {
		return function_exists( 'get_field' ) ? get_field( $name, $form_id ) : null;
	}

	/** ACF: adressen måste vara https när webhooken är på. */
	public function validate_url( $valid, $value, $field = null, $input = null ) {
		if ( true !== $valid ) {
			return $valid;
		}
		$value = trim( (string) $value );
		if ( '' !== $value && ! self::valid_url( $value ) ) {
			return 'Adressen måste börja med https:// och får inte innehålla användarnamn eller lösenord.';
		}
		return $valid;
	}

	/** ACF: namnet på det egna huvudet. */
	public function validate_header_name( $valid, $value ) {
		if ( true !== $valid ) {
			return $valid;
		}
		$value = trim( (string) $value );
		if ( '' !== $value && ! self::valid_header_name( $value ) ) {
			return 'Bara bokstäver, siffror och bindestreck, t.ex. X-Webhook-Secret. Content-Type, Host, User-Agent och liknande går inte att sätta.';
		}
		return $valid;
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/**
	 * Datan som skickas. Fälten är formulärets validerade värden – dolda
	 * fält och överhoppade steg är redan borta, precis som i mejlet – plus
	 * rader som relativt_form_values lagt till.
	 *
	 * @param array<int,array<string,mixed>> $values
	 * @param array<string,mixed>            $meta
	 * @return array<string,mixed>|null null om filtret tog bort datan.
	 */
	public function payload( int $form_id, array $values, array $meta, int $entry_id ): ?array {
		$fields  = [];
		$display = [];
		foreach ( $values as $v ) {
			$key = (string) ( $v['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$raw = (string) ( $v['raw'] ?? '' );
			// Flerval som lista. Värdena sparas ihopskrivna med ", ".
			$fields[ $key ]  = 'checkboxes' === ( $v['type'] ?? '' ) ? ( '' === $raw ? [] : explode( ', ', $raw ) ) : $raw;
			$display[ $key ] = (string) ( $v['value'] ?? '' );
		}

		$out_meta = [
			'page'       => (string) ( $meta['page'] ?? '' ),
			'post_id'    => (int) ( $meta['post_id'] ?? 0 ),
			'post_title' => (string) ( $meta['post_title'] ?? '' ),
			'landing'    => (string) ( $meta['landing'] ?? '' ),
			'referrer'   => (string) ( $meta['referrer'] ?? '' ),
			'consent'    => (string) ( $meta['consent'] ?? '' ),
			'utm'        => is_array( $meta['utm'] ?? null ) ? $meta['utm'] : [],
		];
		// IP-adressen bara om formuläret sparar den.
		if ( '' !== (string) ( $meta['ip'] ?? '' ) ) {
			$out_meta['ip'] = (string) $meta['ip'];
		}

		$data = [
			'form_id'        => $form_id,
			'form_title'     => html_entity_decode( wp_strip_all_tags( (string) get_the_title( $form_id ) ), ENT_QUOTES, 'UTF-8' ),
			'submission_id'  => $entry_id > 0 ? $entry_id : null,
			'submitted_at'   => wp_date( 'c' ),
			'fields'         => $fields,
			'fields_display' => $display,
			'meta'           => $out_meta,
		];

		/**
		 * Datan som skickas till webhooken (1.17.0). Lägg till egna nycklar,
		 * t.ex. uppgifter som Zapier behöver som egna fält. Returnera null för
		 * att inte skicka något alls för just det här inskicket.
		 *
		 * @param array $data    Datan. fields, fields_display och meta.utm är vanliga arrayer här.
		 * @param int   $form_id Formulärets id.
		 * @param array $values  Inskickets värden: key, label, type, raw, value.
		 * @param array $meta    Inskickets metadata.
		 */
		$data = apply_filters( 'relativt_form_webhook_payload', $data, $form_id, $values, $meta );
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Efter filtret: de här är alltid JSON-objekt, även när de är tomma.
		foreach ( [ 'fields', 'fields_display' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ] = (object) $data[ $key ];
			}
		}
		if ( isset( $data['meta']['utm'] ) && is_array( $data['meta']['utm'] ) ) {
			$data['meta']['utm'] = (object) $data['meta']['utm'];
		}

		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Sändning
	 * ------------------------------------------------------------------ */

	/**
	 * Anropas av motorn efter ett lyckat inskick, när det redan sparats och
	 * mailats. Gör ingenting om webhooken inte är på.
	 *
	 * @param array<int,array<string,mixed>> $values
	 * @param array<string,mixed>            $meta
	 */
	public function queue( int $form_id, array $values, array $meta, int $entry_id ): void {
		if ( ! $this->enabled( $form_id ) ) {
			return;
		}

		$data = $this->payload( $form_id, $values, $meta, $entry_id );
		if ( null === $data ) {
			return;
		}

		/*
		 * Datan görs till JSON en gång. Det är den texten som skickas, och
		 * som sparas på inskicket för omförsöket och för att skicka om – så
		 * att varje försök skickar exakt samma sak.
		 */
		$body = self::encode( $data );

		if ( $entry_id > 0 ) {
			update_post_meta( $entry_id, self::META_PAYLOAD, wp_slash( $body ) );
			$this->set_status( $entry_id, [ 'status' => 'pending', 'attempts' => 0, 'code' => 0, 'time' => '', 'error' => '' ] );
		}

		if ( self::can_finish_request() || $entry_id <= 0 ) {
			/*
			 * Efter svaret. Prioritet 100: efter att WordPress tömt sina
			 * utdatabuffertar (wp_ob_end_flush_all, prioritet 1). Utan
			 * finish-funktion och utan sparat inskick finns inget annat sätt
			 * än att skicka här, efter svaret men innan anslutningen stängs –
			 * se docs/webhook.md.
			 */
			add_action(
				'shutdown',
				function () use ( $form_id, $body, $entry_id ): void {
					self::finish_request();
					$this->deliver( $form_id, $body, $entry_id );
				},
				100
			);
			return;
		}

		// Ingen finish-funktion: WP-Cron gör försöket, vid nästa sidvisning.
		wp_schedule_single_event( time(), self::CRON_HOOK, [ $entry_id ] );
	}

	/** Kan svaret skickas klart till besökaren innan PHP slutar köra? */
	public static function can_finish_request(): bool {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	private static function finish_request(): void {
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Ett försök, med status och omförsök. Utan sparat inskick: ett försök
	 * och en rad i felloggen om det misslyckas.
	 */
	public function deliver( int $form_id, string $body, int $entry_id ): void {
		$result = $this->send( $form_id, $body );

		if ( $entry_id <= 0 ) {
			if ( ! $result['ok'] ) {
				error_log( sprintf( 'Relativt Formulär: webhooken för formulär %d misslyckades (%s). Inskicket sparas inte, så inget omförsök görs.', $form_id, $result['error'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			return;
		}

		$status   = $this->status( $entry_id );
		$attempts = (int) ( $status['attempts'] ?? 0 ) + 1;

		if ( $result['ok'] ) {
			$state = 'sent';
		} elseif ( $attempts < self::MAX_ATTEMPTS ) {
			$state = 'pending';
			wp_schedule_single_event( time() + self::RETRY_DELAY, self::CRON_HOOK, [ $entry_id ] );
		} else {
			$state = 'failed';
		}

		$this->set_status( $entry_id, [
			'status'   => $state,
			'attempts' => $attempts,
			'code'     => $result['code'],
			'time'     => current_time( 'Y-m-d H:i:s' ),
			'error'    => $result['error'],
		] );
	}

	/**
	 * WP-Cron: försöket som inte kunde göras direkt, och omförsöket. Gör
	 * ingenting för ett inskick som redan skickats eller som raderats
	 * (gallring, integritetsverktyget) – då finns ingen data kvar.
	 */
	public function run_scheduled( $entry_id ): void {
		$entry_id = (int) $entry_id;
		$body     = get_post_meta( $entry_id, self::META_PAYLOAD, true );
		$form_id  = (int) get_post_meta( $entry_id, '_xf_form_id', true );
		$status   = $this->status( $entry_id );

		if ( ! is_string( $body ) || '' === $body || ! $form_id || 'pending' !== ( $status['status'] ?? '' ) ) {
			return;
		}

		// Har webhooken stängts av sedan inskicket skickas ingenting mer.
		if ( ! $this->enabled( $form_id ) ) {
			$this->set_status( $entry_id, [ 'status' => 'failed', 'error' => 'Webhooken är avstängd för formuläret.' ] + $status );
			return;
		}

		$this->deliver( $form_id, $body, $entry_id );
	}

	/** JSON-texten som skickas. */
	public static function encode( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Själva anropet. Bara https, och via wp_safe_remote_post(), som vägrar
	 * adresser på det interna nätet. Ingen omdirigering följs.
	 *
	 * @return array{ok:bool,code:int,error:string}
	 */
	public function send( int $form_id, string $body ): array {
		$url = $this->url( $form_id );
		if ( '' === $url ) {
			return [ 'ok' => false, 'code' => 0, 'error' => 'Adressen saknas eller är inte https.' ];
		}

		$headers = [
			'Content-Type' => 'application/json; charset=utf-8',
			'User-Agent'   => 'Relativt-Formular/' . ( defined( 'RELATIVT_FORM_VERSION' ) ? RELATIVT_FORM_VERSION : '' ) . '; ' . home_url( '/' ),
		];
		$name = trim( (string) $this->field( 'xf_webhook_header_name', $form_id ) );
		if ( '' !== $name && self::valid_header_name( $name ) ) {
			$headers[ $name ] = (string) $this->field( 'xf_webhook_header_value', $form_id );
		}

		$args = [
			'timeout'     => self::MAX_TIMEOUT,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
			'data_format' => 'body',
		];

		/**
		 * Argumenten till wp_safe_remote_post() (1.17.0): timeout, headers m.m.
		 * Timeouten hålls ändå inom 1–10 sekunder.
		 *
		 * @param array $args    Argumenten. body är JSON-texten; ändra den inte här – använd relativt_form_webhook_payload.
		 * @param int   $form_id Formulärets id.
		 * @param array $data    Datan som skickas, avkodad.
		 */
		$args = (array) apply_filters( 'relativt_form_webhook_args', $args, $form_id, (array) json_decode( $body, true ) );
		$args['timeout'] = max( 1, min( self::MAX_TIMEOUT, (int) ( $args['timeout'] ?? self::MAX_TIMEOUT ) ) );

		$response = wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'code' => 0, 'error' => mb_substr( (string) $response->get_error_message(), 0, 300 ) ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;

		return [ 'ok' => $ok, 'code' => $code, 'error' => $ok ? '' : 'Svarskod ' . $code . '.' ];
	}

	/* ---------------------------------------------------------------------
	 * Status
	 * ------------------------------------------------------------------ */

	/** @return array{status?:string,attempts?:int,code?:int,time?:string,error?:string} */
	public function status( int $entry_id ): array {
		$status = get_post_meta( $entry_id, self::META_STATUS, true );
		return is_array( $status ) ? $status : [];
	}

	private function set_status( int $entry_id, array $status ): void {
		update_post_meta( $entry_id, self::META_STATUS, $status );
	}

	/** En rad för inskickslistan, eller '' för inskick utan webhook. */
	public function list_label( int $entry_id ): string {
		$status = $this->status( $entry_id );
		$state  = (string) ( $status['status'] ?? '' );
		if ( ! isset( self::STATUSES[ $state ] ) ) {
			return '';
		}
		$color = [ 'sent' => '#008a20', 'pending' => '#996800', 'failed' => '#b32d2e' ][ $state ];
		return '<br><span style="color:' . $color . '">Webhook: ' . esc_html( self::STATUSES[ $state ] ) . '</span>';
	}

	/** Avsnittet i inskicksvyn, eller '' för inskick utan webhook. */
	public function entry_box( int $entry_id ): string {
		$status = $this->status( $entry_id );
		$state  = (string) ( $status['status'] ?? '' );
		if ( ! isset( self::STATUSES[ $state ] ) ) {
			return '';
		}

		$rows = [
			'Status'       => self::STATUSES[ $state ],
			'Senaste försök' => (string) ( $status['time'] ?? '' ),
			'Svarskod'     => ! empty( $status['code'] ) ? (string) $status['code'] : '',
			'Antal försök' => (string) (int) ( $status['attempts'] ?? 0 ),
			'Fel'          => (string) ( $status['error'] ?? '' ),
		];

		$html = '<h4>Webhook</h4><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$html .= sprintf( '<tr><th style="width:200px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		$html .= '</tbody></table>';

		$body = get_post_meta( $entry_id, self::META_PAYLOAD, true );
		if ( is_string( $body ) && '' !== $body ) {
			$url   = wp_nonce_url( admin_url( 'admin-post.php?action=relativt_form_webhook_resend&entry=' . $entry_id ), 'relativt_form_webhook_resend_' . $entry_id );
			$label = 'sent' === $state ? 'Skicka igen' : 'Skicka om';
			$html .= '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Skicka om och testinskick (wp-admin)
	 * ------------------------------------------------------------------ */

	/** Skicka om ett inskick för hand. Ett försök, direkt, utan automatiskt omförsök. */
	public function handle_resend(): void {
		$entry_id = (int) ( $_GET['entry'] ?? 0 );
		if ( ! current_user_can( 'edit_pages' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'relativt_form_webhook_resend_' . $entry_id ) ) {
			wp_die( 'Åtkomst nekad.' );
		}

		$body    = get_post_meta( $entry_id, self::META_PAYLOAD, true );
		$form_id = (int) get_post_meta( $entry_id, '_xf_form_id', true );
		$result  = is_string( $body ) && '' !== $body && $form_id
			? $this->send( $form_id, $body )
			: [ 'ok' => false, 'code' => 0, 'error' => 'Inskicket saknar sparad webhookdata.' ];

		$status = $this->status( $entry_id );
		$this->set_status( $entry_id, [
			'status'   => $result['ok'] ? 'sent' : 'failed',
			'attempts' => (int) ( $status['attempts'] ?? 0 ) + 1,
			'code'     => $result['code'],
			'time'     => current_time( 'Y-m-d H:i:s' ),
			'error'    => $result['error'],
		] );

		$this->redirect( admin_url( 'post.php?post=' . $entry_id . '&action=edit' ), $result );
	}

	/**
	 * Testinskick med exempeldata och formulärets riktiga fältnycklar, så att
	 * en zap kan sättas upp innan sajten är i drift. Skickas till den
	 * SPARADE adressen. Datan får nyckeln test: true och går genom samma
	 * filter som ett riktigt inskick.
	 */
	public function handle_test(): void {
		$form_id = (int) ( $_GET['form'] ?? 0 );
		if ( ! current_user_can( 'edit_pages' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'relativt_form_webhook_test_' . $form_id ) ) {
			wp_die( 'Åtkomst nekad.' );
		}

		$data = $this->sample( $form_id );
		$result = null === $data
			? [ 'ok' => false, 'code' => 0, 'error' => 'Filtret relativt_form_webhook_payload returnerade ingen data.' ]
			: $this->send( $form_id, self::encode( $data ) );

		$this->redirect( admin_url( 'post.php?post=' . $form_id . '&action=edit' ), $result );
	}

	/**
	 * Exempeldatan för testinskicket.
	 *
	 * @return array<string,mixed>|null
	 */
	public function sample( int $form_id ): ?array {
		$values = [];
		foreach ( Relativt_Form::instance()->get_fields( $form_id ) as $f ) {
			if ( '' === $f['key'] || in_array( $f['type'], [ 'heading', 'step', 'confirm' ], true ) ) {
				continue;
			}
			$first = $f['choices'] ? (string) array_key_first( $f['choices'] ) : '';
			$raw   = [
				'email'      => 'anna@exempel.se',
				'tel'        => '0701234567',
				'url'        => 'https://exempel.se',
				'number'     => '1',
				'date'       => current_time( 'Y-m-d' ),
				'checkbox'   => '1',
				'select'     => '' !== $first ? $first : 'exempel',
				'buttons'    => '' !== $first ? $first : 'exempel',
				'radio'      => '' !== $first ? $first : 'exempel',
				'checkboxes' => '' !== $first ? $first : 'exempel',
				'hidden'     => '' !== $f['default'] ? $f['default'] : 'exempel',
			][ $f['type'] ] ?? 'Exempel';
			$value = 'checkbox' === $f['type'] ? 'Ja' : ( $f['choices'][ $raw ] ?? $raw );
			$values[] = [ 'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'value' => $value, 'raw' => $raw ];
		}

		$meta = [
			'page'     => home_url( '/exempel/' ),
			'landing'  => home_url( '/exempel/?utm_source=exempel' ),
			'referrer' => 'https://www.google.com/',
			'consent'  => '',
			'utm'      => [ 'utm_source' => 'exempel', 'utm_medium' => 'exempel', 'utm_campaign' => 'exempel' ],
		];

		$data = $this->payload( $form_id, $values, $meta, 0 );
		if ( null !== $data ) {
			$data['test'] = true;
		}
		return $data;
	}

	/** Tillbaka till skärmen med resultatet i adressen, för admin_notice(). */
	private function redirect( string $url, array $result ): void {
		$arg = $result['ok'] ? 'ok-' . $result['code'] : ( $result['code'] ? 'code-' . $result['code'] : 'error' );
		wp_safe_redirect( add_query_arg( 'xf_webhook', $arg, $url ) );
		exit;
	}

	/** Resultatet av ett testinskick eller en omsändning. */
	public function admin_notice(): void {
		$arg = sanitize_key( (string) ( $_GET['xf_webhook'] ?? '' ) );
		if ( '' === $arg || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		if ( str_starts_with( $arg, 'ok-' ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p><strong>Webhook:</strong> skickad, svarskod %s.</p></div>', esc_html( substr( $arg, 3 ) ) );
			return;
		}
		$detail = str_starts_with( $arg, 'code-' ) ? 'svarskod ' . substr( $arg, 5 ) : 'ingen kontakt med adressen, eller adressen saknas';
		printf( '<div class="notice notice-error"><p><strong>Webhook:</strong> misslyckades (%s). Kontrollera adressen och spara formuläret.</p></div>', esc_html( $detail ) );
	}

	/** ACF: knappen för testinskicket, med formulärets id. Döljs innan formuläret sparats. */
	public function prepare_test_field( $field ) {
		$form_id = (int) ( $_GET['post'] ?? 0 );
		if ( ! $form_id || Relativt_Form::CPT_FORM !== get_post_type( $form_id ) ) {
			$field['message'] = '<p>Spara formuläret först. Sedan kan du skicka ett testinskick med exempeldata och formulärets fältnycklar.</p>';
			return $field;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=relativt_form_webhook_test&form=' . $form_id ), 'relativt_form_webhook_test_' . $form_id );
		$field['message'] = '<p>Skickar exempeldata med formulärets fältnycklar till den <strong>sparade</strong> adressen, med nyckeln <code>"test": true</code>. Spara formuläret först om du ändrat något.</p>'
			. '<p><a class="button" href="' . esc_url( $url ) . '">Skicka testinskick</a></p>';
		return $field;
	}

	/* ---------------------------------------------------------------------
	 * Integritet
	 * ------------------------------------------------------------------ */

	/**
	 * Ett avsnitt i WordPress förslag till integritetspolicy – men bara när
	 * något formulär har webhooken på. Sajter utan webhook ser ingen skillnad.
	 */
	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$forms = get_posts( [
			'post_type'   => Relativt_Form::CPT_FORM,
			'post_status' => 'any',
			'numberposts' => 20,
			'fields'      => 'ids',
			'meta_key'    => 'xf_webhook',
			'meta_value'  => '1',
		] );
		if ( ! $forms ) {
			return;
		}

		$names = implode( ', ', array_map( static fn( $id ) => '”' . get_the_title( $id ) . '”', $forms ) );
		wp_add_privacy_policy_content(
			'Relativt Formulär',
			wp_kses_post(
				'<p>Inskick från formulären ' . esc_html( $names ) . ' skickas, utöver e-postnotisen, vidare till en extern tjänst via en webhook (en adress som anges i formulärets inställningar, t.ex. en automatiseringstjänst som Zapier). Det som skickas är formulärets ifyllda fält och metadata om inskicket: sidan det skickades från, sidtitel och post-ID om formuläret kopplar inskicket till sidan, landningssida, hänvisande sida, samtycke, kampanjparametrar och – om formuläret sparar den – IP-adressen.</p>'
				. '<p>Ange vilken tjänst som tar emot uppgifterna, var den behandlar dem och hur länge de sparas där. Uppgifter som redan skickats vidare raderas inte av WordPress integritetsverktyg.</p>'
			)
		);
	}
}

endif;
