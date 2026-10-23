<?php
/**
 * Theme supports, cleanup and small policies (POL-45, TECH-40).
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	static function (): void {
		load_theme_textdomain( 'updateotter', UPDATEOTTER_DIR . '/languages' );
		// Direct load as well: the just-in-time loader caches the folder listing, so a new file can be missed.
		load_textdomain( 'updateotter', UPDATEOTTER_DIR . '/languages/updateotter-' . determine_locale() . '.mo' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_editor_style( array( 'assets/css/tokens.css', 'assets/css/base.css', 'assets/css/components.css' ) );
		remove_theme_support( 'core-block-patterns' );
		// Featured image sizes for update posts (TECH-40, REV C-23).
		add_image_size( 'rbx-og', 1200, 630, true );
		add_image_size( 'rbx-feat-169', 1200, 675, true );
		add_image_size( 'rbx-feat-43', 1200, 900, true );
		add_image_size( 'rbx-feat-11', 1200, 1200, true );
		add_image_size( 'rbx-hero', 768, 0 );
	}
);

// Unused default sizes (TECH-40).
add_filter(
	'intermediate_image_sizes_advanced',
	static function ( array $sizes ): array {
		unset( $sizes['medium_large'], $sizes['1536x1536'], $sizes['2048x2048'] );
		return $sizes;
	}
);

// No emoji script, no Gravatar, no oEmbed discovery noise (POL-45).
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );
add_filter( 'emoji_svg_url', '__return_false' );
add_filter( 'option_show_avatars', '__return_false' );
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
add_filter(
	'wp_resource_hints',
	static function ( array $urls, string $type ): array {
		return 'dns-prefetch' === $type ? array_values( array_filter( $urls, static fn( $u ) => ! str_contains( (string) ( is_array( $u ) ? ( $u['href'] ?? '' ) : $u ), 's.w.org' ) ) ) : $urls;
	},
	10,
	2
);

// WordPress Site Icon stays unset: icons come from the root files (UX-54).
add_filter( 'get_site_icon_url', '__return_empty_string' );

// Block patterns (UX-34): FAQ, how we calculated, comparison table, scam warning, update summary.
add_action(
	'init',
	static function (): void {
		register_block_pattern_category( 'updateotter', array( 'label' => __( 'UpdateOtter', 'updateotter' ) ) );
	}
);

// No shortlink HTTP header either (UO-26); the head tag is removed above.
remove_action( 'template_redirect', 'wp_shortlink_header', 11 );

// A mistyped or missing URL is a 404, never a 301 to the nearest slug of another game (UO-15).
add_filter( 'do_redirect_guess_404_permalink', '__return_false' );

/**
 * Archives are not pages of this site (UO-14): category, tag, date and author archives, post type
 * archives and the blog index (where /?post_type=rbx_pet lands) are real 404s, not soft ones.
 * Feeds and search stay as they are.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( is_feed() || is_search() || is_admin() || is_404() ) {
			return;
		}
		if ( is_category() || is_tag() || is_tax() || is_date() || is_author() || is_post_type_archive() || ( is_home() && ! is_front_page() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	0
);
