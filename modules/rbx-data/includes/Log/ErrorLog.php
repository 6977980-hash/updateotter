<?php
/**
 * Capped error log in an option (REV-G29). No personal data is stored.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the newest 200 entries.
 */
final class ErrorLog {

	private const OPTION = 'rbx_error_log';
	private const MAX    = 200;

	public static function add( string $area, string $message ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't'    => gmdate( 'c' ),
				'area' => substr( $area, 0, 32 ),
				'msg'  => substr( $message, 0, 500 ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/** @return array<int, array{t:string, area:string, msg:string}> */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function count_since( int $days ): int {
		$cut = time() - $days * DAY_IN_SECONDS;
		return count( array_filter( self::all(), static fn( $r ) => strtotime( $r['t'] ) >= $cut ) );
	}

	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
