<?php
/**
 * Registers the rbx/data-section block and passes game options to its editor script.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic block only; the editor uses ServerSideRender so authors see real data.
 */
final class Blocks {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
	}

	public static function register(): void {
		register_block_type( RBX_DATA_DIR . '/blocks/data-section' );
	}

	public static function editor_data(): void {
		$games = array();
		foreach ( Loader::games() as $g ) {
			$ents = array();
			foreach ( $g->entities() as $code => $def ) {
				$ents[] = array(
					'code'  => $code,
					'label' => $g->entity_label( $code, true ),
				);
			}
			$games[] = array(
				'key'      => $g->key(),
				'name'     => $g->name(),
				'entities' => $ents,
			);
		}
		wp_add_inline_script(
			'rbx-data-section-editor-script',
			'window.rbxDataEditor = ' . wp_json_encode(
				array(
					'games'    => $games,
					'sections' => Sections::NAMES,
				)
			) . ';',
			'before'
		);
	}
}
