<?php
/**
 * Block editor sidebar panel (edit kind, index override, manual index OK, gate reasons)
 * and a "Page state" list column (ENG-22, DATA-20).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Admin;

use RBX\Data\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * No build step: the panel is plain JS using the wp.* globals.
 */
final class Editor {

	public static function boot(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'assets' ) );
		foreach ( array_merge( Plugin::post_types(), array( 'page' ) ) as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", array( self::class, 'columns' ) );
			add_action( "manage_{$pt}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		}
	}

	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array_merge( Plugin::post_types(), array( 'page' ) ), true ) ) {
			return;
		}
		wp_enqueue_script(
			'rbx-data-editor-panel',
			plugins_url( 'assets/admin/editor.js', RBX_DATA_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n' ),
			RBX_DATA_VERSION,
			true
		);
		wp_add_inline_script(
			'rbx-data-editor-panel',
			'window.rbxEditor=' . wp_json_encode(
				array(
					'canIndex' => current_user_can( 'rbx_import' ),
					'i18n'     => array(
						'panel'       => __( 'RBX page', 'rbx-data' ),
						'state'       => __( 'Index state', 'rbx-data' ),
						'reasons'     => __( 'Why', 'rbx-data' ),
						'kind'        => __( 'This edit is', 'rbx-data' ),
						'minor'       => __( 'Minor (typo, wording): page date stays', 'rbx-data' ),
						'substantive' => __( 'Substantive (new facts, new section): page date moves', 'rbx-data' ),
						'override'    => __( 'Index override', 'rbx-data' ),
						'auto'        => __( 'Automatic (gate decides)', 'rbx-data' ),
						'noindex'     => __( 'Force noindex', 'rbx-data' ),
						'index'       => __( 'Allow index if the gate passes', 'rbx-data' ),
						'manual'      => __( 'I checked this page by hand and it is good enough to index', 'rbx-data' ),
						'none'        => __( 'Not checked yet. Run an import or the gate.', 'rbx-data' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * @param array<string, string> $cols Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['rbx_state'] = __( 'Index state', 'rbx-data' );
			}
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'rbx_state' !== $col ) {
			return;
		}
		$state = (string) get_post_meta( $post_id, '_rbx_page_state', true );
		if ( '' === $state ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		$why = implode( ', ', (array) json_decode( (string) get_post_meta( $post_id, '_rbx_gate_reasons', true ), true ) );
		echo '<span class="rbx-state rbx-state--' . esc_attr( $state ) . '" title="' . esc_attr( $why ) . '">' . esc_html( $state ) . '</span>';
	}
}
