<?php
/**
 * Routing and dedupe (ENG-45 rule 4, ENG-47). Off production only the admin notice runs.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Alerts;

use RBX\Notifier\Classifier;
use RBX\Notifier\Clock;
use RBX\Notifier\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Sends event and system alerts.
 */
final class Dispatcher {

	/** @var array<int, array{channel: string, subject: string}> Sent messages in this request (tests read it). */
	public static array $sent = array();

	private static function live(): bool {
		return 'production' === wp_get_environment_type() || (bool) apply_filters( 'rbx_notifier_send_off_production', false );
	}

	/** @return array<string, callable> */
	private static function channels(): array {
		return array(
			'email'    => array( Email::class, 'send' ),
			'telegram' => array( Telegram::class, 'send' ),
			'discord'  => array( Discord::class, 'send' ),
		);
	}

	/**
	 * @return array<string, array{ok: bool, note: string}>
	 */
	private static function fan_out( string $dedupe, string $subject, string $text ): array {
		$out = array();
		if ( ! self::live() ) {
			return $out;
		}
		foreach ( self::channels() as $ch => $fn ) {
			if ( 'email' !== $ch && ! ( 'telegram' === $ch ? Telegram::configured() : Discord::configured() ) ) {
				continue;
			}
			$key = 'rbx_ntf_' . sha1( $dedupe . '|' . $ch );
			if ( get_transient( $key ) ) {
				continue;
			}
			set_transient( $key, 1, DAY_IN_SECONDS );
			$out[ $ch ]     = $fn( $subject, $text );
			self::$sent[]   = array(
				'channel' => $ch,
				'subject' => $subject,
			);
		}
		return $out;
	}

	public static function status_url(): string {
		return admin_url( 'admin.php?page=rbx-notifier' );
	}

	/** Called when an event leaves "settling". Hotfixes wait for the digest. */
	public static function event( array $ev ): void {
		$game = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::game( (string) $ev['game'] ) : null;
		$name = $game ? $game->name() : (string) $ev['game'];
		$sent = (array) $ev['alerts_sent'];
		if ( 'likely_hotfix' !== $ev['classification'] ) {
			/* translators: 1: game name, 2: classification */
			$subject = sprintf( __( '%1$s published an update (%2$s)', 'rbx-notifier' ), $name, Classifier::label( (string) $ev['classification'] ) );
			foreach ( self::fan_out( $ev['id'] . '|event', $subject, self::event_text( $ev, $name ) ) as $ch => $r ) {
				$sent[ $ch ] = array(
					'at' => Clock::iso(),
					'ok' => $r['ok'],
				);
			}
		}
		$sent['notice'] = array(
			'at' => Clock::iso(),
			'ok' => true,
		);
		Events::update( (int) $ev['id'], array( 'alerts_sent' => $sent ) );
	}

	public static function event_text( array $ev, string $name ): string {
		$lines = array(
			/* translators: %s: game name */
			sprintf( __( 'Roblox shows a new publish time for %s.', 'rbx-notifier' ), $name ),
			'',
			__( 'Detected:', 'rbx-notifier' ) . ' ' . Clock::human( Clock::iso( Events::detected_ts( $ev ) ) ),
			__( 'Old publish time:', 'rbx-notifier' ) . ' ' . Clock::human( (string) $ev['old_updated'] ),
			__( 'New publish time:', 'rbx-notifier' ) . ' ' . Clock::human( (string) $ev['last_merged_updated'] ),
			__( 'Publishes merged:', 'rbx-notifier' ) . ' ' . (int) $ev['publish_count'],
			__( 'Suggested type:', 'rbx-notifier' ) . ' ' . Classifier::label( (string) $ev['classification'] ),
			'',
			__( 'Open the status screen and pick what it was:', 'rbx-notifier' ) . ' ' . self::status_url(),
		);
		return implode( "\n", $lines );
	}

	/**
	 * System alerts: blind, recovered, API format changed, config error. One per kind per 24 h.
	 */
	public static function system( string $kind, string $subject, string $text ): void {
		$notices          = (array) get_option( 'rbx_notifier_system_notice', array() );
		$notices[ $kind ] = array(
			'subject' => $subject,
			'at'      => Clock::iso(),
		);
		if ( 'recovered' === $kind ) {
			unset( $notices['blind'], $notices['shape_error'], $notices['config_error'] );
		}
		update_option( 'rbx_notifier_system_notice', $notices, false );
		if ( 'recovered' === $kind ) {
			// A new outage after recovery alerts again.
			foreach ( array( 'blind', 'shape_error', 'config_error' ) as $k ) {
				foreach ( array_keys( self::channels() ) as $ch ) {
					delete_transient( 'rbx_ntf_' . sha1( 'sys|' . $k . '|' . $ch ) );
				}
			}
		}
		self::fan_out( 'sys|' . $kind . ( 'recovered' === $kind ? '|' . Clock::now() : '' ), $subject, $text . "\n\n" . self::status_url() );
	}

	/** Daily digest (ENG-44): last 24 h of events, hotfixes included, and the last poll time. */
	public static function digest(): void {
		$events = Events::since( Clock::now() - DAY_IN_SECONDS );
		$state  = \RBX\Notifier\State::get();
		$last   = '';
		foreach ( $state['u'] as $u ) {
			$last = max( $last, (string) ( $u['last_poll_at'] ?? '' ) );
		}
		$lines = array( __( 'Last poll:', 'rbx-notifier' ) . ' ' . Clock::human( '' !== $last ? $last : null ), '' );
		if ( ! $events ) {
			$lines[] = __( 'No game updates in the last 24 hours.', 'rbx-notifier' );
		}
		foreach ( $events as $ev ) {
			$lines[] = sprintf( '#%d %s, %s, %s, %d publish(es), %s', $ev['id'], $ev['game'], Clock::human( Clock::iso( Events::detected_ts( $ev ) ) ), Classifier::label( (string) $ev['classification'] ), $ev['publish_count'], $ev['state'] );
		}
		$lines[] = '';
		$lines[] = self::status_url();
		if ( self::live() ) {
			Email::send( __( 'Daily notifier digest', 'rbx-notifier' ), implode( "\n", $lines ) );
		}
		self::$sent[] = array(
			'channel' => 'digest',
			'subject' => 'digest',
		);
	}

	/**
	 * "Send test alert" (ENG-47): every configured channel, no dedupe.
	 *
	 * @return array<string, array{ok: bool, note: string}>
	 */
	public static function test(): array {
		$subject = __( 'Test alert', 'rbx-notifier' );
		$text    = __( 'This is a test from the UpdateOtter notifier. No action needed.', 'rbx-notifier' ) . "\n" . Clock::human( Clock::iso() );
		$out     = array( 'email' => Email::send( $subject, $text ) );
		$out['telegram'] = Telegram::configured() ? Telegram::send( $subject, $text ) : array( 'ok' => false, 'note' => __( 'not set up', 'rbx-notifier' ) );
		$out['discord']  = Discord::configured() ? Discord::send( $subject, $text ) : array( 'ok' => false, 'note' => __( 'not set up', 'rbx-notifier' ) );
		return $out;
	}
}
