<?php
/**
 * Loads datasets from the database and answers changelog-derived questions.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Data;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Db\Changelog;
use RBX\Data\Db\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request cache plus the persistent object cache when present (ENG-80).
 */
final class Repository {

	/** @var array<string, Dataset> */
	private static array $datasets = array();

	/** @var array<string, array<string, array<string, string>>> game => record => field => Y-m-d */
	private static array $changed = array();

	public static function dataset( Game $game ): Dataset {
		$key = $game->key();
		if ( isset( self::$datasets[ $key ] ) ) {
			return self::$datasets[ $key ];
		}
		$ck      = 'rbx:' . $key . ':' . self::version( $game ) . ':records';
		$records = wp_cache_get( $ck, 'rbx' );
		if ( ! is_array( $records ) ) {
			$records = Records::for_game( $game->code() );
			wp_cache_set( $ck, $records, 'rbx', HOUR_IN_SECONDS );
		}
		self::$datasets[ $key ] = new Dataset( $game, $records );
		return self::$datasets[ $key ];
	}

	public static function flush( ?Game $game = null ): void {
		if ( null === $game ) {
			self::$datasets = array();
			self::$changed  = array();
			return;
		}
		unset( self::$datasets[ $game->key() ], self::$changed[ $game->key() ] );
	}

	public static function version( Game $game ): string {
		return (string) get_option( 'rbx_dataset_version_' . $game->key(), '' );
	}

	/** Next dataset version: {CODE}-{YYYYMMDD}-{NN}. */
	public static function next_version( Game $game ): string {
		$day  = gmdate( 'Ymd' );
		$prev = self::version( $game );
		$n    = 1;
		if ( preg_match( '/^' . preg_quote( $game->code(), '/' ) . '-' . $day . '-(\d+)$/', $prev, $m ) ) {
			$n = (int) $m[1] + 1;
		}
		return sprintf( '%s-%s-%02d', $game->code(), $day, $n );
	}

	public static function set_version( Game $game, string $v ): void {
		update_option( 'rbx_dataset_version_' . $game->key(), $v, false );
		self::flush( $game );
	}

	/** Date (Y-m-d) of the newest changelog row for a record field. */
	public static function field_changed( Game $game, string $record_id, string $field ): ?string {
		$key = $game->key();
		if ( ! isset( self::$changed[ $key ] ) ) {
			global $wpdb;
			self::$changed[ $key ] = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT record_id, field, MAX(changed_at) AS c FROM %i WHERE game = %s GROUP BY record_id, field', Changelog::table(), $game->code() ), ARRAY_A );
			foreach ( $rows as $r ) {
				self::$changed[ $key ][ $r['record_id'] ][ $r['field'] ] = substr( (string) $r['c'], 0, 10 );
			}
		}
		return self::$changed[ $key ][ $record_id ][ $field ] ?? null;
	}

	public static function update_changes( string $update_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE update_id = %s', Changelog::table(), $update_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Record for a published shell post, with its game.
	 *
	 * @return array{0: Game, 1: array<string, mixed>}|null
	 */
	public static function for_post( int $post_id ): ?array {
		$game_key = (string) get_post_meta( $post_id, '_rbx_game', true );
		$rid      = (string) get_post_meta( $post_id, '_rbx_record_id', true );
		$game     = Loader::game( $game_key );
		if ( null === $game || '' === $rid ) {
			return null;
		}
		$rec = self::dataset( $game )->get( $rid );
		return null === $rec ? null : array( $game, $rec );
	}
}
