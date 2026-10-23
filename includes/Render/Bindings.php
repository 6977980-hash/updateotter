<?php
/**
 * Block Bindings source `rbx/data` (DATA-30, ENG-28): a Paragraph or Heading bound to
 * {record_id, field, format} shows the dataset value.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the source on init.
 */
final class Bindings {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			'rbx/data',
			array(
				'label'              => __( 'Game data', 'rbx-data' ),
				'get_value_callback' => array( self::class, 'value' ),
				'uses_context'       => array( 'postId' ),
			)
		);
	}

	/** @param array<string, mixed> $args record_id, field, format. */
	public static function value( array $args ): string {
		$id    = (string) ( $args['record_id'] ?? '' );
		$field = sanitize_key( (string) ( $args['field'] ?? '' ) );
		$v     = Shortcodes::lookup( $id, $field, sanitize_text_field( (string) ( $args['format'] ?? '' ) ) );
		return null === $v ? '' : $v['text'];
	}
}
