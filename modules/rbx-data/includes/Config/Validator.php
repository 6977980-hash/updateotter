<?php
/**
 * Structural validation of a game config (DATA-40 acceptance; mirrors games/games.schema.json).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Pure validator; returns a list of error strings.
 */
final class Validator {

	public const FIELD_TYPES = array( 'id', 'slug', 'ref', 'ref_list', 'string', 'string_list', 'text', 'enum', 'int', 'decimal', 'prob', 'mult', 'duration_s', 'date', 'datetime', 'bool', 'url', 'robux', 'json' );

	public const FORMATS = array( 'int', 'mult', 'prob', 'duration', 'abbr', 'decimal(0)', 'decimal(1)', 'decimal(2)', 'decimal(3)', 'decimal(4)' );

	/**
	 * @param array<string, mixed> $c Config.
	 * @return array<int, string>
	 */
	public static function validate( array $c ): array {
		$e = array();
		foreach ( array( 'game_code', 'name', 'folder', 'schema_version', 'entities' ) as $k ) {
			if ( ! isset( $c[ $k ] ) ) {
				$e[] = "Missing key '$k'.";
			}
		}
		if ( $e ) {
			return $e;
		}
		if ( ! preg_match( '/^[A-Z][A-Z0-9]{1,4}$/', (string) $c['game_code'] ) ) {
			$e[] = 'game_code must match ^[A-Z][A-Z0-9]{1,4}$.';
		}
		if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $c['folder'] ) ) {
			$e[] = 'folder must be a lowercase hyphen slug.';
		}
		if ( ! preg_match( '/^\d+\.\d+$/', (string) $c['schema_version'] ) ) {
			$e[] = 'schema_version must be MAJOR.MINOR.';
		}
		foreach ( array( 'roblox_universe_id', 'roblox_place_id' ) as $k ) {
			if ( isset( $c[ $k ] ) && null !== $c[ $k ] && '' !== $c[ $k ] && ! preg_match( '/^\d{1,20}$/', (string) $c[ $k ] ) ) {
				$e[] = "$k must be digits or null.";
			}
		}
		if ( ! is_array( $c['entities'] ) || ! $c['entities'] ) {
			$e[] = 'entities must be a non-empty object.';
			return $e;
		}
		$codes    = array_merge( array_keys( $c['entities'] ), Game::special_codes() );
		$tabs     = array();
		$paths    = array();
		$sections = array_keys( (array) ( $c['sections'] ?? array() ) );
		foreach ( $c['entities'] as $code => $def ) {
			$p = "entities.$code";
			if ( ! preg_match( '/^[A-Z]{3}$/', (string) $code ) ) {
				$e[] = "$p: entity code must be 3 capital letters.";
			}
			if ( in_array( $code, Game::special_codes(), true ) ) {
				$e[] = "$p: $code is a built-in tab.";
			}
			if ( ! is_array( $def ) || empty( $def['tab'] ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) $def['tab'] ) ) {
				$e[] = "$p: tab must be a lowercase name.";
				continue;
			}
			if ( isset( $tabs[ $def['tab'] ] ) || isset( Tabs::special()[ $def['tab'] ] ) ) {
				$e[] = "$p: duplicate tab '{$def['tab']}'.";
			}
			$tabs[ $def['tab'] ] = true;
			if ( isset( $def['post_type'] ) && ( strlen( (string) $def['post_type'] ) > 20 || ! preg_match( '/^rbx_[a-z_]+$/', (string) $def['post_type'] ) ) ) {
				$e[] = "$p: post_type must start with rbx_ and be at most 20 characters.";
			}
			if ( ! empty( $def['page'] ) ) {
				if ( empty( $def['path'] ) || ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $def['path'] ) ) {
					$e[] = "$p: page entities need a slug path.";
				} elseif ( isset( $paths[ $def['path'] ] ) ) {
					$e[] = "$p: duplicate path.";
				}
				$paths[ $def['path'] ?? '' ] = true;
				if ( empty( $def['named'] ) && empty( $def['slugged'] ) ) {
					$e[] = "$p: page entities need slugs (named or slugged).";
				}
			}
			if ( ! isset( $def['fields'] ) || ! is_array( $def['fields'] ) ) {
				$e[] = "$p: fields must be an object.";
				continue;
			}
			foreach ( $def['fields'] as $field => $f ) {
				$fp = "$p.fields.$field";
				if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) $field ) ) {
					$e[] = "$fp: invalid field name.";
				}
				if ( in_array( $field, Tabs::DERIVED, true ) || in_array( $field, array( 'id', 'slug', 'name' ), true ) || array_key_exists( $field, Tabs::common_tail( true ) ) ) {
					$e[] = "$fp: reserved field name.";
				}
				if ( ! is_array( $f ) || ! in_array( $f['type'] ?? '', self::FIELD_TYPES, true ) ) {
					$e[] = "$fp: unknown type.";
					continue;
				}
				if ( in_array( $f['type'], array( 'ref', 'ref_list' ), true ) ) {
					foreach ( (array) ( $f['target'] ?? array() ) as $t ) {
						if ( '*' !== $t && ! in_array( $t, $codes, true ) ) {
							$e[] = "$fp: unknown target '$t'.";
						}
					}
				}
				if ( 'enum' === $f['type'] && ( empty( $f['enum'] ) || ! is_array( $f['enum'] ) ) ) {
					$e[] = "$fp: enum needs values.";
				}
				if ( isset( $f['display_format'] ) && ! in_array( $f['display_format'], self::FORMATS, true ) ) {
					$e[] = "$fp: unknown display_format.";
				}
				if ( 'text' === $f['type'] && ! preg_match( '/__[a-z]{2}$/', (string) $field ) ) {
					$e[] = "$fp: text fields need a locale suffix such as __en (DATA-07).";
				}
			}
			foreach ( (array) ( $def['siblings_by'] ?? array() ) as $sb ) {
				if ( ! isset( $def['fields'][ $sb ] ) ) {
					$e[] = "$p: siblings_by field '$sb' does not exist.";
				}
			}
		}
		foreach ( (array) ( $c['sections'] ?? array() ) as $slug => $s ) {
			if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $slug ) ) {
				$e[] = "sections.$slug: invalid slug.";
			}
			if ( isset( $s['entity'] ) && ! isset( $c['entities'][ $s['entity'] ] ) ) {
				$e[] = "sections.$slug: unknown entity.";
			}
		}
		foreach ( (array) ( $c['menu_order'] ?? array() ) as $m ) {
			if ( ! in_array( $m, $sections, true ) ) {
				$e[] = "menu_order: '$m' is not a section.";
			}
		}
		foreach ( (array) ( $c['tools'] ?? array() ) as $i => $tool ) {
			if ( empty( $tool['key'] ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/', (string) $tool['key'] ) ) {
				$e[] = "tools.$i: key required.";
			}
			foreach ( (array) ( $tool['inputs'] ?? array() ) as $in ) {
				if ( empty( $in['key'] ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) $in['key'] ) ) {
					$e[] = "tools.$i: input key invalid.";
				}
				if ( 'ref' === ( $in['type'] ?? '' ) && ! isset( $c['entities'][ $in['target'] ?? '' ] ) ) {
					$e[] = "tools.$i: input '" . ( $in['key'] ?? '' ) . "' has unknown target.";
				}
				if ( ! in_array( $in['type'] ?? '', array( 'ref', 'number', 'bool' ), true ) ) {
					$e[] = "tools.$i: input type must be ref, number or bool.";
				}
			}
		}
		return $e;
	}
}
