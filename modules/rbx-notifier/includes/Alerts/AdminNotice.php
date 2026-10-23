<?php
/**
 * Admin notices (ENG-47): open events with ack buttons, system problems, "Polling is late".
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Alerts;

use RBX\Notifier\Classifier;
use RBX\Notifier\Clock;
use RBX\Notifier\Events;
use RBX\Notifier\State;

defined( 'ABSPATH' ) || exit;

/**
 * Shown to users with rbx_notifier_view.
 */
final class AdminNotice {

	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	public static function ack_form( array $ev ): string {
		$f = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:6px;flex-wrap:wrap">'
			. wp_nonce_field( 'rbx_notifier_ack', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="rbx_notifier_ack"><input type="hidden" name="event" value="' . (int) $ev['id'] . '">'
			. '<button class="button button-primary" name="kind" value="content_update">' . esc_html__( 'Content update: open checklist', 'rbx-notifier' ) . '</button>'
			. '<button class="button" name="kind" value="hotfix_no_change">' . esc_html__( 'Hotfix / no data change', 'rbx-notifier' ) . '</button>'
			. '<button class="button" name="kind" value="remind">' . esc_html__( 'Not sure: remind me in 24 h', 'rbx-notifier' ) . '</button></form>';
		return $f;
	}

	public static function render(): void {
		if ( ! current_user_can( 'rbx_notifier_view' ) ) {
			return;
		}
		foreach ( (array) get_option( 'rbx_notifier_system_notice', array() ) as $kind => $n ) {
			if ( 'recovered' === $kind ) {
				continue;
			}
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Update notifier:', 'rbx-notifier' ) . '</strong> ' . esc_html( (string) $n['subject'] ) . ' <a href="' . esc_url( Dispatcher::status_url() ) . '">' . esc_html__( 'Status', 'rbx-notifier' ) . '</a></p></div>';
		}
		if ( State::universes() ) {
			$last = null;
			foreach ( State::get()['u'] as $u ) {
				$t    = Clock::ts( (string) ( $u['last_poll_at'] ?? '' ) );
				$last = null === $t ? $last : max( (int) $last, $t );
			}
			if ( null !== $last && Clock::now() - $last > 2 * State::interval() * MINUTE_IN_SECONDS ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Polling is late — check the cron-job.org pinger (TECH-36).', 'rbx-notifier' ) . '</p></div>';
			}
		}
		$can_ack = current_user_can( 'rbx_notifier_ack' );
		foreach ( Events::needing_ack() as $ev ) {
			$game = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::game( (string) $ev['game'] ) : null;
			echo '<div class="notice notice-info"><p><strong>' . esc_html( $game ? $game->name() : (string) $ev['game'] ) . '</strong>: '
				. esc_html__( 'Roblox shows a new publish', 'rbx-notifier' ) . ' (' . esc_html( Clock::human( (string) $ev['last_merged_updated'] ) ) . ', '
				. esc_html( Classifier::label( (string) $ev['classification'] ) ) . ', ' . (int) $ev['publish_count'] . '×).</p>'
				. ( $can_ack ? '<p>' . self::ack_form( $ev ) . '</p>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ack_form().
		}
	}
}
