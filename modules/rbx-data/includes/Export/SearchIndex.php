<?php
/**
 * Site search index for the header search overlay (UX-24): name, type, game, URL, aliases.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Export;

use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Gate\PageState;

defined( 'ABSPATH' ) || exit;

/**
 * One file for all games: search-index.{hash}.json.
 */
final class SearchIndex {

	/** @return string File name written. */
	public static function write(): string {
		$items = array();
		foreach ( Loader::games() as $game ) {
			$ds = Repository::dataset( $game );
			foreach ( $game->page_entities() as $entity ) {
				foreach ( $ds->all( $entity ) as $rec ) {
					if ( empty( $rec['post_id'] ) || 'publish' !== get_post_status( (int) $rec['post_id'] ) ) {
						continue;
					}
					$items[] = array(
						'n' => 'UPD' === $entity ? (string) ( $rec['data']['title__en'] ?? $rec['name'] ) : (string) $rec['name'],
						't' => $game->entity_label( $entity ),
						'g' => $game->key(),
						'u' => wp_make_link_relative( (string) get_permalink( (int) $rec['post_id'] ) ),
						'a' => (string) ( $rec['data']['aliases'] ?? '' ),
					);
				}
			}
			$pages = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'meta_key'       => '_rbx_game', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $game->key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'no_found_rows'  => true,
				)
			);
			foreach ( $pages as $p ) {
				$items[] = array(
					'n' => get_the_title( $p ),
					't' => __( 'Page', 'rbx-data' ),
					'g' => $game->key(),
					'u' => wp_make_link_relative( (string) get_permalink( $p ) ),
					'a' => '',
				);
			}
		}
		$games = array();
		foreach ( Loader::games() as $g ) {
			$games[ $g->key() ] = $g->short_name();
		}
		$json = (string) wp_json_encode(
			array(
				'games' => $games,
				'items' => $items,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$name = 'search-index.' . substr( hash( 'sha256', $json ), 0, 10 ) . '.json';
		Writer::ensure_dirs( Writer::base_dir() );
		Writer::put( Writer::base_dir() . '/' . $name, $json );
		update_option( 'rbx_search_index', $name, false );
		foreach ( (array) glob( Writer::base_dir() . '/search-index.*.json' ) as $f ) {
			if ( basename( (string) $f ) !== $name && filemtime( (string) $f ) < time() - 7 * DAY_IN_SECONDS ) {
				wp_delete_file( (string) $f );
			}
		}
		return $name;
	}

	public static function url(): string {
		$name = (string) get_option( 'rbx_search_index', '' );
		return '' === $name ? '' : Writer::base_url() . '/' . $name;
	}
}
