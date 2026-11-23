<?php
/**
 * "Do these numbers match your game? Yes / No" on item pages. After enough yes answers on
 * different days, the item's wiki values say "Players confirm" instead of "Source: community wiki".
 *
 * Only counts are kept (option rbx_votes_{game}: record => value key, yes, no, yes days). No row
 * per vote, no IP, no text. A one-day transient keyed by sha256(IP + record + daily salt) stops a
 * second answer on the same item that day, like the report form's rate limit.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Reports;

use RBX\Data\Config\Loader;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Vote counts and the "Players confirm" rule.
 */
final class Votes {

	/** Yes answers needed before the label changes. */
	public const MIN_YES = 5;
	/** The yes answers must come on at least this many different days (UTC). */
	public const MIN_DAYS = 3;
	/** At most one no for every MAX_NO_PER_YES yes answers (80 % yes). */
	public const MAX_NO_PER_YES = 4;
	public const PER_HOUR = 20;

	/**
	 * Codes ("Did this code work for you?", Fisch 2026-11-23, Ali): codes expire, so only answers
	 * from the last CODE_WINDOW seconds count. Counts show from CODE_SHOW answers; "Verified by
	 * players" needs CODE_MIN yes and at most one no per MAX_NO_PER_YES yes; "Players say it does
	 * not work" needs CODE_MIN no and at most one yes per MAX_NO_PER_YES no. One answer per
	 * connection and code per window. Stored as hourly buckets: no row per vote, no IP.
	 */
	public const CODE_WINDOW = 172800;
	public const CODE_SHOW   = 3;
	public const CODE_MIN    = 5;
	public const PER_DAY  = 300;

	/** @var array<string, array<string, array<string, mixed>>> Loaded counts per game key. */
	private static array $counts = array();

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_nopriv_rbx_vote', array( self::class, 'post' ) );
		add_action( 'admin_post_rbx_vote', array( self::class, 'post' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'rbx/v1',
			'/vote',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest' ),
				'args'                => array(
					'answer' => array(
						'type'     => 'string',
						'enum'     => array( 'yes', 'no' ),
						'required' => true,
					),
				),
			)
		);
	}

	public static function rest( \WP_REST_Request $req ): \WP_REST_Response {
		$res = self::handle( (array) $req->get_params() );
		if ( is_wp_error( $res ) ) {
			return new \WP_REST_Response( array( 'message' => $res->get_error_message() ), (int) ( $res->get_error_data()['status'] ?? 400 ) );
		}
		return new \WP_REST_Response( array( 'message' => self::thanks() ), 201 );
	}

	/** No-JS post: a yes goes back to the page, a no goes on to the report form for that item. */
	public static function post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form on cached pages; honeypot, timing and rate limit as the report form.
		$p    = wp_unslash( $_POST );
		$res  = self::handle( (array) $p );
		$page = esc_url_raw( (string) ( $p['page_url'] ?? '' ) );
		$back = (string) wp_parse_url( $page, PHP_URL_HOST ) === (string) wp_parse_url( home_url(), PHP_URL_HOST ) ? $page : home_url( '/' );
		$is_code = (bool) preg_match( '/^[A-Z][A-Z0-9]{1,4}-COD-\d{4,6}$/', (string) ( $p['record_id'] ?? '' ) );
		if ( 'no' === ( $p['answer'] ?? '' ) && ! $is_code ) {
			$back = add_query_arg(
				array_filter(
					array(
						'game'   => sanitize_key( (string) ( $p['game'] ?? '' ) ),
						'record' => sanitize_text_field( (string) ( $p['record_id'] ?? '' ) ),
					)
				),
				home_url( '/report/' )
			);
		}
		wp_safe_redirect( is_wp_error( $res ) ? $back : $back . ( str_contains( $back, '/report/' ) ? '' : ( $is_code ? '#reported-codes' : '#stats' ) ), 303 );
		exit;
	}

	public static function thanks(): string {
		return __( 'Thanks for checking.', 'rbx-data' );
	}

	/**
	 * Key of the numbers a visitor sees on the item: a vote counts only for these values, so a
	 * changed number starts again from zero.
	 *
	 * @param array<string, mixed> $rec Record.
	 */
	public static function value_key( Dataset $ds, array $rec ): string {
		$vals = array();
		foreach ( (array) ( $ds->game()->entity( (string) $rec['entity'] )['fields'] ?? array() ) as $f => $def ) {
			if ( empty( $def['internal'] ) && in_array( (string) ( $def['type'] ?? '' ), Dataset::NUMERIC, true ) && null !== ( $rec['data'][ $f ] ?? null ) ) {
				$vals[ $f ] = (string) $rec['data'][ $f ];
			}
		}
		ksort( $vals );
		return substr( hash( 'sha256', (string) wp_json_encode( $vals ) ), 0, 16 );
	}

	/**
	 * The rule, on one record's counts (y yes, n no, d distinct yes days, k value key).
	 *
	 * @param array<string, mixed> $c Counts.
	 */
	public static function confirms( array $c, string $key ): bool {
		$y = (int) ( $c['y'] ?? 0 );
		return ( $c['k'] ?? '' ) === $key
			&& $y >= self::MIN_YES
			&& count( (array) ( $c['d'] ?? array() ) ) >= self::MIN_DAYS
			&& (int) ( $c['n'] ?? 0 ) * self::MAX_NO_PER_YES <= $y;
	}

	/** @return array<string, mixed> Counts of one record, or an empty array. */
	public static function counts( string $game_key, string $record_id ): array {
		if ( ! isset( self::$counts[ $game_key ] ) ) {
			self::$counts[ $game_key ] = function_exists( 'get_option' ) ? (array) get_option( 'rbx_votes_' . $game_key, array() ) : array();
		}
		return (array) ( self::$counts[ $game_key ][ $record_id ] ?? array() );
	}

	/** @param array<string, mixed> $rec Record. */
	public static function players_confirm( Dataset $ds, array $rec ): bool {
		$c = self::counts( $ds->game()->key(), (string) $rec['id'] );
		return $c && self::confirms( $c, self::value_key( $ds, $rec ) );
	}

	/**
	 * @param array<string, mixed> $p Raw params.
	 * @return true|\WP_Error
	 */
	public static function handle( array $p ): bool|\WP_Error {
		$spam = new \WP_Error( 'spam', __( 'Could not send. Please try again.', 'rbx-data' ), array( 'status' => 400 ) );
		if ( '' !== trim( (string) ( $p['website'] ?? '' ) ) ) {
			return $spam;
		}
		if ( isset( $p['e'] ) && '' !== (string) $p['e'] ) {
			if ( (int) $p['e'] < 2000 ) {
				return $spam;
			}
		} else {
			$t0 = (int) ( $p['t'] ?? 0 );
			if ( $t0 <= 0 || time() - (int) floor( $t0 / 1000 ) < 2 ) {
				return $spam;
			}
		}
		$answer = (string) ( $p['answer'] ?? '' );
		$game   = Loader::game( sanitize_key( (string) ( $p['game'] ?? '' ) ) );
		// One item, or every row of a table (biomes): "A,B,C", at most 40 ids.
		$rids = array_slice( array_values( array_unique( array_filter( array_map( 'trim', explode( ',', (string) ( $p['record_id'] ?? '' ) ) ) ) ) ), 0, 40 );
		if ( ! in_array( $answer, array( 'yes', 'no' ), true ) || ! $game || ! $rids ) {
			return $spam;
		}
		$ds   = Repository::dataset( $game );
		$recs = array();
		foreach ( $rids as $rid ) {
			$rec = preg_match( '/^[A-Z][A-Z0-9]{1,4}-[A-Z]{3}-\d{4,6}$/', $rid ) ? $ds->get( $rid ) : null;
			if ( ! $rec || ! in_array( (string) ( $rec['status'] ?? '' ), array( 'live', 'limited' ), true ) ) {
				return $spam;
			}
			$recs[] = $rec;
		}
		$code = 1 === count( $recs ) && 'COD' === ( $recs[0]['entity'] ?? '' );
		$who  = $code ? self::visitor( implode( ',', $rids ), 'w' . intdiv( time(), self::CODE_WINDOW ) ) : self::visitor( implode( ',', $rids ) );
		if ( get_transient( $who ) ) {
			// Already answered this today: say thanks, count nothing.
			return true;
		}
		if ( ! self::rate_ok() ) {
			return new \WP_Error( 'rate', __( 'Too many answers from this connection. Please try again later.', 'rbx-data' ), array( 'status' => 429 ) );
		}
		set_transient( $who, 1, $code ? self::CODE_WINDOW : DAY_IN_SECONDS );
		try {
			if ( $code ) {
				self::add_code( $ds, $recs[0], 'yes' === $answer );
				return true;
			}
			self::add( $ds, $recs, 'yes' === $answer );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'vote', $e->getMessage() );
			return $spam;
		}
		return true;
	}

	/** @param array<int, array<string, mixed>> $recs Records the answer is about. */
	private static function add( Dataset $ds, array $recs, bool $yes ): void {
		$opt     = 'rbx_votes_' . $ds->game()->key();
		$all     = (array) get_option( $opt, array() );
		$flipped = false;
		foreach ( $recs as $rec ) {
			$key    = self::value_key( $ds, $rec );
			$c      = (array) ( $all[ $rec['id'] ] ?? array() );
			$before = self::confirms( $c, $key );
			if ( ( $c['k'] ?? '' ) !== $key ) {
				$c = array(
					'k' => $key,
					'y' => 0,
					'n' => 0,
					'd' => array(),
				);
			}
			if ( $yes ) {
				++$c['y'];
				// Only the number of days matters; keep the last few so the option stays small.
				$c['d'] = array_slice( array_values( array_unique( array_merge( (array) $c['d'], array( gmdate( 'Y-m-d' ) ) ) ) ), -self::MIN_DAYS * 2 );
			} else {
				++$c['n'];
			}
			$all[ $rec['id'] ] = $c;
			if ( $before !== self::confirms( $c, $key ) ) {
				$flipped = true;
				do_action( 'litespeed_purge', 'rbx-rec-' . strtolower( (string) $rec['id'] ) );
			}
		}
		update_option( $opt, $all, false );
		self::$counts[ $ds->game()->key() ] = $all;
		if ( $flipped ) {
			do_action( 'litespeed_purge', 'rbx-' . $ds->game()->key() );
		}
	}

	/**
	 * Yes and no answers on a code within the window ending at $now.
	 *
	 * @param array<int|string, array{0: int, 1: int}> $buckets Hour start (Unix time) => [yes, no].
	 * @return array{y: int, n: int}
	 */
	public static function code_tally( array $buckets, int $now ): array {
		$y = 0;
		$n = 0;
		foreach ( $buckets as $h => $c ) {
			if ( (int) $h > $now - self::CODE_WINDOW && (int) $h <= $now ) {
				$y += (int) ( $c[0] ?? 0 );
				$n += (int) ( $c[1] ?? 0 );
			}
		}
		return array(
			'y' => $y,
			'n' => $n,
		);
	}

	/** 'verified', 'not_working' or '' for a code's tally. */
	public static function code_label( int $y, int $n ): string {
		if ( $y >= self::CODE_MIN && $n * self::MAX_NO_PER_YES <= $y ) {
			return 'verified';
		}
		if ( $n >= self::CODE_MIN && $y * self::MAX_NO_PER_YES <= $n ) {
			return 'not_working';
		}
		return '';
	}

	/** @return array{y: int, n: int} Answers on one code in the last CODE_WINDOW seconds. */
	public static function code_counts( string $game_key, string $record_id ): array {
		$all = function_exists( 'get_option' ) ? (array) get_option( 'rbx_codevotes_' . $game_key, array() ) : array();
		return self::code_tally( (array) ( $all[ $record_id ] ?? array() ), time() );
	}

	/** @param array<string, mixed> $rec Code record. */
	private static function add_code( Dataset $ds, array $rec, bool $yes ): void {
		$opt = 'rbx_codevotes_' . $ds->game()->key();
		$all = (array) get_option( $opt, array() );
		$now = time();
		$b   = array();
		// Drop buckets that left the window, so the option stays small.
		foreach ( (array) ( $all[ $rec['id'] ] ?? array() ) as $h => $c ) {
			if ( (int) $h > $now - self::CODE_WINDOW ) {
				$b[ (int) $h ] = $c;
			}
		}
		$h       = $now - $now % 3600;
		$b[ $h ] = $b[ $h ] ?? array( 0, 0 );
		++$b[ $h ][ $yes ? 0 : 1 ];
		$all[ $rec['id'] ] = $b;
		update_option( $opt, $all, false );
		// The codes page carries each code's record tag; refresh it so the counts are current.
		do_action( 'litespeed_purge', 'rbx-rec-' . strtolower( (string) $rec['id'] ) );
	}

	private static function visitor( string $rid, string $period = '' ): string {
		$ip   = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$fwd  = trim( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$salt = (string) get_option( 'rbx_report_salt', 'rbx' ) . ( '' !== $period ? $period : gmdate( 'Y-m-d' ) );
		return 'rbx_vt_' . substr( hash( 'sha256', $ip . '|' . $fwd . '|' . $rid . $salt ), 0, 32 );
	}

	/** Per visitor per hour and for the whole site per day, as the report form (UO-45). */
	private static function rate_ok(): bool {
		$ip   = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$fwd  = trim( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$salt = (string) get_option( 'rbx_report_salt', 'rbx' ) . gmdate( 'Y-m-d' );
		$key  = 'rbx_vrl_' . substr( hash( 'sha256', $ip . '|' . $fwd . $salt ), 0, 32 );
		$day  = 'rbx_vrl_day_' . gmdate( 'Ymd' );
		$n    = (int) get_transient( $key );
		$all  = (int) get_transient( $day );
		if ( $n >= self::PER_HOUR || $all >= self::PER_DAY ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		set_transient( $day, $all + 1, DAY_IN_SECONDS );
		return true;
	}
}
