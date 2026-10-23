<?php
/**
 * 404 (REV-G04): plain help, search, game hubs, no ads. A game folder in the URL gets that game's
 * hub first, and popular tools are listed (UO-39).
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;

$updateotter_path    = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$updateotter_segs    = explode( '/', $updateotter_path );
$updateotter_first   = 'id' === $updateotter_segs[0] ? (string) ( $updateotter_segs[1] ?? '' ) : $updateotter_segs[0];
$updateotter_guess   = null;
$updateotter_popular = array();
foreach ( updateotter_games() as $updateotter_g ) {
	if ( '' !== $updateotter_first && $updateotter_g->folder() === $updateotter_first ) {
		$updateotter_guess = $updateotter_g;
	}
	$updateotter_tools = $updateotter_g->tools();
	if ( $updateotter_tools ) {
		/* translators: %s: short game name */
		$updateotter_popular[] = array( sprintf( __( '%s calculator', 'updateotter' ), $updateotter_g->short_name() ), updateotter_game_url( $updateotter_g, (string) ( $updateotter_tools[0]['path'] ?? $updateotter_tools[0]['key'] ) ) );
	}
	if ( $updateotter_g->section( 'codes' ) ) {
		/* translators: %s: short game name */
		$updateotter_popular[] = array( sprintf( __( '%s codes', 'updateotter' ), $updateotter_g->short_name() ), updateotter_game_url( $updateotter_g, 'codes' ) );
	}
	if ( $updateotter_g->section( 'tier-list' ) ) {
		/* translators: %s: short game name */
		$updateotter_popular[] = array( sprintf( __( '%s tier list', 'updateotter' ), $updateotter_g->short_name() ), updateotter_game_url( $updateotter_g, 'tier-list' ) );
	}
}

get_header();
?>
<main id="main" class="site-main is-prose not-found">
	<h1><?php esc_html_e( 'Page not found', 'updateotter' ); ?></h1>
	<p><?php esc_html_e( 'This page does not exist or was moved. Try a search or pick a game.', 'updateotter' ); ?></p>
	<?php if ( $updateotter_guess ) : ?>
	<p class="not-found__guess"><a class="button" href="<?php echo esc_url( updateotter_game_url( $updateotter_guess ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: game name */ __( 'Go to the %s hub', 'updateotter' ), $updateotter_guess->name() ) ); ?></a></p>
	<?php endif; ?>
	<?php get_search_form(); ?>
	<h2><?php esc_html_e( 'Popular pages', 'updateotter' ); ?></h2>
	<ul class="not-found__links">
		<?php foreach ( $updateotter_popular as [ $updateotter_label, $updateotter_url ] ) : ?>
		<li><a href="<?php echo esc_url( $updateotter_url ); ?>"><?php echo esc_html( $updateotter_label ); ?></a></li>
		<?php endforeach; ?>
	</ul>
	<h2><?php esc_html_e( 'Games', 'updateotter' ); ?></h2>
	<ul class="not-found__links">
		<?php foreach ( updateotter_games() as $updateotter_game ) : ?>
		<li><a href="<?php echo esc_url( updateotter_game_url( $updateotter_game ) ); ?>"><?php echo esc_html( $updateotter_game->name() ); ?></a></li>
		<?php endforeach; ?>
		<li><a href="<?php echo esc_url( updateotter_url() ); ?>"><?php esc_html_e( 'Home', 'updateotter' ); ?></a></li>
	</ul>
</main>
<?php
get_footer();
