<?php
/**
 * The only implementation of rbx_page_state(record) → not_generated | noindex | index (DATA-20).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Gate;

use RBX\Data\Data\Dataset;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates G- and I-rules for one record. Pure apart from the callables passed in.
 */
final class PageState {

	public const NOT_GENERATED = 'not_generated';
	public const NOINDEX       = 'noindex';
	public const INDEX         = 'index';

	/** @var array<string, array<string, array<string, true>>> */
	private array $shingle_cache = array();

	/**
	 * @param Dataset                $ds   Dataset.
	 * @param array<string, mixed>   $opts field_changed: callable(id, field): ?string (Y-m-d);
	 *                                     update_changes: callable(id): int;
	 *                                     error_ids: string[]; today: Y-m-d.
	 */
	public function __construct( private Dataset $ds, private array $opts = array() ) {}

	/**
	 * A note counts as reviewed only by a human editor: an id in content/author.json `reviewer_ids`
	 * (or `opts['reviewer_ids']`). Without that list any reviewer except the AI drafter counts.
	 */
	private function is_reviewer( string $who ): bool {
		$who = strtolower( trim( $who ) );
		if ( '' === $who ) {
			return false;
		}
		$ids = isset( $this->opts['reviewer_ids'] ) ? (array) $this->opts['reviewer_ids'] : \RBX\Data\Render\Ui::reviewer_ids();
		return $ids ? in_array( $who, $ids, true ) : 'claude' !== $who;
	}

	/**
	 * @param array<string, mixed> $rec  Record.
	 * @param array<string, mixed> $post override ('' | noindex | index), manual_index_ok (bool), was_indexed (bool).
	 * @return array{state:string, reasons:array<int, string>, page_last_verified:?string, notes:array<int, string>}
	 */
	public function evaluate( array $rec, array $post = array() ): array {
		$game    = $this->ds->game();
		$entity  = (string) $rec['entity'];
		$def     = $game->entity( $entity ) ?? array();
		$reasons = array();
		$notes   = array();
		$today   = (string) ( $this->opts['today'] ?? $this->ds->today() );

		// G-rules.
		if ( empty( $def['page'] ) ) {
			$reasons[] = 'G-1';
		}
		if ( ! in_array( $rec['status'], Dataset::PUBLIC_STATUSES, true ) ) {
			$reasons[] = 'G-2';
		}
		$d = $rec['data'];
		if ( ! is_string( $rec['slug'] ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $rec['slug'] )
			|| ( $game->is_named( $entity ) && '' === (string) $rec['name'] )
			|| empty( $d['source_type'] ) || empty( $d['last_verified'] )
			|| ( empty( $d['source_url'] ) && empty( $d['evidence_media_id'] ) ) ) {
			$reasons[] = 'G-3';
		}
		if ( $reasons ) {
			return array(
				'state'              => self::NOT_GENERATED,
				'reasons'            => $reasons,
				'page_last_verified' => null,
				'notes'              => $notes,
			);
		}

		$required = array();
		foreach ( (array) $def['fields'] as $f => $fd ) {
			if ( ! empty( $fd['required_for_index'] ) ) {
				$required[] = $f;
			}
		}

		// I-1 required fields verified (or community, when the game allows it).
		$i1 = true;
		foreach ( $required as $f ) {
			if ( ! self::counts( $this->ds, $this->ds->field_state( $rec, $f ) ) ) {
				$i1 = false;
			}
		}
		foreach ( (array) ( $def['index_any_of'] ?? array() ) as $set ) {
			$any = false;
			foreach ( (array) $set as $f ) {
				if ( self::counts( $this->ds, $this->ds->field_state( $rec, $f ) ) ) {
					$any        = true;
					$required[] = $f;
					break;
				}
			}
			$i1 = $i1 && $any;
		}
		foreach ( (array) ( $def['required_relations_for_index'] ?? array() ) as $rel ) {
			if ( count( $this->ds->children( (string) $rel['entity'], (string) $rel['field'], (string) $rec['id'] ) ) < (int) ( $rel['min'] ?? 1 ) ) {
				$i1 = false;
			}
		}
		if ( 'UPD' === $entity ) {
			if ( empty( $d['official_source_url'] ) && empty( $d['roblox_updated_at'] ) ) {
				$i1 = false;
			}
			$changes = isset( $this->opts['update_changes'] ) ? (int) ( $this->opts['update_changes'] )( (string) $rec['id'] ) : 0;
			// Records tagged added_in_update / removed_in_update also fill the "What's new" list.
			foreach ( $this->ds->records() as $r ) {
				if ( $changes > 0 ) {
					break;
				}
				if ( in_array( $r['status'], Dataset::PUBLIC_STATUSES, true ) && in_array( (string) $rec['id'], array( (string) ( $r['data']['added_in_update'] ?? '' ), (string) ( $r['data']['removed_in_update'] ?? '' ) ), true ) ) {
					++$changes;
				}
			}
			if ( $changes < 1 ) {
				$i1 = false;
			}
		}
		if ( ! $i1 ) {
			$reasons[] = 'I-1';
		}

		// I-2 freshness (min last_verified over displayed required fields).
		$plv = null;
		foreach ( $required as $f ) {
			$lv = $this->ds->field_state( $rec, $f )['last_verified'] ?? null;
			if ( is_string( $lv ) && ( null === $plv || $lv < $plv ) ) {
				$plv = $lv;
			}
		}
		$plv  = $plv ?? ( is_string( $d['last_verified'] ?? null ) ? $d['last_verified'] : null );
		$days = $this->ds->game()->freshness_days();
		if ( null !== $plv ) {
			$cut  = gmdate( 'Y-m-d', (int) strtotime( $today . ' -' . $days . ' days' ) );
			$cut2 = gmdate( 'Y-m-d', (int) strtotime( $today . ' -' . ( 2 * $days ) . ' days' ) );
			if ( $plv < $cut ) {
				if ( ! empty( $post['was_indexed'] ) && $plv >= $cut2 ) {
					$notes[] = 'stale';
				} else {
					$reasons[] = 'I-2';
				}
			}
		}

		// I-9 index scope (GR-15, Ali 2026-10-24): an entity with `"index": false` in the game config
		// keeps every page out of search, whatever its notes say. The near-duplicate checks below
		// are then skipped: they are the slowest part of a gate run and cannot change the result.
		$scoped_out = array_key_exists( 'index', $def ) && false === $def['index'];
		if ( $scoped_out ) {
			$reasons[] = 'I-9';
		}

		// I-3 unique editorial block, reviewed by a person listed in content/author.json (UO-02).
		if ( $scoped_out ) {
			// Nothing to compare.
		} elseif ( isset( $def['fields']['notes__en'] ) ) {
			if ( '' === trim( (string) ( $d['notes__en'] ?? '' ) ) || ! $this->is_reviewer( (string) ( $d['notes_reviewed_by'] ?? '' ) ) || empty( $d['notes_reviewed_on'] ) ) {
				$reasons[] = 'I-3';
			} else {
				$mine = $this->shingles( $rec, 'notes' );
				foreach ( $this->ds->all( $entity ) as $sid => $sib ) {
					if ( $sid !== $rec['id'] && Similarity::jaccard( $mine, $this->shingles( $sib, 'notes' ) ) >= 0.6 ) {
						$reasons[] = 'I-3';
						break;
					}
				}
			}
		}

		// I-4 page-level near duplicate. Only worth the comparisons while the page could still be
		// indexed: a page with a reason already is noindex, and an "index" override ignores I-4.
		if ( ! $reasons ) {
			$mine = $this->shingles( $rec, 'page' );
			foreach ( $this->ds->all( $entity ) as $sid => $sib ) {
				if ( $sid !== $rec['id'] && Similarity::jaccard( $mine, $this->shingles( $sib, 'page' ) ) >= 0.8 ) {
					$reasons[] = 'I-4';
					break;
				}
			}
		}

		// I-5 screenshot evidence.
		if ( ! empty( $def['require_screenshot_for_index'] ) && ! $game->index_community() ) {
			$all_official = (bool) $required;
			foreach ( $required as $f ) {
				if ( 'official' !== ( $this->ds->field_state( $rec, $f )['source_type'] ?? null ) ) {
					$all_official = false;
				}
			}
			if ( ! $all_official && ! $this->has_fresh_screenshot( $rec, $required ) ) {
				$reasons[] = 'I-5';
			}
		}

		// I-6 comparison computable (skipped like I-4 once the page is noindex for another reason).
		$compare = array();
		foreach ( (array) $def['fields'] as $f => $fd ) {
			if ( ! empty( $fd['compare'] ) ) {
				$compare[] = $f;
			}
		}
		if ( $compare && ! $reasons && ! $this->comparison_ok( $rec, $compare, (array) ( $def['siblings_by'] ?? array() ) ) && empty( $post['manual_index_ok'] ) ) {
			$reasons[] = 'I-6';
		}

		// I-7 unresolved errors.
		if ( in_array( $rec['id'], (array) ( $this->opts['error_ids'] ?? array() ), true ) ) {
			$reasons[] = 'I-7';
		}

		$reasons  = array_values( array_unique( $reasons ) );
		$override = (string) ( $post['override'] ?? '' );
		if ( 'noindex' === $override ) {
			$state = self::NOINDEX;
			if ( ! in_array( 'override', $reasons, true ) ) {
				$reasons[] = 'override';
			}
		} elseif ( 'index' === $override ) {
			$hard  = array_intersect( $reasons, array( 'I-1', 'I-7', 'I-8', 'I-9' ) );
			$state = $hard ? self::NOINDEX : self::INDEX;
			if ( ! $hard ) {
				$notes[] = 'forced_index';
			}
		} else {
			$state = $reasons ? self::NOINDEX : self::INDEX;
		}
		return array(
			'state'              => $state,
			'reasons'            => $reasons,
			'page_last_verified' => $plv,
			'notes'              => $notes,
		);
	}

	/**
	 * @param array<string, mixed> $rec Record.
	 * @return array<string, true>
	 */
	private function shingles( array $rec, string $kind ): array {
		$id = (string) $rec['id'];
		if ( isset( $this->shingle_cache[ $kind ][ $id ] ) ) {
			return $this->shingle_cache[ $kind ][ $id ];
		}
		if ( 'notes' === $kind ) {
			$text = (string) ( $rec['data']['notes__en'] ?? '' );
		} else {
			$parts = array( (string) $rec['name'] );
			foreach ( (array) ( $this->ds->game()->entity( (string) $rec['entity'] )['fields'] ?? array() ) as $f => $fd ) {
				if ( ! empty( $fd['internal'] ) ) {
					continue;
				}
				$v = $rec['data'][ $f ] ?? null;
				if ( is_string( $v ) && $this->ds->get( $v ) ) {
					$v = $this->ds->get( $v )['name'];
				}
				if ( is_scalar( $v ) ) {
					$parts[] = $this->ds->game()->label( (string) $rec['entity'], $f ) . ' ' . (string) $v;
				}
			}
			$text = implode( ' ', $parts );
		}
		$this->shingle_cache[ $kind ][ $id ] = Similarity::shingles( $text );
		return $this->shingle_cache[ $kind ][ $id ];
	}

	/**
	 * @param array<string, mixed> $rec      Record.
	 * @param array<int, string>   $required Required fields.
	 */
	private function has_fresh_screenshot( array $rec, array $required ): bool {
		$changed = $this->opts['field_changed'] ?? null;
		$newest  = '';
		foreach ( $required as $f ) {
			$c = is_callable( $changed ) ? $changed( (string) $rec['id'], $f ) : null;
			if ( is_string( $c ) && $c > $newest ) {
				$newest = $c;
			}
		}
		foreach ( $this->ds->media_for( (string) $rec['id'] ) as $m ) {
			$md = $m['data'];
			// A wiki picture (Phase E) is not first-hand evidence, so it never counts here (GR-01).
			if ( true === ( $md['contains_usernames'] ?? true ) || empty( $md['image_source'] ) || 'wiki_cc' === $md['image_source'] || empty( $md['image_credit'] ) ) {
				continue;
			}
			if ( (string) $md['captured_on'] >= $newest ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $rec     Record.
	 * @param array<int, string>   $compare Compare fields.
	 * @param array<int, string>   $by      Sibling grouping fields.
	 */
	private function comparison_ok( array $rec, array $compare, array $by ): bool {
		return count( $this->siblings( $rec, $compare, $by, 6 ) ) >= 2;
	}

	/**
	 * Deterministic siblings: same grouping value, comparable stat verified, nearest first (DATA-22b block 3).
	 *
	 * @param array<string, mixed> $rec     Record.
	 * @param array<int, string>   $compare Compare fields.
	 * @param array<int, string>   $by      Sibling grouping fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function siblings( array $rec, array $compare, array $by, int $max ): array {
		return self::find_siblings( $this->ds, $rec, $compare, $by, $max );
	}

	/** A field state that counts toward indexing: verified, or community when the game allows it. */
	private static function counts( Dataset $ds, array $st ): bool {
		return $st['mechanic_ok'] && ( 'verified' === $st['state'] || ( 'community' === $st['state'] && $ds->game()->index_community() ) );
	}

	/**
	 * @param array<string, mixed> $rec     Record.
	 * @param array<int, string>   $compare Compare fields.
	 * @param array<int, string>   $by      Sibling grouping fields.
	 * @return array<string, array<string, mixed>>
	 */
	public static function find_siblings( Dataset $ds, array $rec, array $compare, array $by, int $max ): array {
		$field = null;
		foreach ( $compare as $f ) {
			$st = $ds->field_state( $rec, $f );
			if ( self::counts( $ds, $st ) && is_numeric( $st['value'] ) ) {
				$field = $f;
				break;
			}
		}
		if ( null === $field ) {
			return array();
		}
		$mine = (float) $ds->field_state( $rec, $field )['value'];
		$cand = array();
		foreach ( $ds->all( (string) $rec['entity'], array( 'live', 'limited' ) ) as $sid => $s ) {
			if ( $sid === $rec['id'] ) {
				continue;
			}
			$same = true;
			foreach ( $by as $b ) {
				if ( ( $s['data'][ $b ] ?? null ) !== ( $rec['data'][ $b ] ?? null ) ) {
					$same = false;
				}
			}
			$st = $ds->field_state( $s, $field );
			if ( ! $same || ! self::counts( $ds, $st ) || ! is_numeric( $st['value'] ) ) {
				continue;
			}
			$cand[ $sid ] = array(
				'record' => $s,
				'dist'   => abs( log( max( 1e-300, (float) $st['value'] ) ) - log( max( 1e-300, $mine ) ) ),
				'field'  => $field,
			);
		}
		uasort(
			$cand,
			static fn( $a, $b ) => ( $a['dist'] <=> $b['dist'] ) ?: strcmp( (string) $a['record']['id'], (string) $b['record']['id'] )
		);
		return array_slice( $cand, 0, $max, true );
	}
}
