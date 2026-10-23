<?php
/**
 * WP Pages: hubs, lists, tools, codes, updates, home and trust pages. Data comes from
 * rbx/data-section blocks inside the content.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main<?php echo updateotter_is_site_page() ? ' is-prose' : ''; ?>">
	<?php
	do_action( 'updateotter_breadcrumbs' );
	while ( have_posts() ) :
		the_post();
		?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry' ); ?>>
		<?php updateotter_page_head(); ?>
		<div class="entry-content">
			<?php the_content(); ?>
		</div>
		<?php do_action( 'updateotter_ad_slot', 'end' ); ?>
	</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
