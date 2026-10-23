<?php
/**
 * Import run history and snapshots (DATA-19, DATA-24).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Runs: dry_run → applying → finalizing → applied; or blocked / failed / discarded.
 */
final class Runs {

	public static function table(): string {
		return Schema::table( 'rbx_import_runs' );
	}

	public static function snapshots(): string {
		return Schema::table( 'rbx_import_snapshots' );
	}

	/** @param array<string, mixed> $row Columns. */
	public static function create( array $row ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'game'        => (string) $row['game'],
				'status'      => (string) $row['status'],
				'user_id'     => (int) ( $row['user_id'] ?? 0 ),
				'source'      => (string) $row['source'],
				'kind'        => (string) ( $row['kind'] ?? 'import' ),
				'started_at'  => gmdate( 'Y-m-d H:i:s' ),
				'counts'      => (string) wp_json_encode( $row['counts'] ?? array() ),
				'plan'        => (string) wp_json_encode( $row['plan'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'acks'        => (string) wp_json_encode( $row['acks'] ?? array() ),
				'rollback_of' => (int) ( $row['rollback_of'] ?? 0 ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public static function get( int $id, bool $with_plan = true ): ?array {
		global $wpdb;
		$cols = $with_plan ? '*' : 'id, game, status, user_id, source, kind, started_at, finished_at, counts, acks, dataset_version, last_committed_batch, rollback_of';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT $cols FROM %i WHERE id = %d", self::table(), $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'counts', 'plan', 'acks' ) as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$d         = json_decode( (string) $row[ $k ], true );
				$row[ $k ] = is_array( $d ) ? $d : array();
			}
		}
		$row['id']                   = (int) $row['id'];
		$row['last_committed_batch'] = (int) $row['last_committed_batch'];
		return $row;
	}

	/** @param array<string, mixed> $fields Columns to update. */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		foreach ( array( 'counts', 'plan', 'acks' ) as $k ) {
			if ( isset( $fields[ $k ] ) && is_array( $fields[ $k ] ) ) {
				$fields[ $k ] = (string) wp_json_encode( $fields[ $k ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return array<int, array<string, mixed>> */
	public static function recent( string $game, int $limit = 30 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, game, status, user_id, source, kind, started_at, finished_at, counts, dataset_version, rollback_of FROM %i WHERE game = %s ORDER BY id DESC LIMIT %d', self::table(), $game, $limit ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$d           = json_decode( (string) $r['counts'], true );
			$r['counts'] = is_array( $d ) ? $d : array();
			$r['id']     = (int) $r['id'];
		}
		return $rows;
	}

	/** Stores a pre-image once per (run, record). @param array<string, mixed>|null $pre Pre-image. */
	public static function snapshot( int $run_id, string $record_id, ?array $pre ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_run_id = %d AND record_id = %s', self::snapshots(), $run_id, $record_id ) );
		if ( $exists ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::snapshots(),
			array(
				'import_run_id' => $run_id,
				'record_id'     => $record_id,
				'pre_image'     => null === $pre ? null : (string) wp_json_encode( $pre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
	}

	/** @return array<string, array<string, mixed>|null> record_id => pre-image */
	public static function snapshot_for( int $run_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT record_id, pre_image FROM %i WHERE import_run_id = %d', self::snapshots(), $run_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$d                      = null === $r['pre_image'] ? null : json_decode( (string) $r['pre_image'], true );
			$out[ $r['record_id'] ] = is_array( $d ) ? $d : null;
		}
		return $out;
	}

	public static function has_record_snapshot( int $run_id, string $record_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_run_id = %d AND record_id = %s', self::snapshots(), $run_id, $record_id ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function has_snapshot( int $run_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_run_id = %d', self::snapshots(), $run_id ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Keeps snapshots of the newest 20 applied runs per game (DATA-24). */
	public static function prune_snapshots( string $game ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keep = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE game = %s AND status = 'applied' ORDER BY id DESC LIMIT 20", self::table(), $game ) );
		if ( count( $keep ) < 20 ) {
			return;
		}
		$min = min( array_map( 'intval', $keep ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE game = %s AND id < %d', self::table(), $game, $min ) );
		foreach ( $old as $rid ) {
			$wpdb->delete( self::snapshots(), array( 'import_run_id' => (int) $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
