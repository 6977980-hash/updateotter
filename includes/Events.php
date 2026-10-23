<?php
/**
 * Event rows (ENG-45, ENG-46).
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * States: settling → open → acknowledged → closed.
 */
final class Events {

	public const SETTLE = 20 * MINUTE_IN_SECONDS;

	private static function dt( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** @return array<string, mixed>|null */
	private static function decode( ?array $row ): ?array {
		if ( null === $row ) {
			return null;
		}
		$row['id']            = (int) $row['id'];
		$row['publish_count'] = (int) $row['publish_count'];
		$row['ack_by']        = (int) $row['ack_by'];
		$row['checklist']     = (array) ( json_decode( (string) $row['checklist'], true ) ?: array() );
		$row['alerts_sent']   = (array) ( json_decode( (string) $row['alerts_sent'], true ) ?: array() );
		return $row;
	}

	/** @return array<string, mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		$t = Schema::events();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ) );
	}

	/** @return array<int, array<string, mixed>> */
	private static function rows( string $where, array $args, string $tail = '' ): array {
		global $wpdb;
		$t   = Schema::events();
		$sql = "SELECT * FROM $t WHERE $where $tail";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	/** @return array<string, mixed>|null */
	public static function settling( string $universe ): ?array {
		return self::rows( "universe_id = %s AND state = 'settling'", array( $universe ), 'ORDER BY id DESC LIMIT 1' )[0] ?? null;
	}

	/** Previous event for the same universe, before $before. @return array<string, mixed>|null */
	public static function previous( string $universe, int $before_id ): ?array {
		return self::rows( 'universe_id = %s AND id < %d', array( $universe, $before_id ), 'ORDER BY id DESC LIMIT 1' )[0] ?? null;
	}

	/** @return array<int, array<string, mixed>> */
	public static function due(): array {
		return self::rows( "state = 'settling' AND detected_at <= %s", array( self::dt( Clock::now() - self::SETTLE ) ), 'ORDER BY id ASC' );
	}

	/** @return array<int, array<string, mixed>> */
	public static function recent( string $universe, int $limit = 20 ): array {
		return self::rows( 'universe_id = %s', array( $universe ), 'ORDER BY id DESC LIMIT ' . max( 1, $limit ) );
	}

	/** @return array<int, array<string, mixed>> */
	public static function since( int $ts ): array {
		return self::rows( 'detected_at >= %s', array( self::dt( $ts ) ), 'ORDER BY id ASC' );
	}

	/** Open events that should show the admin notice. @return array<int, array<string, mixed>> */
	public static function needing_ack(): array {
		$rows = self::rows( "state = 'open'", array(), 'ORDER BY id DESC LIMIT 10' );
		return array_values(
			array_filter(
				$rows,
				static fn( $r ) => 'remind' !== $r['ack_kind'] || ( Clock::ts( (string) $r['ack_at'] . ' UTC' ) ?? 0 ) + DAY_IN_SECONDS <= Clock::now()
			)
		);
	}

	public static function create( string $game, string $universe, ?string $old, string $new ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::events(),
			array(
				'game'                => $game,
				'universe_id'         => $universe,
				'detected_at'         => self::dt( Clock::now() ),
				'old_updated'         => (string) $old,
				'new_updated'         => $new,
				'last_merged_updated' => $new,
				'publish_count'       => 1,
				'state'               => 'settling',
				'checklist'           => '{}',
				'alerts_sent'         => '{}',
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $fields Columns. */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		foreach ( array( 'checklist', 'alerts_sent' ) as $j ) {
			if ( isset( $fields[ $j ] ) && is_array( $fields[ $j ] ) ) {
				$fields[ $j ] = (string) wp_json_encode( (object) $fields[ $j ] );
			}
		}
		$wpdb->update( Schema::events(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function merge( array $ev, string $new ): void {
		self::update(
			(int) $ev['id'],
			array(
				'last_merged_updated' => $new,
				'publish_count'       => (int) $ev['publish_count'] + 1,
			)
		);
	}

	public static function detected_ts( array $ev ): int {
		return (int) Clock::ts( (string) $ev['detected_at'] . ' UTC' );
	}
}
