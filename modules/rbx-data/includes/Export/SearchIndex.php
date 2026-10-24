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
			// Every published Page under the game's folder (hub, calculator, lists, codes, guides), not only
			// the ones with a _rbx_game meta (UO-31). Pages kept out of search engines stay out here too.
			$hub = get_page_by_path( $game->folder() );
			if ( $hub && 'publish' === $hub->post_status ) {
				$pages = array_merge(
					array( $hub ),
					get_pages(
						array(
							'child_of'    => $hub->ID,
							'post_status' => 'publish',
						)
					)
				);
				$skip  = \RBX\Data\Render\Seo::empty_update_pages();
				foreach ( $pages as $p ) {
					if ( in_array( (int) $p->ID, $skip, true ) ) {
						continue;
					}
					$items[] = array(
						'n' => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
						't' => __( 'Page', 'rbx-data' ),
						'g' => $game->key(),
						'u' => wp_make_link_relative( (string) get_permalink( $p ) ),
						'a' => $game->short_name() . '|' . $p->post_name,
					);
				}
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

	/** CR-19: Indonesian words people type for English page names ("kode" for codes). */
	private const SYNONYMS = array(
		'kode'       => 'codes',
		'telur'      => 'egg',
		'hewan'      => 'pet',
		'kalkulator' => 'calculator',
		'mutasi'     => 'mutations',
		'panduan'    => 'guide',
		'daftar'     => 'list',
	);

	/**
	 * Search words with typos fixed against the names in the index ("pheonix" -> "phoenix",
	 * "kode" -> "codes"), or '' when nothing changes (CR-19). Same rule as the overlay in theme.js.
	 */
	public static function suggest( string $q ): string {
		$name = (string) get_option( 'rbx_search_index', '' );
		$file = Writer::base_dir() . '/' . $name;
		if ( '' === $name || ! is_readable( $file ) ) {
			return '';
		}
		$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$vocab = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $it ) {
			foreach ( self::words( (string) ( $it['n'] ?? '' ) . ' ' . (string) ( $it['a'] ?? '' ) ) as $w ) {
				$vocab[ $w ] = true;
			}
		}
		if ( ! $vocab ) {
			return '';
		}
		$out     = array();
		$changed = false;
		foreach ( self::words( $q ) as $w ) {
			$fix = self::SYNONYMS[ $w ] ?? null;
			if ( null === $fix && ! isset( $vocab[ $w ] ) && strlen( $w ) >= 4 ) {
				$max  = strlen( $w ) >= 7 ? 2 : 1;
				$best = $max + 1;
				foreach ( array_keys( $vocab ) as $v ) {
					$d = self::edits( $w, (string) $v );
					if ( $d < $best || ( $d === $best && null !== $fix && strlen( (string) $v ) < strlen( $fix ) ) ) {
						$best = $d;
						$fix  = (string) $v;
					}
				}
			}
			$changed = $changed || ( null !== $fix && $fix !== $w );
			$out[]   = $fix ?? $w;
		}
		return $changed ? implode( ' ', $out ) : '';
	}

	/** @return string[] Lower-case words. */
	private static function words( string $s ): array {
		return array_values( array_filter( (array) preg_split( '/[^a-z0-9]+/', strtolower( $s ) ) ) );
	}

	/** Edit distance with swapped neighbours counting as one edit. */
	private static function edits( string $a, string $b ): int {
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( abs( $la - $lb ) > 2 ) {
			return 3;
		}
		$d = array();
		for ( $i = 0; $i <= $la; $i++ ) {
			$d[ $i ][0] = $i;
		}
		for ( $j = 0; $j <= $lb; $j++ ) {
			$d[0][ $j ] = $j;
		}
		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + ( $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1 ) );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
				}
			}
		}
		return $d[ $la ][ $lb ];
	}

	public static function url(): string {
		$name = (string) get_option( 'rbx_search_index', '' );
		return '' === $name ? '' : Writer::base_url() . '/' . $name;
	}
}
