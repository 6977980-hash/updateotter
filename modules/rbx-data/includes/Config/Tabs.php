<?php
/**
 * Built-in tab schemas shared by every game (DATA-05, DATA-09, DATA-12, DATA-16, DATA-25, DATA-34).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Column lists and field types for the special tabs and the common provenance columns.
 */
final class Tabs {

	public const META_TAB  = '_meta';
	public const ENUMS_TAB = '_enums';

	/** Value of `source_type` (DATA-05). */
	public const SOURCE_TYPES = array( 'in_game', 'official', 'community' );

	/** Record status (DATA-05). */
	public const STATUSES = array( 'draft', 'live', 'limited', 'removed_from_game', 'retired_id' );

	/** Keys allowed in `_meta` (DATA-16). */
	public const META_KEYS = array(
		'game_code',
		'schema_version',
		'roblox_universe_id',
		'roblox_place_id',
		'timezone_note',
		'game_name',
		'developer_name',
		'developer_roblox_url',
		'official_channels',
		'release_date',
		'genre',
		'update_cadence_text',
		'redeem_ui_exists',
		'suffix_ladder',
		'calc_defaults',
	);

	/** Derived column names that may never appear in a Sheet (DATA-36). */
	public const DERIVED = array( 'confidence', 'odds_complete', 'odds_sum', 'page_state', 'gate_reasons', 'page_last_verified', 'last_changed' );

	/**
	 * Special tabs: tab name => [ entity code, field definitions in column order ].
	 *
	 * @return array<string, array{code:string, fields:array<string, array<string, mixed>>}>
	 */
	public static function special(): array {
		$src = array(
			'source_type'       => array( 'type' => 'enum', 'enum' => self::SOURCE_TYPES ),
			'source_url'        => array( 'type' => 'url' ),
			'evidence_media_id' => array( 'type' => 'ref', 'target' => 'MED' ),
			'last_verified'     => array( 'type' => 'date' ),
			'verified_by'       => array( 'type' => 'string' ),
		);
		return array(
			'mechanics'     => array(
				'code'   => 'MEC',
				'fields' => array_merge(
					array(
						'mechanic_key'   => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-z][a-z0-9_]{1,40}$' ),
						'status'         => array( 'type' => 'enum', 'enum' => array( 'confirmed', 'partially_confirmed', 'unconfirmed' ), 'required' => true ),
						'description__en' => array( 'type' => 'text' ),
						'definition__en' => array( 'type' => 'text' ),
						'in_glossary'    => array( 'type' => 'bool' ),
						'parameters'     => array( 'type' => 'json' ),
					),
					$src
				),
			),
			'formulas'      => array(
				'code'   => 'FRM',
				'fields' => array_merge(
					array(
						'key'                => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-z][a-z0-9_]{1,40}$' ),
						'tool'               => array( 'type' => 'string' ),
						'label__en'          => array( 'type' => 'text', 'required' => true ),
						'expression'         => array( 'type' => 'string', 'required' => true ),
						'inputs'             => array( 'type' => 'json', 'required' => true ),
						'output_unit__en'    => array( 'type' => 'string' ),
						'display_format'     => array( 'type' => 'string' ),
						'sort_order'         => array( 'type' => 'int' ),
						'requires_mechanics' => array( 'type' => 'string_list' ),
						'version'            => array( 'type' => 'int' ),
						'status'             => array( 'type' => 'enum', 'enum' => array( 'draft', 'live' ), 'required' => true ),
					),
					$src
				),
			),
			'formula_tests' => array(
				'code'   => 'TST',
				'fields' => array(
					'formula_id'            => array( 'type' => 'ref', 'target' => 'FRM', 'required' => true ),
					'inputs'                => array( 'type' => 'json', 'required' => true ),
					'expected'              => array( 'type' => 'decimal', 'required' => true ),
					'tolerance_abs'         => array( 'type' => 'decimal' ),
					'tolerance_rel'         => array( 'type' => 'decimal' ),
					'evidence_media_id'     => array( 'type' => 'ref', 'target' => 'MED' ),
					'captured_in_update_id' => array( 'type' => 'ref', 'target' => 'UPD' ),
					'verified_by'           => array( 'type' => 'string' ),
					'status'                => array( 'type' => 'enum', 'enum' => array( 'live', 'needs_recheck', 'draft' ), 'required' => true ),
				),
			),
			'media'         => array(
				'code'   => 'MED',
				'fields' => array(
					'file_name'             => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-z0-9][a-z0-9._-]*\\.(webp|png|jpg|jpeg|avif)$' ),
					'alt__en'               => array( 'type' => 'text', 'required' => true ),
					'caption__en'           => array( 'type' => 'text' ),
					'captured_on'           => array( 'type' => 'date', 'required' => true ),
					'captured_in_update_id' => array( 'type' => 'ref', 'target' => 'UPD' ),
					'captured_by'           => array( 'type' => 'string' ),
					'depicts_record_ids'    => array( 'type' => 'ref_list', 'target' => '*' ),
					'shows_fields'          => array( 'type' => 'string_list' ),
					'is_cropped_ui'         => array( 'type' => 'bool' ),
					'contains_usernames'    => array( 'type' => 'bool', 'required' => true ),
					'image_source'          => array( 'type' => 'enum', 'enum' => array( 'own_screenshot', 'press_kit', 'licensed', 'own_illustration' ), 'required' => true ),
					'image_credit'          => array( 'type' => 'string', 'required' => true ),
					'license_note'          => array( 'type' => 'string' ),
					'status'                => array( 'type' => 'enum', 'enum' => array( 'draft', 'live' ), 'required' => true ),
				),
			),
			'provenance'    => array(
				'code'   => 'PRV',
				'fields' => array_merge(
					array(
						'record_id' => array( 'type' => 'ref', 'target' => '*', 'required' => true ),
						'field'     => array( 'type' => 'string', 'required' => true ),
						'value'     => array( 'type' => 'string' ),
					),
					$src,
					array( 'is_selected' => array( 'type' => 'bool' ) )
				),
			),
		);
	}

	/**
	 * Common trailing columns of every entity tab, in order (DATA-16).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function common_tail( bool $slugged ): array {
		$cols = array(
			'status'            => array( 'type' => 'enum', 'enum' => self::STATUSES, 'required' => true ),
			'source_type'       => array( 'type' => 'enum', 'enum' => self::SOURCE_TYPES ),
			'source_url'        => array( 'type' => 'url' ),
			'evidence_media_id' => array( 'type' => 'ref', 'target' => 'MED' ),
			'source_note'       => array( 'type' => 'string', 'internal' => true ),
			'last_verified'     => array( 'type' => 'date' ),
			'verified_by'       => array( 'type' => 'string', 'internal' => true ),
			'added_in_update'   => array( 'type' => 'ref', 'target' => 'UPD' ),
			'removed_in_update' => array( 'type' => 'ref', 'target' => 'UPD' ),
			'change_update_id'  => array( 'type' => 'ref', 'target' => 'UPD', 'transient' => true ),
		);
		if ( $slugged ) {
			$cols['slug_history'] = array( 'type' => 'string_list', 'internal' => true );
		}
		return $cols;
	}
}
