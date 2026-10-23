<?php
/**
 * Site header (UX-20): logo, game switcher, search, menu. Not sticky.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;
$updateotter_game = updateotter_game();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Skip to content', 'updateotter' ); ?></a>
<?php require UPDATEOTTER_DIR . '/assets/svg/icons.svg'; ?>
<header class="site-header">
	<div class="container site-header__bar">
		<a class="site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'UpdateOtter home', 'updateotter' ); ?>"><?php updateotter_logo(); ?></a>
		<?php if ( count( updateotter_games() ) > 0 ) : ?>
		<button type="button" class="icon-btn" aria-expanded="false" aria-controls="game-switcher" data-disclosure>
			<?php echo updateotter_icon( 'gamepad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="icon-btn__label"><?php echo esc_html( $updateotter_game ? $updateotter_game->short_name() : __( 'Games', 'updateotter' ) ); ?></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Choose a game', 'updateotter' ); ?></span>
			<?php echo updateotter_icon( 'chevron-down', 'icon--sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
		<?php endif; ?>
		<a class="icon-btn" href="<?php echo esc_url( home_url( '/?s=' ) ); ?>" data-search-open aria-controls="site-search" aria-expanded="false">
			<?php echo updateotter_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="icon-btn__label"><?php esc_html_e( 'Search', 'updateotter' ); ?></span>
		</a>
		<button type="button" class="icon-btn desktop-hide" aria-expanded="false" aria-controls="site-menu" data-disclosure aria-label="<?php esc_attr_e( 'Menu', 'updateotter' ); ?>">
			<?php echo updateotter_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</div>
	<?php
	updateotter_game_switcher_panel();
	updateotter_mobile_menu_panel();
	get_template_part( 'parts/search-overlay' );
	?>
</header>
<?php updateotter_game_menu(); ?>
