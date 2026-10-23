<?php
/**
 * IndexNow submissions (TECH-45, audit UO-22): item pages whose state or date moved, plus the
 * game's hub, tools, lists and codes after every import, the codes page after a codes check and a
 * site Page when it is saved. Off on local/development installs and while the pre-launch lock is on.
 * Key: Rank Math's when it has one, else our own (generated once) served at /{key}.txt.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Index;

use RBX\Data\Config\Game;
use RBX\Data\Render\Breadcrumbs;
use RBX\Data\Gate\PageState;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Submits only pages that are `index` and whose last_changed moved or which just became `index`.
 */
final class IndexNow {

	public const ENDPOINT = 'https://api.indexnow.org/indexnow';
	public const BATCH    = 100;

	public static function boot(): void {
		add_action( 'parse_request', array( self::class, 'serve_key' ), 0 );
		add_action( 'transition_post_status', array( self::class, 'page_saved' ), 20, 3 );
	}

	public static function enabled(): bool {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$test = 'localhost' === $host || (bool) preg_match( '/\.(local|test|localhost)$|^[0-9.]+$/', $host );
		return ! $test && ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) && ! get_option( 'rbx_prelaunch_lock', 1 ) && '' !== self::key();
	}

	/** The key Rank Math serves at /{key}.txt, or our own, created on first use. */
	public static function key(): string {
		$rm = get_option( 'rank-math-options-instant-indexing' );
		if ( is_array( $rm ) && ! empty( $rm['indexnow_api_key'] ) ) {
			return (string) $rm['indexnow_api_key'];
		}
		$key = (string) get_option( 'rbx_indexnow_key', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( 'rbx_indexnow_key', $key, true );
		}
		return $key;
	}

	/** /{key}.txt answers with the key, which proves to the search engines that we own the host. */
	public static function serve_key(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! preg_match( '#/([a-f0-9]{32})\.txt$#', $path, $m ) || $m[1] !== self::key() ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		echo esc_html( $m[1] );
		exit;
	}

	/** Hub, tool, list, codes and guide pages of a game (no thin or empty pages). @return array<int, string> */
	public static function game_urls( Game $game ): array {
		return array_map( static fn( array $row ): string => $row[1], Llms::game_pages( $game ) );
	}

	/** Every site page worth a ping: home, About pages, each game's pages. @return array<int, string> */
	public static function site_urls(): array {
		$urls = array( home_url( '/' ) );
		foreach ( \RBX\Data\Config\Loader::games() as $game ) {
			$urls = array_merge( $urls, self::game_urls( $game ) );
		}
		$id = get_page_by_path( \RBX\Data\Content\Lang::ID, OBJECT, 'page' );
		if ( $id instanceof \WP_Post && 'publish' === $id->post_status ) {
			$urls[] = (string) get_permalink( $id );
			foreach ( get_pages( array( 'child_of' => $id->ID, 'post_status' => 'publish' ) ) as $p ) {
				$urls[] = (string) get_permalink( $p );
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** Codes page after a codes check (CheckLog::add). */
	public static function codes_checked( Game $game ): void {
		$url = Breadcrumbs::page_url( $game->folder() . '/codes' );
		if ( '' !== $url ) {
			self::submit( array( $url ) );
		}
	}

	/** A published site Page that is saved again (bundled page sync, editor). */
	public static function page_saved( string $new, string $old, \WP_Post $post ): void {
		if ( 'page' !== $post->post_type || 'publish' !== $new || wp_is_post_revision( $post ) || in_array( (int) $post->ID, \RBX\Data\Render\Seo::thin_pages(), true ) || 'report' === $post->post_name ) {
			return;
		}
		$url = (string) get_permalink( $post );
		static $done = array();
		if ( '' === $url || isset( $done[ $url ] ) ) {
			return;
		}
		$done[ $url ] = true;
		self::submit( array( $url ) );
	}

	/**
	 * @param array<int, string>                  $record_ids  Changed records (unused beyond logging).
	 * @param array<string, array<string, mixed>> $transitions From Gate\Runner::run().
	 */
	public static function after_run( Game $game, array $record_ids, array $transitions ): void {
		$urls = array();
		foreach ( $transitions as $t ) {
			if ( PageState::INDEX !== $t['new'] ) {
				continue;
			}
			if ( $t['lc_moved'] || PageState::INDEX !== $t['old'] ) {
				$urls[] = (string) get_permalink( (int) $t['post_id'] );
			}
		}
		// The hub and list pages show these records too, so they changed with them.
		if ( $record_ids || $urls ) {
			$urls = array_merge( $urls, self::game_urls( $game ) );
		}
		self::submit( array_values( array_unique( array_filter( $urls ) ) ) );
	}

	/**
	 * @param array<int, string> $urls Absolute URLs on this host.
	 * @return int URLs sent.
	 */
	public static function submit( array $urls ): int {
		if ( ! $urls || ! self::enabled() ) {
			return 0;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$sent = 0;
		$last = 0;
		foreach ( array_chunk( $urls, self::BATCH ) as $chunk ) {
			$res = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => (string) wp_json_encode(
						array(
							'host'        => $host,
							'key'         => self::key(),
							'keyLocation' => home_url( '/' . self::key() . '.txt' ),
							'urlList'     => $chunk,
						)
					),
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$last = $code;
			if ( $code < 200 || $code >= 300 ) {
				ErrorLog::add( 'indexnow', 'IndexNow returned ' . ( is_wp_error( $res ) ? $res->get_error_message() : (string) $code ) );
				continue;
			}
			$sent += count( $chunk );
		}
		$log   = (array) get_option( 'rbx_indexnow_log', array() );
		$log[] = array(
			'at'   => gmdate( 'Y-m-d H:i:s' ),
			'sent' => $sent,
			'code' => $last,
			'urls' => array_slice( $urls, 0, 20 ),
		);
		update_option( 'rbx_indexnow_log', array_slice( $log, -30 ), false );
		return $sent;
	}
}
