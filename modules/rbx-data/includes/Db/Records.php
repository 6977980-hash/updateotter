<?php
/**
 * Repository for `{prefix}rbx_records` (DATA-01, ENG-23).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Db;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL uses $wpdb->prepare (ENG-51).
 */
final class Records {

	public static function table(): string {
		return Schema::table( 'rbx_records' );
	}

	/**
	 * @param array<string, mixed>|null $row DB row.
	 * @return array<string, mixed>|null
	 */
	public static function hydrate( ?array $row ): ?array {
		if ( null === $row ) {
			return null;
		}
		$data          = json_decode( (string) $row['data'], true );
		$row['data']   = is_array( $data ) ? $data : array();
		$row['post_id'] = (int) $row['post_id'];
		return $row;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', self::table(), $id ), ARRAY_A );
		return self::hydrate( $row );
	}

	/** @return array<string, mixed>|null */
	public static function by_slug( string $game, string $entity, string $slug ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE game = %s AND entity = %s AND slug = %s', self::table(), $game, $entity, $slug ), ARRAY_A );
		return self::hydrate( $row );
	}

	/** @return array<string, mixed>|null */
	public static function by_post( int $post_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', self::table(), $post_id ), ARRAY_A );
		return self::hydrate( $row );
	}

	/**
	 * Every record of a game, keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_game( string $game ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE game = %s ORDER BY id', self::table(), $game ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$h               = self::hydrate( $row );
			$out[ $h['id'] ] = $h;
		}
		return $out;
	}

	public static function count( string $game = '' ): int {
		global $wpdb;
		if ( '' === $game ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE game = %s', self::table(), $game ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Insert or replace one record.
	 *
	 * @param array<string, mixed> $r Record with data array.
	 */
	public static function save( array $r ): void {
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$exists = self::get( (string) $r['id'] );
		$row    = array(
			'id'            => (string) $r['id'],
			'game'          => (string) $r['game'],
			'entity'        => (string) $r['entity'],
			'slug'          => isset( $r['slug'] ) && '' !== $r['slug'] ? (string) $r['slug'] : null,
			'name'          => substr( (string) ( $r['name'] ?? '' ), 0, 191 ),
			'status'        => (string) ( $r['status'] ?? 'draft' ),
			'data'          => (string) wp_json_encode( $r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'content_hash'  => (string) ( $r['content_hash'] ?? '' ),
			'last_verified' => ! empty( $r['last_verified'] ) ? (string) $r['last_verified'] : null,
			'confidence'    => (string) ( $r['confidence'] ?? '' ),
			'updated_at'    => $now,
		);
		if ( $exists ) {
			$wpdb->update( self::table(), $row, array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$row['created_at'] = $now;
			$row['post_id']    = (int) ( $r['post_id'] ?? 0 );
			$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function set_post_id( string $id, int $post_id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'post_id' => $post_id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function delete( string $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function begin(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
