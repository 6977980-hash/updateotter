<?php
/**
 * Pre-launch lock (live-site build, owner decision 2026-10-23): while the option
 * `rbx_prelaunch_lock` is on (default on), logged-out visitors get a short noindex holding page,
 * robots.txt disallows everything and every response carries X-Robots-Tag noindex.
 * Logged-in users see the real site. Phase 1c turns it off (RBX Data → Setup, or `wp rbx prelaunch off`).
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

function updateotter_locked(): bool {
	return (bool) get_option( 'rbx_prelaunch_lock', 1 );
}

add_action(
	'send_headers',
	static function (): void {
		if ( updateotter_locked() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}
);

add_filter(
	'wp_robots',
	static function ( array $r ): array {
		if ( updateotter_locked() ) {
			unset( $r['index'], $r['follow'], $r['max-image-preview'] );
			$r['noindex']  = true;
			$r['nofollow'] = true;
		}
		return $r;
	},
	999
);
add_filter(
	'rank_math/frontend/robots',
	static function ( array $r ): array {
		if ( updateotter_locked() ) {
			$r['index']  = 'noindex';
			$r['follow'] = 'nofollow';
		}
		return $r;
	},
	999
);

add_filter(
	'robots_txt',
	static function (): string {
		if ( updateotter_locked() ) {
			return "User-agent: *\nDisallow: /\n";
		}
		// TECH-09 exact content once live; replaces the WordPress and Rank Math defaults.
		return "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /*?s=\nDisallow: /search/\nDisallow: /*?*replytocom=\n\nSitemap: " . home_url( '/sitemap_index.xml' ) . "\n";
	},
	999
);

add_action(
	'template_redirect',
	static function (): void {
		if ( ! updateotter_locked() || is_user_logged_in() || is_robots() || is_favicon() ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=utf-8' );
		get_template_part( 'parts/holding' );
		exit;
	},
	0
);
