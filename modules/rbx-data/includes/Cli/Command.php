<?php
/**
 * `wp rbx` commands: the same pipeline as the admin screens, for SSH and local testing.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Cli;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Db\Runs;
use RBX\Data\Export\Writer;
use RBX\Data\Import\Bundled;
use RBX\Data\Import\Runner;
use RBX\Data\Import\Source;
use RBX\Data\Lint\NumberLint;
use RBX\Data\Sheet\Template;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage RBX game data.
 */
final class Command {

	private function game( array $assoc ): Game {
		$key  = (string) ( $assoc['game'] ?? '' );
		$game = Loader::game( $key ) ?? Loader::by_code( $key );
		if ( ! $game ) {
			$all = array_map( static fn( Game $g ) => $g->key(), Loader::games() );
			WP_CLI::error( 'Pass --game=<key>. Known: ' . implode( ', ', $all ) );
		}
		return $game;
	}

	/**
	 * Dry-runs an import, and applies it with --yes.
	 *
	 * ## OPTIONS
	 *
	 * --game=<key>
	 * : Game key (e.g. sae).
	 *
	 * [--file=<path>]
	 * : .xlsx workbook or .zip of {tab}.csv. Without it, the saved published Sheet URLs are fetched.
	 *
	 * [--yes]
	 * : Approve and apply. Without it, only the dry run is stored.
	 *
	 * [--ack=<pairs>]
	 * : Warning acknowledgment; repeat as --ack=a=reason,b=reason.
	 *
	 * [--large=<ids>]
	 * : Comma-separated IDs whose large changes you confirm.
	 *
	 * [--missing=<pairs>]
	 * : Comma list of ID=removed_from_game|draft|ignore.
	 *
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function import( array $args, array $assoc ): void {
		$game = $this->game( $assoc );
		try {
			$input = isset( $assoc['file'] ) ? Source::from_file( (string) $assoc['file'], (string) $assoc['file'] ) : Source::from_sheet( $game );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$run_id = Runner::prepare( $game, $input, isset( $assoc['file'] ) ? 'upload' : 'sheet', get_current_user_id() );
		$run    = (array) Runs::get( $run_id );
		$this->report( $run );
		if ( 'blocked' === $run['status'] ) {
			WP_CLI::error( "Run {$run_id} is blocked by errors. Fix the Sheet and run again." );
		}
		if ( ! isset( $assoc['yes'] ) ) {
			WP_CLI::success( "Dry run {$run_id} stored. Re-run with --yes (and --ack/--large/--missing) to apply, or approve it in WP Admin." );
			return;
		}
		$acks = $this->pairs( (string) ( $assoc['ack'] ?? '' ) );
		$miss = $this->pairs( (string) ( $assoc['missing'] ?? '' ) );
		$big  = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['large'] ?? '' ) ) ) );
		$ok   = Runner::approve( $run_id, $acks, $big, array(), $miss );
		if ( is_wp_error( $ok ) ) {
			WP_CLI::error( $ok->get_error_message() );
		}
		$this->apply( $run_id );
	}

	/**
	 * Imports the bundled CSVs from the theme's data/{game}/ folder now (what a deploy does in the background).
	 *
	 * ## OPTIONS
	 *
	 * --game=<key>
	 * : Game key.
	 *
	 * [--dry-run]
	 * : Only make the dry run.
	 *
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function bundled( array $args, array $assoc ): void {
		$game = $this->game( $assoc );
		if ( ! Bundled::exists( $game ) ) {
			WP_CLI::error( 'No CSV files in ' . Bundled::dir( $game ) );
		}
		$s = Bundled::run( $game, ! isset( $assoc['dry-run'] ) );
		if ( ! empty( $s['run'] ) ) {
			$this->report( (array) Runs::get( (int) $s['run'] ) );
		}
		WP_CLI::log( 'Status: ' . $s['status'] . ( empty( $s['message'] ) ? '' : ' (' . $s['message'] . ')' ) );
		in_array( $s['status'], array( 'applied', 'ready' ), true ) ? WP_CLI::success( 'Run ' . (int) $s['run'] ) : WP_CLI::error( 'Not applied.' );
	}

	/**
	 * Writes the bundled page copy from the theme's content/pages/ folder into the Pages now.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Also overwrite Pages that were edited in WordPress.
	 *
	 * @subcommand pages-sync
	 *
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function pages_sync( array $args, array $assoc ): void {
		$s = \RBX\Data\Content\BundledPages::sync( isset( $assoc['force'] ) );
		foreach ( $s['skipped'] as $path => $why ) {
			WP_CLI::warning( "$path: $why" );
		}
		WP_CLI::success( count( $s['written'] ) . ' page(s) written.' );
	}

	/**
	 * Applies an approved run in batches.
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Run ID.
	 *
	 * @param array<int, string> $args Args.
	 */
	public function apply( $args, array $assoc = array() ): void {
		$run_id = is_array( $args ) ? (int) $args[0] : (int) $args;
		do {
			$r = Runner::step( $run_id );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
			WP_CLI::log( sprintf( 'Batch %d of %d', $r['batch'], $r['batches'] ) );
		} while ( ! $r['done'] );
		WP_CLI::success( "Run {$run_id} applied." );
	}

	/**
	 * Rolls back an applied run (as a new run).
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Run ID.
	 *
	 * @param array<int, string> $args Args.
	 */
	public function rollback( array $args ): void {
		$r = Runner::rollback( (int) $args[0], get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( "Rolled back as run {$r}." );
	}

	/**
	 * Lists recent runs.
	 *
	 * ## OPTIONS
	 *
	 * --game=<key>
	 * : Game key.
	 *
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function runs( array $args, array $assoc ): void {
		$rows = array();
		foreach ( Runs::recent( $this->game( $assoc )->code(), 20 ) as $r ) {
			$c      = (array) ( $r['counts'] ?? array() );
			$rows[] = array(
				'id'      => $r['id'],
				'status'  => $r['status'],
				'kind'    => $r['kind'],
				'started' => $r['started_at'] ?? '',
				'changes' => (int) ( $c['create'] ?? 0 ) + (int) ( $c['update'] ?? 0 ),
				'errors'  => (int) ( $c['errors'] ?? 0 ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'kind', 'started', 'changes', 'errors' ) );
	}

	/**
	 * Regenerates exports and the search index.
	 *
	 * [--game=<key>]
	 * : One game (default all).
	 *
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function export( array $args, array $assoc ): void {
		$games = isset( $assoc['game'] ) ? array( $this->game( $assoc ) ) : Loader::games();
		foreach ( $games as $g ) {
			$info = Writer::write( $g );
			WP_CLI::log( $g->key() . ': ' . wp_json_encode( $info['files'] ?? $info ) );
		}
		WP_CLI::success( 'Exports written.' );
	}

	/**
	 * Re-runs the index gate and page dates.
	 *
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function gate( array $args, array $assoc ): void {
		foreach ( Loader::games() as $g ) {
			$states = \RBX\Data\Gate\Runner::run( $g );
			\RBX\Data\Render\Pages::refresh_game( $g );
			$tally = array_count_values( array_map( static fn( $s ) => (string) $s['new'], $states ) );
			WP_CLI::log( $g->key() . ': ' . wp_json_encode( $tally ) );
		}
		WP_CLI::success( 'Gate done.' );
	}

	/**
	 * Checks a post (or all published pages) for hand-typed numbers and banned words.
	 *
	 * [<id>]
	 * : Post ID.
	 *
	 * @param array<int, string> $args Args.
	 */
	public function lint( array $args ): void {
		$ids = $args ? array( (int) $args[0] ) : get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		$bad = 0;
		foreach ( $ids as $id ) {
			$p = get_post( (int) $id );
			if ( ! $p ) {
				continue;
			}
			foreach ( NumberLint::check( $p->post_content, $p->post_title, $p->post_excerpt ) as $i ) {
				++$bad;
				WP_CLI::log( sprintf( '#%d %s: %s', $p->ID, $p->post_title, is_array( $i ) ? (string) ( $i['msg'] ?? wp_json_encode( $i ) ) : (string) $i ) );
			}
		}
		$bad ? WP_CLI::warning( "{$bad} issues." ) : WP_CLI::success( 'Clean.' );
	}

	/**
	 * Writes the Sheet template workbook.
	 *
	 * --game=<key>
	 * : Game key.
	 *
	 * [--out=<path>]
	 * : Output path.
	 *
	 * @subcommand sheet-template
	 * @param array<int, string>    $args  Args.
	 * @param array<string, string> $assoc Options.
	 */
	public function sheet_template( array $args, array $assoc ): void {
		$g   = $this->game( $assoc );
		$out = (string) ( $assoc['out'] ?? getcwd() . '/' . $g->folder() . '-data-template.xlsx' );
		Template::write( $g, $out );
		WP_CLI::success( $out );
	}

	/**
	 * Creates the launch page skeletons as drafts (existing pages are left alone).
	 *
	 * @subcommand setup-pages
	 */
	public function setup_pages(): void {
		WP_CLI::success( \RBX\Data\Admin\SetupScreen::create() . ' draft pages created.' );
	}

	/**
	 * Turns the pre-launch lock on or off.
	 *
	 * <state>
	 * : on or off
	 *
	 * @param array<int, string> $args Args.
	 */
	public function prelaunch( array $args ): void {
		$on = 'on' === ( $args[0] ?? '' );
		update_option( 'rbx_prelaunch_lock', $on ? 1 : 0, true );
		do_action( 'litespeed_purge_all' );
		WP_CLI::success( 'Pre-launch lock ' . ( $on ? 'ON' : 'OFF' ) . '.' );
	}

	/**
	 * Prints config errors, environment and record counts.
	 */
	public function health(): void {
		WP_CLI::log( 'Environment: ' . wp_get_environment_type() );
		WP_CLI::log( 'Pre-launch lock: ' . ( get_option( 'rbx_prelaunch_lock', 1 ) ? 'on' : 'off' ) );
		foreach ( Loader::errors() as $e ) {
			WP_CLI::warning( (string) $e );
		}
		foreach ( Loader::games() as $g ) {
			$recs = \RBX\Data\Db\Records::for_game( $g->code() );
			$by   = array_count_values( array_map( static fn( $r ) => (string) $r['entity'], $recs ) );
			WP_CLI::log( $g->key() . ': ' . wp_json_encode( $by ) . ', exports v' . (string) ( Writer::info( $g )['version'] ?? '-' ) );
		}
		WP_CLI::success( 'OK' );
	}

	/** @param array<string, mixed> $run Run row. */
	private function report( array $run ): void {
		$c = (array) $run['counts'];
		WP_CLI::log( sprintf( 'Run %d (%s): %d new, %d changed, %d unchanged, %d missing, %d errors, %d warnings', $run['id'], $run['status'], $c['create'] ?? 0, $c['update'] ?? 0, $c['unchanged'] ?? 0, $c['missing'] ?? 0, $c['errors'] ?? 0, $c['warnings'] ?? 0 ) );
		foreach ( (array) ( $run['plan']['issues'] ?? array() ) as $i ) {
			$loc = trim( $i['tab'] . ( $i['row'] ? ':' . $i['row'] : '' ) . ' ' . $i['id'] . ( $i['field'] ? '.' . $i['field'] : '' ) );
			WP_CLI::log( sprintf( '  [%s/%s] %s %s', $i['sev'], $i['class'], $loc, $i['msg'] ) );
		}
		foreach ( (array) ( $run['plan']['tests'] ?? array() ) as $t ) {
			WP_CLI::log( '  test: ' . wp_json_encode( $t ) );
		}
		foreach ( (array) ( $run['plan']['ops'] ?? array() ) as $op ) {
			if ( ! empty( $op['large'] ) ) {
				WP_CLI::log( '  large change: ' . $op['id'] );
			}
		}
		foreach ( (array) ( $run['plan']['missing'] ?? array() ) as $m ) {
			WP_CLI::log( '  missing from Sheet: ' . $m['id'] . ' ' . $m['name'] );
		}
	}

	/** @return array<string, string> "a=b,c=d" parsed. */
	private function pairs( string $s ): array {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $s ) ) ) as $p ) {
			$kv = explode( '=', $p, 2 );
			if ( 2 === count( $kv ) ) {
				$out[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}
		return $out;
	}
}
