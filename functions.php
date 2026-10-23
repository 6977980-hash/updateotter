<?php
/**
 * UpdateOtter theme bootstrap. Standalone theme (owner decision 2026-10-23, replaces the
 * GeneratePress child of UX-31); same file layout and rules otherwise.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'UPDATEOTTER_VERSION', '1.0.0' );
define( 'UPDATEOTTER_DIR', __DIR__ );

foreach ( array( 'modules', 'setup', 'assets', 'head', 'root-files', 'prelaunch', 'launch', 'navigation', 'ads', 'template-tags' ) as $updateotter_file ) {
	require __DIR__ . '/inc/' . $updateotter_file . '.php';
}
