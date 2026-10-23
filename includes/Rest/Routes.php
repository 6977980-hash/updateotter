<?php
/**
 * Admin REST routes `rbx/v1` (ENG-26). Every route checks a capability; game args must exist.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Rest;

use RBX\Data\Config\Loader;
use RBX\Data\Db\Runs;
use RBX\Data\Export\Writer;
use RBX\Data\Import\Runner;
use RBX\Data\Import\Source;
use RBX\Data\Lint\NumberLint;

defined( 'ABSPATH' ) || exit;

/**
 * Import fetch, diff pages, apply batches, rollback, export regeneration, lint.
 */
final class Routes {

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	public static function can_import(): bool {
		return current_user_can( 'rbx_import' );
	}

	public static function register(): void {
		$game_arg = array(
			'type'              => 'string',
			'pattern'           => '^[a-z0-9]{2,8}$',
			'required'          => true,
			'validate_callback' => static fn( $v ) => null !== Loader::game( (string) $v ),
		);
		$run_arg  = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		register_rest_route(
			'rbx/v1',
			'/import/(?P<game>[a-z0-9]{2,8})/fetch',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array( 'game' => $game_arg ),
				'callback'            => array( self::class, 'fetch' ),
			)
		);
		register_rest_route(
			'rbx/v1',
			'/import/runs/(?P<run_id>\d+)/diff',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array(
					'run_id' => $run_arg,
					'tab'    => array(
						'type'    => 'string',
						'pattern' => '^[a-z_]{0,40}$',
						'default' => '',
					),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
				'callback'            => array( self::class, 'diff' ),
			)
		);
		register_rest_route(
			'rbx/v1',
			'/import/runs/(?P<run_id>\d+)/apply-batch',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array( 'run_id' => $run_arg ),
				'callback'            => array( self::class, 'apply_batch' ),
			)
		);
		register_rest_route(
			'rbx/v1',
			'/import/runs/(?P<run_id>\d+)/rollback',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array( 'run_id' => $run_arg ),
				'callback'            => array( self::class, 'rollback' ),
			)
		);
		register_rest_route(
			'rbx/v1',
			'/exports/(?P<game>[a-z0-9]{2,8})/regenerate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array( 'game' => $game_arg ),
				'callback'            => array( self::class, 'regenerate' ),
			)
		);
		register_rest_route(
			'rbx/v1',
			'/lint',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => array( self::class, 'lint' ),
			)
		);
	}

	public static function fetch( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$game = Loader::game( (string) $r['game'] );
		try {
			$input = Source::from_sheet( $game );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'rbx_fetch', $e->getMessage(), array( 'status' => 400 ) );
		}
		$id  = Runner::prepare( $game, $input, 'sheet', get_current_user_id() );
		$run = Runs::get( $id, false );
		return new \WP_REST_Response(
			array(
				'run_id' => $id,
				'status' => $run['status'] ?? '',
				'counts' => $run['counts'] ?? array(),
			),
			201
		);
	}

	public static function diff( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$run = Runs::get( (int) $r['run_id'] );
		if ( ! $run ) {
			return new \WP_Error( 'rbx_run', __( 'Run not found.', 'rbx-data' ), array( 'status' => 404 ) );
		}
		$ops  = array_values( array_filter( (array) ( $run['plan']['ops'] ?? array() ), static fn( $o ) => '' === (string) $r['tab'] || $o['tab'] === (string) $r['tab'] ) );
		$page = max( 1, (int) $r['page'] );
		return new \WP_REST_Response(
			array(
				'total' => count( $ops ),
				'page'  => $page,
				'ops'   => array_slice( $ops, ( $page - 1 ) * 200, 200 ),
			)
		);
	}

	public static function apply_batch( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$id = (int) $r['run_id'];
		if ( ! wp_verify_nonce( (string) $r->get_header( 'x_rbx_run_nonce' ), 'rbx_import_apply_' . $id ) ) {
			return new \WP_Error( 'rbx_nonce', __( 'This page is out of date. Reload it and try again.', 'rbx-data' ), array( 'status' => 403 ) );
		}
		$res = Runner::step( $id );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 409 ) );
			return $res;
		}
		return new \WP_REST_Response( $res );
	}

	public static function rollback( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$id = (int) $r['run_id'];
		if ( ! wp_verify_nonce( (string) $r->get_header( 'x_rbx_run_nonce' ), 'rbx_import_rollback_' . $id ) ) {
			return new \WP_Error( 'rbx_nonce', __( 'This page is out of date. Reload it and try again.', 'rbx-data' ), array( 'status' => 403 ) );
		}
		$res = Runner::rollback( $id, get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 409 ) );
			return $res;
		}
		return new \WP_REST_Response( array( 'run_id' => $res ) );
	}

	public static function regenerate( \WP_REST_Request $r ): \WP_REST_Response {
		$game = Loader::game( (string) $r['game'] );
		return new \WP_REST_Response( Writer::write( $game ) );
	}

	public static function lint( \WP_REST_Request $r ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'issues' => NumberLint::check( (string) $r['content'] ) ) );
	}
}
