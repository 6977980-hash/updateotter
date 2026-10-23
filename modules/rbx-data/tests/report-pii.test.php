<?php
/**
 * Report form personal-data check (UO-44): game numbers pass, phone numbers and emails don't.
 * Run: php modules/rbx-data/tests/report-pii.test.php
 *
 * @package RBX\Data
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/Reports/Endpoint.php';

$cases = array(
	'Shark makes 150000000 per hour'            => false,
	'1.500.000.000 per detik'                   => false,
	'Phoenix 85000000'                          => false,
	'Spirit World Guardian 26,000,000,000 Cash' => false,
	'Golden Chicken 26 kg makes 5 per second'   => false,
	'It costs 0.5 and gives 1,000,000'          => false,
	'Shark 150 000 000 Cash per jam'            => false,
	'call me +62 812 3456 7890'                 => true,
	'my number 0812-3456-7890'                  => true,
	'wa 081234567890'                           => true,
	'+1 (555) 123-4567'                         => true,
	'mail me at kid@example.com'                => true,
	'roblox.com/users/12345/profile'            => true,
);
$fail = 0;
foreach ( $cases as $text => $want ) {
	$got = \RBX\Data\Reports\Endpoint::has_pii( $text );
	if ( $got !== $want ) {
		++$fail;
		echo 'FAIL: "' . $text . '" gave ' . ( $got ? 'pii' : 'ok' ) . "\n";
	}
}
echo ( count( $cases ) - $fail ) . '/' . count( $cases ) . " passed\n";
exit( $fail ? 1 : 0 );
