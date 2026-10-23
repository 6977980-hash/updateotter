<?php
/**
 * Roblox HTTP client (ENG-42, ENG-43, POL-54). Public, unauthenticated reads only.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Roblox;

defined( 'ABSPATH' ) || exit;

/**
 * One batched request per poll.
 */
final class Client {

	public const UA = 'UpdateOtter-notifier/1.0 (+https://updateotter.com/contact/)';

	/** @return array<string, mixed> */
	private static function args(): array {
		return array(
			'timeout'     => 10,
			'redirection' => 2,
			'user-agent'  => self::UA,
			'headers'     => array( 'Accept' => 'application/json' ),
			'sslverify'   => true,
		);
	}

	/**
	 * Game details for universe IDs.
	 *
	 * kind: ok | shape_error | rate_limited | server_error | config_error.
	 *
	 * @param string[] $ids Universe IDs.
	 * @return array{kind: string, http: int, ms: int, games: array<string, array{id: string, root_place_id: string, name: string, updated: string}>, retry_after: int|null, ratelimit_remaining: string, ratelimit_reset: string, error: string}
	 */
	public static function games( array $ids ): array {
		$out = array(
			'kind'                => 'ok',
			'http'                => 0,
			'ms'                  => 0,
			'games'               => array(),
			'retry_after'         => null,
			'ratelimit_remaining' => '',
			'ratelimit_reset'     => '',
			'error'               => '',
		);
		$ids = array_values( array_filter( array_map( 'strval', $ids ), static fn( $i ) => (bool) preg_match( '/^\d{1,20}$/', $i ) ) );
		if ( ! $ids ) {
			$out['kind']  = 'config_error';
			$out['error'] = 'no universe IDs';
			return $out;
		}
		if ( get_option( 'rbx_notifier_use_open_cloud' ) && defined( 'RBX_ROBLOX_OPEN_CLOUD_KEY' ) && '' !== RBX_ROBLOX_OPEN_CLOUD_KEY ) {
			return self::open_cloud( $ids, $out );
		}
		$t0  = microtime( true );
		$res = wp_safe_remote_get( 'https://games.roblox.com/v1/games?universeIds=' . implode( ',', $ids ), self::args() );
		$out['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $res ) ) {
			$out['kind']  = 'server_error';
			$out['error'] = substr( $res->get_error_message(), 0, 200 );
			return $out;
		}
		$out = self::status( $res, $out );
		if ( 'ok' !== $out['kind'] ) {
			return $out;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) || ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) {
			$out['kind']  = 'shape_error';
			$out['error'] = 'no data[] array';
			return $out;
		}
		foreach ( $json['data'] as $g ) {
			if ( ! is_array( $g ) || ! isset( $g['id'], $g['updated'] ) ) {
				continue;
			}
			$ts = strtotime( (string) $g['updated'] );
			if ( false === $ts ) {
				continue;
			}
			$id                   = (string) $g['id'];
			$out['games'][ $id ] = array(
				'id'            => $id,
				'root_place_id' => (string) ( $g['rootPlaceId'] ?? '' ),
				'name'          => sanitize_text_field( (string) ( $g['name'] ?? '' ) ),
				'updated'       => gmdate( 'Y-m-d\TH:i:s\Z', $ts ),
			);
		}
		$missing = array_diff( $ids, array_keys( $out['games'] ) );
		if ( $missing ) {
			$out['kind']  = 'shape_error';
			$out['error'] = 'missing or unparsable: ' . implode( ',', $missing );
		}
		return $out;
	}

	/**
	 * Fallback (off by default): Open Cloud universe resource, one call per universe.
	 *
	 * @param string[]             $ids Universe IDs.
	 * @param array<string, mixed> $out Result skeleton.
	 * @return array<string, mixed>
	 */
	private static function open_cloud( array $ids, array $out ): array {
		$args                         = self::args();
		$args['headers']['x-api-key'] = RBX_ROBLOX_OPEN_CLOUD_KEY;
		$t0                           = microtime( true );
		foreach ( $ids as $id ) {
			$res = wp_safe_remote_get( 'https://apis.roblox.com/cloud/v2/universes/' . rawurlencode( $id ), $args );
			if ( is_wp_error( $res ) ) {
				$out['kind']  = 'server_error';
				$out['error'] = substr( $res->get_error_message(), 0, 200 );
				break;
			}
			$out = self::status( $res, $out );
			if ( 'ok' !== $out['kind'] ) {
				break;
			}
			$j  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$ts = is_array( $j ) && isset( $j['updateTime'] ) ? strtotime( (string) $j['updateTime'] ) : false;
			if ( false === $ts ) {
				$out['kind']  = 'shape_error';
				$out['error'] = 'no updateTime for ' . $id;
				break;
			}
			$out['games'][ $id ] = array(
				'id'            => $id,
				'root_place_id' => preg_replace( '/\D/', '', (string) ( $j['rootPlace'] ?? '' ) ) ?? '',
				'name'          => sanitize_text_field( (string) ( $j['displayName'] ?? '' ) ),
				'updated'       => gmdate( 'Y-m-d\TH:i:s\Z', $ts ),
			);
		}
		$out['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		return $out;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $res Response.
	 * @param array<string, mixed>           $out Result.
	 * @return array<string, mixed>
	 */
	private static function status( $res, array $out ): array {
		$code                       = (int) wp_remote_retrieve_response_code( $res );
		$out['http']                = $code;
		$out['ratelimit_remaining'] = (string) wp_remote_retrieve_header( $res, 'x-ratelimit-remaining' );
		$out['ratelimit_reset']     = (string) wp_remote_retrieve_header( $res, 'x-ratelimit-reset' );
		if ( 200 === $code ) {
			return $out;
		}
		if ( 429 === $code ) {
			$out['kind'] = 'rate_limited';
			$ra          = trim( (string) wp_remote_retrieve_header( $res, 'retry-after' ) );
			if ( '' !== $ra ) {
				$out['retry_after'] = ctype_digit( $ra ) ? (int) $ra : max( 0, (int) strtotime( $ra ) - time() );
			}
		} elseif ( $code >= 500 ) {
			$out['kind'] = 'server_error';
		} else {
			$out['kind'] = 'config_error';
		}
		$out['error'] = 'HTTP ' . $code;
		return $out;
	}

	/** Universe ID for a place ID (ENG-41 helper), or an error string. */
	public static function universe_for_place( string $place ): array {
		if ( ! preg_match( '/^\d{1,20}$/', $place ) ) {
			return array( 'ok' => false, 'value' => __( 'A place ID is digits only.', 'rbx-notifier' ) );
		}
		$res = wp_safe_remote_get( 'https://apis.roblox.com/universes/v1/places/' . $place . '/universe', self::args() );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'value' => $res->get_error_message() );
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $j ) || empty( $j['universeId'] ) ) {
			/* translators: %d: HTTP status */
			return array( 'ok' => false, 'value' => sprintf( __( 'Roblox answered HTTP %d without a universe ID.', 'rbx-notifier' ), (int) wp_remote_retrieve_response_code( $res ) ) );
		}
		return array( 'ok' => true, 'value' => (string) $j['universeId'] );
	}
}
