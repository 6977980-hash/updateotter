<?php
/**
 * Versioned, content-hashed JSON exports for calculators (DATA-26, DATA-27, ENG-27) and the
 * full-dataset archive (DATA-37).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Export;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Slices: core, pets, eggs, recipes (SAE). Slice composition comes from the entity list below.
 */
final class Writer {

	public const SLICE_BUDGET = 40960;
	public const TOOL_BUDGET  = 61440;

	/** Slice name => entity codes. Unknown entities are skipped per game. */
	public const SLICES = array(
		'core'    => array( 'CUR', 'RAR', 'BIO', 'MUT', 'SIZ', 'VAR', 'GPS', 'MNT' ),
		'pets'    => array( 'PET' ),
		'eggs'    => array( 'EGG', 'DRP' ),
		'recipes' => array( 'FUS', 'RIT', 'LAB' ),
		'plants'  => array( 'PLT', 'SED' ),
		'gear'    => array( 'GER' ),
	);

	public static function base_dir(): string {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . 'rbx-data';
	}

	public static function base_url(): string {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['baseurl'] ) . 'rbx-data';
	}

	/** @return array<string, mixed> Saved export info for a game. */
	public static function info( Game $game ): array {
		$all = get_option( 'rbx_exports', array() );
		return is_array( $all ) && isset( $all[ $game->key() ] ) && is_array( $all[ $game->key() ] ) ? $all[ $game->key() ] : array();
	}

	/** Public URL of a slice file, or '' if none. */
	public static function slice_url( Game $game, string $slice ): string {
		$info = self::info( $game );
		$file = $info['files'][ $slice ] ?? '';
		return '' === $file ? '' : self::base_url() . '/' . $game->key() . '/' . $file;
	}

	/**
	 * Builds the payload for one slice.
	 *
	 * @return array<string, mixed>
	 */
	public static function slice( Dataset $ds, string $slice ): array {
		$game     = $ds->game();
		$entities = array_values( array_filter( self::SLICES[ $slice ] ?? array(), static fn( $e ) => null !== $game->entity( $e ) ) );
		$payload  = array(
			'v'       => 1,
			'game'    => $game->code(),
			'dataset' => Repository::version( $game ),
			'slice'   => $slice,
		);
		$data     = $ds->calc_data( $entities );
		$payload['records'] = (object) $data['records'];
		if ( 'core' === $slice ) {
			$payload['params']   = (object) $data['params'];
			$payload['ladder']   = $ds->meta( 'suffix_ladder' );
			$payload['formulas'] = self::formulas( $ds );
		}
		return $payload;
	}

	/** @return array<int, array<string, mixed>> */
	public static function formulas( Dataset $ds ): array {
		$out = array();
		foreach ( $ds->formulas() as $key => $f ) {
			$d    = $f['record']['data'];
			$item = array(
				'key'    => $key,
				'tool'   => (string) ( $d['tool'] ?? '' ),
				'label'  => (string) ( $d['label__en'] ?? $key ),
				'unit'   => (string) ( $d['output_unit__en'] ?? '' ),
				'format' => (string) ( $d['display_format'] ?? 'abbr' ),
			);
			if ( $f['available'] ) {
				$item['expr']   = (string) $d['expression'];
				$item['inputs'] = (object) $f['inputs'];
			} else {
				$item['na'] = $f['reason'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Writes every slice, the manifest, the archive and the site search index.
	 *
	 * @return array<string, mixed> Info saved to `rbx_exports`.
	 */
	public static function write( Game $game ): array {
		Repository::flush( $game );
		$ds  = Repository::dataset( $game );
		$dir = self::base_dir() . '/' . $game->key();
		self::ensure_dirs( $dir );
		$files = array();
		$sizes = array();
		foreach ( array_keys( self::SLICES ) as $slice ) {
			if ( ! array_filter( self::SLICES[ $slice ], static fn( $e ) => null !== $game->entity( $e ) ) && 'core' !== $slice ) {
				continue;
			}
			$json = (string) wp_json_encode( self::slice( $ds, $slice ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
			$name = $slice . '.' . substr( hash( 'sha256', $json ), 0, 10 ) . '.json';
			self::put( $dir . '/' . $name, $json );
			$files[ $slice ] = $name;
			$sizes[ $slice ] = strlen( (string) gzencode( $json, 9 ) );
			if ( $sizes[ $slice ] > self::SLICE_BUDGET ) {
				/* translators: 1: slice, 2: size */
				ErrorLog::add( 'export', sprintf( 'Slice %1$s is %2$d bytes gzipped (budget 40 KB).', $slice, $sizes[ $slice ] ) );
			}
		}
		$version  = Repository::version( $game );
		$manifest = array(
			'game'            => $game->code(),
			'schema_version'  => $game->schema_version(),
			'dataset_version' => $version,
			'generated_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'files'           => $files,
			'fields'          => array(
				'n'    => 'name',
				'slug' => 'slug',
				's'    => 'state: c community (usable), x community (not usable), d disputed (c = candidates), u unknown, m mechanic unconfirmed',
				'v'    => 'value when not verified',
			),
		);
		self::put( $dir . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		self::archive( $game, $version );
		$search = SearchIndex::write();
		$info   = array(
			'version'   => $version,
			'files'     => $files,
			'sizes'     => $sizes,
			'search'    => $search,
			'written'   => gmdate( 'Y-m-d H:i:s' ),
		);
		$all                  = get_option( 'rbx_exports', array() );
		$all                  = is_array( $all ) ? $all : array();
		$all[ $game->key() ]  = $info;
		update_option( 'rbx_exports', $all, false );
		self::prune( $dir, $files );
		return $info;
	}

	/** Full normalized dataset, gzipped, kept 60 days (DATA-37). */
	private static function archive( Game $game, string $version ): void {
		$dir = self::base_dir() . '/' . $game->key() . '/archive';
		self::ensure_dirs( $dir );
		$records = \RBX\Data\Db\Records::for_game( $game->code() );
		$json    = (string) wp_json_encode(
			array(
				'dataset_version' => $version,
				'records'         => array_values( $records ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		self::put( $dir . '/' . sanitize_file_name( ( '' === $version ? 'initial' : $version ) ) . '.json.gz', (string) gzencode( $json, 6 ) );
	}

	/** @param array<string, string> $current Current slice files. */
	private static function prune( string $dir, array $current ): void {
		$keep = array_flip( $current );
		foreach ( (array) glob( $dir . '/*.json' ) as $f ) {
			$b = basename( (string) $f );
			if ( 'manifest.json' === $b || isset( $keep[ $b ] ) ) {
				continue;
			}
			if ( filemtime( (string) $f ) < time() - 7 * DAY_IN_SECONDS ) {
				wp_delete_file( (string) $f );
			}
		}
		foreach ( (array) glob( $dir . '/archive/*.json.gz' ) as $f ) {
			if ( filemtime( (string) $f ) < time() - 60 * DAY_IN_SECONDS ) {
				wp_delete_file( (string) $f );
			}
		}
	}

	/** Atomic write: temp file then rename (ENG-27). */
	public static function put( string $path, string $contents ): void {
		$tmp = $path . '.tmp';
		if ( false === file_put_contents( $tmp, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			ErrorLog::add( 'export', 'Could not write ' . basename( $path ) );
			throw new \RuntimeException( 'Export write failed.' );
		}
		rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/** Creates folders and the protective .htaccess files (ENG-27). */
	public static function ensure_dirs( string $dir ): void {
		wp_mkdir_p( $dir );
		$base = self::base_dir();
		$ht   = $base . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			self::put(
				$ht,
				"# RBX Data exports (ENG-27)\nOptions -Indexes\n<IfModule mod_headers.c>\n  Header set X-Content-Type-Options \"nosniff\"\n  <FilesMatch \"\\.[0-9a-f]{10}\\.json$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n  </FilesMatch>\n  <FilesMatch \"^manifest\\.json$\">\n    Header set Cache-Control \"public, max-age=300\"\n  </FilesMatch>\n</IfModule>\n<IfModule mod_mime.c>\n  AddType \"application/json; charset=utf-8\" .json\n</IfModule>\n<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n"
			);
		}
		foreach ( Loader::games() as $g ) {
			$adir = $base . '/' . $g->key() . '/archive';
			if ( is_dir( $adir ) && ! file_exists( $adir . '/.htaccess' ) ) {
				self::put( $adir . '/.htaccess', "Require all denied\n" );
			}
		}
		if ( str_ends_with( $dir, '/archive' ) && ! file_exists( $dir . '/.htaccess' ) ) {
			self::put( $dir . '/.htaccess', "Require all denied\n" );
		}
		if ( ! file_exists( $base . '/index.html' ) ) {
			self::put( $base . '/index.html', '' );
		}
	}
}
