<?php
/**
 * Dates for WP Pages that show game data (CONT-36): `_rbx_last_changed` and
 * `_rbx_page_last_verified` are computed by rendering the page's data sections once, after each
 * import and on save, so the visible date, JSON-LD and sitemap agree without per-request work.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Game;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Changelog;

defined( 'ABSPATH' ) || exit;

/**
 * Editorial "substantive" saves move last_changed (CONT-37); minor saves do not.
 */
final class Pages {

	public static function boot(): void {
		add_action( 'save_post', array( self::class, 'saved' ), 20, 2 );
	}

	/** Pages of one game: the hub and its descendants. @return array<int, \WP_Post> */
	public static function for_game( Game $game ): array {
		$hub = get_page_by_path( $game->folder() );
		if ( ! $hub ) {
			return array();
		}
		$kids = get_pages(
			array(
				'child_of'    => $hub->ID,
				'post_status' => array( 'publish', 'draft', 'private' ),
			)
		);
		return array_merge( array( $hub ), $kids );
	}

	/**
	 * Substantive edit marker. New posts count as substantive on first publish.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function saved( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, array_merge( array( 'page' ), \RBX\Data\Plugin::post_types() ), true ) ) {
			return;
		}
		$kind = (string) get_post_meta( $post_id, '_rbx_edit_kind', true );
		if ( 'substantive' === $kind || '' === (string) get_post_meta( $post_id, '_rbx_last_substantive', true ) ) {
			update_post_meta( $post_id, '_rbx_last_substantive', current_time( 'mysql', true ) );
			// Reset to "minor" so the next save must opt in again.
			update_post_meta( $post_id, '_rbx_edit_kind', 'minor' );
		}
		if ( 'page' === $post->post_type ) {
			self::refresh( $post );
		} else {
			$found = Repository::for_post( $post_id );
			if ( $found ) {
				$lc = \RBX\Data\Gate\Runner::last_changed( Repository::dataset( $found[0] ), $found[1], $post_id );
				update_post_meta( $post_id, '_rbx_last_changed', $lc );
			}
		}
	}

	public static function refresh_game( Game $game ): void {
		foreach ( self::for_game( $game ) as $p ) {
			self::refresh( $p );
		}
	}

	public static function refresh( \WP_Post $post ): void {
		if ( ! has_block( 'rbx/data-section', $post ) && ! has_shortcode( $post->post_content, 'rbx_section' ) ) {
			$edit = (string) get_post_meta( $post->ID, '_rbx_last_substantive', true );
			update_post_meta( $post->ID, '_rbx_last_changed', '' !== $edit ? substr( $edit, 0, 10 ) : substr( $post->post_date_gmt, 0, 10 ) );
			delete_post_meta( $post->ID, '_rbx_page_last_verified' );
			return;
		}
		$ids = self::shown_ids( $post );
		$lc  = (string) Changelog::last_changed( $ids );
		$ed  = (string) get_post_meta( $post->ID, '_rbx_last_substantive', true );
		$max = max( $lc, $ed, '' === $lc && '' === $ed ? (string) $post->post_date_gmt : '' );
		update_post_meta( $post->ID, '_rbx_last_changed', substr( $max, 0, 10 ) );
		$min = '';
		$game = Breadcrumbs::game_for( $post );
		if ( $game ) {
			$ds = Repository::dataset( $game );
			foreach ( $ids as $id ) {
				$lv = (string) ( $ds->get( $id )['data']['last_verified'] ?? '' );
				if ( '' !== $lv && ( '' === $min || $lv < $min ) ) {
					$min = $lv;
				}
			}
		}
		if ( '' !== $min ) {
			update_post_meta( $post->ID, '_rbx_page_last_verified', $min );
		} else {
			delete_post_meta( $post->ID, '_rbx_page_last_verified' );
		}
	}

	/** @return array<int, string> Record IDs the page's sections display. */
	private static function shown_ids( \WP_Post $post ): array {
		$before = Sections::shown();
		Sections::reset();
		do_shortcode( do_blocks( $post->post_content ) );
		$ids = Sections::shown();
		Sections::reset();
		foreach ( $before as $id ) {
			Sections::remember( $id );
		}
		return $ids;
	}
}
