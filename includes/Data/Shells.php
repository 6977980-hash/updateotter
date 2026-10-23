<?php
/**
 * CPT page shells for page-bearing records (DATA-01, DATA-21, ENG-21).
 * Shells hold editorial blocks only; the importer never writes post_content.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Data;

use RBX\Data\Db\Records;
use RBX\Data\Gate\PageState;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates shells; publish state follows the G-rules (DATA-20).
 */
final class Shells {

	/**
	 * @param array<string, array<string, mixed>> $states record_id => gate result.
	 * @return array<string, int> record_id => post_id for generated pages.
	 */
	public static function sync( Dataset $ds, array $states ): array {
		$game = $ds->game();
		$out  = array();
		foreach ( $game->page_entities() as $entity ) {
			$pt = (string) $game->post_type( $entity );
			foreach ( $ds->all( $entity, null ) as $id => $rec ) {
				$state   = $states[ $id ]['state'] ?? PageState::NOT_GENERATED;
				$post_id = (int) ( $rec['post_id'] ?? 0 );
				if ( $post_id && ! get_post( $post_id ) ) {
					$post_id = 0;
				}
				if ( PageState::NOT_GENERATED === $state ) {
					if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
						wp_update_post(
							array(
								'ID'          => $post_id,
								'post_status' => 'draft',
							)
						);
					}
					continue;
				}
				$title = 'UPD' === $entity ? (string) ( $rec['data']['title__en'] ?? $rec['name'] ) : (string) $rec['name'];
				$name  = $game->key() . '-' . (string) $rec['slug'];
				if ( ! $post_id ) {
					$post_id = (int) wp_insert_post(
						array(
							'post_type'      => $pt,
							'post_status'    => 'publish',
							'post_title'     => $title,
							'post_name'      => $name,
							'post_content'   => '',
							'comment_status' => 'closed',
							'ping_status'    => 'closed',
							'post_author'    => self::author_id(),
							'meta_input'     => array(
								'_rbx_record_id' => $id,
								'_rbx_game'      => $game->key(),
								'_rbx_entity'    => $entity,
							),
						),
						true
					);
					if ( $post_id ) {
						Records::set_post_id( (string) $id, $post_id );
					}
				} else {
					$p = get_post( $post_id );
					if ( $p && ( $p->post_title !== $title || $p->post_name !== $name || 'publish' !== $p->post_status ) ) {
						wp_update_post(
							array(
								'ID'          => $post_id,
								'post_title'  => $title,
								'post_name'   => $name,
								'post_status' => 'publish',
							)
						);
					}
				}
				if ( $post_id ) {
					$out[ $id ] = $post_id;
				}
			}
		}
		return $out;
	}

	/** Shells are bylined to the site's default author (CONT-41): the first administrator. */
	private static function author_id(): int {
		$id = (int) get_option( 'rbx_default_author', 0 );
		if ( $id && get_userdata( $id ) ) {
			return $id;
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		return (int) ( $admins[0] ?? get_current_user_id() );
	}
}
