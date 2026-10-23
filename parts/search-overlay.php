<?php
/**
 * Search overlay (UX-24). The index file loads only when the overlay opens; Enter submits to /?s=.
 *
 * @package UpdateOtter
 */

defined( 'ABSPATH' ) || exit;
$updateotter_idx  = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::search_index_url() : '';
$updateotter_game = updateotter_game();
$updateotter_i18n = array(
	/* translators: %s: what the visitor typed */
	'none'   => __( 'No results for "%s". Press Enter to search all pages.', 'updateotter' ),
	/* translators: %s: game name */
	'others' => __( 'Nothing in %s. Results from all games:', 'updateotter' ),
	'page'   => __( 'Page', 'updateotter' ),
);
?>
<div class="panel search-panel" id="site-search" hidden role="search" data-index="<?php echo esc_url( $updateotter_idx ); ?>" data-game="<?php echo esc_attr( $updateotter_game ? $updateotter_game->key() : '' ); ?>" data-i18n="<?php echo esc_attr( (string) wp_json_encode( $updateotter_i18n ) ); ?>">
	<div class="container">
		<form action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" class="search-form">
			<label for="site-search-q"><?php esc_html_e( 'Search UpdateOtter', 'updateotter' ); ?></label>
			<div class="search-form__row">
				<input type="search" id="site-search-q" name="s" autocomplete="off" spellcheck="false">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'updateotter' ); ?></button>
			</div>
			<?php if ( $updateotter_game ) : ?>
			<label class="search-form__only"><input type="checkbox" data-only-game> <?php echo esc_html( sprintf( /* translators: %s: game */ __( 'Only %s', 'updateotter' ), $updateotter_game->short_name() ) ); ?></label>
			<?php endif; ?>
		</form>
		<div class="search-results" data-results aria-live="polite"></div>
	</div>
</div>
