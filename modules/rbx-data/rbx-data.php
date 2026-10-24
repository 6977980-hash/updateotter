<?php
/**
 * Plugin Name:       RBX Data
 * Description:       Game datasets for UpdateOtter: Google Sheet import, validation, changelog, page gate, exports and data rendering.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            UpdateOtter
 * License:           GPL-2.0-or-later
 * Text Domain:       rbx-data
 * Domain Path:       /languages
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'RBX_DATA_VERSION', '1.0.0' );
define( 'RBX_DATA_DB_VERSION', '1' );
define( 'RBX_DATA_FILE', __FILE__ );
define( 'RBX_DATA_DIR', __DIR__ );
// The UpdateOtter theme bundles this module and sets the URL itself.
defined( 'RBX_DATA_URL' ) || define( 'RBX_DATA_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'RBX\\Data\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Fire only for a plugin install; the theme copy uses the init/switch_theme hooks in inc/modules.php.
register_activation_hook( __FILE__, array( 'RBX\\Data\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RBX\\Data\\Plugin', 'deactivate' ) );

\RBX\Data\Plugin::instance()->boot();
