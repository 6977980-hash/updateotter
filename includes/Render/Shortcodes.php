<?php
/**
 * Data tokens for prose (DATA-30, ENG-28): block bindings source `rbx/data`, shortcodes
 * [rbx_value], [rbx_count], [rbx_meta], [rbx_section], and the shared value callback.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Format\Number;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Unknown IDs or fields render an empty string and log a lint warning, never a PHP notice.
 */
final class Shortcodes {

	public static function boot(): void {
		add_shortcode( 'rbx_value', array( self::class, 'value_sc' ) );
		add_shortcode( 'rbx_count', array( self::class, 'count_sc' ) );
		add_shortcode( 'rbx_meta', array( self::class, 'meta_sc' ) );
		add_shortcode( 'rbx_section', array( self::class, 'section_sc' ) );
	}

	/**
	 * Plain-text value of one field (used by bindings, shortcode and Rank Math vars).
	 *
	 * @return array{text: string, exact: string}|null
	 */
	public static function lookup( string $id, string $field, string $format = '' ): ?array {
		if ( ! preg_match( '/^([A-Z][A-Z0-9]{1,4})-[A-Z]{3}-\d{4,6}$/', $id, $m ) ) {
			return null;
		}
		$game = Loader::by_code( $m[1] );
		if ( null === $game ) {
			return null;
		}
		$ds  = Repository::dataset( $game );
		$rec = $ds->get( $id );
		if ( null === $rec ) {
			self::lint( "Unknown record {$id}" );
			return null;
		}
		if ( 'name' === $field ) {
			return array(
				'text'  => (string) $rec['name'],
				'exact' => '',
			);
		}
		$def = $game->field( (string) $rec['entity'], $field );
		if ( null === $def || ! empty( $def['internal'] ) ) {
			self::lint( "Unknown field {$id}.{$field}" );
			return null;
		}
		$st = $ds->field_state( $rec, $field );
		if ( 'verified' !== $st['state'] && 'community' !== $st['state'] ) {
			return array(
				'text'  => 'disputed' === $st['state'] ? __( 'conflicting reports', 'rbx-data' ) : __( 'not confirmed yet', 'rbx-data' ),
				'exact' => '',
			);
		}
		$v = $st['value'];
		if ( is_int( $v ) || is_float( $v ) ) {
			$fmt    = '' !== $format ? $format : Ui::format_of( $def );
			$ladder = $ds->meta( 'suffix_ladder' );
			return array(
				'text'  => Number::format( $v, $fmt, 'en', is_array( $ladder ) ? $ladder : null ),
				'exact' => Number::exact( $v, 'en' ),
			);
		}
		if ( 'ref' === ( $def['type'] ?? '' ) ) {
			return array(
				'text'  => (string) ( $ds->get( (string) $v )['name'] ?? '' ),
				'exact' => '',
			);
		}
		if ( 'date' === ( $def['type'] ?? '' ) ) {
			return array(
				'text'  => wp_date( 'j M Y', (int) strtotime( (string) $v . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ),
				'exact' => (string) $v,
			);
		}
		if ( 'enum' === ( $def['type'] ?? '' ) ) {
			return array(
				'text'  => Ui::enum_label( (string) $v ),
				'exact' => '',
			);
		}
		return array(
			'text'  => wp_strip_all_tags( is_array( $v ) ? implode( ', ', $v ) : (string) $v ),
			'exact' => '',
		);
	}

	private static function lint( string $msg ): void {
		$seen = (array) wp_cache_get( 'rbx_lint_seen', 'rbx' );
		if ( isset( $seen[ $msg ] ) ) {
			return;
		}
		$seen[ $msg ] = true;
		wp_cache_set( 'rbx_lint_seen', $seen, 'rbx' );
		ErrorLog::add( 'lint', $msg );
	}

	/** @param array<string, string>|string $atts Attributes. */
	public static function value_sc( $atts ): string {
		$a = shortcode_atts(
			array(
				'id'     => '',
				'field'  => '',
				'format' => '',
			),
			(array) $atts
		);
		$v = self::lookup( (string) $a['id'], sanitize_key( (string) $a['field'] ), sanitize_text_field( (string) $a['format'] ) );
		if ( null === $v ) {
			return '';
		}
		if ( '' !== $v['exact'] && $v['exact'] !== $v['text'] ) {
			return '<span class="rbx-num" data-exact="' . esc_attr( $v['exact'] ) . '" title="' . esc_attr( $v['exact'] ) . '">' . esc_html( $v['text'] ) . '</span>';
		}
		return esc_html( $v['text'] );
	}

	/** [rbx_count game="sae" entity="PET"] */
	public static function count_sc( $atts ): string {
		$a    = shortcode_atts(
			array(
				'game'   => '',
				'entity' => '',
			),
			(array) $atts
		);
		$game = Loader::game( sanitize_key( (string) $a['game'] ) );
		if ( null === $game || null === $game->entity( strtoupper( (string) $a['entity'] ) ) ) {
			return '';
		}
		return esc_html( number_format_i18n( Repository::dataset( $game )->count( strtoupper( (string) $a['entity'] ) ) ) );
	}

	/** [rbx_meta game="sae" key="developer_name"] */
	public static function meta_sc( $atts ): string {
		$a    = shortcode_atts(
			array(
				'game' => '',
				'key'  => '',
			),
			(array) $atts
		);
		$game = Loader::game( sanitize_key( (string) $a['game'] ) );
		if ( null === $game ) {
			return '';
		}
		$v = Repository::dataset( $game )->meta( sanitize_key( (string) $a['key'] ) );
		if ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return Ui::date( $v );
		}
		return is_scalar( $v ) ? esc_html( (string) $v ) : '';
	}

	/** [rbx_section game="sae" section="list" entity="PET"] */
	public static function section_sc( $atts ): string {
		$a = shortcode_atts(
			array(
				'game'    => '',
				'section' => '',
				'entity'  => '',
				'limit'   => '5',
			),
			(array) $atts
		);
		return Sections::render( $a );
	}
}
