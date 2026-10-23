<?php
/**
 * Indonesian pages (Phase 3, TECH-20) without a translation plugin: every Indonesian page is a
 * normal WP Page under the top-level Page `id`, so `/id/steal-an-egg/calculator/` mirrors
 * `/steal-an-egg/calculator/`. Copy ships in `content/pages/id/...` like every other page.
 *
 * On an `/id/` request the locale is `id_ID` (theme and module strings come from the
 * `languages/*-id_ID.l10n.php` files), numbers use the `id` format (decimal comma), labels from
 * the game configs go through `content/i18n/id.json`, and links to a game page point at its
 * Indonesian twin when one is published. Each EN/ID pair gets hreflang `en`, `id` and
 * `x-default` (English), with return links. Item pages stay English only.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Language of the current request, Indonesian twins and hreflang.
 */
final class Lang {

	/** Language code, URL prefix and slug of the root Page of the Indonesian pages. */
	public const ID = 'id';

	private const MONTHS = array( 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des' );

	private const MONTHS_FULL = array( 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember' );

	public static function boot(): void {
		add_filter( 'language_attributes', array( self::class, 'html_lang' ) );
		add_action( 'wp_head', array( self::class, 'hreflang' ), 3 );
		add_action( 'wp', array( self::class, 'months' ) );
		if ( self::is_id() ) {
			add_filter( 'locale', static fn(): string => 'id_ID' );
		}
	}

	/** Request path relative to the site root, without slashes at either end. */
	private static function request_path(): string {
		$uri  = (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && '/' !== $base && str_starts_with( $uri, rtrim( $base, '/' ) ) ) {
			$uri = substr( $uri, strlen( rtrim( $base, '/' ) ) );
		}
		return trim( $uri, '/' );
	}

	/** True on a front-end request for an Indonesian page. */
	public static function is_id(): bool {
		static $id = null;
		if ( null === $id ) {
			$p  = self::request_path();
			$id = ! is_admin() && ! wp_doing_cron() && ( self::ID === $p || str_starts_with( $p, self::ID . '/' ) );
		}
		return $id;
	}

	/** Locale code for RBX\Data\Format\Number and the calculator JS. */
	public static function num(): string {
		return self::is_id() ? self::ID : 'en';
	}

	/** Translation of a config or data label (English => Indonesian in content/i18n/id.json). */
	public static function t( string $en ): string {
		if ( ! self::is_id() || '' === $en ) {
			return $en;
		}
		static $map = null;
		if ( null === $map ) {
			$file = get_template_directory() . '/content/i18n/id.json';
			$map  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$map  = is_array( $map ) ? $map : array();
		}
		$v = $map[ $en ] ?? null;
		return is_string( $v ) && '' !== $v ? $v : $en;
	}

	/**
	 * Game config with every `*__en` label translated (Indonesian requests only).
	 *
	 * @param array<string, mixed> $config Config.
	 * @return array<string, mixed>
	 */
	public static function localize( array $config ): array {
		if ( ! self::is_id() ) {
			return $config;
		}
		foreach ( $config as $k => $v ) {
			if ( is_array( $v ) ) {
				$config[ $k ] = self::localize( $v );
			} elseif ( is_string( $v ) && is_string( $k ) && preg_match( '/^(label|none_label|plural|help|output_unit)__en$/', $k ) ) {
				$config[ $k ] = self::t( $v );
			}
		}
		return $config;
	}

	/** The root Page `/id/`, or null. */
	private static function root(): ?\WP_Post {
		static $root = false;
		if ( false === $root ) {
			$p    = get_page_by_path( self::ID, OBJECT, 'page' );
			$root = $p instanceof \WP_Post && 0 === (int) $p->post_parent ? $p : null;
		}
		return $root;
	}

	public static function is_root( \WP_Post|int|null $post ): bool {
		$root = self::root();
		$id   = $post instanceof \WP_Post ? $post->ID : (int) $post;
		return null !== $root && $id === $root->ID;
	}

	/** True when the Page is the Indonesian root or below it. */
	public static function in_id( \WP_Post $post ): bool {
		if ( 'page' !== $post->post_type ) {
			return false;
		}
		$anc = get_post_ancestors( $post );
		return self::is_root( $anc ? (int) end( $anc ) : $post );
	}

	/** Parent of a Page as the English tree sees it: a top-level Indonesian page counts as top level. */
	public static function parent( \WP_Post $post ): int {
		$p = (int) $post->post_parent;
		return self::is_root( $p ) ? 0 : $p;
	}

	/** Ancestors of a Page without the Indonesian root, nearest first. @return int[] */
	public static function ancestors( \WP_Post $post ): array {
		return array_values( array_filter( get_post_ancestors( $post ), static fn( $a ) => ! self::is_root( (int) $a ) ) );
	}

	/** Path of a WP Page by path on the current request's language, falling back to English. */
	public static function path( string $path ): string {
		$path = trim( $path, '/' );
		if ( self::is_id() && '' !== $path ) {
			$p = get_page_by_path( self::ID . '/' . $path, OBJECT, 'page' );
			if ( $p instanceof \WP_Post && 'publish' === $p->post_status ) {
				return self::ID . '/' . $path;
			}
		}
		return $path;
	}

	/** Site URL of a path on the current request's language (English when no twin exists). */
	public static function url( string $path = '' ): string {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return self::is_id() && self::published( self::root() ) ? home_url( '/' . self::ID . '/' ) : home_url( '/' );
		}
		return home_url( '/' . self::path( $path ) . '/' );
	}

	private static function published( ?\WP_Post $p ): bool {
		return $p instanceof \WP_Post && 'publish' === $p->post_status;
	}

	/**
	 * English and Indonesian URLs of the current page, or null when it has no published twin.
	 *
	 * @return array{en: string, id: string}|null
	 */
	public static function pair(): ?array {
		if ( is_front_page() ) {
			$id = self::root();
			return self::published( $id ) ? array( 'en' => home_url( '/' ), 'id' => (string) get_permalink( $id ) ) : null;
		}
		$post = get_queried_object();
		if ( ! is_page() || ! $post instanceof \WP_Post || ! self::published( $post ) ) {
			return null;
		}
		$uri = get_page_uri( $post );
		if ( self::is_root( $post ) ) {
			$front = (int) get_option( 'page_on_front' );
			return $front ? array( 'en' => home_url( '/' ), 'id' => (string) get_permalink( $post ) ) : null;
		}
		if ( self::in_id( $post ) ) {
			$en = get_page_by_path( substr( $uri, strlen( self::ID ) + 1 ), OBJECT, 'page' );
			return self::published( $en ) ? array( 'en' => (string) get_permalink( $en ), 'id' => (string) get_permalink( $post ) ) : null;
		}
		$id = get_page_by_path( self::ID . '/' . $uri, OBJECT, 'page' );
		return self::published( $id ) ? array( 'en' => (string) get_permalink( $post ), 'id' => (string) get_permalink( $id ) ) : null;
	}

	/** TECH-20: one method only, HTML link tags, fully qualified, both directions. */
	public static function hreflang(): void {
		$pair = self::pair();
		if ( null === $pair ) {
			return;
		}
		echo '<link rel="alternate" hreflang="en" href="' . esc_url( $pair['en'] ) . "\">\n";
		echo '<link rel="alternate" hreflang="id" href="' . esc_url( $pair['id'] ) . "\">\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $pair['en'] ) . "\">\n";
	}

	public static function html_lang( string $attr ): string {
		return self::is_id() ? (string) preg_replace( '/lang="[^"]*"/', 'lang="' . self::ID . '"', $attr ) : $attr;
	}

	/** Current month and year for titles, e.g. "October 2026" or "Oktober 2026". */
	public static function month_year( ?int $ts = null ): string {
		$ts ??= time();
		return self::is_id() ? self::MONTHS_FULL[ (int) gmdate( 'n', $ts ) - 1 ] . ' ' . gmdate( 'Y', $ts ) : gmdate( 'F Y', $ts );
	}

	/** Indonesian month names for `j M Y` and `F Y` dates (no core language pack is installed). */
	public static function months(): void {
		global $wp_locale;
		if ( ! self::is_id() || ! $wp_locale instanceof \WP_Locale ) {
			return;
		}
		foreach ( self::MONTHS_FULL as $i => $m ) {
			$wp_locale->month[ zeroise( $i + 1, 2 ) ] = $m;
			$wp_locale->month_abbrev[ $m ]          = self::MONTHS[ $i ];
		}
	}
}
