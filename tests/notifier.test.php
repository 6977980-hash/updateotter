<?php
/**
 * Mocked tests for ENG-43 (HTTP handling), ENG-44 (lock), ENG-45 (timeline) and ENG-48 (close rule).
 * Run on a throwaway site: `wp eval-file wp-content/plugins/rbx-notifier/tests/notifier.test.php`.
 * It writes test rows and restores the notifier state and overrides at the end.
 *
 * @package RBX\Notifier
 */

defined( 'ABSPATH' ) || exit;

use RBX\Notifier\Admin\Checklist;
use RBX\Notifier\Alerts\Dispatcher;
use RBX\Notifier\Clock;
use RBX\Notifier\Events;
use RBX\Notifier\Poller;
use RBX\Notifier\Schema;
use RBX\Notifier\State;

global $wpdb;
$fails = 0;
$check = static function ( bool $ok, string $what ) use ( &$fails ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $what . "\n";
	$fails += $ok ? 0 : 1;
};

$saved = array(
	'state'     => get_option( 'rbx_notifier_state', false ),
	'overrides' => get_option( 'rbx_game_overrides', false ),
	'notices'   => get_option( 'rbx_notifier_system_notice', false ),
);
$game = array_values( \RBX\Data\Api::games() )[0];
$uid  = '9990001';
update_option( 'rbx_game_overrides', array( $game->key() => array( 'roblox_universe_id' => $uid, 'roblox_place_id' => '9990002' ) ), false );
\RBX\Data\Config\Loader::reset();
delete_option( 'rbx_notifier_state' );
delete_option( 'rbx_notifier_system_notice' );
delete_option( 'rbx_notifier_lock' );
$wpdb->query( 'DELETE FROM ' . Schema::events() ); // phpcs:ignore
add_filter( 'rbx_notifier_send_off_production', '__return_true' );
add_filter( 'pre_wp_mail', '__return_true' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%rbx_ntf_%'" ); // phpcs:ignore
wp_cache_flush();

// Mock Roblox: $mock = [code, body, headers] or WP_Error.
$mock = null;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$mock ) {
		if ( ! str_contains( $url, 'roblox.com' ) ) {
			return $pre;
		}
		if ( $mock instanceof WP_Error ) {
			return $mock;
		}
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $mock[2] ?? array() ),
			'body'     => $mock[1],
			'response' => array( 'code' => $mock[0], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$ok_at = static fn( string $iso ) => array( 200, wp_json_encode( array( 'data' => array( array( 'id' => (int) '9990001', 'rootPlaceId' => 9990002, 'name' => 'Fixture', 'updated' => $iso ) ) ) ) );
$u     = static fn() => State::universe( '9990001' );

// --- ENG-43 state transitions -------------------------------------------------
$t = strtotime( '2026-10-19 10:00:00 UTC' ); // Monday.
Clock::set( $t );
$mock = $ok_at( '2026-10-16T15:00:00.123Z' );
$r    = Poller::run( true );
$check( 'ok' === $r['status'] && '2026-10-16T15:00:00Z' === $u()['last_seen_updated'], '200 valid: baseline recorded' );
$check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::events() ), 'baseline creates no event' );

Dispatcher::$sent = array();
$mock = array( 200, '{"data":[{"id":1}]}' );
Poller::run( true );
$check( 'shape_error' === $u()['last_error_kind'] && 1 === (int) $u()['consecutive_failures'], '200 bad shape: shape_error, failures 1' );
$mock = array( 200, 'not json' );
Poller::run( true );
$sent_shape = count( array_filter( Dispatcher::$sent, static fn( $s ) => 'email' === $s['channel'] ) );
$check( 1 === $sent_shape, 'shape_error: one "API format changed" email per 24 h (got ' . $sent_shape . ')' );

$mock = array( 429, '', array( 'retry-after' => '120', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '120' ) );
Poller::run( true );
$check( 'rate_limited' === $u()['last_error_kind'] && Clock::ts( $u()['next_allowed_at'] ) === $t + 120 && '0' === (string) $u()['ratelimit_remaining'], '429 with retry-after: next poll at +120 s, rate limit stored' );
$mock = array( 429, '' );
Poller::run( true );
$f    = (int) $u()['consecutive_failures'];
$wait = Clock::ts( $u()['next_allowed_at'] ) - $t;
$base = min( 240, 15 * 2 ** ( $f - 1 ) ) * 60;
$check( $wait >= $base * 0.9 && $wait <= $base * 1.1, "429 without retry-after: backoff ~{$base}s (got {$wait}s, failures {$f})" );
$r = Poller::run( false );
$check( str_starts_with( $r['status'], 'backoff' ), 'backoff respected without --force' );

$mock = array( 500, '' );
Poller::run( true );
$check( 'server_error' === $u()['last_error_kind'], '500: server_error with backoff' );
$mock = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
Dispatcher::$sent = array();
Poller::run( true );
$check( 'server_error' === $u()['last_error_kind'] && 6 === (int) $u()['consecutive_failures'], 'timeout: server_error, failures 6' );
$blind = count( array_filter( Dispatcher::$sent, static fn( $s ) => str_contains( $s['subject'], 'blind' ) ) );
$check( 1 === $blind, 'circuit breaker: one "Notifier blind" alert after 6 failures' );
Dispatcher::$sent = array();
Poller::run( true );
$check( 0 === count( Dispatcher::$sent ), 'no second blind alert within 24 h' );

$mock = array( 404, '' );
Dispatcher::$sent = array();
Poller::run( true );
$check( 'config_error' === $u()['last_error_kind'] && 1 === count( array_filter( Dispatcher::$sent, static fn( $s ) => 'email' === $s['channel'] ) ), '404: config_error, immediate alert' );
Clock::set( $t + 3 * HOUR_IN_SECONDS );
$r = Poller::run( false );
$check( str_starts_with( $r['status'], 'config error' ), 'config_error: no retries until config changes' );

$mock = $ok_at( '2026-10-16T15:00:00Z' );
Dispatcher::$sent = array();
Poller::run( true );
$check( 0 === (int) $u()['consecutive_failures'] && 1 === count( array_filter( Dispatcher::$sent, static fn( $s ) => str_contains( $s['subject'], 'recovered' ) ) ), 'recovery: failures reset, one "recovered" message' );

// --- ENG-44 lock -------------------------------------------------------------
add_option( 'rbx_notifier_lock', Clock::now() + 100, '', false );
$r = Poller::run( true );
$check( 'lock held' === $r['status'], 'lock held: second poll skipped' );
delete_option( 'rbx_notifier_lock' );

// --- ENG-45 mocked timeline ----------------------------------------------------
add_filter( 'rbx_notifier_update_windows', static fn() => array( array( 'day' => 'fri', 'from' => 14, 'to' => 17 ) ) );
Dispatcher::$sent = array();
$fri = strtotime( '2026-10-23 15:00:00 UTC' );
$steps = array(
	array( $fri, '2026-10-23T14:58:00Z' ),
	array( $fri + 5 * 60, '2026-10-23T15:03:00Z' ),
	array( $fri + 12 * 60, '2026-10-23T15:10:00Z' ),
	array( $fri + 30 * 60, '2026-10-23T15:10:00Z' ),
	array( $fri + 3 * HOUR_IN_SECONDS + 10 * 60, '2026-10-23T18:08:00Z' ),
	array( $fri + 3 * HOUR_IN_SECONDS + 35 * 60, '2026-10-23T18:08:00Z' ),
);
foreach ( $steps as $s ) {
	Clock::set( $s[0] );
	$mock = $ok_at( $s[1] );
	Poller::run( true );
}
$evs = Events::recent( $uid, 10 );
$check( 2 === count( $evs ), '2 events from the timeline (got ' . count( $evs ) . ')' );
$first  = end( $evs );
$second = reset( $evs );
$check( 'likely_content_update' === $first['classification'] && 3 === $first['publish_count'], 'Friday change: likely_content_update, publish_count 3' );
$check( 'likely_hotfix' === $second['classification'], 'change 3 h later: likely_hotfix' );
$imm = array_filter( Dispatcher::$sent, static fn( $s ) => 'email' === $s['channel'] );
$check( 1 === count( $imm ), 'exactly 1 immediate alert (got ' . count( $imm ) . ')' );
Dispatcher::digest();
$check( in_array( 'digest', array_column( Dispatcher::$sent, 'channel' ), true ), 'hotfix goes to the daily digest' );

// --- ENG-48 close rule -------------------------------------------------------
Checklist::ack( (int) $first['id'], 'content_update', 1 );
foreach ( array( '5', '7', '8' ) as $i ) {
	Checklist::tick( (int) $first['id'], $i, true, '', 1 );
}
$r = Checklist::close( (int) $first['id'], '', 1 );
$check( is_string( $r ) && str_contains( $r, '6' ), 'closing with item 6 unticked is refused' );
Checklist::tick( (int) $first['id'], '6', true, 'tests green', 1 );
$r  = Checklist::close( (int) $first['id'], '', 1 );
$ev = Events::get( (int) $first['id'] );
$check( true === $r && 'closed' === $ev['state'] && 4 === count( array_filter( $ev['checklist'], static fn( $c ) => ! empty( $c['at'] ) ) ), 'closed event keeps all tick timestamps' );
Checklist::ack( (int) $second['id'], 'hotfix_no_change', 1 );
$check( 'closed' === Events::get( (int) $second['id'] )['state'], 'hotfix acknowledgment closes without the checklist' );

$auto = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'rbx_notifier_state' ) ); // phpcs:ignore
$check( in_array( $auto, array( 'no', 'off' ), true ), 'rbx_notifier_state autoload is off (' . $auto . ')' );

// Restore.
Clock::set( null );
$wpdb->query( 'DELETE FROM ' . Schema::events() ); // phpcs:ignore
$wpdb->query( 'DELETE FROM ' . Schema::polls() ); // phpcs:ignore
foreach ( $saved as $k => $v ) {
	$opt = array( 'state' => 'rbx_notifier_state', 'overrides' => 'rbx_game_overrides', 'notices' => 'rbx_notifier_system_notice' )[ $k ];
	false === $v ? delete_option( $opt ) : update_option( $opt, $v, false );
}
\RBX\Data\Config\Loader::reset();
echo $fails ? "\n{$fails} FAILED\n" : "\nAll passed\n";
if ( $fails ) {
	exit( 1 );
}
