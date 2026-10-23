<?php
/**
 * Email channel (ENG-47): wp_mail() with SMTP from wp-config RBX_SMTP_* constants.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text mail.
 */
final class Email {

	public static function boot(): void {
		add_action( 'phpmailer_init', array( self::class, 'smtp' ) );
	}

	/** Uses SMTP only when wp-config defines RBX_SMTP_HOST; the password never leaves wp-config. */
	public static function smtp( $mailer ): void {
		if ( ! defined( 'RBX_SMTP_HOST' ) || '' === (string) RBX_SMTP_HOST ) {
			return;
		}
		$mailer->isSMTP();
		$mailer->Host       = (string) RBX_SMTP_HOST; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Port       = defined( 'RBX_SMTP_PORT' ) ? (int) RBX_SMTP_PORT : 465; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPSecure = 465 === $mailer->Port ? 'ssl' : 'tls'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPAuth   = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Username   = defined( 'RBX_SMTP_USER' ) ? (string) RBX_SMTP_USER : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Password   = defined( 'RBX_SMTP_PASS' ) ? (string) RBX_SMTP_PASS : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '' !== $mailer->Username ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->setFrom( $mailer->Username, 'UpdateOtter alerts', false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/** @return string[] */
	public static function recipients(): array {
		$raw = (string) get_option( 'rbx_notifier_recipients', '' );
		$out = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,]+/', $raw ) ?: array() ) );
		return $out ? array_values( $out ) : array( (string) get_option( 'admin_email' ) );
	}

	public static function configured(): bool {
		return (bool) self::recipients();
	}

	/** @return array{ok: bool, note: string} */
	public static function send( string $subject, string $text ): array {
		$ok = wp_mail( self::recipients(), '[UpdateOtter] ' . $subject, $text, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		return array(
			'ok'   => (bool) $ok,
			'note' => $ok ? implode( ', ', self::recipients() ) : __( 'wp_mail() failed; check the SMTP settings in wp-config.php.', 'rbx-notifier' ),
		);
	}
}
