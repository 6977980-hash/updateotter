<?php
/**
 * Serves the brand root files (UX-54..58) from the theme's root-files/ folder, because the
 * Hostinger deployment only writes the theme folder. Stable URLs, long cache, no WP query.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const UPDATEOTTER_ROOT_FILES = array(
	'favicon.ico'           => 'image/x-icon',
	'favicon.svg'           => 'image/svg+xml',
	'favicon-96x96.png'     => 'image/png',
	'favicon-192x192.png'   => 'image/png',
	'apple-touch-icon.png'  => 'image/png',
	'icon-192.png'          => 'image/png',
	'icon-512.png'          => 'image/png',
	'icon-maskable-192.png' => 'image/png',
	'icon-maskable-512.png' => 'image/png',
	'site.webmanifest'      => 'application/manifest+json',
	'logo-organization.png' => 'image/png',
	'og-default.jpg'        => 'image/jpeg',
	'og-steal-an-egg.jpg'   => 'image/jpeg',
	'og-grow-a-garden.jpg'  => 'image/jpeg',
	'og-grow-a-garden-2.jpg' => 'image/jpeg',
	'og-fish-it.jpg'        => 'image/jpeg',
);

/** True when a root file ships with the theme (used by the Open Graph tags, UO-13). */
function updateotter_root_file_exists( string $name ): bool {
	return isset( UPDATEOTTER_ROOT_FILES[ $name ] ) && is_readable( UPDATEOTTER_DIR . '/root-files/' . $name );
}

function updateotter_serve_root_file(): void {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( '' === $path || ! str_starts_with( $path, $base ) ) {
		return;
	}
	$name = substr( $path, strlen( $base ) );
	if ( ! isset( UPDATEOTTER_ROOT_FILES[ $name ] ) ) {
		return;
	}
	$file = UPDATEOTTER_DIR . '/root-files/' . $name;
	if ( ! is_readable( $file ) ) {
		return;
	}
	$mtime = (int) filemtime( $file );
	$etag  = '"' . md5( $name . $mtime . filesize( $file ) ) . '"';
	status_header( 200 );
	header( 'Content-Type: ' . UPDATEOTTER_ROOT_FILES[ $name ] );
	header( 'Cache-Control: public, max-age=604800' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'ETag: ' . $etag );
	header( 'X-Content-Type-Options: nosniff' );
	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		status_header( 304 );
		exit;
	}
	header( 'Content-Length: ' . (string) filesize( $file ) );
	if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}
	exit;
}

// /favicon.ico has its own core hook; the rest are caught before the main query runs.
add_action( 'do_favicon', 'updateotter_serve_root_file', 0 );
add_action( 'parse_request', 'updateotter_serve_root_file', 0 );
