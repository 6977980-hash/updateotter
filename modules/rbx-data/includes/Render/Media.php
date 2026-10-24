<?php
/**
 * MED file names → media library attachments (DATA-09: "import matches by file name").
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Map kept in option `rbx_media_map` (basename => attachment ID), rebuilt after each import and
 * from Data health; new uploads are added as they happen.
 */
final class Media {

	/** @var array<string, int>|null */
	private static ?array $map = null;

	public static function boot(): void {
		add_action( 'add_attachment', array( self::class, 'added' ) );
		add_action( 'delete_attachment', array( self::class, 'sync' ) );
		add_action( 'after_setup_theme', array( self::class, 'sizes' ) );
	}

	/** Item icons (96 @2x crop-free) and cards; featured images come from the theme. */
	public static function sizes(): void {
		add_image_size( 'rbx-icon', 192, 192 );
		add_image_size( 'rbx-card', 512, 512 );
	}

	/** @return array<string, int> */
	public static function map(): array {
		if ( null === self::$map ) {
			$m         = get_option( 'rbx_media_map', null );
			self::$map = is_array( $m ) ? $m : self::sync();
		}
		return self::$map;
	}

	/** @return array<string, int> */
	public static function sync(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_value FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_wp_attached_file' ), ARRAY_A );
		$map  = array();
		foreach ( $rows as $r ) {
			$map[ strtolower( basename( (string) $r['meta_value'] ) ) ] = (int) $r['post_id'];
		}
		update_option( 'rbx_media_map', $map, false );
		self::$map = $map;
		return $map;
	}

	public static function added( int $id ): void {
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return;
		}
		$map                                       = self::map();
		$map[ strtolower( basename( $file ) ) ]    = $id;
		self::$map                                 = $map;
		update_option( 'rbx_media_map', $map, false );
	}

	public static function id( string $file_name ): int {
		return self::map()[ strtolower( $file_name ) ] ?? 0;
	}

	public static function url( string $file_name ): string {
		$id = self::id( $file_name );
		return $id ? (string) wp_get_attachment_url( $id ) : '';
	}

	/**
	 * Responsive image with width/height (TECH-40). Uses rbx-icon/rbx-card sizes when present.
	 */
	public static function img( string $file_name, string $alt, int $size, string $class = '', bool $lazy = false ): string {
		$id = self::id( $file_name );
		if ( ! $id ) {
			return '';
		}
		// UO-57: small list icons load lazily at their shown size (40 px), and no icon takes
		// fetchpriority=high from WordPress, because the LCP on these pages is text.
		$icon = $size < 96;
		$attr = array(
			'class'         => $class,
			'alt'           => $alt,
			'decoding'      => 'async',
			'loading'       => $lazy || $icon ? 'lazy' : 'eager',
			'sizes'         => ( $icon ? min( $size, 40 ) : $size ) . 'px',
			'fetchpriority' => $size <= 96 ? 'low' : 'auto',
		);
		$name = $size <= 96 ? 'rbx-icon' : ( $size <= 256 ? 'rbx-card' : 'large' );
		$html = wp_get_attachment_image( $id, has_image_size( $name ) ? $name : 'medium', false, $attr );
		return (string) $html;
	}
}
