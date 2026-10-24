<?php
/**
 * The one number formatter (DATA-29, ENG-35). Twin of rbx-tools/assets/js/format.js;
 * both are driven by tests/fixtures/format_vectors.json and must stay byte-identical.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions, no WordPress calls, no intl.
 */
final class Number {

	public const DEFAULT_LADDER = array(
		array( 's' => 'K', 'e' => 3 ),
		array( 's' => 'M', 'e' => 6 ),
		array( 's' => 'B', 'e' => 9 ),
		array( 's' => 'T', 'e' => 12 ),
	);

	private const LOCALES = array(
		'en' => array( 'dec' => '.', 'grp' => ',' ),
		'id' => array( 'dec' => ',', 'grp' => '.' ),
	);

	private const MAX_SAFE = 9007199254740991;

	/**
	 * @param array<int, array{s:string, e:int}>|null $ladder Suffix ladder from `_meta.suffix_ladder`.
	 */
	public static function format( mixed $value, string $format, string $locale = 'en', ?array $ladder = null ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return '';
		}
		$v = (float) $value;
		if ( is_nan( $v ) || is_infinite( $v ) ) {
			return '';
		}
		$ladder = self::ladder( $ladder );
		if ( preg_match( '/^decimal\((\d)\)$/', $format, $m ) ) {
			return self::fixed( $v, (int) $m[1], true, $locale );
		}
		switch ( $format ) {
			case 'int':
				return self::fixed( $v, 0, false, $locale );
			case 'mult':
				return "\u{00D7}" . self::fixed( $v, 4, true, $locale );
			case 'prob':
				return self::prob( $v, $locale );
			case 'duration':
				return self::duration( $v );
			case 'abbr':
				return self::abbr( $v, $locale, $ladder );
			default:
				return self::fixed( $v, 4, true, $locale );
		}
	}

	/** Full value for `data-exact` and screen readers. */
	public static function exact( mixed $value, string $locale = 'en', ?array $ladder = null ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return '';
		}
		$v = (float) $value;
		if ( is_nan( $v ) || is_infinite( $v ) ) {
			return '';
		}
		if ( abs( $v ) > self::MAX_SAFE ) {
			return self::abbr( $v, $locale, self::ladder( $ladder ) );
		}
		// CR-17: whole numbers from 1,000 up ("912,330,365", not "912,330,365.0784").
		return self::fixed( $v, abs( $v ) >= 1000 ? 0 : 4, true, $locale );
	}

	/**
	 * @param array<int, array{s:string, e:int}>|null $ladder Ladder.
	 * @return array<int, array{s:string, e:int}>
	 */
	public static function ladder( ?array $ladder ): array {
		if ( ! $ladder ) {
			return self::DEFAULT_LADDER;
		}
		$out = array();
		foreach ( $ladder as $step ) {
			if ( isset( $step['s'], $step['e'] ) && is_string( $step['s'] ) && is_numeric( $step['e'] ) ) {
				$out[] = array(
					's' => $step['s'],
					'e' => (int) $step['e'],
				);
			}
		}
		usort( $out, static fn( $a, $b ) => $a['e'] <=> $b['e'] );
		return $out ? $out : self::DEFAULT_LADDER;
	}

	/**
	 * Rounds half away from zero to $d decimals and returns [ sign, integer digits string, fraction digits string ].
	 *
	 * @return array{0:string, 1:string, 2:string}
	 */
	private static function digits( float $v, int $d ): array {
		$f = 10 ** $d;
		$n = floor( abs( $v ) * $f + 0.5 );
		$s = sprintf( '%.0f', $n );
		if ( $d > 0 ) {
			$s    = str_pad( $s, $d + 1, '0', STR_PAD_LEFT );
			$int  = substr( $s, 0, -$d );
			$frac = substr( $s, -$d );
		} else {
			$int  = $s;
			$frac = '';
		}
		$sign = ( $v < 0 && $n > 0 ) ? '-' : '';
		return array( $sign, $int, $frac );
	}

	private static function fixed( float $v, int $d, bool $trim, string $locale ): string {
		list( $sign, $int, $frac ) = self::digits( $v, $d );
		if ( $trim ) {
			$frac = rtrim( $frac, '0' );
		}
		return $sign . self::join( $int, $frac, $locale );
	}

	private static function join( string $int, string $frac, string $locale ): string {
		$l   = self::LOCALES[ $locale ] ?? self::LOCALES['en'];
		$int = ltrim( $int, '0' );
		$int = '' === $int ? '0' : $int;
		$grp = '';
		$len = strlen( $int );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $i > 0 && 0 === ( $len - $i ) % 3 ) {
				$grp .= $l['grp'];
			}
			$grp .= $int[ $i ];
		}
		return '' === $frac ? $grp : $grp . $l['dec'] . $frac;
	}

	/** Number of decimals that leaves 3 significant digits for |m| (m != 0). */
	private static function sig3_decimals( float $m ): int {
		$a = abs( $m );
		if ( $a >= 100 ) {
			return 0;
		}
		if ( $a >= 10 ) {
			return 1;
		}
		if ( $a >= 1 ) {
			return 2;
		}
		$d = 2;
		while ( $a < 1 && $d < 12 ) {
			$a *= 10;
			++$d;
		}
		return $d;
	}

	/**
	 * Rounded mantissa value (3 significant digits) as float, for step promotion checks.
	 */
	private static function sig3( float $m ): float {
		$d = self::sig3_decimals( $m );
		$f = 10 ** $d;
		return floor( abs( $m ) * $f + 0.5 ) / $f;
	}

	/**
	 * @param array<int, array{s:string, e:int}> $ladder Ladder sorted by e.
	 */
	private static function abbr( float $v, string $locale, array $ladder ): string {
		if ( 0.0 === $v ) {
			return '0';
		}
		$a    = abs( $v );
		$step = -1;
		foreach ( $ladder as $i => $s ) {
			if ( $a >= 10 ** $s['e'] ) {
				$step = $i;
			}
		}
		$m = $a;
		while ( true ) {
			$div = $step >= 0 ? 10 ** $ladder[ $step ]['e'] : 1;
			$m   = $a / $div;
			if ( self::sig3( $m ) >= 1000 && isset( $ladder[ $step + 1 ] ) ) {
				++$step;
				continue;
			}
			break;
		}
		$suffix = $step >= 0 ? $ladder[ $step ]['s'] : '';
		if ( self::sig3( $m ) >= 1000 ) {
			$body = self::fixed( $m, 0, true, $locale );
		} else {
			$body = self::fixed( $m, self::sig3_decimals( $m ), true, $locale );
		}
		return ( $v < 0 ? '-' : '' ) . $body . $suffix;
	}

	private static function prob( float $p, string $locale ): string {
		if ( $p <= 0 ) {
			return '0%';
		}
		$pct = $p * 100;
		if ( $pct >= 1 ) {
			return self::fixed( $pct, 1, true, $locale ) . '%';
		}
		if ( $pct >= 0.0001 ) {
			$d = 1;
			$a = $pct;
			while ( $a < 10 ) {
				$a *= 10;
				++$d;
			}
			return self::fixed( $pct, $d - 1, true, $locale ) . '%';
		}
		$n = floor( 1 / $p + 0.5 );
		return '1 in ' . self::fixed( $n, 0, false, $locale );
	}

	private static function duration( float $s ): string {
		$t = (int) floor( abs( $s ) + 0.5 );
		$u = array(
			'd' => intdiv( $t, 86400 ),
			'h' => intdiv( $t % 86400, 3600 ),
			'm' => intdiv( $t % 3600, 60 ),
			's' => $t % 60,
		);
		$out   = array();
		$start = false;
		foreach ( $u as $k => $n ) {
			if ( ! $start && 0 === $n && 's' !== $k ) {
				continue;
			}
			$out[] = ( $start ? str_pad( (string) $n, 2, '0', STR_PAD_LEFT ) : (string) $n ) . $k;
			$start = true;
		}
		return implode( ' ', $out );
	}
}
