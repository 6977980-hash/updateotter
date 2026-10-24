<?php
/**
 * Gentle cache warm after a full page purge (GR-02). The pages that are in search are fetched
 * one at a time in small batches through WP-Cron, so the first crawler visits after a deploy hit
 * a warm cache instead of rendering hundreds of cold pages at once. Never more than one fetch
 * runs at a time, which keeps the host's CPU and database connections low.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Cache;

use RBX\Data\Gate\PageState;
use RBX\Data\Render\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * State in option `rbx_cache_warm`: queue (URLs left), done, failed, started, finished.
 */
final class Warm {

	public const HOOK   = 'rbx_cache_warm';
	public const OPTION = 'rbx_cache_warm';
	/** URLs per cron run, and seconds between runs. */
	public const BATCH = 12;
	public const GAP   = 60;

	public static function boot(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** Queues a warm of every page in search, starting a few minutes after the purge. */
	public static function schedule( int $delay = 180 ): void {
		$urls = self::urls();
		update_option(
			self::OPTION,
			array(
				'queue'   => $urls,
				'total'   => count( $urls ),
				'done'    => 0,
				'failed'  => array(),
				'started' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			),
			false
		);
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + $delay, self::HOOK );
	}

	/** One batch: fetch each URL in turn, then queue the next batch. */
	public static function run(): void {
		$s     = self::state();
		$queue = (array) ( $s['queue'] ?? array() );
		if ( ! $queue ) {
			return;
		}
		$batch = array_splice( $queue, 0, self::BATCH );
		foreach ( $batch as $url ) {
			$r = wp_remote_get(
				(string) $url,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'user-agent'  => 'UpdateOtter-CacheWarm/1.0 (+' . home_url( '/' ) . ')',
					'headers'     => array( 'Accept' => 'text/html' ),
				)
			);
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( 200 === $code ) {
				$s['done'] = (int) ( $s['done'] ?? 0 ) + 1;
			} else {
				$s['failed'][] = $code . ' ' . $url;
				// A busy server (5xx or timeout): stop this batch and try the rest later.
				if ( 0 === $code || $code >= 500 ) {
					break;
				}
			}
		}
		$s['queue']  = $queue;
		$s['failed'] = array_slice( (array) $s['failed'], -20 );
		if ( $queue ) {
			wp_schedule_single_event( time() + self::GAP, self::HOOK );
		} else {
			$s['finished'] = gmdate( 'Y-m-d H:i:s' );
		}
		update_option( self::OPTION, $s, false );
	}

	/** @return array<string, mixed> */
	public static function state(): array {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/** @return array<string, mixed> Counts only (for the public launch status). */
	public static function summary(): array {
		$s = self::state();
		return array(
			'total'    => (int) ( $s['total'] ?? 0 ),
			'done'     => (int) ( $s['done'] ?? 0 ),
			'left'     => count( (array) ( $s['queue'] ?? array() ) ),
			'failed'   => count( (array) ( $s['failed'] ?? array() ) ),
			'started'  => (string) ( $s['started'] ?? '' ),
			'finished' => (string) ( $s['finished'] ?? '' ),
		);
	}

	/**
	 * The sitemap's URLs: published Pages that are in search, then item pages in search.
	 *
	 * @return array<int, string>
	 */
	public static function urls(): array {
		$skip = Seo::empty_update_pages();
		$urls = array( home_url( '/' ) );
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'menu_order title',
				'order'       => 'ASC',
			)
		);
		$list = array();
		foreach ( $pages as $p ) {
			if ( in_array( (int) $p->ID, $skip, true ) || ( 'report' === $p->post_name && 0 === (int) $p->post_parent ) ) {
				continue;
			}
			$list[] = (string) get_permalink( $p );
		}
		// Hubs and tools before deeper pages, English before the /id/ twins.
		$rank = static function ( string $u ): int {
			$path = trim( (string) wp_parse_url( $u, PHP_URL_PATH ), '/' );
			return ( str_starts_with( $path . '/', \RBX\Data\Content\Lang::ID . '/' ) ? 10 : 0 ) + substr_count( $path, '/' );
		};
		usort( $list, static fn( string $a, string $b ): int => $rank( $a ) <=> $rank( $b ) );
		$urls = array_merge( $urls, $list );
		$items = get_posts(
			array(
				'post_type'   => \RBX\Data\Plugin::post_types(),
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_key'    => '_rbx_page_state', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'  => PageState::INDEX, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);
		foreach ( $items as $p ) {
			$urls[] = (string) get_permalink( $p );
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}
}
