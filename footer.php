<?php
/**
 * Footer (UX-25, exact columns) with the POL-46 disclaimer, theme toggle and copyright.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;
$updateotter_links = updateotter_footer_links();
?>
<footer class="site-footer">
	<div class="container">
		<div class="footer-cols">
			<?php
			foreach ( array(
				'games' => __( 'Games', 'updateotter' ),
				'tools' => __( 'Tools', 'updateotter' ),
				'about' => __( 'About', 'updateotter' ),
				'legal' => __( 'Legal', 'updateotter' ),
			) as $updateotter_key => $updateotter_title ) :
				if ( ! $updateotter_links[ $updateotter_key ] ) {
					continue;
				}
				?>
			<nav class="footer-col" aria-label="<?php echo esc_attr( $updateotter_title ); ?>">
				<h2><?php echo esc_html( $updateotter_title ); ?></h2>
				<ul>
					<?php foreach ( $updateotter_links[ $updateotter_key ] as [ $updateotter_label, $updateotter_url ] ) : ?>
					<li>
						<?php if ( '' === $updateotter_url ) : ?>
						<button type="button" data-privacy-settings hidden><?php echo esc_html( $updateotter_label ); ?></button>
						<?php else : ?>
						<a href="<?php echo esc_url( $updateotter_url ); ?>"><?php echo esc_html( $updateotter_label ); ?></a>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<?php endforeach; ?>
		</div>
		<p class="footer-disclaimer"><?php echo esc_html( updateotter_disclaimer() ); ?></p>
		<p class="footer-disclaimer"><?php echo wp_kses( updateotter_license_line(), array( 'a' => array( 'href' => true, 'rel' => true ) ) ); ?></p>
		<div class="footer-bottom">
			<?php updateotter_theme_toggle(); ?>
			<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> UpdateOtter</p>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
