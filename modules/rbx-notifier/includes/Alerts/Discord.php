<?php
/**
 * Discord channel (optional; the owner's private webhook, ENG-47). On 429 the message is
 * kept and retried once on the next poll run.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook post with mentions disabled.
 */
final class Discord {

	public static function configured(): bool {
		return defined( 'RBX_DISCORD_WEBHOOK_URL' ) && str_starts_with( (string) RBX_DISCORD_WEBHOOK_URL, 'https://' );
	}

	/** @return array{ok: bool, note: string} */
	public static function send( string $subject, string $text, bool $is_retry = false ): array {
		if ( ! self::configured() ) {
			return array( 'ok' => false, 'note' => 'off' );
		}
		$content = mb_substr( '**' . $subject . "**\n" . $text, 0, 1900 );
		$res     = wp_safe_remote_post(
			(string) RBX_DISCORD_WEBHOOK_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'content'          => $content,
						'allowed_mentions' => array( 'parse' => array() ),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'note' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 429 === $code && ! $is_retry ) {
			$j     = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$after = (float) ( is_array( $j ) ? ( $j['retry_after'] ?? 1 ) : 1 );
			$queue = (array) get_option( 'rbx_notifier_discord_retry', array() );
			$queue[] = array(
				'subject' => $subject,
				'text'    => $text,
				'after'   => \RBX\Notifier\Clock::now() + (int) ceil( $after ),
			);
			update_option( 'rbx_notifier_discord_retry', array_slice( $queue, -10 ), false );
			return array( 'ok' => false, 'note' => 'HTTP 429, retry queued' );
		}
		return array( 'ok' => $code >= 200 && $code < 300, 'note' => 'HTTP ' . $code );
	}

	/** Exactly one retry per queued message. */
	public static function retry_queued(): int {
		$queue = (array) get_option( 'rbx_notifier_discord_retry', array() );
		if ( ! $queue ) {
			return 0;
		}
		$keep = array();
		$sent = 0;
		foreach ( $queue as $m ) {
			if ( (int) ( $m['after'] ?? 0 ) > \RBX\Notifier\Clock::now() ) {
				$keep[] = $m;
				continue;
			}
			self::send( (string) $m['subject'], (string) $m['text'], true );
			++$sent;
		}
		update_option( 'rbx_notifier_discord_retry', $keep, false );
		return $sent;
	}
}
