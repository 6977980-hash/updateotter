<?php
/**
 * Tables (ENG-46): events and the poll log.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * DDL.
 */
final class Schema {

	public static function events(): string {
		global $wpdb;
		return $wpdb->prefix . 'rbx_notifier_events';
	}

	public static function polls(): string {
		global $wpdb;
		return $wpdb->prefix . 'rbx_notifier_polls';
	}

	public static function migrate(): void {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$e = self::events();
		$p = self::polls();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE $e (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game varchar(8) NOT NULL,
  universe_id varchar(20) NOT NULL,
  detected_at datetime NOT NULL,
  old_updated varchar(32) NOT NULL DEFAULT '',
  new_updated varchar(32) NOT NULL DEFAULT '',
  last_merged_updated varchar(32) NOT NULL DEFAULT '',
  publish_count int(10) unsigned NOT NULL DEFAULT 1,
  classification varchar(32) NOT NULL DEFAULT '',
  state varchar(16) NOT NULL DEFAULT 'settling',
  ack_kind varchar(32) NOT NULL DEFAULT '',
  ack_by bigint(20) unsigned NOT NULL DEFAULT 0,
  ack_at datetime DEFAULT NULL,
  update_record_id varchar(32) NOT NULL DEFAULT '',
  checklist longtext NOT NULL,
  alerts_sent longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY game_detected (game,detected_at)
) $c;"
		);
		dbDelta(
			"CREATE TABLE $p (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  polled_at datetime NOT NULL,
  kind varchar(24) NOT NULL,
  http_code smallint(5) unsigned NOT NULL DEFAULT 0,
  ms int(10) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY polled_at (polled_at)
) $c;"
		);
		update_option( 'rbx_notifier_db_version', RBX_NOTIFIER_DB_VERSION, false );
	}

	/** Events 365 days; poll log 14 days and at most 2,000 rows. */
	public static function prune(): void {
		global $wpdb;
		$e = self::events();
		$p = self::polls();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM $e WHERE detected_at < %s AND state = 'closed'", gmdate( 'Y-m-d H:i:s', Clock::now() - 365 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $p WHERE polled_at < %s", gmdate( 'Y-m-d H:i:s', Clock::now() - 14 * DAY_IN_SECONDS ) ) );
		$keep = (int) $wpdb->get_var( "SELECT id FROM $p ORDER BY id DESC LIMIT 1 OFFSET 1999" );
		if ( $keep ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $p WHERE id < %d", $keep ) );
		}
		// phpcs:enable
	}
}
