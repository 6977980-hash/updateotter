<?php
/**
 * Search results (UX-24): grouped by game, no ads, noindex (TECH-09/11 backstop).
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_robots',
	static function ( array $r ): array {
		return array(
			'noindex' => true,
			'follow'  => true,
		);
	}
);
get_header();
$updateotter_q = get_search_query();
?>
<main id="main" class="site-main is-prose">
	<h1>
	<?php
	/* translators: %s: search terms */
	echo esc_html( sprintf( __( 'Search results for "%s"', 'updateotter' ), $updateotter_q ) );
	?>
	</h1>
	<?php get_search_form(); ?>
	<?php
	if ( have_posts() ) :
		$updateotter_groups = array();
		while ( have_posts() ) :
			the_post();
			$updateotter_g = class_exists( '\RBX\Data\Render\Breadcrumbs' ) ? \RBX\Data\Render\Breadcrumbs::game_for( get_post() ) : null;
			$updateotter_groups[ $updateotter_g ? $updateotter_g->name() : __( 'Site', 'updateotter' ) ][] = get_post();
		endwhile;
		foreach ( $updateotter_groups as $updateotter_name => $updateotter_posts ) :
			?>
		<h2><?php echo esc_html( $updateotter_name ); ?></h2>
		<ul>
			<?php foreach ( $updateotter_posts as $updateotter_p ) : ?>
			<li><a href="<?php echo esc_url( (string) get_permalink( $updateotter_p ) ); ?>"><?php echo esc_html( get_the_title( $updateotter_p ) ); ?></a> <span class="rbx-chip"><?php echo esc_html( (string) get_post_type_object( $updateotter_p->post_type )->labels->singular_name ); ?></span></li>
			<?php endforeach; ?>
		</ul>
			<?php
		endforeach;
		the_posts_pagination();
	else :
		?>
		<p class="rbx-empty">
		<?php
		/* translators: %s: search terms */
		echo esc_html( sprintf( __( 'No results for "%s".', 'updateotter' ), $updateotter_q ) );
		?>
		<a href="<?php echo esc_url( home_url( '/report/?kind=missing_item' ) ); ?>"><?php esc_html_e( 'Report a missing item', 'updateotter' ); ?></a></p>
		<ul>
			<?php foreach ( updateotter_games() as $updateotter_game ) : ?>
			<li><a href="<?php echo esc_url( updateotter_game_url( $updateotter_game ) ); ?>"><?php echo esc_html( $updateotter_game->name() ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</main>
<?php
get_footer();
