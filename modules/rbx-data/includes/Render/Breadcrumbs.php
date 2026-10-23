<?php
/**
 * One breadcrumb trail for the visible crumbs and BreadcrumbList (TECH-18, CONT-31).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Content\Lang;
use RBX\Data\Data\Repository;
use RBX\Data\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Trail = list of [name, url]; the last crumb has an empty URL and renders as plain text.
 */
final class Breadcrumbs {

	/** @return array<int, array{0: string, 1: string}> */
	public static function trail( ?int $post_id = null ): array {
		if ( is_front_page() ) {
			return array();
		}
		$home  = array( __( 'Home', 'rbx-data' ), Lang::url() );
		$post  = null === $post_id ? get_queried_object() : get_post( $post_id );
		if ( $post instanceof \WP_Post && Lang::is_root( $post ) ) {
			return array();
		}
		if ( is_404() || is_search() || ! $post instanceof \WP_Post ) {
			if ( is_search() ) {
				return array( $home, array( __( 'Search', 'rbx-data' ), '' ) );
			}
			// Only a real 404 says "Page not found" (UO-14).
			return is_404() ? array( $home, array( __( 'Page not found', 'rbx-data' ), '' ) ) : array();
		}
		$trail = array( $home );
		if ( in_array( $post->post_type, Plugin::post_types(), true ) ) {
			$found = Repository::for_post( $post->ID );
			if ( $found ) {
				[ $game, $rec ] = $found;
				$trail[]        = array( $game->name(), self::page_url( $game->folder() ) );
				$path           = (string) $game->path( (string) $rec['entity'] );
				$section        = $game->section( $path );
				$label          = (string) ( $section['label__en'] ?? $game->entity_label( (string) $rec['entity'], true ) );
				$trail[]        = array( $label, self::page_url( $game->folder() . '/' . $path ) );
				$trail[]        = array( 'UPD' === $rec['entity'] ? (string) ( $rec['data']['title__en'] ?? $rec['name'] ) : (string) $rec['name'], '' );
				return array_values( array_filter( $trail, static fn( $c ) => '' !== $c[0] ) );
			}
		}
		foreach ( array_reverse( Lang::ancestors( $post ) ) as $aid ) {
			$trail[] = array( self::crumb_name( (int) $aid ), (string) get_permalink( (int) $aid ) );
		}
		$trail[] = array( self::crumb_name( $post->ID ), '' );
		return $trail;
	}

	/** Game hub crumbs use the game's canonical name, not the hub's H1 ("Steal an Egg Wiki"). */
	private static function crumb_name( int $id ): string {
		$p = get_post( $id );
		if ( $p && 'page' === $p->post_type && 0 === Lang::parent( $p ) ) {
			$g = Loader::by_folder( $p->post_name );
			if ( $g ) {
				return $g->name();
			}
		}
		$short = (string) get_post_meta( $id, '_rbx_crumb', true );
		return '' !== $short ? $short : html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** URL of a WP Page by path, or '' when it does not exist (crumb then renders as text). */
	public static function page_url( string $path ): string {
		$p = get_page_by_path( Lang::path( $path ) );
		return $p && 'publish' === $p->post_status ? (string) get_permalink( $p ) : '';
	}

	/** Visible breadcrumbs (UX-23). */
	public static function html( ?int $post_id = null ): string {
		$trail = self::trail( $post_id );
		if ( count( $trail ) < 2 ) {
			return '';
		}
		$out = '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'rbx-data' ) . '"><ol>';
		$n   = count( $trail );
		foreach ( $trail as $i => $c ) {
			$last = $i === $n - 1;
			$out .= '<li>' . ( $last || '' === $c[1]
				? '<span' . ( $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $c[0] ) . '</span>'
				: '<a href="' . esc_url( $c[1] ) . '">' . esc_html( $c[0] ) . '</a>' ) . '</li>';
		}
		return $out . '</ol></nav>';
	}

	/** Game a page belongs to: item shells via meta, WP Pages via `_rbx_game` or the top-level folder. */
	public static function game_for( ?\WP_Post $post ): ?Game {
		if ( ! $post ) {
			return null;
		}
		$g = Plugin::game_for_post( $post->ID );
		if ( $g ) {
			return $g;
		}
		$anc  = Lang::ancestors( $post );
		$root = $anc ? get_post( (int) end( $anc ) ) : $post;
		return $root ? Loader::by_folder( (string) $root->post_name ) : null;
	}
}
