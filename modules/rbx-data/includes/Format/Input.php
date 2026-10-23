<?php
/**
 * Number entry parser (ENG-34). Twin of rbx-tools/assets/js/parse-input.js, driven by
 * tests/fixtures/parse_vectors.json.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Returns [ 'ok' => bool, 'value' => float|null, 'error' => string|null ].
 */
final class Input {

	private const MAX_SAFE = 9007199254740991;

	/**
	 * @param array<int, array{s:string, e:int}>|null $ladder   Suffix ladder.
	 * @param array<string, mixed>                    $opts     allow_negative, mult, integer, min, max.
	 * @return array{ok:bool, value:float|null, error:string|null}
	 */
	public static function parse( string $text, string $locale = 'en', ?array $ladder = null, string $selected_suffix = '', array $opts = array() ): array {
		$t = trim( str_replace( "\u{00A0}", ' ', $text ) );
		if ( '' === $t ) {
			return self::err( 'empty' );
		}
		$mult = ! empty( $opts['mult'] );
		if ( $mult ) {
			$t = (string) preg_replace( '/^(?:x|\x{00D7})\s*/iu', '', $t );
			$t = (string) preg_replace( '/\s*(?:x|\x{00D7})$/iu', '', $t );
		}
		$neg = false;
		if ( str_starts_with( $t, '-' ) ) {
			$neg = true;
			$t   = ltrim( substr( $t, 1 ) );
		}
		$ladder = Number::ladder( $ladder );
		$exp    = 0;
		if ( preg_match( '/^(.*?)\s*([a-z]+)$/i', $t, $m ) ) {
			$found = null;
			foreach ( $ladder as $step ) {
				if ( strtolower( $step['s'] ) === strtolower( $m[2] ) ) {
					$found = $step;
				}
			}
			if ( null === $found ) {
				return self::err( 'invalid' );
			}
			$exp = $found['e'];
			$t   = $m[1];
		} elseif ( '' !== $selected_suffix ) {
			foreach ( $ladder as $step ) {
				if ( $step['s'] === $selected_suffix ) {
					$exp = $step['e'];
				}
			}
		}
		$dec = 'id' === $locale ? ',' : '.';
		$grp = 'id' === $locale ? '.' : ',';
		$qd  = preg_quote( $dec, '/' );
		$qg  = preg_quote( $grp, '/' );
		if ( preg_match( '/^\d+(?:' . $qd . '\d+)?$/', $t ) ) {
			$num = $t;
		} elseif ( preg_match( '/^\d{1,3}(?:(?:' . $qg . '| )\d{3})+(?:' . $qd . '\d+)?$/', $t ) ) {
			$num = (string) preg_replace( '/[' . $qg . ' ]/', '', $t );
		} elseif ( preg_match( '/^\d+' . $qg . '\d+$/', $t ) ) {
			return self::err( 'ambiguous' );
		} else {
			return self::err( 'invalid' );
		}
		$num   = str_replace( $dec, '.', $num );
		$value = (float) $num * ( 10 ** $exp );
		if ( is_nan( $value ) || is_infinite( $value ) ) {
			return self::err( 'invalid' );
		}
		if ( $neg ) {
			if ( empty( $opts['allow_negative'] ) ) {
				return self::err( 'negative' );
			}
			$value = -$value;
		}
		if ( ! empty( $opts['integer'] ) ) {
			if ( floor( $value ) !== $value ) {
				return self::err( 'not_integer' );
			}
			if ( abs( $value ) > self::MAX_SAFE ) {
				return self::err( 'too_big' );
			}
		}
		if ( isset( $opts['max'] ) && $value > (float) $opts['max'] ) {
			return self::err( 'too_big' );
		}
		if ( isset( $opts['min'] ) && $value < (float) $opts['min'] ) {
			return self::err( 'too_small' );
		}
		return array(
			'ok'    => true,
			'value' => $value + 0.0,
			'error' => null,
		);
	}

	/** @return array{ok:bool, value:float|null, error:string|null} */
	private static function err( string $code ): array {
		return array(
			'ok'    => false,
			'value' => null,
			'error' => $code,
		);
	}
}
