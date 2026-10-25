<?php
/**
 * Append-only field-level changelog (DATA-06).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Rows are never edited or deleted.
 */
final class Changelog {

	/** Fields whose change never moves `last_changed` (DATA-31, CONT-37). */
	public const NOT_SUBSTANTIVE = array( 'last_verified', 'verified_by', 'source_note', 'notes_reviewed_by', 'notes_reviewed_on', 'slug_history', 'evidence_media_id', 'source_url', 'source_type', 'guide_path', 'added_in_update', 'removed_in_update' );

	public static function table(): string {
		return Schema::table( 'rbx_changelog' );
	}

	/**
	 * @param array<string, mixed> $row Row without id.
	 */
	public static function add( array $row ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'game'          => (string) $row['game'],
				'record_id'     => (string) $row['record_id'],
				'field'         => (string) $row['field'],
				'old_value'     => self::str( $row['old_value'] ?? null ),
				'new_value'     => self::str( $row['new_value'] ?? null ),
				'change_kind'   => (string) $row['change_kind'],
				'update_id'     => ! empty( $row['update_id'] ) ? (string) $row['update_id'] : null,
				'import_run_id' => (int) ( $row['import_run_id'] ?? 0 ),
				'changed_at'    => (string) ( $row['changed_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
				'is_correction' => empty( $row['is_correction'] ) ? 0 : 1,
			)
		);
	}

	private static function str( mixed $v ): ?string {
		if ( null === $v ) {
			return null;
		}
		if ( is_array( $v ) ) {
			return (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( is_bool( $v ) ) {
			return $v ? 'TRUE' : 'FALSE';
		}
		return (string) $v;
	}

	/** @return array<int, array<string, mixed>> */
	public static function for_record( string $record_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE record_id = %s ORDER BY changed_at DESC, id DESC LIMIT %d', self::table(), $record_id, $limit ), ARRAY_A );
	}

	/**
	 * @param array<int, string> $record_ids IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_records( array $record_ids, int $limit = 50 ): array {
		global $wpdb;
		if ( ! $record_ids ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $record_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE record_id IN ($in) ORDER BY changed_at DESC, id DESC LIMIT %d", array_merge( array( self::table() ), $record_ids, array( $limit ) ) ), ARRAY_A );
	}

	/** @return array<int, array<string, mixed>> */
	public static function for_update( string $update_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE update_id = %s ORDER BY record_id, field', self::table(), $update_id ), ARRAY_A );
	}

	/** @return array<int, array<string, mixed>> */
	public static function for_run( int $run_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE import_run_id = %d ORDER BY id', self::table(), $run_id ), ARRAY_A );
	}

	/**
	 * Filtered list for the admin screen and CSV export.
	 *
	 * @param array<string, mixed> $f Filters: game, record_id, update_id, run, corrections.
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( array $f, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array( self::table() );
		foreach ( array( 'game', 'record_id', 'update_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$where[] = "$k = %s";
				$args[]  = (string) $f[ $k ];
			}
		}
		if ( ! empty( $f['run'] ) ) {
			$where[] = 'import_run_id = %d';
			$args[]  = (int) $f['run'];
		}
		if ( ! empty( $f['corrections'] ) ) {
			$where[] = 'is_correction = 1';
		}
		$args[] = $limit;
		$args[] = $offset;
		$sql    = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY changed_at DESC, id DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/**
	 * Latest change time per record for the given fields (CONT-36 last_changed).
	 *
	 * @param array<int, string> $record_ids IDs.
	 * @param array<int, string> $fields     Displayed fields; empty = any.
	 */
	public static function last_changed( array $record_ids, array $fields = array() ): ?string {
		global $wpdb;
		if ( ! $record_ids ) {
			return null;
		}
		$args = array_merge( array( self::table() ), $record_ids );
		$sql  = 'SELECT MAX(changed_at) FROM %i WHERE record_id IN (' . implode( ',', array_fill( 0, count( $record_ids ), '%s' ) ) . ')';
		if ( $fields ) {
			$sql .= ' AND field IN (' . implode( ',', array_fill( 0, count( $fields ), '%s' ) ) . ')';
			$args = array_merge( $args, $fields );
		} else {
			$sql .= ' AND field NOT IN (' . implode( ',', array_fill( 0, count( self::NOT_SUBSTANTIVE ), '%s' ) ) . ')';
			$args = array_merge( $args, self::NOT_SUBSTANTIVE );
			// Translated copy (tier_rationale__id) does not change the English page (GR-14).
			$sql .= " AND SUBSTR(field, -4) <> '__id'";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$v = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return $v ? (string) $v : null;
	}

	public static function count_for_run( int $run_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_run_id = %d', self::table(), $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
