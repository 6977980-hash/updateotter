<?php
/**
 * Bundled page copy (owner decision 2026-10-23: everything ships through git). The theme repo
 * holds the block markup of each site Page in `content/pages/{path}.html` (path as in the
 * Setup skeleton, `home.html` for the front page). After a deploy the copy is written into the
 * matching Page. A Page someone edited in WordPress after the last sync is left alone and
 * reported, so a hand edit is never overwritten. Pages keep their status; publishing is a
 * separate launch step (Phase 1c).
 *
 * A file whose Page does not exist yet (a new guide, say) can create it: its first line is
 * `<!-- uo:page {"title": "...", "template": "guide"} -->`. The Page is created as a draft under
 * its parent path (which must exist or be created earlier in the same sync), `template` becomes
 * `_rbx_template`, and the `publish` launch step publishes it. The header is not saved in the Page.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Content;

use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * State in option `rbx_bundled_pages`: hash, at, written, skipped (path => reason).
 */
final class BundledPages {

	public const HOOK   = 'rbx_bundled_pages';
	public const OPTION = 'rbx_bundled_pages';
	public const META   = '_rbx_bundled_hash';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'maybe_schedule' ), 21 );
		add_action( self::HOOK, array( self::class, 'sync' ) );
	}

	public static function dir(): string {
		return untrailingslashit( (string) apply_filters( 'rbx_data_bundled_pages_dir', get_template_directory() . '/content/pages' ) );
	}

	/** @return array<string, string> page path => file */
	public static function files(): array {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$rel = substr( (string) $f->getPathname(), strlen( $dir ) + 1 );
			if ( preg_match( '#^([a-z0-9-]+(?:/[a-z0-9-]+)*)\.html$#', $rel, $m ) ) {
				$out[ $m[1] ] = (string) $f->getPathname();
			}
		}
		ksort( $out );
		return $out;
	}

	public static function hash(): string {
		$h = hash_init( 'sha256' );
		foreach ( self::files() as $path => $file ) {
			hash_update( $h, $path . "\0" );
			hash_update_file( $h, $file );
		}
		return substr( hash_final( $h ), 0, 16 );
	}

	/** @return array<string, mixed> */
	public static function state(): array {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function maybe_schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) || ! self::files() ) {
			return;
		}
		if ( ( self::state()['hash'] ?? '' ) !== self::hash() ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	private const HEADER_RE = '/^<!--\s*uo:page\s+(\{.*?\})\s*-->/s';

	/**
	 * Creation header of a page file, or null.
	 *
	 * @return array{title: string, template: string, excerpt: string}|null
	 */
	private static function header( string $content ): ?array {
		if ( ! preg_match( self::HEADER_RE, $content, $m ) ) {
			return null;
		}
		$d = json_decode( $m[1], true );
		if ( ! is_array( $d ) || '' === trim( (string) ( $d['title'] ?? '' ) ) ) {
			return null;
		}
		return array(
			'title'    => trim( (string) $d['title'] ),
			'template' => (string) ( $d['template'] ?? '' ),
			'excerpt'  => trim( (string) ( $d['excerpt'] ?? '' ) ),
		);
	}

	/**
	 * Creates a missing Page as an empty draft that the sync then fills.
	 *
	 * @param array{title: string, template: string, excerpt: string} $head Header.
	 * @return \WP_Post|string Page, or the reason it was not created.
	 */
	private static function create( string $path, array $head ): \WP_Post|string {
		$slug   = basename( $path );
		$parent = 0;
		if ( str_contains( $path, '/' ) ) {
			$pp = get_page_by_path( dirname( $path ), OBJECT, 'page' );
			if ( ! $pp instanceof \WP_Post ) {
				return 'parent page ' . dirname( $path ) . ' does not exist';
			}
			$parent = $pp->ID;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'draft',
				'post_title'     => $head['title'],
				'post_name'      => $slug,
				'post_parent'    => $parent,
				'post_excerpt'   => $head['excerpt'],
				'post_content'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			ErrorLog::add( 'content', 'Bundled page ' . $path . ': ' . $id->get_error_message() );
			return $id->get_error_message();
		}
		if ( in_array( $head['template'], array( 'guide' ), true ) ) {
			update_post_meta( $id, '_rbx_template', $head['template'] );
		}
		// Empty and ours, so the normal sync below writes the copy.
		update_post_meta( $id, self::META, self::content_hash( '' ) );
		$page = get_post( $id );
		return $page instanceof \WP_Post ? $page : 'page not created';
	}

	/** Content hash used to spot hand edits. */
	private static function content_hash( string $content ): string {
		return substr( hash( 'sha256', str_replace( "\r\n", "\n", trim( $content ) ) ), 0, 16 );
	}

	/**
	 * Writes changed copy into the Pages.
	 *
	 * @return array<string, mixed> Saved state.
	 */
	public static function sync( bool $force = false ): array {
		$written = array();
		$skipped = array();
		foreach ( self::files() as $path => $file ) {
			$page_path = 'home' === $path && get_option( 'page_on_front' ) ? '' : $path;
			$page      = '' === $page_path ? get_post( (int) get_option( 'page_on_front' ) ) : get_page_by_path( $page_path, OBJECT, 'page' );
			$new  = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$head = self::header( $new );
			if ( null !== $head ) {
				$new = trim( (string) preg_replace( self::HEADER_RE, '', $new, 1 ) );
			}
			if ( ! $page instanceof \WP_Post && null !== $head && '' !== $page_path ) {
				$page = self::create( $page_path, $head );
				if ( is_string( $page ) ) {
					$skipped[ $path ] = $page;
					continue;
				}
			}
			if ( ! $page instanceof \WP_Post ) {
				$skipped[ $path ] = 'no such page (run Setup, Create draft pages)';
				continue;
			}
			$last = (string) get_post_meta( $page->ID, self::META, true );
			$cur  = self::content_hash( (string) $page->post_content );
			if ( self::content_hash( $new ) === $cur ) {
				update_post_meta( $page->ID, self::META, $cur );
				continue;
			}
			// Untouched skeleton (still has Phase 1b todo boxes and was never synced), or unchanged since our last write.
			// WordPress's own draft privacy page counts as a skeleton too.
			$skeleton = str_contains( (string) $page->post_content, 'rbx-todo' ) || ( (int) get_option( 'wp_page_for_privacy_policy' ) === $page->ID && 'draft' === $page->post_status );
			$ours     = ( '' === $last && $skeleton ) || $last === $cur;
			if ( ! $ours && ! $force ) {
				$skipped[ $path ] = 'edited in WordPress after the last sync; not overwritten';
				continue;
			}
			// Trusted repo markup: skip the kses pass a cron request (no user) would apply.
			kses_remove_filters();
			$r = wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => wp_slash( $new ),
				),
				true
			);
			kses_init_filters();
			if ( is_wp_error( $r ) ) {
				$skipped[ $path ] = $r->get_error_message();
				ErrorLog::add( 'content', 'Bundled page ' . $path . ': ' . $r->get_error_message() );
				continue;
			}
			$saved = get_post( $page->ID );
			update_post_meta( $page->ID, self::META, self::content_hash( (string) ( $saved ? $saved->post_content : $new ) ) );
			$written[] = $path;
		}
		$s = array(
			'hash'    => self::hash(),
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'written' => $written,
			'skipped' => $skipped,
		);
		update_option( self::OPTION, $s, false );
		return $s;
	}
}
