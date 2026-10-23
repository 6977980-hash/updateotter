<?php
/**
 * IndexNow submissions for record and update URLs (TECH-45). Production only (REV-G23).
 * Uses the key Rank Math already serves, so there is one key per site.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Index;

use RBX\Data\Config\Game;
use RBX\Data\Gate\PageState;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Submits only pages that are `index` and whose last_changed moved or which just became `index`.
 */
final class IndexNow {

	public const ENDPOINT = 'https://api.indexnow.org/indexnow';
	public const BATCH    = 100;

	public static function enabled(): bool {
		return 'production' === wp_get_environment_type() && ! get_option( 'rbx_prelaunch_lock', 1 ) && '' !== self::key();
	}

	/** The key Rank Math serves at /{key}.txt, or our own fallback option. */
	public static function key(): string {
		$rm = get_option( 'rank-math-options-instant-indexing' );
		if ( is_array( $rm ) && ! empty( $rm['indexnow_api_key'] ) ) {
			return (string) $rm['indexnow_api_key'];
		}
		return (string) get_option( 'rbx_indexnow_key', '' );
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
			'urls' => array_slice( $urls, 0, 20 ),
		);
		update_option( 'rbx_indexnow_log', array_slice( $log, -30 ), false );
		return $sent;
	}
}
