<?php
/**
 * "Players confirm" rule: 5+ yes on 3+ days, at most one no per 4 yes, same numbers only.
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
echo ( count( $cases ) - $fail ) . '/' . count( $cases ) . " passed\n";
exit( $fail ? 1 : 0 );
