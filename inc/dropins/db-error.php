<?php
/**
 * UpdateOtter db-error drop-in (GR-02). WordPress loads wp-content/db-error.php when it cannot
 * reach the database. The default page answers 500, which crawlers read as a broken site; this
 * one answers 503 with Retry-After, the "busy, come back soon" signal. Copied into wp-content
 * by the theme's `db_error` launch step (inc/launch.php); edit this copy, not the deployed one.
 *
 * @package UpdateOtter
 */

if ( ! headers_sent() ) {
	http_response_code( 503 );
	header( 'Retry-After: 120' );
	header( 'Cache-Control: no-store' );
	header( 'X-Robots-Tag: noindex' );
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
