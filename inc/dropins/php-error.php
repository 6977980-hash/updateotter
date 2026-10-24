<?php
/**
 * UpdateOtter php-error drop-in (CR-05). WordPress loads wp-content/php-error.php instead of its
 * "There has been a critical error" page (HTTP 500) when a request ends in a PHP fatal error.
 * On 24 Oct one pet page answered 500 for a moment while a deploy was warming the cache; a 500
 * tells crawlers the page is broken, a 503 with Retry-After says "busy, come back soon".
 *
 * This file runs inside WP_Fatal_Error_Handler::display_error_template(), so `$error` (from
 * error_get_last()) is in scope. It must not depend on the theme or its modules: the fatal may
 * have come from them. It also writes the error to the `updateotter_fatal_log` option (newest 20,
 * shown on GET /wp-json/rbx/v1/launch) and asks OPcache to re-read theme files that changed on
 * disk, so a half-deployed mix of old and new files heals on the next request.
 * Copied into wp-content by the theme's `php_error` launch step (inc/launch.php); edit this copy,
 * not the deployed one.
 *
 * @package UpdateOtter
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.Security.ValidatedSanitizedInput

$uo_err = isset( $error ) && is_array( $error ) ? $error : (array) error_get_last();
$uo_uri = substr( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 0, 200 );

try {
	if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
		$uo_root = defined( 'ABSPATH' ) ? ABSPATH : '';
		$uo_log  = get_option( 'updateotter_fatal_log', array() );
		$uo_log  = is_array( $uo_log ) ? $uo_log : array();
		array_unshift(
			$uo_log,
			array(
				't'    => gmdate( 'c' ),
				'uri'  => strtok( $uo_uri, '?' ),
				'msg'  => substr( str_replace( $uo_root, '', (string) ( $uo_err['message'] ?? '' ) ), 0, 400 ),
				'file' => str_replace( $uo_root, '', (string) ( $uo_err['file'] ?? '' ) ),
				'line' => (int) ( $uo_err['line'] ?? 0 ),
			)
		);
		update_option( 'updateotter_fatal_log', array_slice( $uo_log, 0, 20 ), false );
	}
} catch ( Throwable $uo_e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
	// Logging must never stop the 503 page.
}

// Re-read only theme files whose mtime is newer than the cached copy (force = false).
if ( function_exists( 'opcache_invalidate' ) && defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR . '/themes/updateotter' ) ) {
	try {
		$uo_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR . '/themes/updateotter', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $uo_it as $uo_file ) {
			if ( 'php' === $uo_file->getExtension() ) {
				@opcache_invalidate( $uo_file->getPathname(), false );
			}
		}
	} catch ( Throwable $uo_e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		// A restricted OPcache API is fine: the files expire on their own.
	}
}

// Drop the half-rendered page so the visitor gets one clean answer.
while ( ob_get_level() > 0 ) {
	@ob_end_clean();
}

if ( ! headers_sent() ) {
	// A full status line: WordPress may already have set one (404, 200), which http_response_code() alone does not replace on every server.
	$uo_proto = (string) ( $_SERVER['SERVER_PROTOCOL'] ?? '' );
	header( ( in_array( $uo_proto, array( 'HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3' ), true ) ? $uo_proto : 'HTTP/1.1' ) . ' 503 Service Unavailable', true, 503 );
	header( 'Retry-After: 120' );
	header( 'Cache-Control: no-store' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-Robots-Tag: noindex' );
}

if ( str_contains( $uo_uri, '/wp-json/' ) || str_contains( $uo_uri, 'rest_route=' ) ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
	}
	echo '{"code":"busy","message":"Busy right now. Please try again in a minute or two.","data":{"status":503}}';
	return;
}

if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=utf-8' );
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Back in a moment | UpdateOtter</title>
<style>body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#fff;color:#1a1a1a}main{max-width:32rem;margin:15vh auto;padding:0 16px}@media (prefers-color-scheme:dark){body{background:#121212;color:#eee}}</style>
</head>
<body>
<main>
<h1>Back in a moment</h1>
<p>UpdateOtter is busy right now. Please try again in a minute or two.</p>
</main>
</body>
</html>
