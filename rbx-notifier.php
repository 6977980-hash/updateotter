<?php
/**
 * Plugin Name:       RBX Notifier
 * Description:       Watches Roblox for game updates, alerts the team and runs the per-update checklist. Needs RBX Data.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Requires Plugins:  rbx-data
 * Author:            UpdateOtter
 * License:           GPL-2.0-or-later
 * Text Domain:       rbx-notifier
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'RBX_NOTIFIER_VERSION', '1.0.0' );
define( 'RBX_NOTIFIER_DB_VERSION', '1' );
define( 'RBX_NOTIFIER_FILE', __FILE__ );
define( 'RBX_NOTIFIER_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'RBX\\Notifier\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'RBX\\Notifier\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RBX\\Notifier\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'RBX\\Notifier\\Plugin', 'boot' ) );
