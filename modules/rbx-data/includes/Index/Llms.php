<?php
/**
 * /llms.txt built from the site itself (audit UO-21): hubs, tools, codes, lists and guides with
 * their descriptions, then every indexable item page with its one-line fact. Replaces the orphan
 * static file a Hostinger plugin once wrote (the `llms` launch step deletes that file).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Index;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Content\Lang;
use RBX\Data\Data\Repository;
use RBX\Data\Gate\PageState;
use RBX\Data\Plugin;
use RBX\Data\Render\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and serves /llms.txt; the page list is shared with IndexNow.
 */
final class Llms {

	private const CACHE = 'rbx_llms_txt';

	public static function boot(): void {
		add_action( 'parse_request', array( self::class, 'serve' ), 0 );
		add_action( 'rbx_data_run_applied', array( self::class, 'flush' ) );
		add_action( 'save_post_page', array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	public static function serve(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $base . 'llms.txt' !== $path ) {
			return;
		}
		$txt = get_transient( self::CACHE );
		if ( ! is_string( $txt ) || '' === $txt ) {
			$txt = self::build();
			set_transient( self::CACHE, $txt, 6 * HOUR_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			echo $txt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text.
		}
		exit;
	}

	/**
	 * Published English site pages of a game that may be indexed, hub first: [title, url, description].
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function game_pages( Game $game ): array {
		$hub = get_page_by_path( $game->folder(), OBJECT, 'page' );
		if ( ! $hub instanceof \WP_Post || 'publish' !== $hub->post_status ) {
			return array();
		}
		$skip  = array_merge( Seo::thin_pages(), Seo::empty_update_pages() );
		$pages = array_merge(
			array( $hub ),
			get_pages(
				array(
					'child_of'    => $hub->ID,
					'post_status' => 'publish',
					'sort_column' => 'menu_order,post_title',
				)
			)
		);
		$out   = array();
		foreach ( $pages as $p ) {
			if ( in_array( (int) $p->ID, $skip, true ) ) {
				continue;
			}
			$out[] = self::page_row( $p );
		}
		return $out;
	}

	/** @return array{0: string, 1: string, 2: string} */
	private static function page_row( \WP_Post $p ): array {
		$uri   = get_page_uri( $p );
		$title = Seo::seo_text( $uri, 'title' );
		$title = null === $title ? html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ) : self::clean( $title );
		$desc  = self::clean( (string) ( Seo::seo_text( $uri, 'description' ) ?? '' ) );
		return array( (string) preg_replace( '/\s*\|\s*' . preg_quote( Seo::BRAND, '/' ) . '$/', '', $title ), (string) get_permalink( $p ), $desc );
	}

	/** Drops the {month} placeholder: a month in a cached text file would go stale. */
	private static function clean( string $s ): string {
		return trim( (string) preg_replace( '/\s*\(\{month\}\)|\s*\{month\}/', '', $s ) );
	}

	/**
	 * Indexable item pages per game key: [name, url, description].
	 *
	 * @return array<string, array<int, array{0: string, 1: string, 2: string}>>
	 */
	public static function items(): array {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'      => Plugin::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => '_rbx_page_state', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => PageState::INDEX, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			$found = Repository::for_post( (int) $id );
			if ( ! $found ) {
				continue;
			}
			$out[ $found[0]->key() ][] = array( (string) $found[1]['name'], (string) get_permalink( (int) $id ), Seo::item_description( (int) $id ) );
		}
		return $out;
	}

	public static function build(): string {
		$games = Loader::games();
		$names = implode( ', ', array_map( static fn( Game $g ): string => $g->name(), $games ) );
		$l     = array();
		$l[]   = '# ' . Seo::BRAND;
		$l[]   = '';
		$l[]   = '> Fan-made calculators, item databases, codes and guides for Roblox games (' . $names . '). Not affiliated with Roblox or the game developers.';
		$l[]   = '';
		$l[]   = 'Game data (names, rarities, values, multipliers) is adapted from each game\'s community Fandom wiki under CC BY-SA, and every page says when that wiki data was copied ("Wiki data as of"). Calculators run the community formulas; notes on item pages are our own. Indonesian pages live under /id/.';
		$l[]   = '';
		$l[]   = '## About this site';
		$l[]   = '';
		foreach ( array( 'methodology', 'about', 'authors/ali-ahmad', 'corrections', 'contact' ) as $path ) {
			$p = get_page_by_path( $path, OBJECT, 'page' );
			if ( $p instanceof \WP_Post && 'publish' === $p->post_status ) {
				$l[] = self::line( self::page_row( $p ) );
			}
		}
		$items = self::items();
		foreach ( $games as $game ) {
			$pages = self::game_pages( $game );
			if ( ! $pages ) {
				continue;
			}
			$l[] = '';
			$l[] = '## ' . $game->name();
			$l[] = '';
			foreach ( $pages as $row ) {
				$l[] = self::line( $row );
			}
			if ( ! empty( $items[ $game->key() ] ) ) {
				$l[] = '';
				$l[] = '### ' . $game->name() . ' item pages';
				$l[] = '';
				foreach ( $items[ $game->key() ] as $row ) {
					$l[] = self::line( $row );
				}
			}
		}
		$id = get_page_by_path( Lang::ID, OBJECT, 'page' );
		if ( $id instanceof \WP_Post && 'publish' === $id->post_status ) {
			$l[] = '';
			$l[] = '## Optional';
			$l[] = '';
			$l[] = self::line( array( 'Bahasa Indonesia', (string) get_permalink( $id ), 'Indonesian versions of the Steal an Egg pages.' ) );
		}
		return implode( "\n", $l ) . "\n";
	}

	/** @param array{0: string, 1: string, 2: string} $row */
	private static function line( array $row ): string {
		return '- [' . str_replace( array( '[', ']' ), '', $row[0] ) . '](' . $row[1] . ')' . ( '' !== $row[2] ? ': ' . $row[2] : '' );
	}
}
