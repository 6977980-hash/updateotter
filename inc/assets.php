<?php
/**
 * Styles and scripts. One theme stylesheet (assets/css/site.css, built from the four
 * source files by tools/build-css.sh) keeps non-tool pages at ≤ 3 CSS files (TECH-37).
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

function updateotter_ver( string $rel ): string {
	$f = UPDATEOTTER_DIR . '/' . $rel;
	return is_readable( $f ) ? (string) filemtime( $f ) : UPDATEOTTER_VERSION;
}

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'updateotter', get_theme_file_uri( 'assets/css/site.css' ), array(), updateotter_ver( 'assets/css/site.css' ) );
		wp_register_script_module( 'updateotter', get_theme_file_uri( 'assets/js/theme.js' ), array(), updateotter_ver( 'assets/js/theme.js' ) );
		wp_enqueue_script_module( 'updateotter' );
		// Classic theme helper styles are not used.
		wp_dequeue_style( 'classic-theme-styles' );
		// theme.json presets are not used; tokens live in site.css.
		wp_dequeue_style( 'global-styles' );
		wp_dequeue_style( 'core-block-supports' );
	},
	20
);
remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );

// Only load core block CSS for blocks that are on the page.
add_filter( 'should_load_separate_core_block_assets', '__return_true' );

// No jQuery on the front end (TECH-29).
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_script( 'jquery' );
			wp_deregister_script( 'jquery' );
		}
	},
	100
);
