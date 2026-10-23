<?php
/**
 * Registers the calculator blocks, the fallback shortcode and the item-page mini calculator (ENG-30).
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Everything degrades to nothing when RBX Data is missing (ENG-13).
 */
final class Plugin {

	public static function boot(): void {
		if ( ! class_exists( '\RBX\Data\Api' ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_notice' ) );
			return;
		}
		add_action( 'init', array( self::class, 'register' ) );
		add_shortcode( 'rbx_calculator', array( self::class, 'shortcode' ) );
		add_filter( 'rbx_data_item_after_card', array( Calculator::class, 'mini_for_item' ), 10, 4 );
	}

	public static function register(): void {
		load_textdomain( 'rbx-tools', RBX_TOOLS_DIR . '/languages/rbx-tools-' . determine_locale() . '.mo' );
		wp_register_script_module( 'rbx-calculator', RBX_TOOLS_URL . 'assets/js/calculator.js', array(), RBX_TOOLS_VERSION );
		register_block_type( RBX_TOOLS_DIR . '/blocks/calculator' );
		register_block_type( RBX_TOOLS_DIR . '/blocks/mini-calculator' );
		$tools = array();
		foreach ( \RBX\Data\Api::games() as $g ) {
			foreach ( $g->tools() as $t ) {
				$tools[] = array(
					'game'  => $g->key(),
					'tool'  => (string) $t['key'],
					'label' => (string) ( $t['label__en'] ?? $t['key'] ),
				);
			}
		}
		wp_add_inline_script( 'rbx-calculator-editor-script', 'window.rbxTools=' . wp_json_encode( array( 'tools' => $tools ) ) . ';', 'before' );
	}

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'game' => '',
				'tool' => '',
			),
			(array) $atts
		);
		return Calculator::render( (string) $a['game'], (string) $a['tool'] );
	}

	public static function missing_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'RBX Tools needs the RBX Data plugin. Activate RBX Data first.', 'rbx-tools' ) . '</p></div>';
		}
	}
}
