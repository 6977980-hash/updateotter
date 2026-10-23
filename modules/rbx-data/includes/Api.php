<?php
/**
 * Stable entry points for rbx-tools and rbx-notifier (ENG-13). Callers guard with
 * `class_exists( '\RBX\Data\Api' )`.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Export\Writer;
use RBX\Data\Format\Number;
use RBX\Data\Formula\Interpreter;
use RBX\Data\Formula\Unavailable;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only facade.
 */
final class Api {

	/** @return array<string, Game> */
	public static function games(): array {
		return Loader::games();
	}

	public static function game( string $key ): ?Game {
		return Loader::game( $key );
	}

	public static function dataset( Game $game ): Dataset {
		return Repository::dataset( $game );
	}

	/** @return array<string, mixed>|null */
	public static function record( string $id ): ?array {
		if ( ! preg_match( '/^([A-Z][A-Z0-9]{1,4})-/', $id, $m ) ) {
			return null;
		}
		$game = Loader::by_code( $m[1] );
		return $game ? Repository::dataset( $game )->get( $id ) : null;
	}

	/**
	 * Slice URLs and dataset version for a game (the front end never fetches manifest.json).
	 *
	 * @return array{version: string, files: array<string, string>}
	 */
	public static function export_manifest( Game $game ): array {
		$info  = Writer::info( $game );
		$files = array();
		foreach ( array_keys( (array) ( $info['files'] ?? array() ) ) as $slice ) {
			$files[ (string) $slice ] = Writer::slice_url( $game, (string) $slice );
		}
		return array(
			'version' => (string) ( $info['version'] ?? '' ),
			'files'   => $files,
		);
	}

	/** @param array<int, array{s:string, e:int}>|null $ladder Ladder. */
	public static function format( mixed $value, string $format, string $locale = 'en', ?array $ladder = null ): string {
		return Number::format( $value, $format, $locale, $ladder );
	}

	/**
	 * Server-side evaluation (L7 default results). Ref inputs may be IDs or slugs.
	 *
	 * @param array<string, mixed> $inputs Inputs.
	 * @return array{ok: bool, value: float|null, reason: string, missing: string}
	 */
	public static function evaluate( string $formula_key, array $inputs, Game $game ): array {
		$ds = Repository::dataset( $game );
		$f  = $ds->formulas()[ $formula_key ] ?? null;
		if ( null === $f ) {
			return array(
				'ok'      => false,
				'value'   => null,
				'reason'  => 'missing',
				'missing' => '',
			);
		}
		if ( ! $f['available'] ) {
			return array(
				'ok'      => false,
				'value'   => null,
				'reason'  => (string) $f['reason'],
				'missing' => '',
			);
		}
		try {
			// Item pages evaluate the formula many times per request; build the calc data once per dataset.
			static $data = array();
			$k = $game->key() . ':' . Repository::version( $game ) . ':' . spl_object_id( $ds );
			if ( ! isset( $data[ $k ] ) ) {
				$data = array( $k => $ds->calc_data() );
			}
			$v = Interpreter::evaluate( $f['ast'], \RBX\Data\Import\Validator::test_inputs( $ds, $f['inputs'], $inputs ), $data[ $k ] );
			return array(
				'ok'      => true,
				'value'   => $v,
				'reason'  => '',
				'missing' => '',
			);
		} catch ( Unavailable $e ) {
			return array(
				'ok'      => false,
				'value'   => null,
				'reason'  => $e->reason,
				'missing' => $e->what,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'value'   => null,
				'reason'  => 'error',
				'missing' => '',
			);
		}
	}

	/** Trust strip HTML for any page (theme helper). */
	public static function trust_strip( int $post_id ): string {
		$post = get_post( $post_id );
		$game = $post ? Render\Breadcrumbs::game_for( $post ) : null;
		if ( null === $game ) {
			return '';
		}
		$plv = (string) get_post_meta( $post_id, '_rbx_page_last_verified', true );
		return Render\Ui::trust_strip( $game, (string) get_post_meta( $post_id, '_rbx_last_changed', true ), '' === $plv ? null : $plv );
	}

	/** @return array<int, array{0: string, 1: string}> */
	public static function breadcrumbs(): array {
		return Render\Breadcrumbs::trail();
	}

	public static function search_index_url(): string {
		return Export\SearchIndex::url();
	}
}
