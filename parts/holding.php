<?php
/**
 * Holding page shown while the pre-launch lock is on.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>UpdateOtter</title>
<link rel="icon" href="<?php echo esc_url( home_url( '/favicon.svg' ) ); ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?php echo esc_url( get_theme_file_uri( 'assets/css/site.css' ) . '?v=' . updateotter_ver( 'assets/css/site.css' ) ); ?>">
</head>
<body>
<main class="prelaunch">
	<p class="site-logo prelaunch__logo"><?php updateotter_logo(); ?></p>
	<h1><?php esc_html_e( 'Coming soon', 'updateotter' ); ?></h1>
	<p><?php esc_html_e( 'UpdateOtter is a fan-made site with calculators and wikis for Roblox games. We are checking our data before we open.', 'updateotter' ); ?></p>
	<p class="footer-disclaimer"><?php echo esc_html( updateotter_disclaimer() ); ?></p>
</main>
</body>
</html>
