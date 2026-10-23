<?php
/**
 * Plugin Name:       RBX Tools
 * Description:       Calculators for UpdateOtter game pages. Needs RBX Data.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Requires Plugins:  rbx-data
 * Author:            UpdateOtter
 * License:           GPL-2.0-or-later
 * Text Domain:       rbx-tools
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'RBX_TOOLS_VERSION', '1.0.1' );
define( 'RBX_TOOLS_FILE', __FILE__ );
define( 'RBX_TOOLS_DIR', __DIR__ );
// The UpdateOtter theme bundles this module and sets the URL itself.
defined( 'RBX_TOOLS_URL' ) || define( 'RBX_TOOLS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'RBX\\Tools\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Loaded from the theme after plugins_loaded has fired: boot right away.
if ( did_action( 'plugins_loaded' ) ) {
	\RBX\Tools\Plugin::boot();
} else {
	add_action( 'plugins_loaded', array( 'RBX\\Tools\\Plugin', 'boot' ) );
}
