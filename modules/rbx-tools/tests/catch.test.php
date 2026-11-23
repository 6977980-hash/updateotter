<?php
/**
 * "What can I catch here?": CatchFinder::compute() against the vectors written by
 * catch-make-vectors.py from the real Fisch CSVs, the same vectors catch.js is tested with.
 * Run: python3 modules/rbx-tools/tests/catch-make-vectors.py /tmp/catch-vectors.json && php modules/rbx-tools/tests/catch.test.php /tmp/catch-vectors.json
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/CatchFinder.php';

$v    = json_decode( (string) file_get_contents( $argv[1] ?? __DIR__ . '/catch-vectors.json' ), true );
$fail = 0;
foreach ( $v['cases'] as $c ) {
	$r   = \RBX\Tools\CatchFinder::compute( $v['data'], $c['pick'] );
	$got = array(
		'names' => array_map( static fn( $f ) => $f['name'], $r['rows'] ),
		'here'  => $r['here'],
		'other' => $r['other'],
		'only'  => $r['only'],
		'best'  => null === $r['best'] ? null : $r['best']['name'],
	);
	if ( $got !== $c['expect'] ) {
		++$fail;
		echo 'FAIL ', json_encode( $c['pick'] ), "\n", json_encode( $got ), "\n", json_encode( $c['expect'] ), "\n";
	}
}
echo count( $v['cases'] ) - $fail, '/', count( $v['cases'] ), " catch cases pass\n";
exit( $fail ? 1 : 0 );
