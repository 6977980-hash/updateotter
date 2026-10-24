<?php
/**
 * Per-update page (CONT-26): summary, what's new, changed values, removed, sources, screenshots,
 * and the editor's "What this means for you".
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Game;
use RBX\Data\Data\Dataset;
use RBX\Data\Db\Changelog;

defined( 'ABSPATH' ) || exit;

/**
 * Update shells (`rbx_update`).
 */
final class Update {

	/** H1 "Steal an Egg {Name} Update ({D Mon YYYY})" without repeating words already in the title. */
	public static function heading( Game $game, array $rec ): string {
		$title = trim( (string) ( $rec['data']['title__en'] ?? $rec['name'] ) );
		if ( false === stripos( $title, $game->name() ) ) {
			$title = $game->name() . ' ' . $title;
		}
		if ( ! preg_match( '/\bupdate\b/i', $title ) ) {
			$title .= ' ' . __( 'Update', 'rbx-data' );
		}
		$date = (string) ( $rec['data']['release_date'] ?? '' );
		if ( '' !== $date ) {
			$title .= ' (' . wp_date( 'j M Y', (int) strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) . ')';
		}
		return $title;
	}

	/** @param array{game: Game, ds: Dataset, rec: array<string, mixed>, post_id: int} $c Context. */
	public static function render( array $c, string $editorial ): string {
		$game = $c['game'];
		$ds   = $c['ds'];
		$rec  = $c['rec'];
		$id   = (string) $rec['id'];

		$out  = '<article class="rbx-item rbx-item--upd">';
		$out .= '<header class="rbx-item__head"><h1>' . esc_html( self::heading( $game, $rec ) ) . '</h1>' . Ui::byline( $c['post_id'] ) . '</header>';
		if ( ! empty( $rec['data']['summary__en'] ) ) {
			$out .= '<div class="rbx-summary">' . wpautop( Ui::text( (string) $rec['data']['summary__en'] ) ) . '</div>';
		}
		$out .= Ui::trust_strip( $game, (string) get_post_meta( $c['post_id'], '_rbx_last_changed', true ), Item::page_last_verified( $c['post_id'] ) );

		$rows    = Changelog::for_update( $id );
		$created = array();
		$changed = array();
		foreach ( $rows as $r ) {
			if ( 'create' === $r['change_kind'] ) {
				$created[ $r['record_id'] ] = true;
			} elseif ( Ui::public_change( $ds, $r ) ) {
				$changed[] = $r;
			}
		}
		$removed = array();
		foreach ( $ds->records() as $rid => $r ) {
			if ( ( $r['data']['added_in_update'] ?? '' ) === $id ) {
				$created[ $rid ] = true;
			}
			if ( ( $r['data']['removed_in_update'] ?? '' ) === $id ) {
				$removed[ $rid ] = true;
			}
		}
		foreach ( (array) ( $rec['data']['affected_record_ids'] ?? array() ) as $aid ) {
			if ( ! isset( $created[ $aid ] ) && ! isset( $removed[ $aid ] ) ) {
				$created[ (string) $aid ] = $created[ (string) $aid ] ?? false;
			}
		}
		$new = array_keys( array_filter( $created ) );
		if ( $new ) {
			$out .= '<section>' . Ui::h2( __( "What's new", 'rbx-data' ), 'whats-new' ) . '<ul>';
			foreach ( $new as $nid ) {
				$r = $ds->get( (string) $nid );
				if ( $r && in_array( $r['entity'], array_keys( $game->entities() ), true ) ) {
					$out .= '<li>' . Ui::link( $r ) . ' <span class="rbx-chip">' . esc_html( $game->entity_label( (string) $r['entity'] ) ) . '</span></li>';
				}
			}
			$out .= '</ul></section>';
		}
		if ( $changed ) {
			$tr = '';
			foreach ( $changed as $r ) {
				$tr .= '<li>' . Ui::change_line( $ds, $r ) . '</li>';
			}
			$out .= '<section class="rbx-changes">' . Ui::h2( __( 'Changed values', 'rbx-data' ), 'changed-values' ) . '<ul>' . $tr . '</ul></section>';
		}
		$mentioned = array_keys( array_filter( $created, static fn( $v ) => false === $v ) );
		if ( $mentioned ) {
			$links = array_filter( array_map( static fn( $m ) => Ui::link( $ds->get( (string) $m ) ), $mentioned ) );
			if ( $links ) {
				$out .= '<section>' . Ui::h2( __( 'Items in this update', 'rbx-data' ), 'items' ) . '<ul><li>' . implode( '</li><li>', $links ) . '</li></ul></section>';
			}
		}
		if ( $removed ) {
			$out .= '<section>' . Ui::h2( __( 'Removed', 'rbx-data' ), 'removed' ) . '<ul>';
			foreach ( array_keys( $removed ) as $rid ) {
				$out .= '<li>' . Ui::link( $ds->get( (string) $rid ) ) . '</li>';
			}
			$out .= '</ul></section>';
		}
		$src = array();
		if ( ! empty( $rec['data']['official_source_url'] ) ) {
			$src[] = '<a href="' . esc_url( (string) $rec['data']['official_source_url'] ) . '" rel="noopener">' . esc_html__( 'Update notes from the developer', 'rbx-data' ) . '</a>';
		}
		if ( ! empty( $rec['data']['roblox_updated_at'] ) ) {
			/* translators: %s: date */
			$src[] = sprintf( esc_html__( 'Roblox shows the game updated on %s', 'rbx-data' ), Ui::date( (string) $rec['data']['roblox_updated_at'] ) );
		}
		if ( $src ) {
			$out .= '<section>' . Ui::h2( __( 'Sources', 'rbx-data' ), 'sources' ) . '<ul><li>' . implode( '</li><li>', $src ) . '</li></ul></section>';
		}
		$shots = '';
		foreach ( $ds->all( 'MED', array( 'live' ) ) as $m ) {
			if ( 'live' === ( $m['data']['status'] ?? '' ) && ( $m['data']['captured_in_update_id'] ?? '' ) === $id && in_array( $id, (array) ( $m['data']['depicts_record_ids'] ?? array() ), true ) ) {
				$img = Media::img( (string) $m['data']['file_name'], (string) $m['data']['alt__en'], 800, 'rbx-shot', true );
				if ( '' !== $img ) {
					/* translators: %s: credit */
					$shots .= '<figure class="rbx-figure">' . $img . '<figcaption>' . esc_html( (string) ( $m['data']['caption__en'] ?? '' ) ) . ' ' . esc_html( sprintf( __( 'Credit: %s', 'rbx-data' ), (string) $m['data']['image_credit'] ) ) . '</figcaption></figure>';
				}
			}
		}
		if ( '' !== $shots ) {
			$out .= '<section class="rbx-shots">' . Ui::h2( __( 'Screenshots', 'rbx-data' ), 'screenshots' ) . $shots . '</section>';
		}
		if ( '' !== trim( wp_strip_all_tags( $editorial ) ) ) {
			$out .= '<section class="rbx-notes">' . Ui::h2( __( 'What this means for you', 'rbx-data' ), 'what-this-means' ) . $editorial . '</section>';
		}
		$out .= '<p>' . Ui::report_button( $game, array( 'record_id' => $id ) ) . '</p>';
		return $out . '</article>';
	}
}
