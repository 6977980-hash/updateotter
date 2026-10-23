<?php
/**
 * Loads and validates `games/*.json` (DATA-40).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Config;

use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Game config registry.
 */
final class Loader {

	/** @var array<string, Game>|null */
	private static ?array $games = null;

	/** @var array<string, array<int, string>> */
	private static array $errors = array();

	/**
	 * All valid games keyed by lower-case code.
	 *
	 * @return array<string, Game>
	 */
	public static function games(): array {
		if ( null === self::$games ) {
			self::load();
		}
		return self::$games ?? array();
	}

	public static function game( string $key ): ?Game {
		return self::games()[ strtolower( $key ) ] ?? null;
	}

	public static function by_folder( string $folder ): ?Game {
		foreach ( self::games() as $game ) {
			if ( $game->folder() === $folder ) {
				return $game;
			}
		}
		return null;
	}

	public static function by_code( string $code ): ?Game {
		foreach ( self::games() as $game ) {
			if ( $game->code() === $code ) {
				return $game;
			}
		}
		return null;
	}

	/** @return array<string, array<int, string>> Validation errors per file. */
	public static function errors(): array {
		if ( null === self::$games ) {
			self::load();
		}
		return self::$errors;
	}

	/** Hash of every config file, used to decide when to flush rewrites. */
	public static function hash(): string {
		$h = '';
		foreach ( self::files() as $file ) {
			$h .= basename( $file ) . ':' . md5_file( $file ) . ';';
		}
		return md5( $h );
	}

	/** @return array<int, string> */
	public static function files(): array {
		$dir   = (string) apply_filters( 'rbx_data_games_dir', RBX_DATA_DIR . '/games' );
		$files = glob( $dir . '/*.json' );
		$files = false === $files ? array() : $files;
		return array_values( array_filter( $files, static fn( $f ) => 'games.schema.json' !== basename( $f ) ) );
	}

	/** For tests: inject configs directly. @param array<string, array<string, mixed>> $configs */
	public static function set( array $configs ): void {
		self::$games  = array();
		self::$errors = array();
		foreach ( $configs as $name => $config ) {
			$errors = Validator::validate( $config );
			if ( $errors ) {
				self::$errors[ $name ] = $errors;
				continue;
			}
			$game                             = new Game( $config );
			self::$games[ $game->key() ] = $game;
		}
		// Navigation, footer and home cards list games by `nav_order` (lowest first), then by name.
		uasort( self::$games, static fn( Game $a, Game $b ) => ( (int) ( $a->raw()['nav_order'] ?? 99 ) <=> (int) ( $b->raw()['nav_order'] ?? 99 ) ) ?: strcmp( $a->name(), $b->name() ) );
	}

	public static function reset(): void {
		self::$games = null;
	}

	private static function load(): void {
		$configs = array();
		foreach ( self::files() as $file ) {
			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $json ) ) {
				self::$errors[ basename( $file ) ] = array( 'Invalid JSON.' );
				continue;
			}
			$configs[ basename( $file ) ] = self::apply_overrides( $json );
		}
		$keep_errors = self::$errors;
		self::set( $configs );
		self::$errors = array_merge( $keep_errors, self::$errors );
		foreach ( self::$errors as $file => $errs ) {
			ErrorLog::add( 'config', $file . ': ' . implode( ' ', $errs ) );
		}
	}

	/**
	 * The owner may enter universe/place IDs in wp-admin until they are committed to the config.
	 *
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	private static function apply_overrides( array $config ): array {
		if ( ! function_exists( 'get_option' ) ) {
			return $config;
		}
		$overrides = get_option( 'rbx_game_overrides', array() );
		$code      = strtolower( (string) ( $config['game_code'] ?? '' ) );
		if ( is_array( $overrides ) && isset( $overrides[ $code ] ) && is_array( $overrides[ $code ] ) ) {
			foreach ( array( 'roblox_universe_id', 'roblox_place_id' ) as $k ) {
				if ( empty( $config[ $k ] ) && ! empty( $overrides[ $code ][ $k ] ) ) {
					$config[ $k ] = (string) $overrides[ $code ][ $k ];
				}
			}
		}
		return $config;
	}
}
