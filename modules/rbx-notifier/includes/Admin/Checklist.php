<?php
/**
 * Per-update checklist (ENG-48). Config-driven per game (`notifier.checklist`), else the default.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Admin;

use RBX\Notifier\Clock;
use RBX\Notifier\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Ticks store who and when; closing needs items 5–8 unless the event was a hotfix.
 */
final class Checklist {

	/** Items that must be ticked before closing a content update. */
	public const REQUIRED = array( '5', '6', '7', '8' );

	/** @return array<string, string> */
	public static function items( string $game_key ): array {
		$game = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::game( $game_key ) : null;
		$cfg  = $game ? (array) ( $game->raw()['notifier']['checklist'] ?? array() ) : array();
		if ( $cfg ) {
			$out = array();
			foreach ( array_values( $cfg ) as $i => $label ) {
				$out[ (string) ( $i + 1 ) ] = (string) $label;
			}
			return $out;
		}
		return array(
			'1'  => __( 'Read the developer patch notes; paste the link into the Sheet UPD row.', 'rbx-notifier' ),
			'2'  => __( 'Create the UPD row with roblox_updated_at copied from this event.', 'rbx-notifier' ),
			'3'  => __( 'Mark affected mechanics; set their formula tests to needs_recheck.', 'rbx-notifier' ),
			'4'  => __( 'Add new pets, eggs and mutations as draft.', 'rbx-notifier' ),
			'5'  => __( 'Import dry run, review, apply.', 'rbx-notifier' ),
			'6'  => __( 'Data health: failing tests = 0; disputed = 0 or acknowledged.', 'rbx-notifier' ),
			'7'  => __( 'Spot-check 3 changed pages uncached and the calculator.', 'rbx-notifier' ),
			'8'  => __( 'Codes check logged in RBX Data, Codes (within 24 h).', 'rbx-notifier' ),
			'9'  => __( 'Report queue reviewed for reports about this update.', 'rbx-notifier' ),
			'10' => __( 'Write the human summary for the update page.', 'rbx-notifier' ),
			'11' => __( 'Link this event to the UPD record and close it.', 'rbx-notifier' ),
		);
	}

	public static function tick( int $id, string $item, bool $done, string $note, int $user ): bool {
		$ev = Events::get( $id );
		if ( ! $ev || 'closed' === $ev['state'] || ! isset( self::items( (string) $ev['game'] )[ $item ] ) ) {
			return false;
		}
		$list          = (array) $ev['checklist'];
		$list[ $item ] = array(
			'done' => $done,
			'by'   => $user,
			'at'   => Clock::iso(),
			'note' => substr( sanitize_text_field( $note ), 0, 300 ),
		);
		Events::update( $id, array( 'checklist' => $list ) );
		return true;
	}

	/** @return string[] Item numbers still missing for close. */
	public static function missing( array $ev ): array {
		if ( 'hotfix_no_change' === $ev['ack_kind'] ) {
			return array();
		}
		$items = self::items( (string) $ev['game'] );
		$req   = array_intersect( self::REQUIRED, array_keys( $items ) );
		return array_values( array_filter( $req, static fn( $i ) => empty( $ev['checklist'][ $i ]['done'] ) ) );
	}

	/**
	 * @return true|string True, or the reason it was refused.
	 */
	public static function close( int $id, string $update_record_id, int $user ) {
		$ev = Events::get( $id );
		if ( ! $ev ) {
			return __( 'Event not found.', 'rbx-notifier' );
		}
		$missing = self::missing( $ev );
		if ( $missing ) {
			/* translators: %s: item numbers */
			return sprintf( __( 'Tick items %s first.', 'rbx-notifier' ), implode( ', ', $missing ) );
		}
		$update_record_id = strtoupper( trim( $update_record_id ) );
		if ( '' !== $update_record_id && class_exists( '\RBX\Data\Api' ) && null === \RBX\Data\Api::record( $update_record_id ) ) {
			return __( 'That UPD record ID does not exist.', 'rbx-notifier' );
		}
		$fields = array(
			'state'            => 'closed',
			'update_record_id' => $update_record_id,
		);
		if ( '' === (string) $ev['ack_kind'] || 'remind' === $ev['ack_kind'] ) {
			$fields['ack_kind'] = 'content_update';
			$fields['ack_by']   = $user;
			$fields['ack_at']   = gmdate( 'Y-m-d H:i:s', Clock::now() );
		}
		if ( '' !== $update_record_id && isset( self::items( (string) $ev['game'] )['11'] ) ) {
			$list         = (array) $ev['checklist'];
			$list['11']   = array(
				'done' => true,
				'by'   => $user,
				'at'   => Clock::iso(),
				'note' => $update_record_id,
			);
			$fields['checklist'] = $list;
		}
		Events::update( $id, $fields );
		return true;
	}

	public static function ack( int $id, string $kind, int $user ): bool {
		$ev = Events::get( $id );
		if ( ! $ev || ! in_array( $kind, array( 'content_update', 'hotfix_no_change', 'remind' ), true ) || 'closed' === $ev['state'] ) {
			return false;
		}
		$fields = array(
			'ack_kind' => $kind,
			'ack_by'   => $user,
			'ack_at'   => gmdate( 'Y-m-d H:i:s', Clock::now() ),
		);
		if ( 'content_update' === $kind ) {
			$fields['state'] = 'acknowledged';
		} elseif ( 'hotfix_no_change' === $kind ) {
			$fields['state'] = 'closed';
		}
		Events::update( $id, $fields );
		return true;
	}
}
