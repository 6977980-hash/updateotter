<?php
/**
 * Custom tables (ENG-23). Columns are owned by §3 of the spec.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Db;

defined( 'ABSPATH' ) || exit;

/**
 * DDL and migrations.
 */
final class Schema {

	/** @return array<string, string> Table suffix => CREATE statement. */
	public static function ddl(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix;
		return array(
			'rbx_records'          => "CREATE TABLE {$p}rbx_records (
  id varchar(32) NOT NULL,
  game varchar(8) NOT NULL,
  entity varchar(8) NOT NULL,
  slug varchar(80) DEFAULT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  status varchar(24) NOT NULL DEFAULT 'draft',
  data longtext NOT NULL,
  content_hash char(64) NOT NULL DEFAULT '',
  last_verified date DEFAULT NULL,
  confidence varchar(16) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY game_entity_slug (game,entity,slug),
  KEY game_entity_status (game,entity,status),
  KEY post_id (post_id)
) ENGINE=InnoDB $c;",
			'rbx_changelog'        => "CREATE TABLE {$p}rbx_changelog (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game varchar(8) NOT NULL,
  record_id varchar(32) NOT NULL,
  field varchar(64) NOT NULL,
  old_value longtext DEFAULT NULL,
  new_value longtext DEFAULT NULL,
  change_kind varchar(16) NOT NULL,
  update_id varchar(32) DEFAULT NULL,
  import_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  changed_at datetime NOT NULL,
  is_correction tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY record_changed (record_id,changed_at),
  KEY run (import_run_id),
  KEY update_id (update_id),
  KEY game_changed (game,changed_at)
) ENGINE=InnoDB $c;",
			'rbx_import_runs'      => "CREATE TABLE {$p}rbx_import_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game varchar(8) NOT NULL,
  status varchar(16) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(8) NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'import',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  counts longtext DEFAULT NULL,
  plan longtext DEFAULT NULL,
  acks longtext DEFAULT NULL,
  dataset_version varchar(40) DEFAULT NULL,
  last_committed_batch int(11) NOT NULL DEFAULT -1,
  rollback_of bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY game_started (game,started_at)
) ENGINE=InnoDB $c;",
			'rbx_import_snapshots' => "CREATE TABLE {$p}rbx_import_snapshots (
  import_run_id bigint(20) unsigned NOT NULL,
  record_id varchar(32) NOT NULL,
  pre_image longtext DEFAULT NULL,
  PRIMARY KEY  (import_run_id,record_id)
) ENGINE=InnoDB $c;",
			'rbx_code_checks'      => "CREATE TABLE {$p}rbx_code_checks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game varchar(8) NOT NULL,
  checked_on datetime NOT NULL,
  checked_by varchar(64) NOT NULL,
  channels_checked text NOT NULL,
  result varchar(24) NOT NULL,
  note text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY game_checked (game,checked_on)
) ENGINE=InnoDB $c;",
			'rbx_reports'          => "CREATE TABLE {$p}rbx_reports (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  game varchar(8) NOT NULL DEFAULT '',
  record_id varchar(32) NOT NULL DEFAULT '',
  field varchar(64) NOT NULL DEFAULT '',
  page_url varchar(255) NOT NULL DEFAULT '',
  kind varchar(24) NOT NULL,
  text text NOT NULL,
  update_id varchar(32) NOT NULL DEFAULT '',
  dataset_version varchar(40) NOT NULL DEFAULT '',
  shown_value varchar(64) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'new',
  reason text DEFAULT NULL,
  handled_by bigint(20) unsigned NOT NULL DEFAULT 0,
  handled_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY record (record_id)
) ENGINE=InnoDB $c;",
		);
	}

	public static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	/** Creates or updates tables. Safe to run twice (dbDelta). */
	public static function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::ddl() as $sql ) {
			dbDelta( $sql );
		}
		update_option( 'rbx_data_db_version', RBX_DATA_DB_VERSION, false );
	}

	public static function needs_migration(): bool {
		return (string) get_option( 'rbx_data_db_version', '' ) !== RBX_DATA_DB_VERSION;
	}

	public static function installed(): bool {
		return '' !== (string) get_option( 'rbx_data_db_version', '' );
	}
}
