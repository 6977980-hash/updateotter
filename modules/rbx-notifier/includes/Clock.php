<?php
/**
 * Time source; tests pin it with Clock::set().
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * UTC clock.
 */
final class Clock {

	private static ?int $now = null;

	public static function now(): int {
		return self::$now ?? time();
	}

	public static function set( ?int $ts ): void {
		self::$now = $ts;
	}

	public static function iso( ?int $ts = null ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts ?? self::now() );
	}

	public static function ts( ?string $iso ): ?int {
		if ( null === $iso || '' === $iso ) {
			return null;
		}
		$t = strtotime( $iso );
		return false === $t ? null : $t;
	}

	/** "23 Oct 2026 14:05 UTC (19:05 PKT)". */
	public static function human( ?string $iso ): string {
		$t = self::ts( $iso );
		if ( null === $t ) {
			return '—';
		}
		$pkt = new \DateTimeImmutable( '@' . $t );
		$pkt = $pkt->setTimezone( new \DateTimeZone( 'Asia/Karachi' ) );
		return gmdate( 'j M Y H:i', $t ) . ' UTC (' . $pkt->format( 'H:i' ) . ' PKT)';
	}
}
