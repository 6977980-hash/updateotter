<?php
/**
 * Safe expression language for formulas (DATA-25). No eval; parsed to an array AST.
 * Twin: rbx-tools/assets/js/formula-engine.js (compile()).
 *
 * Grammar (whitespace ignored):
 *   expr    := cmp
 *   cmp     := add ( ( '<' | '<=' | '>' | '>=' | '==' | '!=' ) add )?
 *   add     := mul ( ( '+' | '-' ) mul )*
 *   mul     := unary ( ( '*' | '/' ) unary )*
 *   unary   := '-' unary | pow
 *   pow     := primary ( '^' unary )?
 *   primary := NUMBER | '(' expr ')' | input | ref | call
 *   input   := 'input' '.' NAME | NAME                 (NAME declared in inputs)
 *   ref     := ( 'ref' | 'refs' ) '(' ENT ',' input ')' '.' FIELD
 *   call    := FN '(' args ')'   FN in min max floor ceil round clamp product sum if has abs param
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Recursive-descent parser.
 */
final class Parser {

	public const FUNCTIONS = array(
		'min'     => array( 1, 99 ),
		'max'     => array( 1, 99 ),
		'floor'   => array( 1, 1 ),
		'ceil'    => array( 1, 1 ),
		'abs'     => array( 1, 1 ),
		'round'   => array( 1, 2 ),
		'clamp'   => array( 3, 3 ),
		'product' => array( 1, 1 ),
		'sum'     => array( 1, 1 ),
		'if'      => array( 3, 3 ),
		'has'     => array( 1, 1 ),
		'param'   => array( 2, 2 ),
	);

	/** @var array<int, array{0:string, 1:string}> */
	private array $tokens = array();
	private int $pos      = 0;

	/**
	 * @param array<string, array<string, mixed>> $inputs        Declared inputs: name => {type, target?}.
	 * @param callable|null                       $field_checker fn(string $entity, string $field): bool.
	 */
	private function __construct( private array $inputs, private $field_checker ) {}

	/**
	 * @param array<string, array<string, mixed>> $inputs Declared inputs.
	 * @return array<int, mixed> AST.
	 * @throws SyntaxError On any error.
	 */
	public static function compile( string $expr, array $inputs, ?callable $field_checker = null ): array {
		if ( strlen( $expr ) > 2000 ) {
			throw new SyntaxError( 'Expression too long.' );
		}
		$p         = new self( $inputs, $field_checker );
		$p->tokens = self::lex( $expr );
		$ast       = $p->cmp();
		if ( $p->pos < count( $p->tokens ) ) {
			throw new SyntaxError( 'Unexpected "' . $p->tokens[ $p->pos ][1] . '".' );
		}
		return $ast;
	}

	/** @return array<int, array{0:string, 1:string}> */
	private static function lex( string $s ): array {
		$out = array();
		$len = strlen( $s );
		$i   = 0;
		while ( $i < $len ) {
			$c = $s[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( preg_match( '/\G(\d+(?:\.\d+)?)/', $s, $m, 0, $i ) ) {
				$out[] = array( 'num', $m[1] );
				$i    += strlen( $m[1] );
				continue;
			}
			if ( preg_match( '/\G([A-Za-z_][A-Za-z0-9_]*)/', $s, $m, 0, $i ) ) {
				$out[] = array( 'id', $m[1] );
				$i    += strlen( $m[1] );
				continue;
			}
			if ( preg_match( '/\G(<=|>=|==|!=|[-+*\/^(),.<>])/', $s, $m, 0, $i ) ) {
				$out[] = array( 'op', $m[1] );
				$i    += strlen( $m[1] );
				continue;
			}
			throw new SyntaxError( 'Unexpected character "' . $c . '".' );
		}
		return $out;
	}

	private function peek( string $type, ?string $val = null ): bool {
		$t = $this->tokens[ $this->pos ] ?? null;
		return null !== $t && $t[0] === $type && ( null === $val || $t[1] === $val );
	}

	private function take( string $type, ?string $val = null ): string {
		if ( ! $this->peek( $type, $val ) ) {
			$got = $this->tokens[ $this->pos ][1] ?? 'end of formula';
			throw new SyntaxError( 'Expected ' . ( $val ?? $type ) . ', got "' . $got . '".' );
		}
		return $this->tokens[ $this->pos++ ][1];
	}

	/** @return array<int, mixed> */
	private function cmp(): array {
		$a = $this->add();
		foreach ( array( '<=', '>=', '==', '!=', '<', '>' ) as $op ) {
			if ( $this->peek( 'op', $op ) ) {
				++$this->pos;
				return array( 'bin', $op, $a, $this->add() );
			}
		}
		return $a;
	}

	/** @return array<int, mixed> */
	private function add(): array {
		$a = $this->mul();
		while ( $this->peek( 'op', '+' ) || $this->peek( 'op', '-' ) ) {
			$op = $this->tokens[ $this->pos++ ][1];
			$a  = array( 'bin', $op, $a, $this->mul() );
		}
		return $a;
	}

	/** @return array<int, mixed> */
	private function mul(): array {
		$a = $this->unary();
		while ( $this->peek( 'op', '*' ) || $this->peek( 'op', '/' ) ) {
			$op = $this->tokens[ $this->pos++ ][1];
			$a  = array( 'bin', $op, $a, $this->unary() );
		}
		return $a;
	}

	/** @return array<int, mixed> */
	private function unary(): array {
		if ( $this->peek( 'op', '-' ) ) {
			++$this->pos;
			return array( 'neg', $this->unary() );
		}
		$a = $this->primary();
		if ( $this->peek( 'op', '^' ) ) {
			++$this->pos;
			return array( 'bin', '^', $a, $this->unary() );
		}
		return $a;
	}

	/** @return array<int, mixed> */
	private function primary(): array {
		if ( $this->peek( 'num' ) ) {
			return array( 'num', (float) $this->take( 'num' ) );
		}
		if ( $this->peek( 'op', '(' ) ) {
			++$this->pos;
			$e = $this->cmp();
			$this->take( 'op', ')' );
			return $e;
		}
		$name = $this->take( 'id' );
		if ( 'ref' === $name || 'refs' === $name ) {
			$this->take( 'op', '(' );
			$ent = $this->take( 'id' );
			$this->take( 'op', ',' );
			$in = $this->input_name();
			$this->take( 'op', ')' );
			$this->take( 'op', '.' );
			$field = $this->take( 'id' );
			$decl  = $this->inputs[ $in ];
			$type  = (string) ( $decl['type'] ?? '' );
			if ( 'refs' === $name && 'ref_list' !== $type ) {
				throw new SyntaxError( 'refs() needs a list input; "' . $in . '" is ' . $type . '.' );
			}
			if ( 'ref' === $name && 'ref' !== $type ) {
				throw new SyntaxError( 'ref() needs a record input; "' . $in . '" is ' . $type . '.' );
			}
			if ( isset( $decl['target'] ) && $decl['target'] !== $ent ) {
				throw new SyntaxError( 'Input "' . $in . '" points to ' . $decl['target'] . ', not ' . $ent . '.' );
			}
			if ( $this->field_checker && ! ( $this->field_checker )( $ent, $field ) ) {
				throw new SyntaxError( 'Unknown or non-numeric field ' . $ent . '.' . $field . '.' );
			}
			return array( 'refs' === $name ? 'refs' : 'ref', $ent, $in, $field );
		}
		if ( $this->peek( 'op', '(' ) ) {
			if ( ! isset( self::FUNCTIONS[ $name ] ) ) {
				throw new SyntaxError( 'Unknown function "' . $name . '".' );
			}
			++$this->pos;
			if ( 'has' === $name ) {
				$in = $this->input_name();
				$this->take( 'op', ')' );
				return array( 'has', $in );
			}
			if ( 'param' === $name ) {
				$key = $this->take( 'id' );
				$this->take( 'op', ',' );
				$pn = $this->take( 'id' );
				$this->take( 'op', ')' );
				return array( 'param', $key, $pn );
			}
			$args = array();
			if ( ! $this->peek( 'op', ')' ) ) {
				$args[] = $this->cmp();
				while ( $this->peek( 'op', ',' ) ) {
					++$this->pos;
					$args[] = $this->cmp();
				}
			}
			$this->take( 'op', ')' );
			list( $lo, $hi ) = self::FUNCTIONS[ $name ];
			if ( count( $args ) < $lo || count( $args ) > $hi ) {
				throw new SyntaxError( 'Wrong number of arguments for ' . $name . '().' );
			}
			return array( 'call', $name, $args );
		}
		--$this->pos;
		$in = $this->input_name();
		$t  = (string) ( $this->inputs[ $in ]['type'] ?? '' );
		if ( 'number' !== $t && 'bool' !== $t ) {
			throw new SyntaxError( 'Input "' . $in . '" is a record; use ref(' . ( $this->inputs[ $in ]['target'] ?? 'ENT' ) . ', ' . $in . ').field.' );
		}
		return array( 'in', $in );
	}

	private function input_name(): string {
		$n = $this->take( 'id' );
		if ( 'input' === $n && $this->peek( 'op', '.' ) ) {
			++$this->pos;
			$n = $this->take( 'id' );
		}
		if ( ! isset( $this->inputs[ $n ] ) ) {
			throw new SyntaxError( 'Undeclared input "' . $n . '".' );
		}
		return $n;
	}

	/**
	 * Every input and record field an AST uses.
	 *
	 * @param array<int, mixed> $ast AST.
	 * @return array{inputs: array<int, string>, fields: array<int, string>}
	 */
	public static function uses( array $ast ): array {
		$in = array();
		$f  = array();
		$walk = static function ( array $n ) use ( &$walk, &$in, &$f ): void {
			switch ( $n[0] ) {
				case 'in':
				case 'has':
					$in[] = $n[1];
					break;
				case 'ref':
				case 'refs':
					$in[] = $n[2];
					$f[]  = $n[1] . '.' . $n[3];
					break;
				case 'bin':
					$walk( $n[2] );
					$walk( $n[3] );
					break;
				case 'neg':
					$walk( $n[1] );
					break;
				case 'call':
					foreach ( $n[2] as $a ) {
						$walk( $a );
					}
					break;
			}
		};
		$walk( $ast );
		return array(
			'inputs' => array_values( array_unique( $in ) ),
			'fields' => array_values( array_unique( $f ) ),
		);
	}
}
