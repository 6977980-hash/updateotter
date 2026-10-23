<?php
/**
 * Runs the DATA-20 gate over every page-bearing record and stores the result as post meta.
 * Called after each applied import, nightly (rbx_data_nightly_gate) and on editorial save.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Gate;

use RBX\Data\Config\Game;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Data\Shells;
use RBX\Data\Db\Changelog;

defined( 'ABSPATH' ) || exit;

/**
 * Writes `_rbx_page_state`, `_rbx_gate_reasons`, `_rbx_page_last_verified`, `_rbx_last_changed`.
 */
final class Runner {

	/**
	 * @return array<string, array<string, mixed>> record_id => [ old_state, new_state, post_id, last_changed_moved ]
	 */
	public static function run( Game $game ): array {
		Repository::flush( $game );
		$ds   = Repository::dataset( $game );
		$gate = new PageState(
			$ds,
			array(
				'field_changed'  => static fn( string $id, string $f ) => Repository::field_changed( $game, $id, $f ),
				'update_changes' => static fn( string $id ) => Repository::update_changes( $id ),
			)
		);
		$states = array();
		foreach ( $game->page_entities() as $entity ) {
			foreach ( $ds->all( $entity, null ) as $id => $rec ) {
				$post_id        = (int) $rec['post_id'];
				$states[ $id ]  = $gate->evaluate(
					$rec,
					array(
						'override'        => $post_id ? (string) get_post_meta( $post_id, '_rbx_index_override', true ) : '',
						'manual_index_ok' => $post_id ? (bool) get_post_meta( $post_id, '_rbx_manual_index_ok', true ) : false,
						'was_indexed'     => $post_id ? (bool) get_post_meta( $post_id, '_rbx_was_indexed', true ) : false,
					)
				);
			}
		}
		$posts       = Shells::sync( $ds, $states );
		$transitions = array();
		foreach ( $posts as $id => $post_id ) {
			$st      = $states[ $id ];
			$old     = (string) get_post_meta( $post_id, '_rbx_page_state', true );
			$old_lc  = (string) get_post_meta( $post_id, '_rbx_last_changed', true );
			$new_lc  = self::last_changed( $ds, $ds->get( $id ) ?? array(), $post_id );
			update_post_meta( $post_id, '_rbx_page_state', $st['state'] );
			update_post_meta( $post_id, '_rbx_gate_reasons', (string) wp_json_encode( $st['reasons'] ) );
			update_post_meta( $post_id, '_rbx_page_last_verified', (string) $st['page_last_verified'] );
			update_post_meta( $post_id, '_rbx_gate_notes', (string) wp_json_encode( $st['notes'] ) );
			update_post_meta( $post_id, '_rbx_last_changed', $new_lc );
			if ( PageState::INDEX === $st['state'] ) {
				update_post_meta( $post_id, '_rbx_was_indexed', 1 );
			}
			$transitions[ $id ] = array(
				'old'        => $old,
				'new'        => $st['state'],
				'post_id'    => $post_id,
				'lc_moved'   => '' !== $new_lc && $new_lc !== $old_lc,
			);
		}
		return $transitions;
	}

	/**
	 * CONT-36: max(changed_at of displayed data, last substantive editorial edit), as Y-m-d.
	 *
	 * @param array<string, mixed> $rec Record.
	 */
	public static function last_changed( Dataset $ds, array $rec, int $post_id ): string {
		if ( ! $rec ) {
			return '';
		}
		$ids = array( (string) $rec['id'] );
		foreach ( array( array( 'DRP', 'egg_id' ), array( 'DRP', 'pet_id' ), array( 'SRC', 'pet_id' ) ) as $rel ) {
			if ( $ds->game()->entity( $rel[0] ) ) {
				$ids = array_merge( $ids, array_keys( $ds->children( $rel[0], $rel[1], (string) $rec['id'] ) ) );
			}
		}
		$data = Changelog::last_changed( $ids );
		$edit = $post_id ? (string) get_post_meta( $post_id, '_rbx_last_substantive', true ) : '';
		$max  = max( (string) $data, $edit );
		return '' === $max ? '' : substr( $max, 0, 10 );
	}
}
