<?php
/**
 * Guide queue (2026-10-24): links to guides that are not live become plain text, one weekly
 * slot, reviewed guides only, no catch-up.
 * Run: php modules/rbx-data/tests/guides.test.php
 *
 * @package RBX\Data
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/Content/Guides.php';

use RBX\Data\Content\Guides;

$fail = 0;
$n    = 0;
$eq   = static function ( $got, $want, string $what ) use ( &$fail, &$n ): void {
	++$n;
	if ( $got !== $want ) {
		++$fail;
		echo 'FAIL: ' . $what . "\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n";
	}
};

$live = static fn( string $p ): bool => 'steal-an-egg/guides/beginners-guide' === $p;
$host = 'updateotter.com';

$eq( Guides::unlink( '<p>See the <a href="/steal-an-egg/guides/speed-guide/">Speed guide</a>.</p>', $live, $host ), '<p>See the Speed guide.</p>', 'draft guide link becomes text' );
$eq( Guides::unlink( '<p><a href="/steal-an-egg/guides/beginners-guide/">x</a></p>', $live, $host ), '<p><a href="/steal-an-egg/guides/beginners-guide/">x</a></p>', 'live guide link stays' );
$eq( Guides::unlink( '<a class="b" href="https://updateotter.com/steal-an-egg/guides/speed-guide/#treadmill"><strong>S</strong></a>', $live, $host ), '<strong>S</strong>', 'absolute own-host link with anchor' );
$eq( Guides::unlink( '<a href="https://example.com/x/guides/y/">e</a>', $live, $host ), '<a href="https://example.com/x/guides/y/">e</a>', 'other host untouched' );
$eq( Guides::unlink( '<a href="/steal-an-egg/guides/">hub</a> <a href="/steal-an-egg/pets/fox/">Fox</a>', $live, $host ), '<a href="/steal-an-egg/guides/">hub</a> <a href="/steal-an-egg/pets/fox/">Fox</a>', 'hub and item links untouched' );
$eq( Guides::unlink( '<a href="/steal-an-egg/guides/a/">A</a> and <a href="/steal-an-egg/guides/beginners-guide/">B</a>', $live, $host ), 'A and <a href="/steal-an-egg/guides/beginners-guide/">B</a>', 'two links, one live' );

$t0 = strtotime( '2026-10-31 06:00:00 UTC' );
$eq( Guides::slot( '2026-10-31', '06:00', $t0 - 1 ), -1, 'before start' );
$eq( Guides::slot( '2026-10-31', '06:00', $t0 ), 0, 'first slot' );
$eq( Guides::slot( '2026-10-31', '', $t0 + 6 * 86400 ), 0, 'same week' );
$eq( Guides::slot( '2026-10-31', '06:00', $t0 + 7 * 86400 ), 1, 'next week' );

$cfg = array(
	'start'  => '2026-10-31',
	'guides' => array(
		array( 'slug' => 'beginners-guide' ),
		array( 'slug' => 'a', 'queue' => true, 'reviewed_by' => '' ),
		array( 'slug' => 'b', 'queue' => true, 'reviewed_by' => 'ali' ),
		array( 'slug' => 'c', 'queue' => true, 'reviewed_by' => 'ali' ),
	),
);
$none = static fn( string $s ): bool => false;
$eq( Guides::candidates( $cfg, -1, $t0 - 60, $none ), array(), 'nothing before start' );
$eq( Guides::candidates( $cfg, -1, $t0 + 60, $none ), array( 'b', 'c' ), 'reviewed guides in order, unreviewed skipped' );
$eq( Guides::candidates( $cfg, 0, $t0 + 86400, $none ), array(), 'slot already used this week' );
$eq( Guides::candidates( $cfg, 0, $t0 + 7 * 86400, static fn( string $s ): bool => 'b' === $s ), array( 'c' ), 'next week: next guide' );
$eq( Guides::candidates( $cfg, 0, $t0 + 30 * 86400, $none ), array( 'b', 'c' ), 'missed weeks: still one candidate list, the caller publishes one' );

echo ( $n - $fail ) . '/' . $n . " passed\n";
exit( $fail ? 1 : 0 );
