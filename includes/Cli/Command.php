<?php
/**
 * `wp rbx-notifier` (ENG-42 acceptance: `poll --dry-run`).
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Cli;

use RBX\Notifier\Alerts\Dispatcher;
use RBX\Notifier\Clock;
use RBX\Notifier\Poller;
use RBX\Notifier\State;

defined( 'ABSPATH' ) || exit;

/**
 * Update notifier commands.
 */
final class Command {

	/**
	 * Polls Roblox once.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Fetch and print id, name and parsed updated (UTC) per universe; change nothing.
	 *
	 * [--force]
	 * : Ignore backoff and the interval.
	 *
	 * @param array<int, string>   $args  Args.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function poll( array $args, array $assoc ): void {
		$r = Poller::run( ! empty( $assoc['force'] ), ! empty( $assoc['dry-run'] ) );
		\WP_CLI::log( 'Status: ' . $r['status'] );
		if ( isset( $r['result'] ) ) {
			$res = $r['result'];
			\WP_CLI::log( sprintf( 'kind=%s http=%d ms=%d %s', $res['kind'], $res['http'], $res['ms'], $res['error'] ) );
			$rows = array();
			foreach ( $res['games'] as $g ) {
				$rows[] = array(
					'id'            => $g['id'],
					'root_place_id' => $g['root_place_id'],
					'name'          => $g['name'],
					'updated_utc'   => $g['updated'],
				);
			}
			if ( $rows ) {
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'root_place_id', 'name', 'updated_utc' ) );
			}
		}
		foreach ( (array) ( $r['changes'] ?? array() ) as $c ) {
			\WP_CLI::log( 'Change: ' . $c );
		}
	}

	/**
	 * Shows the poll state per universe.
	 *
	 * @param array<int, string>   $args  Args.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function status( array $args, array $assoc ): void {
		$rows = array();
		foreach ( State::universes() as $id => $g ) {
			$u      = State::universe( (string) $id );
			$rows[] = array(
				'game'        => $g->key(),
				'universe'    => $id,
				'health'      => State::health( $u['last_success_at'] ),
				'last_seen'   => (string) $u['last_seen_updated'],
				'last_poll'   => (string) $u['last_poll_at'],
				'failures'    => (int) $u['consecutive_failures'],
				'next_allowed' => (string) $u['next_allowed_at'],
				'last_error'  => (string) $u['last_error_kind'],
			);
		}
		if ( ! $rows ) {
			\WP_CLI::warning( 'No universe IDs. Fill them on RBX Data, Setup.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		$next = wp_next_scheduled( 'rbx_notifier_poll' );
		\WP_CLI::log( 'Cron next run: ' . ( $next ? Clock::iso( (int) $next ) : 'not scheduled' ) );
	}

	/**
	 * Sends a test alert to every configured channel.
	 *
	 * @param array<int, string>   $args  Args.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function test_alert( array $args, array $assoc ): void {
		foreach ( Dispatcher::test() as $ch => $r ) {
			\WP_CLI::log( sprintf( '%s: %s (%s)', $ch, $r['ok'] ? 'OK' : 'failed', $r['note'] ) );
		}
	}
}
