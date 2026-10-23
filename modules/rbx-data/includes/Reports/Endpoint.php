<?php
/**
 * Public report endpoint (CONT-46, ENG-26): REST POST /rbx/v1/report and the no-JS twin
 * admin-post.php?action=rbx_report. No auth or nonce (pages are cached); honeypot, 3 s minimum
 * fill time, 5 per hour per sha256(IP + daily salt), PII rejection. The IP is never stored.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Reports;

use RBX\Data\Config\Loader;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Validation is shared by both entry points.
 */
final class Endpoint {

	public const MAX_TEXT = 500;
	public const PER_HOUR = 5;

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_nopriv_rbx_report', array( self::class, 'post' ) );
		add_action( 'admin_post_rbx_report', array( self::class, 'post' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'rbx/v1',
			'/report',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest' ),
				'args'                => array(
					'kind' => array(
						'type'     => 'string',
						'enum'     => Queue::KINDS,
						'required' => true,
					),
					'text' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
				),
			)
		);
	}

	public static function rest( \WP_REST_Request $req ): \WP_REST_Response {
		$res = self::handle( (array) $req->get_params() );
		if ( is_wp_error( $res ) ) {
			$code = (int) ( $res->get_error_data()['status'] ?? 400 );
			return new \WP_REST_Response( array( 'message' => $res->get_error_message() ), $code );
		}
		return new \WP_REST_Response( array( 'message' => self::thanks() ), 201 );
	}

	/** No-JS form post: redirects back to /report/ with a status flag. */
	public static function post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form on cached pages (CONT-46); protected by honeypot, timing and rate limit.
		$res  = self::handle( wp_unslash( $_POST ) );
		$back = home_url( '/report/' );
		if ( is_wp_error( $res ) ) {
			$back = add_query_arg( 'rbx_error', rawurlencode( $res->get_error_code() ), $back );
		} else {
			$back = add_query_arg( 'rbx_sent', '1', $back );
		}
		wp_safe_redirect( $back, 303 );
		exit;
	}

	public static function thanks(): string {
		return __( 'Thanks. We check reports against the game before changing anything.', 'rbx-data' );
	}

	/** @return array<string, string> code => message for the no-JS page. */
	public static function messages(): array {
		return array(
			'pii'     => __( 'Please remove personal details.', 'rbx-data' ),
			'empty'   => __( 'Tell us what you saw in the game.', 'rbx-data' ),
			'too_long'=> __( 'Please keep it under 500 characters.', 'rbx-data' ),
			'rate'    => __( 'Too many reports from this connection. Please try again later.', 'rbx-data' ),
			'spam'    => __( 'Could not send. Please try again.', 'rbx-data' ),
		);
	}

	/**
	 * @param array<string, mixed> $p Raw params.
	 * @return true|\WP_Error
	 */
	public static function handle( array $p ): bool|\WP_Error {
		$msgs = self::messages();
		// Honeypot field "website" must stay empty; elapsed time ≥ 3 s from the form's render stamp.
		if ( '' !== trim( (string) ( $p['website'] ?? '' ) ) ) {
			return new \WP_Error( 'spam', $msgs['spam'], array( 'status' => 400 ) );
		}
		$t0 = (int) ( $p['t'] ?? 0 );
		if ( $t0 <= 0 || time() - (int) floor( $t0 / 1000 ) < 3 ) {
			return new \WP_Error( 'spam', $msgs['spam'], array( 'status' => 400 ) );
		}
		$text = trim( sanitize_textarea_field( (string) ( $p['text'] ?? '' ) ) );
		if ( '' === $text ) {
			return new \WP_Error( 'empty', $msgs['empty'], array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			return new \WP_Error( 'too_long', $msgs['too_long'], array( 'status' => 400 ) );
		}
		if ( self::has_pii( $text ) ) {
			return new \WP_Error( 'pii', $msgs['pii'], array( 'status' => 400 ) );
		}
		if ( ! self::rate_ok() ) {
			return new \WP_Error( 'rate', $msgs['rate'], array( 'status' => 429 ) );
		}
		$game   = Loader::game( sanitize_key( (string) ( $p['game'] ?? '' ) ) );
		$kind   = in_array( $p['kind'] ?? '', Queue::KINDS, true ) ? (string) $p['kind'] : 'other';
		$rid    = (string) ( $p['record_id'] ?? '' );
		$rid    = preg_match( '/^[A-Z][A-Z0-9]{1,4}-[A-Z]{3}-\d{4,6}$/', $rid ) ? $rid : '';
		$upd    = (string) ( $p['update_id'] ?? '' );
		$upd    = preg_match( '/^[A-Z][A-Z0-9]{1,4}-UPD-\d{4,6}$/', $upd ) ? $upd : '';
		$page   = esc_url_raw( (string) ( $p['page_url'] ?? '' ) );
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$page   = (string) wp_parse_url( $page, PHP_URL_HOST ) === $host ? substr( $page, 0, 255 ) : '';
		try {
			Queue::add(
				array(
					'game'            => $game ? $game->code() : '',
					'record_id'       => $rid,
					'field'           => substr( sanitize_key( (string) ( $p['field'] ?? '' ) ), 0, 64 ),
					'page_url'        => $page,
					'kind'            => $kind,
					'text'            => $text,
					'update_id'       => $upd,
					'dataset_version' => substr( sanitize_text_field( (string) ( $p['dataset_version'] ?? '' ) ), 0, 40 ),
					'shown_value'     => substr( sanitize_text_field( (string) ( $p['value'] ?? '' ) ), 0, 64 ),
				)
			);
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'report', $e->getMessage() );
			return new \WP_Error( 'spam', $msgs['spam'], array( 'status' => 500 ) );
		}
		do_action( 'rbx_data_report_added', $game, $rid );
		return true;
	}

	/** Email, phone number, or Roblox profile URL (CONT-46). */
	public static function has_pii( string $text ): bool {
		if ( preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text ) ) {
			return true;
		}
		if ( preg_match( '/(?:\+?\d[\s().-]*){8,}/', $text ) ) {
			return true;
		}
		return (bool) preg_match( '#roblox\.com/(?:users|profile)|roblox\.com/[a-z-]*/?users/\d+#i', $text );
	}

	/** 5 per hour keyed by sha256(IP + daily salt) in a 1 h transient; the IP itself is never stored. */
	private static function rate_ok(): bool {
		$ip   = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$salt = (string) get_option( 'rbx_report_salt', 'rbx' ) . gmdate( 'Y-m-d' );
		$key  = 'rbx_rl_' . substr( hash( 'sha256', $ip . $salt ), 0, 32 );
		$n    = (int) get_transient( $key );
		if ( $n >= self::PER_HOUR ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}
}
