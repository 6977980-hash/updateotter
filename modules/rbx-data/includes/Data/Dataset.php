<?php
/**
 * In-memory view of one game's records with field-state resolution (DATA-05, DATA-12, DATA-13).
 * Built from the database for rendering, or from an import plan for dry-run predictions.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Data;

use RBX\Data\Config\Game;
use RBX\Data\Formula\Parser;
use RBX\Data\Formula\SyntaxError;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only dataset.
 */
final class Dataset {

	public const NUMERIC = array( 'int', 'decimal', 'prob', 'mult', 'duration_s', 'robux' );

	/** Record statuses that are shown publicly. */
	public const PUBLIC_STATUSES = array( 'live', 'limited', 'removed_from_game' );

	/** @var array<string, array<string, array<string, mixed>>> entity => id => record */
	private array $by_entity = array();

	/** @var array<string, array<string, array<int, array<string, mixed>>>> record_id => field => PRV rows */
	private array $prv = array();

	/** @var array<string, array<int, string>> record_id => MED ids */
	private array $media_index = array();

	/** @var array<string, array<string, mixed>> */
	private array $meta = array();

	/** @var array<string, array<string, mixed>> */
	private array $state_cache = array();

	/**
	 * @param array<string, array<string, mixed>> $records id => record (with 'data').
	 */
	public function __construct( private Game $game, private array $records, private string $today = '' ) {
		if ( '' === $this->today ) {
			$this->today = gmdate( 'Y-m-d' );
		}
		foreach ( $records as $id => $r ) {
			if ( 'META' === $r['entity'] ) {
				$this->meta = (array) $r['data'];
				unset( $this->records[ $id ] );
				continue;
			}
			$this->by_entity[ $r['entity'] ][ $id ] = $r;
			if ( 'PRV' === $r['entity'] ) {
				$this->prv[ (string) ( $r['data']['record_id'] ?? '' ) ][ (string) ( $r['data']['field'] ?? '' ) ][] = $r['data'];
			}
			if ( 'MED' === $r['entity'] ) {
				foreach ( (array) ( $r['data']['depicts_record_ids'] ?? array() ) as $rid ) {
					$this->media_index[ $rid ][] = $id;
				}
			}
		}
	}

	public function game(): Game {
		return $this->game;
	}

	public function today(): string {
		return $this->today;
	}

	/** @return array<string, array<string, mixed>> */
	public function records(): array {
		return $this->records;
	}

	/** @return array<string, mixed>|null */
	public function get( ?string $id ): ?array {
		return null === $id ? null : ( $this->records[ $id ] ?? null );
	}

	public function meta( string $key ): mixed {
		return $this->meta[ $key ]['value'] ?? null;
	}

	/** @return array<string, array<string, mixed>> */
	public function meta_all(): array {
		return $this->meta;
	}

	/**
	 * Records of one entity, optionally filtered by status, sorted for display.
	 *
	 * @param array<int, string>|null $statuses Statuses; null = all.
	 * @return array<string, array<string, mixed>>
	 */
	public function all( string $entity, ?array $statuses = self::PUBLIC_STATUSES ): array {
		$rows = $this->by_entity[ $entity ] ?? array();
		if ( null !== $statuses && ! in_array( $entity, array( 'MEC', 'FRM', 'TST', 'MED', 'PRV' ), true ) ) {
			$rows = array_filter( $rows, static fn( $r ) => in_array( $r['status'], $statuses, true ) );
		}
		uasort( $rows, array( $this, 'compare_records' ) );
		return $rows;
	}

	/**
	 * Display order: explicit order fields, then rarity sort order, then name.
	 *
	 * @param array<string, mixed> $a Record.
	 * @param array<string, mixed> $b Record.
	 */
	public function compare_records( array $a, array $b ): int {
		foreach ( array( 'order_index', 'sort_order' ) as $f ) {
			if ( isset( $a['data'][ $f ] ) || isset( $b['data'][ $f ] ) ) {
				$c = ( $a['data'][ $f ] ?? PHP_INT_MAX ) <=> ( $b['data'][ $f ] ?? PHP_INT_MAX );
				if ( 0 !== $c ) {
					return $c;
				}
			}
		}
		if ( 'UPD' === $a['entity'] ) {
			return strcmp( (string) ( $b['data']['release_date'] ?? '' ), (string) ( $a['data']['release_date'] ?? '' ) );
		}
		$ra = $this->rarity_order( $a );
		$rb = $this->rarity_order( $b );
		if ( $ra !== $rb ) {
			return $ra <=> $rb;
		}
		return strcasecmp( (string) $a['name'], (string) $b['name'] );
	}

	/** @param array<string, mixed> $r Record. */
	public function rarity_order( array $r ): int {
		$rid = $r['data']['rarity_id'] ?? null;
		if ( ! is_string( $rid ) ) {
			return PHP_INT_MAX;
		}
		return (int) ( $this->records[ $rid ]['data']['sort_order'] ?? PHP_INT_MAX );
	}

	/**
	 * Child rows that point at a record (e.g. egg drops of an egg).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function children( string $entity, string $fk, string $id ): array {
		$out = array();
		foreach ( $this->all( $entity ) as $cid => $c ) {
			$v = $c['data'][ $fk ] ?? null;
			if ( $v === $id || ( is_array( $v ) && in_array( $id, $v, true ) ) ) {
				$out[ $cid ] = $c;
			}
		}
		return $out;
	}

	public function mechanic_status( string $key ): string {
		foreach ( $this->by_entity['MEC'] ?? array() as $m ) {
			if ( ( $m['data']['mechanic_key'] ?? '' ) === $key ) {
				return (string) ( $m['data']['status'] ?? 'unconfirmed' );
			}
		}
		return 'unconfirmed';
	}

	/** @return array<string, mixed>|null */
	public function mechanic( string $key ): ?array {
		foreach ( $this->by_entity['MEC'] ?? array() as $m ) {
			if ( ( $m['data']['mechanic_key'] ?? '' ) === $key ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * A `partially_confirmed` mechanic counts when the wiki formula is allowed, or when it has an
	 * in-game reading with a screenshot (UO-07: one reading fits the rule but cannot pin it down,
	 * so the page shows a "partly tested" badge while the calculator keeps working).
	 *
	 * @param array<int, string> $keys Mechanic keys.
	 */
	public function mechanics_confirmed( array $keys ): bool {
		foreach ( $keys as $k ) {
			$status = $this->mechanic_status( (string) $k );
			if ( 'confirmed' === $status ) {
				continue;
			}
			$m = $this->mechanic( (string) $k );
			if ( 'partially_confirmed' === $status && ( $this->game->wiki_formula_ok() || ( $m && 'in_game' === ( $m['data']['source_type'] ?? '' ) && ! empty( $m['data']['evidence_media_id'] ) ) ) ) {
				continue;
			}
			return false;
		}
		return true;
	}

	/** @return array<int, array<string, mixed>> MED records depicting a record, newest first. */
	public function media_for( string $record_id ): array {
		$out = array();
		foreach ( $this->media_index[ $record_id ] ?? array() as $mid ) {
			if ( isset( $this->records[ $mid ] ) && 'live' === ( $this->records[ $mid ]['data']['status'] ?? '' ) ) {
				$out[] = $this->records[ $mid ];
			}
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) $b['data']['captured_on'], (string) $a['data']['captured_on'] ) );
		return $out;
	}

	/**
	 * Resolved state of one field (DATA-13).
	 *
	 * @param array<string, mixed> $rec Record.
	 * @return array<string, mixed> state, value, label, source_type, source_url, evidence_media_id, last_verified, candidates, mechanic_ok, calc_usable
	 */
	public function field_state( array $rec, string $field ): array {
		$ck = $rec['id'] . '|' . $field;
		if ( isset( $this->state_cache[ $ck ] ) ) {
			return $this->state_cache[ $ck ];
		}
		$def   = $this->game->field( (string) $rec['entity'], $field ) ?? array();
		$value = $rec['data'][ $field ] ?? null;
		$rows  = $this->prv[ $rec['id'] ][ $field ] ?? array();
		$out   = array(
			'value'             => $value,
			'state'             => 'unknown',
			'source_type'       => null,
			'source_url'        => null,
			'evidence_media_id' => null,
			'last_verified'     => null,
			'candidates'        => array(),
			'mechanic_ok'       => $this->mechanics_confirmed( (array) ( $def['requires_mechanics'] ?? array() ) ),
			'calc_usable'       => false,
		);
		if ( $rows ) {
			$sel = array_values( array_filter( $rows, static fn( $r ) => true === ( $r['is_selected'] ?? false ) ) );
			if ( 1 === count( $sel ) ) {
				$s = $sel[0];
				if ( null === $value && null !== ( $s['value'] ?? null ) ) {
					$value        = self::typed( (string) $s['value'], (string) ( $def['type'] ?? 'string' ) );
					$out['value'] = $value;
				}
				$out['source_type']       = $s['source_type'] ?? null;
				$out['source_url']        = $s['source_url'] ?? null;
				$out['evidence_media_id'] = $s['evidence_media_id'] ?? null;
				$out['last_verified']     = $s['last_verified'] ?? null;
				$out['state']             = null === $value ? 'unknown' : ( 'community' === $out['source_type'] ? 'community' : 'verified' );
			} else {
				$out['state']      = 'disputed';
				$out['candidates'] = array_map(
					static fn( $r ) => array(
						'value'       => self::typed( (string) ( $r['value'] ?? '' ), (string) ( $def['type'] ?? 'string' ) ),
						'source_type' => $r['source_type'] ?? null,
						'source_url'  => $r['source_url'] ?? null,
					),
					$rows
				);
			}
		} elseif ( null !== $value && ( ! is_array( $value ) || $value ) ) {
			$st                       = $rec['data']['source_type'] ?? null;
			$out['source_type']       = $st;
			$out['source_url']        = $rec['data']['source_url'] ?? null;
			$out['evidence_media_id'] = $rec['data']['evidence_media_id'] ?? null;
			$out['last_verified']     = $rec['data']['last_verified'] ?? null;
			$out['state']             = null === $st ? 'unknown' : ( 'community' === $st ? 'community' : 'verified' );
		}
		if ( in_array( (string) ( $def['type'] ?? '' ), self::NUMERIC, true ) && $out['mechanic_ok'] ) {
			if ( 'verified' === $out['state'] ) {
				$out['calc_usable'] = true;
			} elseif ( 'community' === $out['state'] ) {
				$out['calc_usable'] = ( $this->game->community_single_source() && null !== $value ) || self::community_agrees( $rows, $value );
			}
		}
		$out['label']              = self::state_label( $out['state'], (bool) $out['mechanic_ok'] );
		// Community values from a site other than the wiki say so (GR-05).
		if ( 'community' === $out['state'] && $out['mechanic_ok'] && ! empty( $out['source_url'] ) && ! str_contains( (string) wp_parse_url( (string) $out['source_url'], PHP_URL_HOST ), 'fandom.com' ) ) {
			$out['label'] = __( 'Source: community site', 'rbx-data' );
		}
		$this->state_cache[ $ck ] = $out;
		return $out;
	}

	/**
	 * ≥ 2 PRV rows with the same value from independent domains (DATA-13, POL-49 d).
	 *
	 * @param array<int, array<string, mixed>> $rows PRV rows.
	 */
	private static function community_agrees( array $rows, mixed $value ): bool {
		if ( null === $value ) {
			return false;
		}
		$domains = array();
		foreach ( $rows as $r ) {
			if ( ! is_numeric( $r['value'] ?? null ) || abs( (float) $r['value'] - (float) $value ) > 1e-12 * max( 1, abs( (float) $value ) ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( (string) ( $r['source_url'] ?? '' ), PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host ) ?? $host;
			if ( '' !== $host ) {
				$domains[ $host ] = true;
			}
		}
		return count( $domains ) >= 2;
	}

	public static function typed( string $v, string $type ): mixed {
		$v = trim( $v );
		if ( '' === $v ) {
			return null;
		}
		if ( in_array( $type, self::NUMERIC, true ) && is_numeric( $v ) ) {
			return 0 + $v;
		}
		if ( 'bool' === $type ) {
			return 'TRUE' === strtoupper( $v );
		}
		return $v;
	}

	/** DATA-05 display vocabulary. */
	public static function state_label( string $state, bool $mechanic_ok = true ): string {
		if ( ! $mechanic_ok && in_array( $state, array( 'verified', 'community' ), true ) ) {
			return __( 'Not tested in game yet', 'rbx-data' );
		}
		switch ( $state ) {
			case 'verified':
				return __( 'Verified', 'rbx-data' );
			case 'community':
				return __( 'Source: community wiki', 'rbx-data' );
			case 'disputed':
				return __( 'Conflicting reports', 'rbx-data' );
		}
		return __( 'Not confirmed yet', 'rbx-data' );
	}

	public static function source_label( ?string $source_type ): string {
		switch ( $source_type ) {
			case 'in_game':
				return __( 'Verified in game', 'rbx-data' );
			case 'official':
				return __( 'Official patch notes', 'rbx-data' );
			case 'community':
				return __( 'Community report', 'rbx-data' );
		}
		return '';
	}

	/**
	 * Formulas available to a tool that are live, compile, and whose mechanics are confirmed.
	 *
	 * @return array<string, array<string, mixed>> key => [ record, ast, inputs, available(bool), reason ]
	 */
	public function formulas( ?string $tool = null ): array {
		$out = array();
		foreach ( $this->all( 'FRM', null ) as $f ) {
			$d = $f['data'];
			if ( null !== $tool && ( $d['tool'] ?? '' ) !== $tool ) {
				continue;
			}
			$item = array(
				'record'    => $f,
				'key'       => (string) $d['key'],
				'ast'       => null,
				'inputs'    => is_array( $d['inputs'] ?? null ) ? $d['inputs'] : array(),
				'available' => false,
				'reason'    => '',
			);
			try {
				$item['ast'] = Parser::compile( (string) $d['expression'], $item['inputs'], array( $this, 'is_numeric_field' ) );
			} catch ( SyntaxError $e ) {
				$item['reason'] = 'syntax';
				$out[ $item['key'] ] = $item;
				continue;
			}
			if ( 'live' !== ( $d['status'] ?? '' ) ) {
				$item['reason'] = 'draft';
			} elseif ( ! $this->mechanics_confirmed( (array) ( $d['requires_mechanics'] ?? array() ) ) ) {
				$item['reason'] = 'unconfirmed';
			} elseif ( $this->tests_need_recheck( (string) $f['id'] ) ) {
				$item['reason'] = 'recheck';
			} else {
				$item['available'] = true;
			}
			$out[ $item['key'] ] = $item;
		}
		uasort( $out, static fn( $a, $b ) => ( (int) ( $a['record']['data']['sort_order'] ?? 0 ) ) <=> ( (int) ( $b['record']['data']['sort_order'] ?? 0 ) ) );
		return $out;
	}

	public function is_numeric_field( string $entity, string $field ): bool {
		$def = $this->game->field( $entity, $field );
		return null !== $def && in_array( (string) $def['type'], self::NUMERIC, true );
	}

	/** @return array<int, array<string, mixed>> */
	public function tests_for( string $formula_id ): array {
		return array_values( array_filter( $this->all( 'TST', null ), static fn( $t ) => ( $t['data']['formula_id'] ?? '' ) === $formula_id ) );
	}

	public function tests_need_recheck( string $formula_id ): bool {
		foreach ( $this->tests_for( $formula_id ) as $t ) {
			if ( 'needs_recheck' === ( $t['data']['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Data structure for the formula engines (same shape as the export slices).
	 *
	 * @param array<int, string>|null $entities Limit to these entities.
	 * @return array<string, mixed>
	 */
	public function calc_data( ?array $entities = null ): array {
		$records = array();
		foreach ( $this->game->entities() as $code => $def ) {
			if ( null !== $entities && ! in_array( $code, $entities, true ) ) {
				continue;
			}
			foreach ( $this->all( $code ) as $id => $r ) {
				$records[ $code ][ $id ] = $this->calc_record( $r );
			}
		}
		$params = array();
		foreach ( $this->all( 'MEC', null ) as $m ) {
			if ( 'confirmed' === ( $m['data']['status'] ?? '' ) && is_array( $m['data']['parameters'] ?? null ) ) {
				foreach ( $m['data']['parameters'] as $k => $v ) {
					if ( is_int( $v ) || is_float( $v ) ) {
						$params[ (string) $m['data']['mechanic_key'] ][ (string) $k ] = $v;
					}
				}
			}
		}
		return array(
			'records' => $records,
			'params'  => $params,
		);
	}

	/**
	 * One record for calculators: name, slug, rarity and every numeric field with its state.
	 *
	 * @param array<string, mixed> $r Record.
	 * @return array<string, mixed>
	 */
	public function calc_record( array $r ): array {
		$out = array( 'n' => (string) $r['name'] );
		if ( ! empty( $r['slug'] ) ) {
			$out['slug'] = (string) $r['slug'];
		}
		if ( isset( $r['data']['rarity_id'] ) ) {
			$out['rarity_id'] = $r['data']['rarity_id'];
		}
		foreach ( $this->game->columns( (string) $r['entity'] ) as $field => $def ) {
			if ( ! in_array( (string) $def['type'], self::NUMERIC, true ) || ! empty( $def['internal'] ) ) {
				continue;
			}
			$st = $this->field_state( $r, $field );
			if ( null === $st['value'] && 'disputed' !== $st['state'] ) {
				continue;
			}
			$out[ $field ] = self::encode_state( $st );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $st Field state.
	 */
	public static function encode_state( array $st ): mixed {
		if ( ! $st['mechanic_ok'] ) {
			return array( 's' => 'm' );
		}
		if ( 'disputed' === $st['state'] ) {
			return array(
				's' => 'd',
				'c' => array_values( array_filter( array_map( static fn( $c ) => $c['value'], $st['candidates'] ), 'is_numeric' ) ),
			);
		}
		if ( 'verified' === $st['state'] ) {
			return $st['value'];
		}
		if ( 'community' === $st['state'] ) {
			return array(
				'v' => $st['value'],
				's' => $st['calc_usable'] ? 'c' : 'x',
			);
		}
		return array( 's' => 'u' );
	}

	/** Last change of a value through the import (changelog) is not known here; see Repository. */
	public function count( string $entity ): int {
		return count( $this->all( $entity ) );
	}
}
