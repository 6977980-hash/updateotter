<?php
/**
 * "Report a wrong value" form (CONT-46, UX-43): the /report/ page form (no JS) and the
 * <template> the dialog script clones on pages with report buttons.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Reports\Endpoint;

defined( 'ABSPATH' ) || exit;

/**
 * No name, email, nickname or upload fields, by design.
 */
final class Report {

	public static function boot(): void {
		add_action( 'wp_footer', array( self::class, 'template' ) );
	}

	/**
	 * @param array<string, string> $prefill game, record, field.
	 */
	private static function fields( array $prefill, bool $dialog ): string {
		$game    = Loader::game( (string) ( $prefill['game'] ?? '' ) );
		$updates = '';
		$games   = $game ? array( $game ) : Loader::games();
		foreach ( $games as $g ) {
			$upds = Repository::dataset( $g )->all( 'UPD', array( 'live', 'limited' ) );
			uasort( $upds, static fn( $a, $b ) => strcmp( (string) ( $b['data']['release_date'] ?? '' ), (string) ( $a['data']['release_date'] ?? '' ) ) );
			foreach ( array_slice( $upds, 0, 8, true ) as $id => $u ) {
				$updates .= '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( (string) ( $u['data']['title__en'] ?? $u['name'] ) ) . '</option>';
			}
		}
		$p    = $dialog ? 'rbx-dlg-' : 'rbx-rep-';
		$out  = '<p class="rbx-note">' . esc_html__( "Don't write your name, username or any personal details.", 'rbx-data' ) . '</p>';
		$out .= '<p><label for="' . $p . 'kind">' . esc_html__( 'What is wrong?', 'rbx-data' ) . '</label><select id="' . $p . 'kind" name="kind" required>'
			. '<option value="wrong_number">' . esc_html__( 'Wrong number', 'rbx-data' ) . '</option>'
			. '<option value="missing_item">' . esc_html__( 'Missing item', 'rbx-data' ) . '</option>'
			. '<option value="item_removed">' . esc_html__( 'Item removed', 'rbx-data' ) . '</option>'
			. '<option value="other">' . esc_html__( 'Other', 'rbx-data' ) . '</option></select></p>';
		$out .= '<p><label for="' . $p . 'text">' . esc_html__( 'What did you see in the game?', 'rbx-data' ) . '</label><textarea id="' . $p . 'text" name="text" required maxlength="500" rows="4"></textarea></p>';
		if ( '' !== $updates ) {
			$out .= '<p><label for="' . $p . 'upd">' . esc_html__( 'Game update (optional)', 'rbx-data' ) . '</label><select id="' . $p . 'upd" name="update_id"><option value="">' . esc_html__( 'Not sure', 'rbx-data' ) . '</option>' . $updates . '</select></p>';
		}
		$out .= '<p class="rbx-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
		$out .= '<input type="hidden" name="t" value="' . esc_attr( (string) ( time() * 1000 ) ) . '">';
		$out .= '<input type="hidden" name="game" value="' . esc_attr( (string) ( $prefill['game'] ?? '' ) ) . '">';
		$out .= '<input type="hidden" name="record_id" value="' . esc_attr( (string) ( $prefill['record'] ?? '' ) ) . '">';
		$out .= '<input type="hidden" name="field" value="' . esc_attr( (string) ( $prefill['field'] ?? '' ) ) . '">';
		$out .= '<input type="hidden" name="value" value="">';
		$out .= '<input type="hidden" name="dataset_version" value="' . esc_attr( $game ? Repository::version( $game ) : '' ) . '">';
		$out .= '<input type="hidden" name="page_url" value="' . esc_attr( (string) ( $prefill['page'] ?? '' ) ) . '">';
		return $out;
	}

	/** Form on the /report/ page; posts to admin-post.php (works with JS off). */
	public static function form(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$prefill = array(
			'game'   => sanitize_key( (string) wp_unslash( $_GET['game'] ?? '' ) ),
			'record' => sanitize_text_field( (string) wp_unslash( $_GET['record'] ?? '' ) ),
			'field'  => sanitize_key( (string) wp_unslash( $_GET['field'] ?? '' ) ),
			'page'   => esc_url_raw( (string) wp_get_referer() ),
		);
		$msg = '';
		if ( isset( $_GET['rbx_sent'] ) ) {
			$msg = '<p class="rbx-banner rbx-banner--ok" role="status">' . esc_html( Endpoint::thanks() ) . '</p>';
		} elseif ( isset( $_GET['rbx_error'] ) ) {
			$m   = Endpoint::messages();
			$c   = sanitize_key( (string) wp_unslash( $_GET['rbx_error'] ) );
			$msg = '<p class="rbx-banner rbx-banner--error" role="alert">' . esc_html( $m[ $c ] ?? $m['spam'] ) . '</p>';
		}
		// phpcs:enable
		return $msg . '<form class="rbx-report-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rbx_report">' . self::fields( $prefill, false ) . '<p><button type="submit" class="button">' . esc_html__( 'Send report', 'rbx-data' ) . '</button></p></form>';
	}

	/** Dialog template printed once in the footer of pages that show report buttons. */
	public static function template(): void {
		if ( is_admin() || ! ( is_singular( \RBX\Data\Plugin::post_types() ) || ( is_page() && Breadcrumbs::game_for( get_queried_object() instanceof \WP_Post ? get_queried_object() : null ) ) ) ) {
			return;
		}
		$game = Breadcrumbs::game_for( get_queried_object() instanceof \WP_Post ? get_queried_object() : null );
		echo '<template id="rbx-report-tpl"><dialog class="rbx-dialog" aria-labelledby="rbx-dlg-title"><form method="dialog" class="rbx-dialog__close-form"><button class="rbx-dialog__close" value="cancel" aria-label="' . esc_attr__( 'Close', 'rbx-data' ) . '">×</button></form><h2 id="rbx-dlg-title">' . esc_html__( 'Report a wrong value', 'rbx-data' ) . '</h2><form class="rbx-report-form" data-endpoint="' . esc_url( rest_url( 'rbx/v1/report' ) ) . '">'
			. self::fields( array( 'game' => $game ? $game->key() : '' ), true ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fields().
			. '<p class="rbx-live" role="status"></p><p><button type="submit" class="button">' . esc_html__( 'Send report', 'rbx-data' ) . '</button></p></form></dialog></template>';
		wp_enqueue_script_module( 'rbx-data-report', RBX_DATA_URL . 'assets/front/report.js', array(), RBX_DATA_VERSION );
		printf(
			'<script type="application/json" id="rbx-report-i18n">%s</script>',
			wp_json_encode(
				array(
					'thanks' => Endpoint::thanks(),
					'error'  => __( 'Could not send. Check your connection and try again.', 'rbx-data' ),
				)
			)
		);
	}
}
