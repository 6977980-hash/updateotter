<?php
/**
 * Site hardening owned by rbx-data (ENG-53..56, TECH-08): no comments, no XML-RPC methods,
 * no user enumeration, no Application Passwords, security headers, 2FA enrolment nudge.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks only; no options.
 */
final class Hardening {

	public const HSTS_MAX_AGE = 300;

	public static function boot(): void {
		add_filter( 'comments_open', '__return_false', 99 );
		add_filter( 'pings_open', '__return_false', 99 );
		add_filter( 'comments_array', '__return_empty_array', 99 );
		add_action( 'pre_comment_on_post', array( self::class, 'deny_comment' ) );
		add_filter( 'rest_endpoints', array( self::class, 'rest_endpoints' ) );
		add_filter( 'rest_authentication_errors', array( self::class, 'users_rest' ), 99 );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		add_action( 'template_redirect', array( self::class, 'author_enum' ), 0 );
		add_filter( 'wp_headers', array( self::class, 'headers' ) );
		add_action( 'admin_init', array( self::class, 'require_2fa' ) );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_action( 'template_redirect', array( self::class, 'comment_feed_404' ), 1 );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		// UO-63: no WordPress version in feeds, OPML or anywhere else the_generator is printed.
		add_filter( 'the_generator', '__return_empty_string' );
	}

	public static function deny_comment(): void {
		wp_die( esc_html__( 'Comments are closed.', 'rbx-data' ), '', array( 'response' => 403 ) );
	}

	/** @param array<string, mixed> $endpoints REST endpoints. */
	public static function rest_endpoints( array $endpoints ): array {
		if ( ! is_user_logged_in() ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( str_starts_with( (string) $route, '/wp/v2/comments' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}

	/**
	 * Unauthenticated /wp/v2/users → 401 (ENG-26).
	 *
	 * @param mixed $result Auth result.
	 */
	public static function users_rest( $result ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}
		$route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		}
		if ( preg_match( '#/wp/v2/users#', $route ) ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You are not currently logged in.', 'rbx-data' ), array( 'status' => 401 ) );
		}
		return $result;
	}

	/** /?author=N and author archives → 404 (TECH-08). */
	public static function author_enum(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['author'] ) || is_author() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function comment_feed_404(): void {
		if ( is_comment_feed() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
		}
	}

	/**
	 * @param array<string, string> $h Headers.
	 * @return array<string, string>
	 */
	public static function headers( array $h ): array {
		$h['X-Content-Type-Options'] = 'nosniff';
		$h['Referrer-Policy']        = 'strict-origin-when-cross-origin';
		$h['X-Frame-Options']        = 'SAMEORIGIN';
		$h['Permissions-Policy']     = 'camera=(), microphone=(), geolocation=(), interest-cohort=()';
		// TECH-02 phased HSTS: 300 s in week 1, raised to 31536000 after 7 clean days. No includeSubDomains or preload.
		if ( is_ssl() ) {
			$h['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE;
		}
		return $h;
	}

	/**
	 * Editors without a Two Factor provider are sent to their profile (ENG-55), when the
	 * Two Factor plugin is active. Without the plugin this does nothing.
	 */
	public static function require_2fa(): void {
		if ( wp_doing_ajax() || ! class_exists( '\Two_Factor_Core' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( ! method_exists( '\Two_Factor_Core', 'is_user_using_two_factor' ) || \Two_Factor_Core::is_user_using_two_factor( get_current_user_id() ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		if ( apply_filters( 'rbx_data_require_2fa', true ) ) {
			wp_safe_redirect( admin_url( 'profile.php#two-factor-options' ) );
			exit;
		}
	}
}
