<?php
/**
 * Uninstall: removes options, roles and caps. Tables and exports are dropped only
 * when RBX_DATA_UNINSTALL_DROP is true (ENG-27).
 *
 * @package RBX\Data
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'rbx_data_db_version', 'rbx_config_hash', 'rbx_import_lock', 'rbx_sheet_urls', 'rbx_error_log', 'rbx_exports', 'rbx_report_salt', 'rbx_game_overrides', 'rbx_backup_confirmed_on' ) as $rbx_option ) {
	delete_option( $rbx_option );
}

remove_role( 'rbx_data_editor' );
$rbx_admin = get_role( 'administrator' );
if ( $rbx_admin ) {
	foreach ( array( 'rbx_import', 'rbx_view_health', 'rbx_reports_manage', 'rbx_codes_log', 'rbx_notifier_view', 'rbx_notifier_ack' ) as $rbx_cap ) {
		$rbx_admin->remove_cap( $rbx_cap );
	}
}

if ( defined( 'RBX_DATA_UNINSTALL_DROP' ) && RBX_DATA_UNINSTALL_DROP ) {
	foreach ( array( 'rbx_records', 'rbx_changelog', 'rbx_import_snapshots', 'rbx_import_runs', 'rbx_code_checks', 'rbx_reports' ) as $rbx_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $rbx_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
