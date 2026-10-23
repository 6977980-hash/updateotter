<?php
/**
 * Suggested classification (ENG-45 rule 3). A human decides.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * likely_content_update | likely_hotfix | unknown.
 */
final class Classifier {

	public const CONTENT_GAP = 72 * HOUR_IN_SECONDS;
	public const HOTFIX_GAP  = 6 * HOUR_IN_SECONDS;

	private const DAYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Windows look like {"day": "fri", "from": 14, "to": 18} (UTC hours, `to` exclusive).
	 *
	 * @param array<int, array<string, mixed>> $windows Config notifier.update_windows.
	 */
	public static function in_window( int $ts, array $windows ): bool {
		$day  = self::DAYS[ (int) gmdate( 'w', $ts ) ];
		$hour = (int) gmdate( 'G', $ts );
		foreach ( $windows as $w ) {
			$wd = strtolower( substr( (string) ( $w['day'] ?? $w['weekday'] ?? '' ), 0, 3 ) );
			if ( $wd !== $day ) {
				continue;
			}
			$from = (int) ( $w['from'] ?? $w['from_utc'] ?? 0 );
			$to   = (int) ( $w['to'] ?? $w['to_utc'] ?? 24 );
			if ( $hour >= $from && $hour < $to ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $windows Update windows.
	 */
	public static function classify( int $detected, ?int $previous, array $windows ): string {
		$inside = self::in_window( $detected, $windows );
		if ( null === $previous || $detected - $previous >= self::CONTENT_GAP || $inside ) {
			return 'likely_content_update';
		}
		if ( $detected - $previous < self::HOTFIX_GAP ) {
			return 'likely_hotfix';
		}
		return 'unknown';
	}

	public static function label( string $c ): string {
		switch ( $c ) {
			case 'likely_content_update':
				return __( 'Likely content update', 'rbx-notifier' );
			case 'likely_hotfix':
				return __( 'Likely hotfix', 'rbx-notifier' );
			case 'unknown':
				return __( 'Unknown', 'rbx-notifier' );
		}
		return __( 'Settling', 'rbx-notifier' );
	}
}
