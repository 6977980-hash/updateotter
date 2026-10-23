<?php
/**
 * Evaluates a formula AST (DATA-25). Twin: rbx-tools/assets/js/formula-engine.js (evaluate()).
 *
 * Data shape (same as the JSON export): [ 'records' => [ ENT => [ id => [ field => value ] ] ], 'params' => [ key => [ name => value ] ] ].
 * A value is a bare number (verified), [ 'v' => n, 's' => 'c' ] (usable community value) or
 * any other array (unknown 'u', disputed 'd', mechanic unconfirmed 'm', not usable community 'x').
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Pure evaluator.
 */
final class Interpreter {

	/**
	 * @param array<int, mixed>    $ast    AST from Parser::compile().
	 * @param array<string, mixed> $inputs Input values.
	 * @param array<string, mixed> $data   Records and params.
	 * @throws Unavailable When a needed value cannot be used.
	 */
	public static function evaluate( array $ast, array $inputs, array $data ): float {
		$v = self::ev( $ast, $inputs, $data );
		if ( is_array( $v ) ) {
			throw new Unavailable( 'type', 'list' );
		}
		if ( is_nan( $v ) || is_infinite( $v ) ) {
			throw new Unavailable( 'math' );
		}
		return $v;
	}

	/**
	 * @param array<int, mixed>    $n      Node.
	 * @param array<string, mixed> $inputs Inputs.
	 * @param array<string, mixed> $data   Data.
	 * @return float|array<int, float>
	 */
	private static function ev( array $n, array $inputs, array $data ): float|array {
		switch ( $n[0] ) {
			case 'num':
				return (float) $n[1];
			case 'in':
				$x = $inputs[ $n[1] ] ?? null;
				if ( is_bool( $x ) ) {
					return $x ? 1.0 : 0.0;
				}
				if ( ! is_int( $x ) && ! is_float( $x ) ) {
					throw new Unavailable( 'input', (string) $n[1] );
				}
				return (float) $x;
			case 'has':
				$x = $inputs[ $n[1] ] ?? null;
				return ( null === $x || '' === $x || array() === $x ) ? 0.0 : 1.0;
			case 'ref':
				$id = $inputs[ $n[2] ] ?? null;
				if ( ! is_string( $id ) || '' === $id ) {
					throw new Unavailable( 'input', (string) $n[2] );
				}
				return self::field( $data, (string) $n[1], $id, (string) $n[3] );
			case 'refs':
				$ids = $inputs[ $n[2] ] ?? array();
				$out = array();
				foreach ( (array) $ids as $id ) {
					$out[] = self::field( $data, (string) $n[1], (string) $id, (string) $n[3] );
				}
				return $out;
			case 'param':
				return self::value( $data['params'][ $n[1] ][ $n[2] ] ?? null, 'param ' . $n[1] . '.' . $n[2] );
			case 'neg':
				return -self::num( self::ev( $n[1], $inputs, $data ) );
			case 'bin':
				$a = self::num( self::ev( $n[2], $inputs, $data ) );
				$b = self::num( self::ev( $n[3], $inputs, $data ) );
				switch ( $n[1] ) {
					case '+':
						return $a + $b;
					case '-':
						return $a - $b;
					case '*':
						return $a * $b;
					case '/':
						if ( 0.0 === $b ) {
							throw new Unavailable( 'math', 'division by zero' );
						}
						return $a / $b;
					case '^':
						return (float) ( $a ** $b );
					case '<':
						return $a < $b ? 1.0 : 0.0;
					case '<=':
						return $a <= $b ? 1.0 : 0.0;
					case '>':
						return $a > $b ? 1.0 : 0.0;
					case '>=':
						return $a >= $b ? 1.0 : 0.0;
					case '==':
						return $a === $b ? 1.0 : 0.0;
					case '!=':
						return $a !== $b ? 1.0 : 0.0;
				}
				throw new Unavailable( 'op', (string) $n[1] );
			case 'call':
				return self::call( (string) $n[1], $n[2], $inputs, $data );
		}
		throw new Unavailable( 'node', (string) $n[0] );
	}

	/**
	 * @param array<int, array<int, mixed>> $args   Argument nodes.
	 * @param array<string, mixed>          $inputs Inputs.
	 * @param array<string, mixed>          $data   Data.
	 */
	private static function call( string $fn, array $args, array $inputs, array $data ): float {
		if ( 'if' === $fn ) {
			$c = self::num( self::ev( $args[0], $inputs, $data ) );
			return self::num( self::ev( 0.0 !== $c ? $args[1] : $args[2], $inputs, $data ) );
		}
		$vals = array_map( static fn( $a ) => self::ev( $a, $inputs, $data ), $args );
		switch ( $fn ) {
			case 'product':
				$p = 1.0;
				foreach ( self::lst( $vals[0] ) as $x ) {
					$p *= $x;
				}
				return $p;
			case 'sum':
				$s = 0.0;
				foreach ( self::lst( $vals[0] ) as $x ) {
					$s += $x;
				}
				return $s;
			case 'min':
			case 'max':
				$flat = array();
				foreach ( $vals as $v ) {
					$flat = array_merge( $flat, self::lst( $v ) );
				}
				if ( ! $flat ) {
					throw new Unavailable( 'math', 'empty list' );
				}
				return 'min' === $fn ? (float) min( $flat ) : (float) max( $flat );
			case 'floor':
				return floor( self::num( $vals[0] ) );
			case 'ceil':
				return ceil( self::num( $vals[0] ) );
			case 'abs':
				return abs( self::num( $vals[0] ) );
			case 'round':
				$x = self::num( $vals[0] );
				$d = isset( $vals[1] ) ? (int) self::num( $vals[1] ) : 0;
				$f = 10 ** $d;
				$r = floor( abs( $x ) * $f + 0.5 ) / $f;
				return $x < 0 ? -$r : $r;
			case 'clamp':
				$x = self::num( $vals[0] );
				return min( max( $x, self::num( $vals[1] ) ), self::num( $vals[2] ) );
		}
		throw new Unavailable( 'function', $fn );
	}

	/** @param float|array<int, float> $v Value. */
	private static function num( float|array $v ): float {
		if ( is_array( $v ) ) {
			throw new Unavailable( 'type', 'list' );
		}
		return $v;
	}

	/**
	 * @param float|array<int, float> $v Value.
	 * @return array<int, float>
	 */
	private static function lst( float|array $v ): array {
		return is_array( $v ) ? $v : array( $v );
	}

	/** @param array<string, mixed> $data Data. */
	private static function field( array $data, string $ent, string $id, string $field ): float {
		if ( ! isset( $data['records'][ $ent ][ $id ] ) ) {
			throw new Unavailable( 'record', $id );
		}
		return self::value( $data['records'][ $ent ][ $id ][ $field ] ?? null, $id . '.' . $field );
	}

	private static function value( mixed $v, string $what ): float {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		if ( is_array( $v ) && 'c' === ( $v['s'] ?? '' ) && ( is_int( $v['v'] ?? null ) || is_float( $v['v'] ?? null ) ) ) {
			return (float) $v['v'];
		}
		$state = is_array( $v ) ? (string) ( $v['s'] ?? 'u' ) : 'u';
		throw new Unavailable( 'state_' . $state, $what );
	}
}
