<?php
/**
 * Dataset-level validation and anomaly checks (DATA-02, DATA-03, DATA-05, DATA-10, DATA-12,
 * DATA-34, DATA-35, ED-09). Cell-level typing happens in Normalizer.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

use RBX\Data\Config\Game;
use RBX\Data\Config\Tabs;
use RBX\Data\Data\Dataset;
use RBX\Data\Formula\Interpreter;
use RBX\Data\Formula\Parser;
use RBX\Data\Formula\SyntaxError;
use RBX\Data\Formula\Unavailable;

defined( 'ABSPATH' ) || exit;

/**
 * Pure checks over the normalized import and the current database rows.
 */
final class Validator {

	/**
	 * @param array<string, array<string, mixed>> $records    New records (id => record).
	 * @param array<string, array<string, mixed>> $meta       Parsed _meta.
	 * @param array<string, array<string, mixed>> $current    Current DB records of the game.
	 * @param array<int, string>                  $page_slugs Child-Page slugs under the game folder (REV C-05).
	 */
	public function __construct(
		private Game $game,
		private Issues $issues,
		private array $records,
		private array $meta,
		private array $current,
		private array $page_slugs = array(),
		private string $today = ''
	) {
		if ( '' === $this->today ) {
			$this->today = gmdate( 'Y-m-d' );
		}
	}

	/**
	 * @param array<string, array<int, string>> $enums Parsed _enums tab.
	 */
	public function run( array $enums = array() ): void {
		$this->ids();
		$this->refs();
		$this->names_and_slugs();
		$this->provenance();
		$this->entity_rules();
		$this->drops();
		$this->codes();
		$this->media();
		$this->meta_checks();
		$this->enums( $enums );
		$this->numeric_anomalies();
		$this->field_states();
		$this->formulas();
	}

	/** @param array<string, mixed> $r Record. */
	private function is_public( array $r ): bool {
		return in_array( $r['status'], Dataset::PUBLIC_STATUSES, true );
	}

	private function tab( string $id ): string {
		return (string) ( $this->records[ $id ]['_tab'] ?? '' );
	}

	private function row( string $id ): int {
		return (int) ( $this->records[ $id ]['_row'] ?? 0 );
	}

	private function ids(): void {
		foreach ( $this->records as $id => $r ) {
			$old = $this->current[ $id ] ?? null;
			if ( null === $old ) {
				continue;
			}
			if ( $old['entity'] !== $r['entity'] || $old['game'] !== $r['game'] ) {
				/* translators: 1: ID, 2: old entity */
				$this->issues->error( 'id_reuse', sprintf( __( 'ID %1$s already belongs to a %2$s record. IDs are never reused (DATA-02).', 'rbx-data' ), $id, $old['entity'] ), $this->tab( $id ), $this->row( $id ), $id, 'id' );
			}
			if ( 'retired_id' === $old['status'] && 'retired_id' !== $r['status'] ) {
				/* translators: %s: ID */
				$this->issues->error( 'id_reuse', sprintf( __( 'ID %s was retired and cannot be used again.', 'rbx-data' ), $id ), $this->tab( $id ), $this->row( $id ), $id, 'id' );
			}
		}
	}

	private function refs(): void {
		foreach ( $this->records as $id => $r ) {
			foreach ( $this->game->columns( (string) $r['entity'] ) as $field => $def ) {
				if ( ! in_array( $def['type'], array( 'ref', 'ref_list' ), true ) || 'id' === $field ) {
					continue;
				}
				$targets = (array) ( $def['target'] ?? array() );
				foreach ( (array) ( $r['data'][ $field ] ?? array() ) as $ref ) {
					if ( null === $ref ) {
						continue;
					}
					$t = $this->records[ $ref ] ?? null;
					if ( null === $t || 'retired_id' === $t['status'] ) {
						/* translators: 1: field, 2: ID */
						$this->issues->error( 'fk', sprintf( __( '%1$s points to %2$s, which does not exist or is retired.', 'rbx-data' ), $field, $ref ), $this->tab( $id ), $this->row( $id ), $id, $field );
						continue;
					}
					if ( ! in_array( '*', $targets, true ) && ! in_array( $t['entity'], $targets, true ) ) {
						/* translators: 1: field, 2: ID, 3: expected entity */
						$this->issues->error( 'fk', sprintf( __( '%1$s points to %2$s, but it must point to %3$s.', 'rbx-data' ), $field, $ref, implode( '/', $targets ) ), $this->tab( $id ), $this->row( $id ), $id, $field );
					}
				}
			}
		}
	}

	private function names_and_slugs(): void {
		$names   = array();
		$slugs   = array();
		$history = array();
		foreach ( $this->current as $id => $c ) {
			foreach ( (array) ( $c['data']['slug_history'] ?? array() ) as $old ) {
				$history[ $c['entity'] ][ $old ] = $id;
			}
			if ( ! empty( $c['slug'] ) && isset( $this->records[ $id ] ) && $this->records[ $id ]['slug'] !== $c['slug'] ) {
				$history[ $c['entity'] ][ (string) $c['slug'] ] = $id;
			}
		}
		foreach ( $this->records as $id => $r ) {
			$e = (string) $r['entity'];
			if ( $this->game->is_named( $e ) && '' !== $r['name'] ) {
				$k = $e . '|' . mb_strtolower( (string) $r['name'] );
				if ( isset( $names[ $k ] ) ) {
					/* translators: 1: name, 2: other ID */
					$this->issues->error( 'duplicate_name', sprintf( __( 'Name "%1$s" is also used by %2$s.', 'rbx-data' ), $r['name'], $names[ $k ] ), $this->tab( $id ), $this->row( $id ), $id, 'name' );
				}
				$names[ $k ] = $id;
			}
			$slug = $r['slug'];
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$k = $e . '|' . $slug;
			if ( isset( $slugs[ $k ] ) ) {
				/* translators: 1: slug, 2: other ID */
				$this->issues->error( 'slug', sprintf( __( 'Slug "%1$s" is also used by %2$s.', 'rbx-data' ), $slug, $slugs[ $k ] ), $this->tab( $id ), $this->row( $id ), $id, 'slug' );
			}
			$slugs[ $k ] = $id;
			if ( in_array( $slug, $this->game->reserved_slugs( $e ), true ) || in_array( $slug, $this->page_slugs, true ) ) {
				/* translators: %s: slug */
				$this->issues->error( 'slug', sprintf( __( 'Slug "%s" is reserved or equals a WordPress Page under this game.', 'rbx-data' ), $slug ), $this->tab( $id ), $this->row( $id ), $id, 'slug' );
			}
			if ( isset( $history[ $e ][ $slug ] ) && $history[ $e ][ $slug ] !== $id ) {
				/* translators: 1: slug, 2: other ID */
				$this->issues->error( 'slug', sprintf( __( 'Slug "%1$s" was used before by %2$s and redirects there. Pick another slug.', 'rbx-data' ), $slug, $history[ $e ][ $slug ] ), $this->tab( $id ), $this->row( $id ), $id, 'slug' );
			}
			$old = $this->current[ $id ] ?? null;
			if ( $old && ! empty( $old['slug'] ) && $old['slug'] !== $slug ) {
				/* translators: 1: old slug, 2: new slug */
				$this->issues->warn( 'slug_change', sprintf( __( 'Slug changes from "%1$s" to "%2$s". The old URL will redirect (301).', 'rbx-data' ), $old['slug'], $slug ), $this->tab( $id ), $this->row( $id ), $id, 'slug' );
			}
		}
	}

	private function provenance(): void {
		foreach ( $this->records as $id => $r ) {
			if ( ! $this->game->entity( (string) $r['entity'] ) || ! $this->is_public( $r ) ) {
				continue;
			}
			$d = $r['data'];
			if ( empty( $d['last_verified'] ) ) {
				$this->issues->error( 'provenance', __( 'Live records need last_verified (DATA-05).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'last_verified' );
			}
			if ( empty( $d['source_type'] ) ) {
				$this->issues->error( 'provenance', __( 'Live records need source_type.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'source_type' );
			} elseif ( 'in_game' === $d['source_type'] && empty( $d['evidence_media_id'] ) ) {
				$this->issues->error( 'provenance', __( 'in_game records need evidence_media_id (a screenshot row in media).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'evidence_media_id' );
			} elseif ( 'in_game' !== $d['source_type'] && empty( $d['source_url'] ) ) {
				$this->issues->error( 'provenance', __( 'official and community records need source_url.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'source_url' );
			}
			if ( empty( $d['verified_by'] ) ) {
				$this->issues->error( 'provenance', __( 'Live records need verified_by.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'verified_by' );
			}
			if ( 'removed_from_game' === $r['status'] && empty( $d['removed_in_update'] ) ) {
				$this->issues->error( 'provenance', __( 'removed_from_game needs removed_in_update.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'removed_in_update' );
			}
		}
		foreach ( $this->records as $id => $r ) {
			if ( 'PRV' !== $r['entity'] ) {
				continue;
			}
			$target = $this->records[ $r['data']['record_id'] ?? '' ] ?? null;
			if ( $target && null === $this->game->field( (string) $target['entity'], (string) $r['data']['field'] ) ) {
				/* translators: %s: field */
				$this->issues->error( 'provenance', sprintf( __( 'Provenance row names unknown field "%s".', 'rbx-data' ), $r['data']['field'] ), $this->tab( $id ), $this->row( $id ), $id, 'field' );
			}
		}
	}

	private function entity_rules(): void {
		foreach ( $this->records as $id => $r ) {
			$def = $this->game->entity( (string) $r['entity'] );
			if ( null === $def ) {
				continue;
			}
			foreach ( (array) ( $def['exactly_one_of'] ?? array() ) as $set ) {
				$n = count( array_filter( (array) $set, static fn( $f ) => null !== ( $r['data'][ $f ] ?? null ) ) );
				if ( $n > 1 ) {
					/* translators: %s: field list */
					$this->issues->error( 'one_of', sprintf( __( 'Fill only one of: %s.', 'rbx-data' ), implode( ', ', (array) $set ) ), $this->tab( $id ), $this->row( $id ), $id );
				}
			}
		}
	}

	private function drops(): void {
		$by_egg = array();
		foreach ( $this->records as $id => $r ) {
			if ( 'DRP' !== $r['entity'] || ! $this->is_public( $r ) ) {
				continue;
			}
			$by_egg[ (string) ( $r['data']['egg_id'] ?? '' ) ][] = $r;
			if ( null !== ( $r['data']['observed_rate'] ?? null ) && empty( $r['data']['sample_size'] ) ) {
				$this->issues->error( 'odds', __( 'observed_rate needs sample_size (CONT-22).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'sample_size' );
			}
		}
		foreach ( $by_egg as $egg => $rows ) {
			$sum = 0.0;
			$all = true;
			foreach ( $rows as $r ) {
				$p = $r['data']['chance_prob'] ?? null;
				if ( null === $p ) {
					$all = false;
				} else {
					$sum += (float) $p;
				}
			}
			if ( $sum > 1 + 1e-6 ) {
				/* translators: %s: egg ID */
				$this->issues->error( 'odds', sprintf( __( 'Drop chances of %s add up to more than 100%%.', 'rbx-data' ), $egg ), 'egg_drops', 0, $egg, 'chance_prob' );
			} elseif ( $all && abs( $sum - 1 ) > 0.001 ) {
				/* translators: %s: egg ID */
				$this->issues->warn( 'odds_incomplete', sprintf( __( 'Drop chances of %s do not add up to 100%%. The page will say the odds are incomplete.', 'rbx-data' ), $egg ), 'egg_drops', 0, $egg, 'chance_prob' );
			}
		}
	}

	private function codes(): void {
		$now = strtotime( $this->today . ' ' . gmdate( 'H:i:s' ) . ' UTC' );
		foreach ( $this->records as $id => $r ) {
			if ( 'COD' !== $r['entity'] || 'active' !== ( $r['data']['code_status'] ?? '' ) ) {
				continue;
			}
			$exp = $r['data']['expires_at'] ?? null;
			if ( $exp && strtotime( (string) $exp ) < $now ) {
				$this->issues->error( 'code', __( 'Active code has expires_at in the past. Mark it expired.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'expires_at' );
			}
			if ( empty( $r['data']['source_url'] ) || empty( $r['data']['last_tested_at'] ) ) {
				$this->issues->error( 'code', __( 'Active codes need an official source_url and last_tested_at (DATA-10).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id );
			} elseif ( strtotime( (string) $r['data']['last_tested_at'] ) < $now - 72 * HOUR_IN_SECONDS ) {
				$this->issues->warn( 'code_stale', __( 'Active code was tested more than 72 hours ago. It will show "Not re-checked since".', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'last_tested_at' );
			}
		}
	}

	private function media(): void {
		$live_refs = array();
		foreach ( $this->records as $id => $r ) {
			if ( ! $this->is_public( $r ) || ! $this->game->entity( (string) $r['entity'] ) ) {
				continue;
			}
			$live_refs[ (string) ( $r['data']['evidence_media_id'] ?? '' ) ] = true;
		}
		foreach ( $this->records as $id => $r ) {
			if ( 'MED' !== $r['entity'] ) {
				continue;
			}
			$d = $r['data'];
			if ( true === ( $d['contains_usernames'] ?? null ) ) {
				$linked = isset( $live_refs[ $id ] );
				foreach ( (array) ( $d['depicts_record_ids'] ?? array() ) as $rid ) {
					if ( isset( $this->records[ $rid ] ) && $this->is_public( $this->records[ $rid ] ) ) {
						$linked = true;
					}
				}
				if ( $linked ) {
					$this->issues->error( 'media_usernames', __( 'Screenshot shows usernames (contains_usernames=TRUE) and is linked to a live record. Blur or crop it first.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'contains_usernames' );
				}
			}
			if ( in_array( $d['image_source'] ?? '', array( 'licensed', 'press_kit' ), true ) && empty( $d['license_note'] ) ) {
				$this->issues->error( 'media_license', __( 'Licensed or press-kit images need license_note (POL-48).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'license_note' );
			}
		}
	}

	private function meta_checks(): void {
		$m   = $this->meta;
		$get = static fn( string $k ) => $m[ $k ]['value'] ?? null;
		if ( null !== $get( 'game_code' ) && $get( 'game_code' ) !== $this->game->code() ) {
			$this->issues->error( 'meta', __( '_meta game_code does not match this game.', 'rbx-data' ), Tabs::META_TAB, 0, '', 'game_code' );
		}
		if ( null !== $get( 'schema_version' ) && $get( 'schema_version' ) !== $this->game->schema_version() ) {
			/* translators: %s: expected version */
			$this->issues->error( 'meta', sprintf( __( '_meta schema_version must be %s.', 'rbx-data' ), $this->game->schema_version() ), Tabs::META_TAB, 0, '', 'schema_version' );
		}
		foreach ( array( 'roblox_universe_id' => $this->game->universe_id(), 'roblox_place_id' => $this->game->place_id() ) as $k => $cfg ) {
			$v = $get( $k );
			if ( null !== $cfg && null !== $v && (string) $v !== $cfg ) {
				/* translators: %s: key */
				$this->issues->error( 'meta_ids', sprintf( __( '_meta %s differs from the game config (ED-09).', 'rbx-data' ), $k ), Tabs::META_TAB, 0, '', $k );
			} elseif ( null === $cfg && null !== $v ) {
				/* translators: %s: key */
				$this->issues->warn( 'meta_ids', sprintf( __( '_meta has %s but the game config does not yet. Enter it under RBX Data, Setup so both match.', 'rbx-data' ), $k ), Tabs::META_TAB, 0, '', $k );
			}
		}
		foreach ( array( 'game_name', 'developer_name', 'release_date', 'redeem_ui_exists', 'update_cadence_text' ) as $k ) {
			if ( null === $get( $k ) ) {
				/* translators: %s: key */
				$this->issues->warn( 'meta_missing', sprintf( __( '_meta %s is empty. The hub will leave that fact out.', 'rbx-data' ), $k ), Tabs::META_TAB, 0, '', $k );
			}
		}
		foreach ( $m as $k => $row ) {
			if ( null !== $row['value'] && ( empty( $row['source_type'] ) || empty( $row['last_verified'] ) ) && ! in_array( $k, array( 'game_code', 'schema_version', 'timezone_note' ), true ) ) {
				/* translators: %s: key */
				$this->issues->warn( 'meta_provenance', sprintf( __( '_meta %s has no source_type or last_verified.', 'rbx-data' ), $k ), Tabs::META_TAB, 0, '', $k );
			}
		}
		$defaults = $get( 'calc_defaults' );
		if ( is_array( $defaults ) ) {
			foreach ( $defaults as $tool_key => $vals ) {
				$tool = $this->game->tool( (string) $tool_key );
				if ( null === $tool || ! is_array( $vals ) ) {
					/* translators: %s: tool key */
					$this->issues->error( 'calc_defaults', sprintf( __( 'calc_defaults names unknown tool "%s".', 'rbx-data' ), $tool_key ), Tabs::META_TAB, 0, '', 'calc_defaults' );
					continue;
				}
				foreach ( $vals as $in => $v ) {
					$decl = null;
					foreach ( (array) $tool['inputs'] as $i ) {
						if ( $i['key'] === $in ) {
							$decl = $i;
						}
					}
					if ( null === $decl ) {
						/* translators: %s: input key */
						$this->issues->error( 'calc_defaults', sprintf( __( 'calc_defaults names unknown input "%s".', 'rbx-data' ), $in ), Tabs::META_TAB, 0, '', 'calc_defaults' );
					} elseif ( 'ref' === $decl['type'] && null !== $v && ( ! isset( $this->records[ $v ] ) || $this->records[ $v ]['entity'] !== $decl['target'] || ! $this->is_public( $this->records[ $v ] ) ) ) {
						/* translators: %s: ID */
						$this->issues->error( 'calc_defaults', sprintf( __( 'calc_defaults points to %s, which is not a live record of the right type.', 'rbx-data' ), (string) $v ), Tabs::META_TAB, 0, '', 'calc_defaults' );
					}
				}
			}
		}
	}

	/** @param array<string, array<int, string>> $enums Enums tab. */
	private function enums( array $enums ): void {
		foreach ( $enums as $name => $vals ) {
			foreach ( $this->game->entities() as $code => $def ) {
				foreach ( (array) $def['fields'] as $field => $f ) {
					if ( 'enum' === $f['type'] && $field === $name && array_values( (array) $f['enum'] ) !== $vals ) {
						/* translators: %s: enum name */
						$this->issues->warn( 'enums', sprintf( __( 'The _enums column "%s" differs from the site config. The config wins; regenerate the Sheet template to fix the dropdown.', 'rbx-data' ), $name ), Tabs::ENUMS_TAB );
						break 2;
					}
				}
			}
		}
	}

	private function numeric_anomalies(): void {
		$groups = array();
		foreach ( $this->records as $id => $r ) {
			$def = $this->game->entity( (string) $r['entity'] );
			if ( null === $def ) {
				continue;
			}
			$old = $this->current[ $id ] ?? null;
			foreach ( (array) $def['fields'] as $field => $f ) {
				if ( ! in_array( $f['type'], Dataset::NUMERIC, true ) ) {
					continue;
				}
				$new = $r['data'][ $field ] ?? null;
				$was = $old['data'][ $field ] ?? null;
				if ( is_numeric( $new ) && is_numeric( $was ) && 0.0 !== (float) $was ) {
					$pct = ( (float) $new - (float) $was ) / abs( (float) $was );
					if ( abs( $pct ) > 0.5 ) {
						/* translators: 1: field, 2: old, 3: new */
						$this->issues->warn( 'large_change', sprintf( __( '%1$s changes by more than 50%% (%2$s to %3$s).', 'rbx-data' ), $field, (string) $was, (string) $new ), $this->tab( $id ), $this->row( $id ), $id, $field );
					}
				}
				if ( ! empty( $f['compare'] ) && is_numeric( $new ) && (float) $new > 0 && $this->is_public( $r ) && isset( $r['data']['rarity_id'] ) ) {
					$groups[ $r['entity'] . '|' . $field ][ (string) $r['data']['rarity_id'] ][ $id ] = log( (float) $new );
				}
			}
		}
		foreach ( $groups as $key => $by_rarity ) {
			list( , $field ) = explode( '|', $key );
			foreach ( $by_rarity as $vals ) {
				if ( count( $vals ) < 4 ) {
					continue;
				}
				$s = array_values( $vals );
				sort( $s );
				$q1  = self::quantile( $s, 0.25 );
				$q3  = self::quantile( $s, 0.75 );
				$iqr = $q3 - $q1;
				foreach ( $vals as $id => $v ) {
					if ( $v < $q1 - 3 * $iqr || $v > $q3 + 3 * $iqr ) {
						/* translators: %s: field */
						$this->issues->warn( 'outlier', sprintf( __( '%s is far outside the range of its rarity group. Double-check it.', 'rbx-data' ), $field ), $this->tab( $id ), $this->row( $id ), $id, $field );
					}
				}
			}
			$medians = array();
			foreach ( $by_rarity as $rid => $vals ) {
				$s = array_values( $vals );
				sort( $s );
				$medians[ $rid ] = self::quantile( $s, 0.5 );
			}
			$order = array();
			foreach ( array_keys( $medians ) as $rid ) {
				$order[ $rid ] = (int) ( $this->records[ $rid ]['data']['sort_order'] ?? 0 );
			}
			asort( $order );
			$prev = null;
			foreach ( array_keys( $order ) as $rid ) {
				if ( null !== $prev ) {
					foreach ( $by_rarity[ $rid ] as $id => $v ) {
						if ( $v < $medians[ $prev ] ) {
							/* translators: %s: field */
							$this->issues->warn( 'rarity_order', sprintf( __( '%s is lower than the median of the rarity below. Double-check it.', 'rbx-data' ), $field ), $this->tab( $id ), $this->row( $id ), $id, $field );
						}
					}
				}
				$prev = $rid;
			}
		}
	}

	/** @param array<int, float> $sorted Sorted values. */
	private static function quantile( array $sorted, float $q ): float {
		$n   = count( $sorted );
		$pos = ( $n - 1 ) * $q;
		$lo  = (int) floor( $pos );
		$hi  = (int) ceil( $pos );
		return $sorted[ $lo ] + ( $sorted[ $hi ] - $sorted[ $lo ] ) * ( $pos - $lo );
	}

	private function field_states(): void {
		$ds   = $this->dataset();
		$cut  = gmdate( 'Y-m-d', (int) strtotime( $this->today . ' -' . $this->game->freshness_days() . ' days' ) );
		foreach ( $this->records as $id => $r ) {
			$def = $this->game->entity( (string) $r['entity'] );
			if ( null === $def || ! $this->is_public( $r ) ) {
				continue;
			}
			if ( ! empty( $r['data']['last_verified'] ) && $r['data']['last_verified'] < $cut ) {
				$this->issues->warn( 'stale', __( 'last_verified is older than the freshness window. Re-check this record.', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'last_verified' );
			}
			foreach ( (array) $def['fields'] as $field => $f ) {
				$st = $ds->field_state( $r, $field );
				if ( 'disputed' === $st['state'] ) {
					/* translators: %s: field */
					$this->issues->warn( 'disputed', sprintf( __( '%s has conflicting sources and no single selected row.', 'rbx-data' ), $field ), $this->tab( $id ), $this->row( $id ), $id, $field );
				} elseif ( 'community' === $st['state'] && ! empty( $f['required_for_index'] ) ) {
					/* translators: %s: field */
					$this->issues->warn( 'community_required', sprintf( __( '%s is only a community report. The page will stay noindex.', 'rbx-data' ), $field ), $this->tab( $id ), $this->row( $id ), $id, $field );
				}
			}
		}
	}

	private ?Dataset $ds = null;

	public function dataset(): Dataset {
		if ( null === $this->ds ) {
			$all = $this->records;
			$all[ $this->game->code() . '-META' ] = array(
				'id'     => $this->game->code() . '-META',
				'game'   => $this->game->code(),
				'entity' => 'META',
				'slug'   => null,
				'name'   => '',
				'status' => 'live',
				'data'   => $this->meta,
			);
			$this->ds = new Dataset( $this->game, $all, $this->today );
		}
		return $this->ds;
	}

	/**
	 * Compiles every formula and runs live tests in PHP (DATA-25, DATA-34).
	 */
	private function formulas(): void {
		$ds = $this->dataset();
		foreach ( $this->records as $id => $r ) {
			if ( 'FRM' !== $r['entity'] ) {
				continue;
			}
			$d = $r['data'];
			try {
				Parser::compile( (string) $d['expression'], is_array( $d['inputs'] ?? null ) ? $d['inputs'] : array(), array( $ds, 'is_numeric_field' ) );
			} catch ( SyntaxError $e ) {
				$this->issues->error( 'formula', __( 'Formula does not compile:', 'rbx-data' ) . ' ' . $e->getMessage(), $this->tab( $id ), $this->row( $id ), $id, 'expression' );
				continue;
			}
			if ( 'live' !== ( $d['status'] ?? '' ) ) {
				continue;
			}
			if ( ! $ds->mechanics_confirmed( (array) ( $d['requires_mechanics'] ?? array() ) ) ) {
				$this->issues->error( 'formula', __( 'Formula is live but one of its mechanics is not confirmed (DATA-12).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'status' );
			}
			$wiki = $ds->game()->wiki_formula_ok();
			$live = array_filter( $ds->tests_for( $id ), static fn( $t ) => 'live' === ( $t['data']['status'] ?? '' ) && ( $wiki || ! empty( $t['data']['evidence_media_id'] ) ) );
			if ( count( $live ) < $ds->game()->min_formula_tests() ) {
				/* translators: %d: minimum number of live tests */
				$this->issues->error( 'formula_tests', sprintf( __( 'Live formulas need at least %d live tests with screenshot evidence (DATA-34).', 'rbx-data' ), $ds->game()->min_formula_tests() ), $this->tab( $id ), $this->row( $id ), $id, 'status' );
			}
		}
		foreach ( self::run_tests( $ds ) as $res ) {
			if ( ! $res['pass'] ) {
				$this->issues->error( 'formula_test_failed', $res['message'], 'formula_tests', $this->row( $res['id'] ), $res['id'], 'expected' );
			}
		}
		foreach ( $this->records as $id => $r ) {
			if ( 'TST' === $r['entity'] && 'live' === ( $r['data']['status'] ?? '' ) && empty( $r['data']['evidence_media_id'] ) && ! $ds->game()->wiki_formula_ok() ) {
				$this->issues->error( 'formula_tests', __( 'Live tests need evidence_media_id (a screenshot of the in-game result).', 'rbx-data' ), $this->tab( $id ), $this->row( $id ), $id, 'evidence_media_id' );
			}
		}
	}

	/**
	 * Runs every live formula test (needs_recheck and draft tests are skipped).
	 *
	 * @return array<int, array{id:string, formula:string, pass:bool, expected:float, actual:float|null, message:string, evidence:string}>
	 */
	public static function run_tests( Dataset $ds ): array {
		$out  = array();
		$data = $ds->calc_data();
		foreach ( $ds->all( 'TST', null ) as $tid => $t ) {
			if ( 'live' !== ( $t['data']['status'] ?? '' ) ) {
				continue;
			}
			$f = $ds->get( (string) $t['data']['formula_id'] );
			if ( null === $f ) {
				continue;
			}
			$exp = (float) $t['data']['expected'];
			$act = null;
			$msg = '';
			try {
				$ast = Parser::compile( (string) $f['data']['expression'], (array) $f['data']['inputs'], array( $ds, 'is_numeric_field' ) );
				$act = Interpreter::evaluate( $ast, self::test_inputs( $ds, (array) $f['data']['inputs'], (array) $t['data']['inputs'] ), $data );
				$tol = max( (float) ( $t['data']['tolerance_abs'] ?? 0 ), (float) ( $t['data']['tolerance_rel'] ?? 0 ) * abs( $exp ), 1e-9 * max( 1, abs( $exp ) ) );
				$ok  = abs( $act - $exp ) <= $tol;
			} catch ( Unavailable $e ) {
				$ok  = false;
				$msg = $e->getMessage();
			} catch ( SyntaxError $e ) {
				$ok  = false;
				$msg = $e->getMessage();
			}
			$out[] = array(
				'id'       => (string) $tid,
				'formula'  => (string) $f['data']['key'],
				'pass'     => $ok,
				'expected' => $exp,
				'actual'   => $act,
				'evidence' => (string) ( $t['data']['evidence_media_id'] ?? '' ),
				/* translators: 1: test ID, 2: formula key, 3: expected, 4: actual, 5: evidence media ID */
				'message'  => $ok ? '' : sprintf( __( 'Test %1$s of %2$s failed: expected %3$s, got %4$s (evidence %5$s).', 'rbx-data' ), $tid, $f['data']['key'], (string) $exp, null === $act ? $msg : (string) $act, (string) ( $t['data']['evidence_media_id'] ?? '-' ) ),
			);
		}
		return $out;
	}

	/**
	 * Test inputs may name records by ID or slug.
	 *
	 * @param array<string, array<string, mixed>> $decl   Declared inputs.
	 * @param array<string, mixed>                $inputs Test inputs.
	 * @return array<string, mixed>
	 */
	public static function test_inputs( Dataset $ds, array $decl, array $inputs ): array {
		$out = array();
		foreach ( $inputs as $k => $v ) {
			$type = (string) ( $decl[ $k ]['type'] ?? '' );
			if ( 'ref' === $type && is_string( $v ) && null === $ds->get( $v ) ) {
				foreach ( $ds->all( (string) ( $decl[ $k ]['target'] ?? '' ), null ) as $id => $r ) {
					if ( $r['slug'] === $v ) {
						$v = $id;
					}
				}
			}
			$out[ $k ] = $v;
		}
		return $out;
	}
}
