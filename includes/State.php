<?php
/**
 * Option rbx_notifier_state (autoload off, ENG-46). Times are UTC ISO 8601.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * Per-universe poll state plus a few global flags.
 */
final class State {

	public const FIELDS = array( 'last_seen_updated', 'last_poll_at', 'last_success_at', 'consecutive_failures', 'next_allowed_at', 'last_error_kind', 'last_error_at', 'last_cron_seen_at', 'ratelimit_remaining', 'ratelimit_reset_at' );

	/** @return array{u: array<string, array<string, mixed>>, g: array<string, mixed>} */
	public static function get(): array {
		$s = get_option( 'rbx_notifier_state', array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'u' => (array) ( $s['u'] ?? array() ),
			'g' => (array) ( $s['g'] ?? array() ),
		);
	}

	/** @param array{u: array<string, array<string, mixed>>, g: array<string, mixed>} $s State. */
	public static function put( array $s ): void {
		if ( false === get_option( 'rbx_notifier_state', false ) ) {
			add_option( 'rbx_notifier_state', $s, '', false );
			return;
		}
		update_option( 'rbx_notifier_state', $s, false );
	}

	/** @return array<string, mixed> */
	public static function universe( string $id ): array {
		$u = self::get()['u'][ $id ] ?? array();
		return array_merge( array_fill_keys( self::FIELDS, null ), array( 'consecutive_failures' => 0 ), (array) $u );
	}

	/** Interval in minutes: 15 (default) or 30. */
	public static function interval(): int {
		return 30 === (int) get_option( 'rbx_notifier_interval', 15 ) ? 30 : 15;
	}

	/**
	 * Tracked universes from game config (ENG-41): universe ID => game.
	 *
	 * @return array<string, \RBX\Data\Config\Game>
	 */
	public static function universes(): array {
		$out = array();
		if ( ! class_exists( '\RBX\Data\Api' ) ) {
			return $out;
		}
		foreach ( \RBX\Data\Api::games() as $g ) {
			$u = $g->universe_id();
			if ( null !== $u && '' !== $u ) {
				$out[ (string) $u ] = $g;
			}
		}
		return $out;
	}

	/** Changes when tracked IDs change; a config_error waits for this to change (ENG-43). */
	public static function config_hash(): string {
		$ids = array_keys( self::universes() );
		sort( $ids );
		return sha1( implode( ',', $ids ) . '|' . (int) get_option( 'rbx_notifier_use_open_cloud' ) );
	}

	/** green < 2 × interval since last success; amber < 6 h; red otherwise. */
	public static function health( ?string $last_success ): string {
		$t = Clock::ts( $last_success );
		if ( null === $t ) {
			return 'red';
		}
		$age = Clock::now() - $t;
		if ( $age < 2 * self::interval() * MINUTE_IN_SECONDS ) {
			return 'green';
		}
		return $age < 6 * HOUR_IN_SECONDS ? 'amber' : 'red';
	}
}
