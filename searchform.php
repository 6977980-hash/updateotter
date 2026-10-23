<?php
/**
 * Search form (works without JS).
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label for="search-page-q"><?php esc_html_e( 'Search UpdateOtter', 'updateotter' ); ?></label>
	<div class="search-form__row">
		<input type="search" id="search-page-q" name="s" value="<?php echo esc_attr( get_search_query() ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'updateotter' ); ?></button>
	</div>
</form>
