<?php
/**
 * Import pipeline (DATA-19..24): prepare a dry run, apply it in resumable batches, finalize,
 * and roll a run back from its snapshots.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Changelog;
use RBX\Data\Db\Records;
use RBX\Data\Db\Runs;
use RBX\Data\Gate\PageState;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Run statuses: blocked (errors), ready (dry run waiting for approval), applying, applied,
 * failed, discarded, rolled_back.
 */
final class Runner {

	public const BATCH       = 200;
	public const LOCK_OPTION = 'rbx_import_lock';
	public const LOCK_TTL    = 900;

	/** Fields whose change never counts as "large" (bookkeeping). */
	private const QUIET = array( 'last_verified', 'verified_by', 'source_note', 'notes_reviewed_by', 'notes_reviewed_on', 'slug_history', 'last_tested_at', 'tested_by' );

	/* ---------------------------------------------------------------- dry run */

	/**
	 * Parses, validates and diffs. Never writes records, changelog, posts or exports (DATA-23).
	 *
	 * @param array{tabs: array<string, array<int, array<int, string>>>, warnings: array<int, string>} $input Source output.
	 * @return int Run ID.
	 */
	public static function prepare( Game $game, array $input, string $source, int $user_id ): int {
		$issues = new Issues();
		foreach ( $input['warnings'] as $w ) {
			$issues->warn( 'private_tab', $w );
		}
		$today  = current_time( 'Y-m-d' );
		$norm   = new Normalizer( $game, $issues, $today );
		$parsed = $norm->run( $input['tabs'] );

		$current = Records::for_game( $game->code() );
		$meta_id = $game->code() . '-META';
		unset( $current[ $meta_id ] );

		$validator = new Validator( $game, $issues, $parsed['records'], $parsed['meta'], $current, self::page_slugs( $game ), $today );
		$validator->run( $parsed['enums'] );

		$ops      = array();
		$counts   = array();
		$max_lv   = '';
		foreach ( $parsed['records'] as $id => $rec ) {
			$tab = (string) $rec['_tab'];
			$counts[ $tab ] ??= self::zero();
			unset( $rec['_tab'], $rec['_row'] );
			$rec['content_hash'] = Normalizer::hash( $rec );
			$lv                  = (string) ( $rec['data']['last_verified'] ?? '' );
			$max_lv              = max( $max_lv, $lv );
			$old                 = $current[ $id ] ?? null;
			if ( null === $old ) {
				$ops[] = array(
					'op'  => 'create',
					'id'  => $id,
					'tab' => $tab,
					'rec' => $rec,
				);
				++$counts[ $tab ]['create'];
				continue;
			}
			if ( $old['content_hash'] === $rec['content_hash'] && $old['status'] === $rec['status'] && (string) $old['slug'] === (string) $rec['slug'] ) {
				++$counts[ $tab ]['unchanged'];
				continue;
			}
			$diff  = self::diff( $old['data'], $rec['data'] );
			$large = array_values( array_filter( $diff, static fn( $d ) => ! empty( $d['large'] ) ) );
			if ( $old['status'] !== $rec['status'] && $old['post_id'] && get_post_meta( (int) $old['post_id'], '_rbx_was_indexed', true ) ) {
				$large[] = array( 'field' => 'status' );
			}
			$ops[] = array(
				'op'    => 'update',
				'id'    => $id,
				'tab'   => $tab,
				'rec'   => $rec,
				'diff'  => $diff,
				'large' => (bool) $large,
				'url'   => $old['post_id'] ? (string) get_permalink( (int) $old['post_id'] ) : '',
			);
			++$counts[ $tab ]['update'];
		}
		$missing = array();
		foreach ( $current as $id => $old ) {
			if ( ! isset( $parsed['records'][ $id ] ) && 'removed_from_game' !== $old['status'] && 'draft' !== $old['status'] ) {
				$tab               = (string) ( $game->tab_for( (string) $old['entity'] ) ?? $old['entity'] );
				$counts[ $tab ]  ??= self::zero();
				++$counts[ $tab ]['missing'];
				$missing[] = array(
					'id'     => $id,
					'name'   => (string) $old['name'],
					'tab'    => $tab,
					'status' => (string) $old['status'],
				);
			}
		}
		foreach ( $issues->all() as $i ) {
			if ( '' !== $i['tab'] ) {
				$counts[ $i['tab'] ] ??= self::zero();
				++$counts[ $i['tab'] ][ 'error' === $i['sev'] ? 'errors' : 'warnings' ];
			}
		}
		$meta_changed = self::meta_changed( $game, $parsed['meta'] );
		$predicted    = self::predict( $game, $validator->dataset(), $current, $issues->error_ids() );
		$tests        = $issues->error_count() ? array() : Validator::run_tests( $validator->dataset() );

		$plan = array(
			'ops'          => $ops,
			'missing'      => $missing,
			'meta'         => $parsed['meta'],
			'meta_changed' => $meta_changed,
			'issues'       => $issues->all(),
			'warn_classes' => $issues->warning_classes(),
			'predicted'    => $predicted,
			'tests'        => $tests,
			'max_lv'       => $max_lv,
			'today'        => $today,
		);
		$total = array(
			'create'    => array_sum( array_column( $counts, 'create' ) ),
			'update'    => array_sum( array_column( $counts, 'update' ) ),
			'unchanged' => array_sum( array_column( $counts, 'unchanged' ) ),
			'missing'   => count( $missing ),
			'errors'    => $issues->error_count(),
			'warnings'  => count( $issues->all() ) - $issues->error_count(),
			'tabs'      => $counts,
		);
		return Runs::create(
			array(
				'game'    => $game->code(),
				'status'  => $issues->error_count() ? 'blocked' : 'ready',
				'user_id' => $user_id,
				'source'  => $source,
				'kind'    => 'import',
				'counts'  => $total,
				'plan'    => $plan,
			)
		);
	}

	/** @return array<string, int> */
	private static function zero(): array {
		return array(
			'create'    => 0,
			'update'    => 0,
			'unchanged' => 0,
			'missing'   => 0,
			'errors'    => 0,
			'warnings'  => 0,
		);
	}

	/**
	 * Field diff with % change for numbers and the large-change flag (DATA-23).
	 *
	 * @param array<string, mixed> $old Old data.
	 * @param array<string, mixed> $new New data.
	 * @return array<int, array<string, mixed>>
	 */
	public static function diff( array $old, array $new ): array {
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $f ) {
			$a = $old[ $f ] ?? null;
			$b = $new[ $f ] ?? null;
			if ( self::same( $a, $b ) ) {
				continue;
			}
			$row = array(
				'field' => $f,
				'old'   => $a,
				'new'   => $b,
			);
			if ( is_numeric( $a ) && is_numeric( $b ) && ! is_string( $a ) && ! is_string( $b ) ) {
				$row['pct'] = 0.0 == $a ? null : round( ( $b - $a ) / abs( $a ) * 100, 1 ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				if ( ! in_array( $f, self::QUIET, true ) && ( null === $row['pct'] || abs( $row['pct'] ) > 50 ) ) {
					$row['large'] = true;
				}
			}
			if ( in_array( $f, array( 'probability', 'status', 'code_status' ), true ) ) {
				$row['large'] = true;
			}
			$out[] = $row;
		}
		return $out;
	}

	private static function same( mixed $a, mixed $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return wp_json_encode( Normalizer::sort_keys( $a ) ) === wp_json_encode( Normalizer::sort_keys( $b ) );
		}
		if ( is_numeric( $a ) && is_numeric( $b ) && ! is_string( $a ) && ! is_string( $b ) ) {
			return (float) $a === (float) $b;
		}
		return $a === $b;
	}

	/**
	 * @param array<string, array<string, mixed>> $meta New _meta.
	 * @return array<int, string> Changed keys.
	 */
	private static function meta_changed( Game $game, array $meta ): array {
		$old = Records::get( $game->code() . '-META' );
		$od  = $old ? $old['data'] : array();
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $od ), array_keys( $meta ) ) ) as $k ) {
			if ( ! self::same( $od[ $k ]['value'] ?? null, $meta[ $k ]['value'] ?? null ) ) {
				$out[] = (string) $k;
			}
		}
		return $out;
	}

	/**
	 * Predicted page state per page record, compared with the stored state.
	 *
	 * @param array<string, array<string, mixed>> $current   Current records.
	 * @param array<int, string>                  $error_ids IDs with errors.
	 * @return array<string, array{old: string, new: string}>
	 */
	private static function predict( Game $game, Dataset $ds, array $current, array $error_ids ): array {
		$gate = new PageState(
			$ds,
			array(
				'field_changed'  => static fn( string $id, string $f ) => Repository::field_changed( $game, $id, $f ),
				'update_changes' => static fn( string $id ) => Repository::update_changes( $id ),
				'error_ids'      => $error_ids,
			)
		);
		$out = array();
		foreach ( $game->page_entities() as $entity ) {
			foreach ( $ds->all( $entity, null ) as $id => $rec ) {
				$post_id = (int) ( $current[ $id ]['post_id'] ?? 0 );
				$old     = $post_id ? (string) get_post_meta( $post_id, '_rbx_page_state', true ) : '';
				$st      = $gate->evaluate(
					$rec + array( 'post_id' => $post_id ),
					array(
						'override'        => $post_id ? (string) get_post_meta( $post_id, '_rbx_index_override', true ) : '',
						'manual_index_ok' => $post_id && get_post_meta( $post_id, '_rbx_manual_index_ok', true ),
						'was_indexed'     => $post_id && get_post_meta( $post_id, '_rbx_was_indexed', true ),
					)
				);
				$new     = $st['state'];
				if ( $old !== $new ) {
					$out[ $id ] = array(
						'old'     => '' === $old ? PageState::NOT_GENERATED : $old,
						'new'     => $new,
						'reasons' => $st['reasons'],
					);
				}
			}
		}
		return $out;
	}

	/** @return array<int, string> Slugs of child Pages under the game folder page (REV C-05). */
	private static function page_slugs( Game $game ): array {
		$parent = get_page_by_path( $game->folder() );
		if ( ! $parent ) {
			return array();
		}
		$kids = get_pages(
			array(
				'child_of'    => $parent->ID,
				'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
			)
		);
		return array_map( static fn( $p ) => (string) $p->post_name, $kids );
	}

	/* ---------------------------------------------------------------- approval and apply */

	/**
	 * Records the approval. Every warning class and every large change needs a tick (DATA-19, DATA-23).
	 *
	 * @param array<string, string> $acks        warning class => reason.
	 * @param array<int, string>    $large_ok    IDs of large changes ticked.
	 * @param array<int, string>    $corrections "ID:field" pairs marked as corrections of our own mistakes (CONT).
	 * @param array<string, string> $missing     ID => removed_from_game|draft|ignore (DATA-22).
	 * @return true|\WP_Error
	 */
	public static function approve( int $run_id, array $acks, array $large_ok, array $corrections, array $missing ): bool|\WP_Error {
		$run = Runs::get( $run_id );
		if ( ! $run || 'ready' !== $run['status'] ) {
			return new \WP_Error( 'rbx_run_state', __( 'This run cannot be approved.', 'rbx-data' ) );
		}
		$plan = $run['plan'];
		foreach ( $plan['warn_classes'] as $class ) {
			if ( '' === trim( (string) ( $acks[ $class ] ?? '' ) ) ) {
				/* translators: %s: warning class */
				return new \WP_Error( 'rbx_ack', sprintf( __( 'Acknowledge the "%s" warnings with a reason.', 'rbx-data' ), $class ) );
			}
		}
		foreach ( $plan['ops'] as $op ) {
			if ( ! empty( $op['large'] ) && ! in_array( $op['id'], $large_ok, true ) ) {
				/* translators: %s: record ID */
				return new \WP_Error( 'rbx_large', sprintf( __( 'Tick the large change for %s to confirm it.', 'rbx-data' ), $op['id'] ) );
			}
		}
		$decisions = array();
		foreach ( $plan['missing'] as $m ) {
			$c                    = (string) ( $missing[ $m['id'] ] ?? 'ignore' );
			$decisions[ $m['id'] ] = in_array( $c, array( 'removed_from_game', 'draft', 'ignore' ), true ) ? $c : 'ignore';
		}
		Runs::update(
			$run_id,
			array(
				'acks' => array(
					'warnings'    => array_map( 'sanitize_text_field', $acks ),
					'large'       => array_values( array_map( 'strval', $large_ok ) ),
					'corrections' => array_values( array_map( 'strval', $corrections ) ),
					'missing'     => $decisions,
					'by'          => get_current_user_id(),
					'at'          => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);
		return true;
	}

	/** Lock held by another run, or 0. */
	public static function lock_holder(): int {
		$l = get_option( self::LOCK_OPTION );
		if ( ! is_array( $l ) || (int) ( $l['expires'] ?? 0 ) < time() ) {
			return 0;
		}
		return (int) $l['run'];
	}

	private static function lock( int $run_id ): bool {
		$holder = self::lock_holder();
		if ( $holder && $holder !== $run_id ) {
			return false;
		}
		update_option(
			self::LOCK_OPTION,
			array(
				'run'     => $run_id,
				'expires' => time() + self::LOCK_TTL,
			),
			false
		);
		return true;
	}

	private static function unlock( int $run_id ): void {
		if ( self::lock_holder() === $run_id ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Applies the next batch. Call repeatedly until `done` (AJAX, ≤200 rows / ≤20 s per request).
	 *
	 * @return array{done: bool, batch: int, batches: int}|\WP_Error
	 */
	public static function step( int $run_id ): array|\WP_Error {
		$run = Runs::get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'rbx_run', __( 'Run not found.', 'rbx-data' ) );
		}
		if ( 'ready' === $run['status'] && empty( $run['acks']['at'] ) ) {
			return new \WP_Error( 'rbx_run_state', __( 'Approve the dry run first.', 'rbx-data' ) );
		}
		if ( ! in_array( $run['status'], array( 'ready', 'applying' ), true ) ) {
			return new \WP_Error( 'rbx_run_state', __( 'This run is not waiting to be applied.', 'rbx-data' ) );
		}
		if ( ! self::lock( $run_id ) ) {
			return new \WP_Error( 'rbx_locked', __( 'Another import is running. Try again in a few minutes.', 'rbx-data' ) );
		}
		$game = Loader::by_code( (string) $run['game'] );
		if ( ! $game ) {
			return new \WP_Error( 'rbx_game', __( 'Unknown game.', 'rbx-data' ) );
		}
		if ( 'ready' === $run['status'] ) {
			if ( ! self::still_current( $run ) ) {
				Runs::update( $run_id, array( 'status' => 'discarded', 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				self::unlock( $run_id );
				return new \WP_Error( 'rbx_stale', __( 'Records changed since this dry run (another run was applied). Run a new dry run.', 'rbx-data' ) );
			}
			Runs::update( $run_id, array( 'status' => 'applying' ) );
		}
		$work    = self::work_items( $run );
		$batches = max( 1, (int) ceil( count( $work ) / self::BATCH ) );
		$next    = $run['last_committed_batch'] + 1;
		$start   = microtime( true );
		while ( $next < $batches && microtime( true ) - $start < 15 ) {
			$slice = array_slice( $work, $next * self::BATCH, self::BATCH );
			try {
				self::apply_batch( $game, $run, $slice );
			} catch ( \Throwable $e ) {
				ErrorLog::add( 'import', 'Run ' . $run_id . ' batch ' . $next . ': ' . $e->getMessage() );
				Runs::update( $run_id, array( 'status' => 'failed', 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				self::unlock( $run_id );
				return new \WP_Error( 'rbx_apply', __( 'A batch failed. Nothing in that batch was written. See Data health for details; roll back this run if needed.', 'rbx-data' ) );
			}
			Runs::update( $run_id, array( 'last_committed_batch' => $next ) );
			++$next;
		}
		if ( $next < $batches ) {
			self::lock( $run_id );
			return array(
				'done'    => false,
				'batch'   => $next,
				'batches' => $batches,
			);
		}
		self::finalize( $game, $run_id, 'import' );
		return array(
			'done'    => true,
			'batch'   => $batches,
			'batches' => $batches,
		);
	}

	/**
	 * Dry runs go stale if any record they would touch changed after the dry run was made.
	 *
	 * @param array<string, mixed> $run Run.
	 */
	private static function still_current( array $run ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$later = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE game = %s AND id > %d AND status IN (%s, %s)', Runs::table(), $run['game'], $run['id'], 'applied', 'rolled_back' ) );
		return 0 === $later;
	}

	/**
	 * Ordered list of record writes: creates/updates, then missing-record decisions.
	 *
	 * @param array<string, mixed> $run Run.
	 * @return array<int, array<string, mixed>>
	 */
	private static function work_items( array $run ): array {
		$work = $run['plan']['ops'];
		foreach ( (array) ( $run['acks']['missing'] ?? array() ) as $id => $choice ) {
			if ( 'ignore' !== $choice ) {
				$work[] = array(
					'op'     => 'status',
					'id'     => (string) $id,
					'status' => (string) $choice,
				);
			}
		}
		return $work;
	}

	/**
	 * One transaction per record: snapshot + write + changelog rows (DATA-19, DATA-24).
	 *
	 * @param array<string, mixed>             $run   Run.
	 * @param array<int, array<string, mixed>> $items Work items.
	 */
	private static function apply_batch( Game $game, array $run, array $items ): void {
		$corr   = array_flip( (array) ( $run['acks']['corrections'] ?? array() ) );
		$now    = gmdate( 'Y-m-d H:i:s' );
		foreach ( $items as $it ) {
			$id  = (string) $it['id'];
			$pre = Records::get( $id );
			if ( Runs::has_record_snapshot( (int) $run['id'], $id ) ) {
				continue; // Already applied by an interrupted request.
			}
			Records::begin();
			try {
				Runs::snapshot( (int) $run['id'], $id, $pre );
				if ( 'status' === $it['op'] ) {
					if ( ! $pre ) {
						Records::commit();
						continue;
					}
					$new           = $pre;
					$new['status'] = (string) $it['status'];
					$new['data']['status'] = (string) $it['status'];
					$new['content_hash']   = Normalizer::hash( $new );
					Records::save( $new );
					self::log( $game, $run, $id, 'status', $pre['status'], $new['status'], 'update', $new['data']['change_update_id'] ?? null, false, $now );
				} else {
					$rec = $it['rec'];
					$rec = self::carry_slug_history( $rec, $pre );
					if ( $pre ) {
						$rec['post_id'] = $pre['post_id'];
					}
					$rec['last_verified'] = $rec['data']['last_verified'] ?? null;
					$rec['confidence']    = (string) ( $rec['data']['source_type'] ?? '' );
					Records::save( $rec );
					$upd = $rec['data']['change_update_id'] ?? null;
					if ( ! $pre ) {
						self::log( $game, $run, $id, '*', null, $rec['name'], 'create', $upd, false, $now );
					} else {
						foreach ( self::diff( $pre['data'], $rec['data'] ) as $d ) {
							self::log( $game, $run, $id, (string) $d['field'], $d['old'], $d['new'], 'update', $upd, isset( $corr[ $id . ':' . $d['field'] ] ), $now );
						}
					}
				}
				Records::commit();
			} catch ( \Throwable $e ) {
				Records::rollback();
				throw $e;
			}
		}
	}

	/**
	 * Keeps the old slug in slug_history when a slug changes so 301s keep working (DATA-41).
	 *
	 * @param array<string, mixed>      $rec New record.
	 * @param array<string, mixed>|null $pre Old record.
	 * @return array<string, mixed>
	 */
	private static function carry_slug_history( array $rec, ?array $pre ): array {
		if ( ! $pre || empty( $pre['slug'] ) || $pre['slug'] === $rec['slug'] ) {
			return $rec;
		}
		$hist = array_merge( (array) ( $rec['data']['slug_history'] ?? array() ), (array) ( $pre['data']['slug_history'] ?? array() ), array( (string) $pre['slug'] ) );
		$hist = array_values( array_diff( array_unique( array_map( 'strval', $hist ) ), array( (string) $rec['slug'] ) ) );
		sort( $hist );
		$rec['data']['slug_history'] = $hist;
		return $rec;
	}

	/** @param array<string, mixed> $run Run. */
	private static function log( Game $game, array $run, string $id, string $field, mixed $old, mixed $new, string $kind, mixed $update_id, bool $correction, string $now ): void {
		Changelog::add(
			array(
				'game'          => $game->code(),
				'record_id'     => $id,
				'field'         => $field,
				'old_value'     => $old,
				'new_value'     => $new,
				'change_kind'   => $kind,
				'update_id'     => is_string( $update_id ) ? $update_id : null,
				'import_run_id' => (int) $run['id'],
				'changed_at'    => $now,
				'is_correction' => $correction,
			)
		);
	}

	/**
	 * Post-apply: META record, dataset version, gate + shells, exports, cache purge, IndexNow (DATA-19).
	 */
	public static function finalize( Game $game, int $run_id, string $kind ): void {
		$run = Runs::get( $run_id );
		if ( 'import' === $kind && $run ) {
			$meta_id = $game->code() . '-META';
			$pre     = Records::get( $meta_id );
			$meta    = array(
				'id'     => $meta_id,
				'game'   => $game->code(),
				'entity' => 'META',
				'slug'   => null,
				'name'   => '_meta',
				'status' => 'live',
				'data'   => $run['plan']['meta'],
			);
			$meta['content_hash'] = Normalizer::hash( $meta );
			if ( ! $pre || $pre['content_hash'] !== $meta['content_hash'] ) {
				Runs::snapshot( $run_id, $meta_id, $pre );
				Records::save( $meta );
				foreach ( $run['plan']['meta_changed'] as $k ) {
					self::log( $game, $run, $meta_id, (string) $k, $pre['data'][ $k ]['value'] ?? null, $run['plan']['meta'][ $k ]['value'] ?? null, 'update', null, false, gmdate( 'Y-m-d H:i:s' ) );
				}
			}
		}
		$changed = $run ? Changelog::count_for_run( $run_id ) : 0;
		$version = Repository::version( $game );
		if ( $changed > 0 || '' === $version ) {
			$version = Repository::next_version( $game );
			Repository::set_version( $game, $version );
		}
		Runs::update(
			$run_id,
			array(
				'status'          => 'rollback' === $kind ? 'applied' : 'applied',
				'finished_at'     => gmdate( 'Y-m-d H:i:s' ),
				'dataset_version' => $version,
			)
		);
		self::unlock( $run_id );
		self::post_apply( $game, $run_id );
	}

	/**
	 * Steps that may fail without undoing the data write; failures are logged for Data health.
	 */
	public static function post_apply( Game $game, int $run_id ): void {
		$transitions = array();
		\RBX\Data\Render\Media::sync();
		try {
			$transitions = \RBX\Data\Gate\Runner::run( $game );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'gate', $e->getMessage() );
		}
		try {
			\RBX\Data\Export\Writer::write( $game );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'export', $e->getMessage() );
		}
		try {
			\RBX\Data\Render\Pages::refresh_game( $game );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'render', $e->getMessage() );
		}
		$ids = array_values( array_unique( array_map( static fn( $r ) => (string) $r['record_id'], Changelog::for_run( $run_id ) ) ) );
		try {
			\RBX\Data\Cache\Purge::after_run( $game, $ids );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'cache', $e->getMessage() );
		}
		try {
			\RBX\Data\Index\IndexNow::after_run( $game, $ids, $transitions );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'indexnow', $e->getMessage() );
		}
		Runs::prune_snapshots( $game->code() );
		do_action( 'rbx_data_run_applied', $game, $run_id, $ids, $transitions );
	}

	public static function discard( int $run_id ): void {
		$run = Runs::get( $run_id, false );
		if ( $run && in_array( $run['status'], array( 'ready', 'blocked' ), true ) ) {
			Runs::update( $run_id, array( 'status' => 'discarded', 'finished_at' => gmdate( 'Y-m-d H:i:s' ), 'plan' => array() ) );
		}
	}

	/* ---------------------------------------------------------------- rollback */

	/**
	 * Later runs that touched the same records (DATA-24). Empty = rollback allowed.
	 *
	 * @return array<int, array{run: int, record: string}>
	 */
	public static function conflicts( int $run_id ): array {
		$run = Runs::get( $run_id, false );
		if ( ! $run ) {
			return array();
		}
		$mine = array_keys( Runs::snapshot_for( $run_id ) );
		if ( ! $mine ) {
			return array();
		}
		$out = array();
		foreach ( Runs::recent( (string) $run['game'], 50 ) as $later ) {
			if ( (int) $later['id'] <= $run_id || 'applied' !== $later['status'] ) {
				continue;
			}
			foreach ( array_intersect( $mine, array_keys( Runs::snapshot_for( (int) $later['id'] ) ) ) as $rid ) {
				$out[] = array(
					'run'    => (int) $later['id'],
					'record' => (string) $rid,
				);
			}
		}
		return $out;
	}

	/**
	 * Re-applies a run's pre-images as a new run with change_kind = rollback.
	 *
	 * @return int|\WP_Error New run ID.
	 */
	public static function rollback( int $run_id, int $user_id ): int|\WP_Error {
		$run = Runs::get( $run_id, false );
		if ( ! $run || 'applied' !== $run['status'] ) {
			return new \WP_Error( 'rbx_run_state', __( 'Only applied runs can be rolled back.', 'rbx-data' ) );
		}
		if ( ! Runs::has_snapshot( $run_id ) ) {
			return new \WP_Error( 'rbx_no_snapshot', __( 'This run has no snapshots left (only the latest 20 runs keep them).', 'rbx-data' ) );
		}
		if ( self::conflicts( $run_id ) ) {
			return new \WP_Error( 'rbx_conflict', __( 'A later run changed the same records. Roll that run back first.', 'rbx-data' ) );
		}
		$game = Loader::by_code( (string) $run['game'] );
		if ( ! $game ) {
			return new \WP_Error( 'rbx_game', __( 'Unknown game.', 'rbx-data' ) );
		}
		$new_id = Runs::create(
			array(
				'game'        => $run['game'],
				'status'      => 'applying',
				'user_id'     => $user_id,
				'source'      => 'rollback',
				'kind'        => 'rollback',
				'rollback_of' => $run_id,
			)
		);
		if ( ! self::lock( $new_id ) ) {
			Runs::update( $new_id, array( 'status' => 'failed' ) );
			return new \WP_Error( 'rbx_locked', __( 'Another import is running. Try again in a few minutes.', 'rbx-data' ) );
		}
		$new_run = Runs::get( $new_id, false ) ?? array( 'id' => $new_id );
		$now     = gmdate( 'Y-m-d H:i:s' );
		foreach ( Runs::snapshot_for( $run_id ) as $rid => $pre ) {
			$cur = Records::get( (string) $rid );
			Records::begin();
			try {
				Runs::snapshot( $new_id, (string) $rid, $cur );
				if ( null === $pre ) {
					// Created by the run: return it to draft instead of deleting (DATA-22).
					if ( $cur ) {
						$cur['status']         = 'draft';
						$cur['data']['status'] = 'draft';
						$cur['content_hash']   = Normalizer::hash( $cur );
						Records::save( $cur );
						self::log( $game, $new_run, (string) $rid, 'status', 'live', 'draft', 'rollback', null, false, $now );
					}
				} else {
					$pre['post_id'] = $cur['post_id'] ?? $pre['post_id'];
					Records::save( $pre );
					foreach ( self::diff( $cur ? $cur['data'] : array(), $pre['data'] ) as $d ) {
						self::log( $game, $new_run, (string) $rid, (string) $d['field'], $d['old'], $d['new'], 'rollback', null, false, $now );
					}
				}
				Records::commit();
			} catch ( \Throwable $e ) {
				Records::rollback();
				ErrorLog::add( 'import', 'Rollback ' . $run_id . ': ' . $e->getMessage() );
				Runs::update( $new_id, array( 'status' => 'failed', 'finished_at' => $now ) );
				self::unlock( $new_id );
				return new \WP_Error( 'rbx_apply', __( 'Rollback failed. See Data health.', 'rbx-data' ) );
			}
		}
		Runs::update( $run_id, array( 'status' => 'rolled_back' ) );
		self::finalize( $game, $new_id, 'rollback' );
		return $new_id;
	}
}
