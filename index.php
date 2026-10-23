<?php
/**
 * Fallback template (posts, archives).
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main is-prose">
	<?php do_action( 'updateotter_breadcrumbs' ); ?>
	<?php if ( is_singular() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry' ); ?>>
			<?php updateotter_page_head(); ?>
			<div class="entry-content"><?php the_content(); ?></div>
		</article>
		<?php endwhile; ?>
	<?php else : ?>
		<h1><?php echo esc_html( wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
		<?php if ( have_posts() ) : ?>
		<ul class="rbx-updates">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
			<li class="rbx-update-card"><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><p class="rbx-meta"><time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d' ) ); ?>"><?php echo esc_html( get_the_date( 'j M Y' ) ); ?></time></p></li>
			<?php endwhile; ?>
		</ul>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
		<p class="rbx-empty"><?php esc_html_e( 'Nothing here yet.', 'updateotter' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</main>
<?php
get_footer();
