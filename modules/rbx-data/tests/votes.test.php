<?php
/**
 * "Players confirm" rule: 5+ yes on 3+ days, at most one no per 4 yes, same numbers only.
 * Codes: answers from the last 48 hours only, 5 one way for a verdict.
 * Run: php modules/rbx-data/tests/votes.test.php
 *
 * @package RBX\Data
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/Reports/Votes.php';

$days  = array( '2026-10-24', '2026-10-25', '2026-10-26' );
$cases = array(
	'5 yes, 3 days'               => array( array( 'k' => 'a', 'y' => 5, 'n' => 0, 'd' => $days ), true ),
	'4 yes'                       => array( array( 'k' => 'a', 'y' => 4, 'n' => 0, 'd' => $days ), false ),
	'5 yes, 2 days'               => array( array( 'k' => 'a', 'y' => 5, 'n' => 0, 'd' => array_slice( $days, 0, 2 ) ), false ),
	'8 yes, 2 no'                 => array( array( 'k' => 'a', 'y' => 8, 'n' => 2, 'd' => $days ), true ),
	'8 yes, 3 no'                 => array( array( 'k' => 'a', 'y' => 8, 'n' => 3, 'd' => $days ), false ),
	'numbers changed since votes' => array( array( 'k' => 'b', 'y' => 9, 'n' => 0, 'd' => $days ), false ),
	'no votes'                    => array( array(), false ),
);
$fail = 0;
foreach ( $cases as $name => [ $c, $want ] ) {
	if ( \RBX\Data\Reports\Votes::confirms( $c, 'a' ) !== $want ) {
		++$fail;
		echo "FAIL: $name\n";
	}
}

// Codes (Fisch): only answers inside the 48-hour window count; verdict needs 5 answers one way.
$now  = 1_800_000_000;
$hour = static fn( int $h ): int => $now - $now % 3600 - $h * 3600;
$code = array(
	'window keeps recent hours'    => array( array( $hour( 0 ) => array( 3, 0 ), $hour( 47 ) => array( 2, 1 ), $hour( 48 ) => array( 9, 9 ) ), array( 5, 1 ), 'verified' ),
	'old answers do not count'     => array( array( $hour( 49 ) => array( 20, 0 ), $hour( 1 ) => array( 1, 0 ) ), array( 1, 0 ), '' ),
	'4 yes is not enough'          => array( array( $hour( 2 ) => array( 4, 0 ) ), array( 4, 0 ), '' ),
	'too many no for verified'     => array( array( $hour( 2 ) => array( 7, 2 ) ), array( 7, 2 ), '' ),
	'players say it does not work' => array( array( $hour( 0 ) => array( 1, 5 ) ), array( 1, 5 ), 'not_working' ),
	'future bucket ignored'        => array( array( $now + 7200 => array( 9, 0 ) ), array( 0, 0 ), '' ),
);
foreach ( $code as $name => [ $b, $want, $label ] ) {
	$t = \RBX\Data\Reports\Votes::code_tally( $b, $now );
	$l = \RBX\Data\Reports\Votes::code_label( $t['y'], $t['n'] );
	if ( array( $t['y'], $t['n'] ) !== $want || $l !== $label ) {
		++$fail;
		echo "FAIL: code $name\n";
	}
}
$cases += $code;
echo ( count( $cases ) - $fail ) . '/' . count( $cases ) . " passed\n";
exit( $fail ? 1 : 0 );
