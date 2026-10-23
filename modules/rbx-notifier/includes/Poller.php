<?php
/**
 * One poll cycle (ENG-43..45): lock, backoff, change detection, settling, alerts.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

use RBX\Notifier\Alerts\Discord;
use RBX\Notifier\Alerts\Dispatcher;
use RBX\Notifier\Roblox\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Never runs during front-end rendering; only from cron, the CLI or the status screen.
 */
final class Poller {

	public const BLIND_FAILURES = 6;
	public const BLIND_AFTER    = 6 * HOUR_IN_SECONDS;

	/** Atomic option lock (ENG-44). */
	private static function lock(): bool {
		$until = Clock::now() + 120;
		if ( add_option( 'rbx_notifier_lock', $until, '', false ) ) {
			return true;
		}
		$held = (int) get_option( 'rbx_notifier_lock', 0 );
		if ( $held > Clock::now() ) {
			return false;
		}
		update_option( 'rbx_notifier_lock', $until, false );
		return true;
	}

	/** next poll = now + min(240, 15 × 2^(failures−1)) min ± 10 % jitter. */
	public static function backoff( int $failures ): int {
		$min = min( 240, 15 * ( 2 ** max( 0, $failures - 1 ) ) );
		$sec = $min * MINUTE_IN_SECONDS;
		return Clock::now() + (int) round( $sec * ( 1 + wp_rand( -100, 100 ) / 1000 ) );
	}

	private static function log( string $kind, int $http, int $ms, string $note ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::polls(),
			array(
				'polled_at' => gmdate( 'Y-m-d H:i:s', Clock::now() ),
				'kind'      => $kind,
				'http_code' => $http,
				'ms'        => $ms,
				'note'      => substr( Alerts\Telegram::mask( $note ), 0, 255 ),
			)
		);
	}

	/** Cron entry point. */
	public static function cron(): void {
		self::run();
	}

	/**
	 * @return array{status: string, result?: array<string, mixed>, changes?: array<int, string>}
	 */
	public static function run( bool $force = false, bool $dry = false ): array {
		$universes = State::universes();
		if ( ! $universes ) {
			self::settle();
			return array( 'status' => 'no universes: fill the Roblox IDs on RBX Data, Setup' );
		}
		if ( $dry ) {
			return array(
				'status' => 'dry run',
				'result' => Client::games( array_keys( $universes ) ),
			);
		}
		if ( ! self::lock() ) {
			self::log( 'skipped', 0, 0, 'lock held' );
			return array( 'status' => 'lock held' );
		}
		try {
			return self::cycle( $universes, $force );
		} finally {
			delete_option( 'rbx_notifier_lock' );
		}
	}

	/**
	 * @param array<string, \RBX\Data\Config\Game> $universes Universe ID => game.
	 * @return array<string, mixed>
	 */
	private static function cycle( array $universes, bool $force ): array {
		$state = State::get();
		$now   = Clock::now();
		$first = State::universe( (string) array_key_first( $universes ) );
		foreach ( array_keys( $universes ) as $id ) {
			$state['u'][ $id ]                      = State::universe( (string) $id );
			$state['u'][ $id ]['last_cron_seen_at'] = Clock::iso();
		}
		$hash = State::config_hash();
		if ( ! $force ) {
			$skip = '';
			if ( 'config_error' === $first['last_error_kind'] && ( $state['g']['config_hash'] ?? '' ) === $hash ) {
				$skip = 'config error: waiting for a config change';
			} elseif ( ( Clock::ts( $first['next_allowed_at'] ) ?? 0 ) > $now ) {
				$skip = 'backoff until ' . $first['next_allowed_at'];
			} elseif ( ( Clock::ts( $first['last_poll_at'] ) ?? 0 ) > $now - State::interval() * MINUTE_IN_SECONDS + 60 ) {
				$skip = 'not due';
			}
			if ( '' !== $skip ) {
				State::put( $state );
				self::settle();
				return array( 'status' => $skip );
			}
		}

		$res = Client::games( array_keys( $universes ) );
		self::log( $res['kind'], $res['http'], $res['ms'], $res['error'] );
		$changes = array();
		$ok      = 'ok' === $res['kind'];
		foreach ( $universes as $id => $game ) {
			$u                        = &$state['u'][ $id ];
			$u['last_poll_at']        = Clock::iso();
			$u['ratelimit_remaining'] = '' !== $res['ratelimit_remaining'] ? $res['ratelimit_remaining'] : $u['ratelimit_remaining'];
			$u['ratelimit_reset_at']  = '' !== $res['ratelimit_reset'] ? Clock::iso( $now + (int) $res['ratelimit_reset'] ) : $u['ratelimit_reset_at'];
			if ( $ok ) {
				$g                         = $res['games'][ $id ];
				$u['last_success_at']      = Clock::iso();
				$u['consecutive_failures'] = 0;
				$u['next_allowed_at']      = null;
				$u['last_error_kind']      = null;
				$u['root_place_id']        = $g['root_place_id'];
				$u['name']                 = $g['name'];
				$seen                      = Clock::ts( $u['last_seen_updated'] );
				$new                       = (int) Clock::ts( $g['updated'] );
				if ( null === $seen ) {
					$u['last_seen_updated'] = $g['updated']; // Baseline only.
				} elseif ( $new > $seen ) {
					$open = Events::settling( (string) $id );
					if ( $open ) {
						Events::merge( $open, $g['updated'] );
					} else {
						Events::create( $game->key(), (string) $id, $u['last_seen_updated'], $g['updated'] );
					}
					$u['last_seen_updated'] = $g['updated'];
					$changes[]              = $game->key() . ' ' . $g['updated'];
					if ( class_exists( '\RBX\Data\Cache\Purge' ) ) {
						\RBX\Data\Cache\Purge::game( $game ); // Hubs show the latest Roblox publish (UO-09).
					}
				}
			} else {
				$u['consecutive_failures'] = (int) $u['consecutive_failures'] + 1;
				$u['last_error_kind']      = $res['kind'];
				$u['last_error_at']        = Clock::iso();
				if ( 'rate_limited' === $res['kind'] && null !== $res['retry_after'] ) {
					$u['next_allowed_at'] = Clock::iso( $now + max( 60, (int) $res['retry_after'] ) );
				} elseif ( 'config_error' !== $res['kind'] ) {
					$u['next_allowed_at'] = Clock::iso( self::backoff( (int) $u['consecutive_failures'] ) );
				}
			}
			unset( $u );
		}

		$state['g']['config_hash'] = $hash;
		if ( $ok ) {
			if ( ! empty( $state['g']['blind_since'] ) ) {
				Dispatcher::system( 'recovered', __( 'Notifier recovered', 'rbx-notifier' ), __( 'Polling works again.', 'rbx-notifier' ) );
			}
			unset( $state['g']['blind_since'] );
			$notices = (array) get_option( 'rbx_notifier_system_notice', array() );
			if ( $notices ) {
				unset( $notices['shape_error'], $notices['config_error'] );
				update_option( 'rbx_notifier_system_notice', $notices, false );
			}
		} else {
			if ( 'shape_error' === $res['kind'] ) {
				Dispatcher::system( 'shape_error', __( 'Roblox API format changed', 'rbx-notifier' ), $res['error'] );
			} elseif ( 'config_error' === $res['kind'] ) {
				Dispatcher::system( 'config_error', __( 'Notifier config error: polling stopped until the Roblox IDs change', 'rbx-notifier' ), $res['error'] );
			}
			$u0           = $state['u'][ (string) array_key_first( $universes ) ];
			$last_ok      = Clock::ts( $u0['last_success_at'] ) ?? ( Clock::ts( $state['g']['installed_at'] ?? null ) ?? $now );
			$blind        = (int) $u0['consecutive_failures'] >= self::BLIND_FAILURES || $now - $last_ok >= self::BLIND_AFTER;
			if ( $blind ) {
				$state['g']['blind_since'] = $state['g']['blind_since'] ?? ( $u0['last_success_at'] ?? Clock::iso() );
				/* translators: %s: time */
				Dispatcher::system( 'blind', sprintf( __( 'Notifier blind since %s', 'rbx-notifier' ), Clock::human( (string) $state['g']['blind_since'] ) ), __( 'Roblox polling keeps failing. Last error:', 'rbx-notifier' ) . ' ' . $res['kind'] . ' ' . $res['error'] );
			}
		}
		State::put( $state );
		self::settle();
		Discord::retry_queued();
		return array(
			'status'  => $res['kind'],
			'result'  => $res,
			'changes' => $changes,
		);
	}

	/** Closes settling windows: classify, mark open, alert (ENG-45 rules 2–4). */
	public static function settle(): void {
		foreach ( Events::due() as $ev ) {
			$game    = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::game( (string) $ev['game'] ) : null;
			$prev    = Events::previous( (string) $ev['universe_id'], (int) $ev['id'] );
			$windows = (array) apply_filters( 'rbx_notifier_update_windows', $game ? $game->update_windows() : array(), (string) $ev['game'] );
			$class   = Classifier::classify( Events::detected_ts( $ev ), $prev ? Events::detected_ts( $prev ) : null, $windows );
			Events::update(
				(int) $ev['id'],
				array(
					'classification' => $class,
					'state'          => 'open',
				)
			);
			$ev['classification'] = $class;
			$ev['state']          = 'open';
			Dispatcher::event( $ev );
		}
	}
}
