<?php
/**
 * Codes check log (DATA-10 d, CONT-38): written from RBX Data → Codes, never from the Sheet.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Codes;

use RBX\Data\Config\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Table rbx_code_checks.
 */
final class CheckLog {

	public const RESULTS = array( 'no_codes', 'codes_found', 'codes_expired', 'no_change' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rbx_code_checks';
	}

	/** @param array<string, string> $row checked_on (Y-m-d H:i), checked_by, channels_checked, result, note. */
	public static function add( Game $game, array $row ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'game'             => $game->code(),
				'checked_on'       => (string) $row['checked_on'],
				'checked_by'       => substr( sanitize_text_field( (string) $row['checked_by'] ), 0, 64 ),
				'channels_checked' => sanitize_textarea_field( (string) $row['channels_checked'] ),
				'result'           => in_array( $row['result'], self::RESULTS, true ) ? (string) $row['result'] : 'no_change',
				'note'             => sanitize_textarea_field( (string) ( $row['note'] ?? '' ) ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<int, array<string, mixed>> */
	public static function recent( Game $game, int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE game = %s ORDER BY checked_on DESC, id DESC LIMIT %d', self::table(), $game->code(), $limit ), ARRAY_A );
	}

	/** Newest checked_on as Y-m-d, or '' (CONT-36 last_checked). */
	public static function last_checked( Game $game ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$v = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(checked_on) FROM %i WHERE game = %s', self::table(), $game->code() ) );
		return '' === $v ? '' : substr( $v, 0, 10 );
	}

	public static function days_since( Game $game ): ?int {
		$d = self::last_checked( $game );
		if ( '' === $d ) {
			return null;
		}
		return (int) floor( ( strtotime( current_time( 'Y-m-d' ) ) - strtotime( $d ) ) / DAY_IN_SECONDS );
	}
}
