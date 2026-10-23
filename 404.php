<?php
/**
 * 404 (REV-G04): plain help, search, game hubs, no ads.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main is-prose">
	<h1><?php esc_html_e( 'Page not found', 'updateotter' ); ?></h1>
	<p><?php esc_html_e( 'This page does not exist or was moved. Try a search or pick a game.', 'updateotter' ); ?></p>
	<?php get_search_form(); ?>
	<ul>
		<?php foreach ( updateotter_games() as $updateotter_game ) : ?>
		<li><a href="<?php echo esc_url( updateotter_game_url( $updateotter_game ) ); ?>"><?php echo esc_html( $updateotter_game->name() ); ?></a></li>
		<?php endforeach; ?>
		<li><a href="<?php echo esc_url( updateotter_url() ); ?>"><?php esc_html_e( 'Home', 'updateotter' ); ?></a></li>
	</ul>
</main>
<?php
get_footer();
