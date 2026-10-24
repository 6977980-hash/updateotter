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
	// CR-19: no results for a typo ("pheonix") or an Indonesian word ("kode"): search the fixed words.
	$updateotter_query = $GLOBALS['wp_query'];
	$updateotter_fixed = '';
	if ( ! have_posts() && '' !== $updateotter_q && class_exists( '\RBX\Data\Export\SearchIndex' ) ) {
		$updateotter_fixed = \RBX\Data\Export\SearchIndex::suggest( $updateotter_q );
		if ( '' !== $updateotter_fixed ) {
			$updateotter_alt = new WP_Query(
				array(
					's'              => $updateotter_fixed,
					'post__not_in'   => (array) $updateotter_query->get( 'post__not_in' ),
					'posts_per_page' => (int) get_option( 'posts_per_page' ),
					'no_found_rows'  => true,
				)
			);
			if ( $updateotter_alt->have_posts() ) {
				$updateotter_query = $updateotter_alt;
				echo '<p class="rbx-note">' . esc_html( sprintf( /* translators: 1: what the visitor typed, 2: fixed search words */ __( 'No results for "%1$s". Showing results for "%2$s".', 'updateotter' ), $updateotter_q, $updateotter_fixed ) ) . '</p>';
			} else {
				$updateotter_fixed = '';
			}
		}
	}
	if ( $updateotter_query->have_posts() ) :
		$updateotter_groups = array();
		while ( $updateotter_query->have_posts() ) :
			$updateotter_query->the_post();
			$updateotter_g = class_exists( '\RBX\Data\Render\Breadcrumbs' ) ? \RBX\Data\Render\Breadcrumbs::game_for( get_post() ) : null;
			$updateotter_groups[ $updateotter_g ? $updateotter_g->name() : __( 'Site', 'updateotter' ) ][] = get_post();
		endwhile;
		foreach ( $updateotter_groups as $updateotter_name => $updateotter_posts ) :
			?>
		<h2><?php echo esc_html( $updateotter_name ); ?></h2>
		<ul>
			<?php foreach ( $updateotter_posts as $updateotter_p ) : ?>
			<li><a href="<?php echo esc_url( (string) get_permalink( $updateotter_p ) ); ?>"><?php echo esc_html( get_the_title( $updateotter_p ) ); ?></a> <span class="rbx-chip"><?php echo esc_html( updateotter_search_label( $updateotter_p ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>
			<?php
		endforeach;
		if ( '' === $updateotter_fixed ) {
			the_posts_pagination();
		}
		wp_reset_postdata();
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
