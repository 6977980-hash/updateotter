<?php
/**
 * Regenerates tests/fixtures/{format,parse,formula}_vectors.json from the PHP twins.
 * The JS twins in rbx-tools must produce identical output (rbx-tools/tests/parity.test.mjs).
 * Run: php tests/make-vectors.php  (only after a deliberate, reviewed change to the PHP rules)
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

'cli' === PHP_SAPI || exit;

define( 'ABSPATH', __DIR__ );
foreach ( array( 'Format/Number', 'Format/Input', 'Formula/SyntaxError', 'Formula/Unavailable', 'Formula/Parser', 'Formula/Interpreter' ) as $f ) {
	require dirname( __DIR__ ) . '/includes/' . $f . '.php';
}
use RBX\Data\Format\Number;
use RBX\Data\Format\Input;
use RBX\Data\Formula\Parser;
use RBX\Data\Formula\Interpreter;

$ladder_qa = array( array( 's' => 'K', 'e' => 3 ), array( 's' => 'M', 'e' => 6 ), array( 's' => 'B', 'e' => 9 ), array( 's' => 'T', 'e' => 12 ), array( 's' => 'Qa', 'e' => 15 ), array( 's' => 'Qi', 'e' => 18 ) );

$fmt = array();
$vals = array( 0, 1, 7, 12, 999, 999.4, 999.5, 1000, 1250, 1999, 12345, 99950, 999499, 999500, 999999, 1000000, 1250000, 12500000, 125000000, 999500000, 1.5e12, 9.995e14, 2.5e15, 3e18, 7.25e21, -1500, -0.5, 0.5, 0.123456, 0.0001234, 1234567.891, 2.5, 1.0e-7 );
foreach ( $vals as $v ) {
	foreach ( array( 'abbr', 'int', 'mult', 'decimal(2)', 'plain' ) as $f ) {
		$fmt[] = array( 'value' => $v, 'format' => $f, 'locale' => 'en', 'ladder' => null, 'out' => Number::format( $v, $f, 'en' ) );
	}
	$fmt[] = array( 'value' => $v, 'format' => 'abbr', 'locale' => 'en', 'ladder' => $ladder_qa, 'out' => Number::format( $v, 'abbr', 'en', $ladder_qa ) );
	$fmt[] = array( 'value' => $v, 'format' => 'abbr', 'locale' => 'id', 'ladder' => null, 'out' => Number::format( $v, 'abbr', 'id' ) );
	$fmt[] = array( 'value' => $v, 'format' => 'exact', 'locale' => 'en', 'ladder' => null, 'out' => Number::exact( $v, 'en' ) );
}
foreach ( array( 0, 1, 0.5, 0.25, 0.0123, 0.001, 0.000123, 0.0000005, 0.00000001, 0.999 ) as $p ) {
	$fmt[] = array( 'value' => $p, 'format' => 'prob', 'locale' => 'en', 'ladder' => null, 'out' => Number::format( $p, 'prob', 'en' ) );
}
foreach ( array( 0, 5, 59.6, 60, 61, 3599, 3600, 3661, 86399, 86400, 90061, 1000000 ) as $s ) {
	$fmt[] = array( 'value' => $s, 'format' => 'duration', 'locale' => 'en', 'ladder' => null, 'out' => Number::format( $s, 'duration', 'en' ) );
}

$parse = array();
$cases = array(
	array( '1.5M', '', array() ), array( '1,500', '', array() ), array( '2.5x', '', array( 'mult' => true ) ), array( 'x2.5', '', array( 'mult' => true ) ), array( '×3', '', array( 'mult' => true ) ),
	array( 'abc', '', array() ), array( '1e9', '', array() ), array( '-1', '', array() ), array( '-1', '', array( 'allow_negative' => true ) ), array( '999T', '', array() ),
	array( '', '', array() ), array( '   ', '', array() ), array( '1.5', 'M', array() ), array( '1.5M', 'K', array() ), array( '1,5', '', array() ),
	array( '1 500 000', '', array() ), array( '12,345.67', '', array() ), array( '1.2.3', '', array() ), array( '1,50', '', array() ), array( '2k', '', array() ),
	array( '2 K', '', array() ), array( '3q', '', array() ), array( '1.5', '', array( 'integer' => true ) ), array( '10', '', array( 'integer' => true, 'max' => 5 ) ), array( '0', '', array( 'min' => 1 ) ),
	array( '9007199254740993', '', array( 'integer' => true ) ), array( 'NaN', '', array() ), array( 'Infinity', '', array() ), array( '1.25B', '', array() ), array( ' 42 ', '', array() ),
	array( '.5', '', array() ), array( '5.', '', array() ), array( '1,234,567', '', array() ), array( '1,2345', '', array() ), array( '7', 'T', array() ), array( '2.5Qa', '', array() ),
);
foreach ( $cases as $c ) {
	$parse[] = array( 'text' => $c[0], 'locale' => 'en', 'ladder' => null, 'suffix' => $c[1], 'opts' => (object) $c[2], 'out' => Input::parse( $c[0], 'en', null, $c[1], $c[2] ) );
}
$parse[] = array( 'text' => '2.5Qa', 'locale' => 'en', 'ladder' => $ladder_qa, 'suffix' => '', 'opts' => (object) array(), 'out' => Input::parse( '2.5Qa', 'en', $ladder_qa ) );
foreach ( array( '1,5', '1.500', '1.500,25', '1.5' ) as $t ) {
	$parse[] = array( 'text' => $t, 'locale' => 'id', 'ladder' => null, 'suffix' => '', 'opts' => (object) array(), 'out' => Input::parse( $t, 'id' ) );
}

$data   = array(
	'records' => array(
		'PET' => array(
			'P1' => array( 'n' => 'A', 'base' => 10, 'speed' => array( 's' => 'c', 'v' => 3 ) ),
			'P2' => array( 'n' => 'B', 'base' => 2500, 'speed' => array( 's' => 'm' ) ),
		),
		'MUT' => array(
			'M1' => array( 'n' => 'G', 'mult' => 2 ),
			'M2' => array( 'n' => 'R', 'mult' => 3.5 ),
		),
	),
	'params'  => array( 'stack' => array( 'cap' => 10 ) ),
);
$inputs = array(
	'pet'  => array( 'type' => 'ref', 'target' => 'PET' ),
	'muts' => array( 'type' => 'ref_list', 'target' => 'MUT' ),
	'mut'  => array( 'type' => 'ref', 'target' => 'MUT' ),
	'n'    => array( 'type' => 'number' ),
	'b'    => array( 'type' => 'bool' ),
);
$exprs  = array(
	array( 'ref(PET, pet).base * n', array( 'pet' => 'P1', 'n' => 3 ) ),
	array( 'ref(PET, pet).base * product(refs(MUT, muts).mult)', array( 'pet' => 'P2', 'muts' => array( 'M1', 'M2' ) ) ),
	array( 'ref(PET, pet).base * min(product(refs(MUT, muts).mult), param(stack, cap))', array( 'pet' => 'P2', 'muts' => array( 'M1', 'M2', 'M2' ) ) ),
	array( 'if(has(mut), ref(MUT, mut).mult, 1) * 2 ^ 3', array( 'mut' => '' ) ),
	array( 'if(has(mut), ref(MUT, mut).mult, 1) * -2 ^ 2', array( 'mut' => 'M2' ) ),
	array( 'round(n / 3, 2) + floor(1.5) + ceil(1.2) + abs(-4) + clamp(n, 0, 5)', array( 'n' => 10 ) ),
	array( 'sum(refs(MUT, muts).mult) + (n >= 2) + (n == 2) + (n != 2) + b', array( 'muts' => array( 'M1' ), 'n' => 2, 'b' => true ) ),
	array( 'ref(PET, pet).speed * 2', array( 'pet' => 'P1' ) ),
	array( 'ref(PET, pet).speed * 2', array( 'pet' => 'P2' ) ),
	array( 'n / 0', array( 'n' => 1 ) ),
	array( 'ref(PET, pet).base', array( 'pet' => 'NOPE' ) ),
	array( 'n * 2', array() ),
	array( 'max(n, 2, 7) - min(3, n)', array( 'n' => 4 ) ),
	array( 'n +', array() ),
	array( 'pet * 2', array() ),
	array( 'evil(n)', array() ),
	array( 'ref(MUT, pet).base', array() ),
	array( 'refs(MUT, mut).mult', array() ),
	array( 'n ; 2', array() ),
	array( 'round(n)', array( 'n' => -2.5 ) ),
);
$formula = array();
foreach ( $exprs as $e ) {
	$row = array( 'expr' => $e[0], 'inputs' => (object) $e[1] );
	try {
		$ast        = Parser::compile( $e[0], $inputs );
		$row['ast'] = $ast;
		try {
			$row['value'] = Interpreter::evaluate( $ast, $e[1], $data );
		} catch ( \RBX\Data\Formula\Unavailable $x ) {
			$row['unavailable'] = array( $x->reason, $x->what );
		}
	} catch ( \RBX\Data\Formula\SyntaxError $x ) {
		$row['syntax_error'] = $x->getMessage();
	}
	$formula[] = $row;
}

$dir = __DIR__ . '/fixtures';
$o   = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
file_put_contents( $dir . '/format_vectors.json', json_encode( $fmt, $o ) . "\n" );
file_put_contents( $dir . '/parse_vectors.json', json_encode( $parse, $o ) . "\n" );
file_put_contents( $dir . '/formula_vectors.json', json_encode( array( 'declared' => $inputs, 'data' => $data, 'cases' => $formula ), $o ) . "\n" );
printf( "%d format, %d parse, %d formula vectors\n", count( $fmt ), count( $parse ), count( $formula ) );
