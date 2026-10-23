<?php
/**
 * Update page template (CONT-26). Override in a theme with rbx/update.php.
 *
 * @package RBX\Data
 */

defined( 'ABSPATH' ) || exit;

get_header();
while ( have_posts() ) :
	the_post();
	$rbx_ctx = \RBX\Data\Render\Item::context( get_the_ID() );
	?>
	<main id="main" class="site-main rbx-main">
		<?php
		do_action( 'updateotter_breadcrumbs' );
		if ( null === $rbx_ctx ) {
			echo '<h1>' . esc_html( get_the_title() ) . '</h1>';
			the_content();
		} else {
			ob_start();
			the_content();
			$rbx_editorial = (string) ob_get_clean();
			// Every part is escaped inside the renderer.
			echo \RBX\Data\Render\Update::render( $rbx_ctx, $rbx_editorial ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</main>
	<?php
endwhile;
get_footer();
