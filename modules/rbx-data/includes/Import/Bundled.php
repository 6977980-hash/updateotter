<?php
/**
 * Bundled data (owner decision 2026-10-23): each game's tabs live as CSV files in the theme repo
 * under `data/{game}/`, deploy with the theme, and import themselves. Approvals that a person
 * would tick on the Import screen come from `data/{game}/approvals.json`, committed with the data.
 * A run applies only when its dry run has 0 errors and every warning class and large change is
 * approved there; otherwise it stays on the Import screen for a person.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Db\Runs;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * State per game in option `rbx_bundled_state`: hash, run, status, message, at.
 */
final class Bundled {

	public const HOOK      = 'rbx_bundled_import';
	public const OPTION    = 'rbx_bundled_state';
	public const APPROVALS = 'approvals.json';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'maybe_schedule' ), 20 );
		add_action( self::HOOK, array( self::class, 'run_all' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Read-only status (`/wp-json/rbx/v1/bundled`): lets a deploy be checked without an admin
	 * login. Without login it holds only the hashes and status (UO-60); admins get the details.
	 */
	public static function routes(): void {
		register_rest_route(
			'rbx/v1',
			'/bundled',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_status' ),
			)
		);
	}

	/** @return array<string, mixed> Full status for admins, hashes and status for everyone else. */
	public static function rest_status(): array {
		$out = self::status();
		if ( current_user_can( 'manage_options' ) ) {
			return $out;
		}
		foreach ( $out as $k => $v ) {
			$out[ $k ] = array_intersect_key( (array) $v, array_flip( array( 'deployed_hash', 'imported_hash', 'synced_hash', 'status', 'at', 'queued' ) ) );
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public static function status(): array {
		$out = array();
		foreach ( Loader::games() as $game ) {
			if ( ! self::exists( $game ) ) {
				continue;
			}
			$s      = self::state( $game );
			$errors   = array();
			$warnings = array();
			if ( ! empty( $s['run'] ) ) {
				$run = Runs::get( (int) $s['run'] );
				foreach ( (array) ( $run['plan']['issues'] ?? array() ) as $i ) {
					$line = trim( $i['tab'] . ' ' . $i['id'] . ' ' . $i['field'] ) . ': ' . $i['msg'];
					if ( 'error' === $i['sev'] ) {
						if ( count( $errors ) < 50 ) {
							$errors[] = $line;
						}
						continue;
					}
					$warnings[ $i['class'] ] ??= array(
						'count'  => 0,
						'sample' => array(),
					);
					++$warnings[ $i['class'] ]['count'];
					if ( count( $warnings[ $i['class'] ]['sample'] ) < 5 ) {
						$warnings[ $i['class'] ]['sample'][] = $line;
					}
				}
			}
			$out[ $game->key() ] = array(
				'deployed_hash' => self::hash( $game ),
				'imported_hash' => (string) ( $s['hash'] ?? '' ),
				'status'        => (string) ( $s['status'] ?? 'pending' ),
				'run'           => (int) ( $s['run'] ?? 0 ),
				'at'            => (string) ( $s['at'] ?? '' ),
				'message'       => (string) ( $s['message'] ?? '' ),
				'counts'        => array_intersect_key( (array) ( $s['counts'] ?? array() ), array_flip( array( 'create', 'update', 'unchanged', 'missing', 'errors', 'warnings' ) ) ),
				'media_added'   => (int) ( $s['media'] ?? 0 ),
				'pages'         => self::page_states( $game ),
				'errors'        => $errors,
				'warnings'      => $warnings,
				'queued'        => (bool) wp_next_scheduled( self::HOOK ),
			);
		}
		$pages               = \RBX\Data\Content\BundledPages::state();
		$out['_pages']       = array(
			'deployed_hash' => \RBX\Data\Content\BundledPages::hash(),
			'synced_hash'   => (string) ( $pages['hash'] ?? '' ),
			'at'            => (string) ( $pages['at'] ?? '' ),
			'written'       => (array) ( $pages['written'] ?? array() ),
			'skipped'       => (array) ( $pages['skipped'] ?? array() ),
		);
		return $out;
	}

	/** @return array<string, int> Page-state counts (DATA-20) for this game's item pages. */
	private static function page_states( Game $game ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.meta_value AS state, COUNT(*) AS n FROM %i s JOIN %i g ON g.post_id = s.post_id AND g.meta_key = '_rbx_game' AND g.meta_value = %s WHERE s.meta_key = '_rbx_page_state' GROUP BY s.meta_value", $wpdb->postmeta, $wpdb->postmeta, $game->code() ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['state'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function dir( Game $game ): string {
		$base = (string) apply_filters( 'rbx_data_bundled_dir', get_template_directory() . '/data' );
		return untrailingslashit( $base ) . '/' . $game->key();
	}

	public static function exists( Game $game ): bool {
		return is_dir( self::dir( $game ) ) && (bool) glob( self::dir( $game ) . '/*.csv' );
	}

	/** Content hash of the CSVs, the approvals file and the media folder listing. */
	public static function hash( Game $game ): string {
		$dir   = self::dir( $game );
		$files = array_merge( (array) glob( $dir . '/*.csv' ), (array) glob( $dir . '/' . self::APPROVALS ), (array) glob( $dir . '/media/*' ) );
		sort( $files );
		// UO-65: hashing ~880 KB on every uncached request is wasted work; the hash only changes
		// when a file is added, removed or rewritten (a deploy sets new mtimes), so cache it by that.
		$sig = '';
		foreach ( $files as $f ) {
			$sig .= basename( (string) $f ) . ':' . (int) @filemtime( (string) $f ) . ':' . (int) @filesize( (string) $f ) . ';'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$key    = 'rbx_bundled_hash_' . $game->key();
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === md5( $sig ) && is_string( $cached['hash'] ?? null ) ) {
			return $cached['hash'];
		}
		$h = hash_init( 'sha256' );
		foreach ( $files as $f ) {
			hash_update( $h, basename( (string) $f ) . "\0" );
			hash_update_file( $h, (string) $f );
		}
		$hash = substr( hash_final( $h ), 0, 16 );
		set_transient(
			$key,
			array(
				'sig'  => md5( $sig ),
				'hash' => $hash,
			),
			DAY_IN_SECONDS
		);
		return $hash;
	}

	/** @return array<string, mixed> */
	public static function state( Game $game ): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $game->key() ] ) && is_array( $all[ $game->key() ] ) ? $all[ $game->key() ] : array();
	}

	/** @param array<string, mixed> $s State. */
	private static function save_state( Game $game, array $s ): void {
		$all                 = get_option( self::OPTION, array() );
		$all                 = is_array( $all ) ? $all : array();
		$all[ $game->key() ] = $s + array( 'at' => gmdate( 'Y-m-d H:i:s' ) );
		update_option( self::OPTION, $all, false );
	}

	/** Cheap check on every request: queue one background import when a deploy changed the data. */
	public static function maybe_schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) || ! get_option( 'rbx_data_db_version' ) ) {
			return;
		}
		foreach ( Loader::games() as $game ) {
			if ( self::exists( $game ) && ( self::state( $game )['hash'] ?? '' ) !== self::hash( $game ) ) {
				wp_schedule_single_event( time(), self::HOOK );
				return;
			}
		}
	}

	public static function run_all(): void {
		foreach ( Loader::games() as $game ) {
			if ( self::exists( $game ) && ( self::state( $game )['hash'] ?? '' ) !== self::hash( $game ) ) {
				self::run( $game );
			}
		}
	}

	/**
	 * Dry run, then apply when allowed. Returns the saved state.
	 *
	 * @return array<string, mixed>
	 */
	public static function run( Game $game, bool $apply = true ): array {
		$hash = self::hash( $game );
		if ( Runner::lock_holder() ) {
			return self::state( $game ); // Retried on the next request.
		}
		try {
			$media = self::sync_media( $game );
			$input = Source::from_dir( self::dir( $game ) );
		} catch ( \Throwable $e ) {
			ErrorLog::add( 'import', 'Bundled ' . $game->key() . ': ' . $e->getMessage() );
			$s = array(
				'hash'    => $hash,
				'run'     => 0,
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
			self::save_state( $game, $s );
			return $s;
		}
		$run_id = Runner::prepare( $game, $input, 'bundled', 0 );
		$run    = (array) Runs::get( $run_id );
		$s      = array(
			'hash'   => $hash,
			'run'    => $run_id,
			'status' => (string) $run['status'],
			'counts' => $run['counts'] ?? array(),
			'media'  => $media,
		);
		if ( 'ready' === $run['status'] && $apply ) {
			$s = array_merge( $s, self::apply( $game, $run_id, $run ) );
		}
		self::save_state( $game, $s );
		return $s;
	}

	/**
	 * @param array<string, mixed> $run Run with plan.
	 * @return array<string, mixed> State changes.
	 */
	private static function apply( Game $game, int $run_id, array $run ): array {
		$ap   = self::approvals( $game );
		$plan = $run['plan'];
		$need = array();
		foreach ( (array) $plan['warn_classes'] as $class ) {
			if ( '' === trim( (string) ( $ap['warnings'][ $class ] ?? '' ) ) ) {
				$need[] = 'warning:' . $class;
			}
		}
		foreach ( (array) $plan['ops'] as $op ) {
			if ( ! empty( $op['large'] ) && ! in_array( $op['id'], $ap['large'], true ) ) {
				$need[] = 'large:' . $op['id'];
			}
		}
		if ( $need ) {
			return array(
				'status'  => 'waiting',
				'message' => 'Not approved in ' . self::APPROVALS . ': ' . implode( ', ', array_slice( $need, 0, 20 ) ),
			);
		}
		$ok = Runner::approve( $run_id, $ap['warnings'], $ap['large'], $ap['corrections'], $ap['missing'] );
		if ( is_wp_error( $ok ) ) {
			return array(
				'status'  => 'waiting',
				'message' => $ok->get_error_message(),
			);
		}
		$start = time();
		do {
			$r = Runner::step( $run_id );
			if ( is_wp_error( $r ) ) {
				return array(
					'status'  => 'failed',
					'message' => $r->get_error_message(),
				);
			}
		} while ( ! $r['done'] && time() - $start < 240 );
		return $r['done'] ? array( 'status' => 'applied' ) : array(
			'status'  => 'applying',
			'message' => 'Still applying; run `wp rbx apply ' . $run_id . '` or the Import screen to finish.',
		);
	}

	/**
	 * @return array{warnings: array<string, string>, large: array<int, string>, corrections: array<int, string>, missing: array<string, string>}
	 */
	public static function approvals( Game $game ): array {
		$f = self::dir( $game ) . '/' . self::APPROVALS;
		$d = is_readable( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$d = is_array( $d ) ? $d : array();
		return array(
			'warnings'    => array_map( 'strval', (array) ( $d['warnings'] ?? array() ) ),
			'large'       => array_values( array_map( 'strval', (array) ( $d['large'] ?? array() ) ) ),
			'corrections' => array_values( array_map( 'strval', (array) ( $d['corrections'] ?? array() ) ) ),
			'missing'     => array_map( 'strval', (array) ( $d['missing'] ?? array() ) ),
		);
	}

	/**
	 * Copies screenshots from `data/{game}/media/` into the media library once (matched by file
	 * name, DATA-09). Returns the number added.
	 */
	public static function sync_media( Game $game ): int {
		$files = (array) glob( self::dir( $game ) . '/media/*.{png,jpg,jpeg,webp,avif}', GLOB_BRACE );
		if ( ! $files ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$added = 0;
		foreach ( $files as $path ) {
			$name = strtolower( basename( (string) $path ) );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*\.(webp|png|jpg|jpeg|avif)$/', $name ) || \RBX\Data\Render\Media::id( $name ) ) {
				continue;
			}
			$tmp = wp_tempnam( $name );
			copy( (string) $path, $tmp );
			$id = media_handle_sideload(
				array(
					'name'     => $name,
					'tmp_name' => $tmp,
				),
				0
			);
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				ErrorLog::add( 'import', 'Bundled media ' . $name . ': ' . $id->get_error_message() );
				continue;
			}
			++$added;
		}
		return $added;
	}
}
