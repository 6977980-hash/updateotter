<?php
/**
 * Title: FAQ section
 * Slug: updateotter/faq
 * Categories: updateotter
 * Description: Questions as details blocks. No FAQPage markup (TECH-22).
 *
 * @package UpdateOtter
 */

?>
<!-- wp:heading -->
<h2 class="wp-block-heading" id="faq"><?php esc_html_e( 'Questions', 'updateotter' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:details {"className":"uo-faq"} -->
<details class="wp-block-details uo-faq"><summary><?php esc_html_e( 'Question in the words players use?', 'updateotter' ); ?></summary><!-- wp:paragraph -->
<p><?php esc_html_e( 'Short answer first. Then one or two lines of detail.', 'updateotter' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details {"className":"uo-faq"} -->
<details class="wp-block-details uo-faq"><summary><?php esc_html_e( 'Second question?', 'updateotter' ); ?></summary><!-- wp:paragraph -->
<p><?php esc_html_e( 'Short answer first.', 'updateotter' ); ?></p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->
