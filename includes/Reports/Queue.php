<?php
/**
 * Reports queue storage (CONT-46): no personal data, rows deleted 180 days after closure.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Table rbx_reports.
 */
final class Queue {

	public const KINDS    = array( 'wrong_number', 'missing_item', 'item_removed', 'other' );
	public const STATUSES = array( 'new', 'checking', 'confirmed', 'rejected' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rbx_reports';
	}

	/** @param array<string, string> $r Clean row. */
	public static function add( array $r ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'game'            => $r['game'],
				'record_id'       => $r['record_id'],
				'field'           => $r['field'],
				'page_url'        => $r['page_url'],
				'kind'            => $r['kind'],
				'text'            => $r['text'],
				'update_id'       => $r['update_id'],
				'dataset_version' => $r['dataset_version'],
				'shown_value'     => $r['shown_value'],
				'status'          => 'new',
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<int, array<string, mixed>> */
	public static function list( string $status = '', int $limit = 100 ): array {
		global $wpdb;
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d', self::table(), $status, $limit ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY FIELD(status, %s, %s, %s, %s), created_at DESC LIMIT %d', self::table(), 'new', 'checking', 'confirmed', 'rejected', $limit ), ARRAY_A );
	}

	public static function count_open(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', self::table(), 'new', 'checking' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function set_status( int $id, string $status, string $reason ): void {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'     => $status,
				'reason'     => sanitize_textarea_field( $reason ),
				'handled_by' => get_current_user_id(),
				'handled_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/** Deletes closed reports 180 days after closure (REV C-14). */
	public static function prune(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status IN (%s, %s) AND handled_at < %s', self::table(), 'confirmed', 'rejected', gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) );
	}
}
