<?php
/**
 * Title: Update summary
 * Slug: updateotter/update-summary
 * Categories: updateotter
 * Description: Human summary at the top of an update page; the change list comes from data.
 *
 * @package UpdateOtter
 */

?>
<!-- wp:group {"className":"uo-box uo-box--update","layout":{"type":"default"}} -->
<div class="wp-block-group uo-box uo-box--update"><!-- wp:heading -->
<h2 class="wp-block-heading" id="summary"><?php esc_html_e( 'What this update means for you', 'updateotter' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><?php esc_html_e( 'The biggest change, in one line.', 'updateotter' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'What to do first after updating.', 'updateotter' ); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e( 'What we have not tested yet.', 'updateotter' ); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
