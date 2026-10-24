<?php
/**
 * Tiny failure counter (UO-58): the calculator reports "data could not load" here, so a broken
 * deploy shows up without analytics. Only a daily count per kind and game is kept: no IP, no
 * cookie, no user agent. Admins read it at GET /wp-json/rbx/v1/beacon.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Counts in one option, newest 30 days, each counter capped.
 */
final class Beacon {

	private const OPTION = 'rbx_beacon';
	private const KINDS  = array( 'calc_load' );
	private const DAYS   = 30;
	private const CAP    = 1000;

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'rbx/v1',
			'/beacon',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => array( self::class, 'hit' ),
					'args'                => array(
						'k' => array(
							'type'     => 'string',
							'required' => true,
						),
						'g' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static fn(): array => self::all(),
				),
			)
		);
	}

	public static function hit( \WP_REST_Request $req ): \WP_REST_Response {
		$kind = (string) $req->get_param( 'k' );
		$game = sanitize_key( (string) $req->get_param( 'g' ) );
		if ( ! in_array( $kind, self::KINDS, true ) || ( '' !== $game && null === \RBX\Data\Config\Loader::game( $game ) ) ) {
			return new \WP_REST_Response( null, 204 );
		}
		$day = gmdate( 'Y-m-d' );
		$all = self::all();
		$key = $kind . ( '' !== $game ? ':' . $game : '' );
		if ( ( $all[ $day ][ $key ] ?? 0 ) < self::CAP ) {
			$all[ $day ][ $key ] = ( $all[ $day ][ $key ] ?? 0 ) + 1;
			krsort( $all );
			update_option( self::OPTION, array_slice( $all, 0, self::DAYS, true ), false );
		}
		return new \WP_REST_Response( null, 204 );
	}

	/** @return array<string, array<string, int>> Day => kind:game => count. */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}
}
