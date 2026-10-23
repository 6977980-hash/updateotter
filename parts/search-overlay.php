<?php
/**
 * Search overlay (UX-24). The index file loads only when the overlay opens; Enter submits to /?s=.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;
$updateotter_idx  = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::search_index_url() : '';
$updateotter_game = updateotter_game();
?>
<div class="panel search-panel" id="site-search" hidden role="search" data-index="<?php echo esc_url( $updateotter_idx ); ?>" data-game="<?php echo esc_attr( $updateotter_game ? $updateotter_game->key() : '' ); ?>">
	<div class="container">
		<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" class="search-form">
			<label for="site-search-q"><?php esc_html_e( 'Search UpdateOtter', 'updateotter' ); ?></label>
			<div class="search-form__row">
				<input type="search" id="site-search-q" name="s" autocomplete="off" spellcheck="false">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'updateotter' ); ?></button>
			</div>
			<?php if ( $updateotter_game ) : ?>
			<label class="search-form__only"><input type="checkbox" checked data-only-game> <?php echo esc_html( sprintf( /* translators: %s: game */ __( 'Only %s', 'updateotter' ), $updateotter_game->short_name() ) ); ?></label>
			<?php endif; ?>
		</form>
		<div class="search-results" data-results aria-live="polite"></div>
	</div>
</div>
