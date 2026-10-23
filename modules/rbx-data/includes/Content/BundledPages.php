<?php
/**
 * Bundled page copy (owner decision 2026-10-23: everything ships through git). The theme repo
 * holds the block markup of each site Page in `content/pages/{path}.html` (path as in the
 * Setup skeleton, `home.html` for the front page). After a deploy the copy is written into the
 * matching Page. A Page someone edited in WordPress after the last sync is left alone and
 * reported, so a hand edit is never overwritten. Pages keep their status; publishing is a
 * separate launch step (Phase 1c).
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
			if ( ! $page instanceof \WP_Post ) {
				$skipped[ $path ] = 'no such page (run Setup, Create draft pages)';
				continue;
			}
			$new  = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
