<?php
/**
 * Fusion planner: FusionPlanner::compute() against fusion-vectors.json, the same vectors the
 * fusion.js twin is tested with. Run: php modules/rbx-tools/tests/fusion.test.php
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/FusionPlanner.php';

$cases = json_decode( (string) file_get_contents( __DIR__ . '/fusion-vectors.json' ), true );
$fail  = 0;
foreach ( $cases as $c ) {
	$got = \RBX\Tools\FusionPlanner::compute( $c['data'], $c['owned'] );
	// JSON has no int/float split: compare through a JSON round trip with floats normalised.
	$norm = static fn( $v ) => json_decode( (string) json_encode( $v, JSON_PRESERVE_ZERO_FRACTION ), true );
	if ( $norm( $got ) !== $c['expect'] ) {
		++$fail;
		echo 'FAIL ', $c['name'], "\n", json_encode( $got ), "\n", json_encode( $c['expect'] ), "\n";
	}
}
echo count( $cases ) - $fail, '/', count( $cases ), " fusion cases pass\n";
exit( $fail ? 1 : 0 );
