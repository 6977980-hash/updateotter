<?php
/**
 * Telegram channel (optional, ENG-47). Off while RBX_TELEGRAM_BOT_TOKEN is empty.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * sendMessage with plain text.
 */
final class Telegram {

	public static function configured(): bool {
		return defined( 'RBX_TELEGRAM_BOT_TOKEN' ) && '' !== (string) RBX_TELEGRAM_BOT_TOKEN && defined( 'RBX_TELEGRAM_CHAT_ID' ) && '' !== (string) RBX_TELEGRAM_CHAT_ID;
	}

	/** @return array{ok: bool, note: string} */
	public static function send( string $subject, string $text ): array {
		if ( ! self::configured() ) {
			return array( 'ok' => false, 'note' => 'off' );
		}
		$res = wp_safe_remote_post(
			'https://api.telegram.org/bot' . RBX_TELEGRAM_BOT_TOKEN . '/sendMessage',
			array(
				'timeout' => 10,
				'body'    => array(
					'chat_id' => (string) RBX_TELEGRAM_CHAT_ID,
					'text'    => mb_substr( $subject . "\n\n" . $text, 0, 1000 ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'note' => self::mask( $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array( 'ok' => 200 === $code, 'note' => 'HTTP ' . $code );
	}

	/** Token masked as bot*** in anything that is logged. */
	public static function mask( string $s ): string {
		return (string) preg_replace( '#bot[0-9]+:[A-Za-z0-9_-]+#', 'bot***', $s );
	}
}
