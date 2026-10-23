<?php
/**
 * RBX Data → Notifier (ENG-48): per-universe status, buttons, events, checklist, settings.
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier\Admin;

use RBX\Notifier\Alerts\AdminNotice;
use RBX\Notifier\Alerts\Dispatcher;
use RBX\Notifier\Classifier;
use RBX\Notifier\Clock;
use RBX\Notifier\Events;
use RBX\Notifier\Poller;
use RBX\Notifier\Roblox\Client;
use RBX\Notifier\State;

defined( 'ABSPATH' ) || exit;

/**
 * admin.php?page=rbx-notifier[&event=ID]
 */
final class StatusPage {

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		foreach ( array( 'ack', 'tick', 'close', 'poll', 'test', 'lookup', 'settings' ) as $a ) {
			add_action( 'admin_post_rbx_notifier_' . $a, array( self::class, 'do_' . $a ) );
		}
	}

	public static function menu(): void {
		add_submenu_page( 'rbx-data', __( 'Update notifier', 'rbx-notifier' ), __( 'Notifier', 'rbx-notifier' ), 'rbx_notifier_view', 'rbx-notifier', array( self::class, 'render' ) );
	}

	private static function guard( string $cap, string $action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rbx-notifier' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back( string $msg, bool $error = false, int $event = 0 ): void {
		$url = add_query_arg(
			array_filter(
				array(
					'page'    => 'rbx-notifier',
					'event'   => $event ?: null,
					'rbx_msg' => rawurlencode( $msg ),
					'rbx_err' => $error ? '1' : null,
				)
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function post( string $k ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers run guard() first.
		return sanitize_text_field( (string) wp_unslash( $_POST[ $k ] ?? '' ) );
	}

	public static function do_ack(): void {
		self::guard( 'rbx_notifier_ack', 'rbx_notifier_ack' );
		$id   = (int) self::post( 'event' );
		$kind = sanitize_key( self::post( 'kind' ) );
		$ok   = Checklist::ack( $id, $kind, get_current_user_id() );
		self::back( $ok ? __( 'Saved.', 'rbx-notifier' ) : __( 'Could not save that choice.', 'rbx-notifier' ), ! $ok, 'content_update' === $kind ? $id : 0 );
	}

	public static function do_tick(): void {
		self::guard( 'rbx_notifier_ack', 'rbx_notifier_tick' );
		$id = (int) self::post( 'event' );
		$ok = Checklist::tick( $id, self::post( 'item' ), '1' === self::post( 'done' ), self::post( 'note' ), get_current_user_id() );
		self::back( $ok ? __( 'Checklist saved.', 'rbx-notifier' ) : __( 'Could not save.', 'rbx-notifier' ), ! $ok, $id );
	}

	public static function do_close(): void {
		self::guard( 'rbx_notifier_ack', 'rbx_notifier_close' );
		$id = (int) self::post( 'event' );
		$r  = Checklist::close( $id, self::post( 'update_record_id' ), get_current_user_id() );
		self::back( true === $r ? __( 'Event closed.', 'rbx-notifier' ) : (string) $r, true !== $r, $id );
	}

	public static function do_poll(): void {
		self::guard( 'rbx_notifier_ack', 'rbx_notifier_poll' );
		$r = Poller::run( '1' === self::post( 'force' ) );
		/* translators: %s: poll result */
		self::back( sprintf( __( 'Poll: %s', 'rbx-notifier' ), $r['status'] ), ! in_array( $r['status'], array( 'ok', 'not due' ), true ) && ! str_starts_with( $r['status'], 'backoff' ) );
	}

	public static function do_test(): void {
		self::guard( 'manage_options', 'rbx_notifier_test' );
		$parts = array();
		foreach ( Dispatcher::test() as $ch => $r ) {
			$parts[] = $ch . ': ' . ( $r['ok'] ? 'OK' : __( 'failed', 'rbx-notifier' ) ) . ' (' . $r['note'] . ')';
		}
		self::back( implode( '; ', $parts ) );
	}

	public static function do_lookup(): void {
		self::guard( 'manage_options', 'rbx_notifier_lookup' );
		$r = Client::universe_for_place( preg_replace( '/\D/', '', self::post( 'place' ) ) ?? '' );
		/* translators: %s: universe ID */
		self::back( $r['ok'] ? sprintf( __( 'Universe ID: %s. Paste it on RBX Data, Setup, Roblox IDs.', 'rbx-notifier' ), $r['value'] ) : (string) $r['value'], ! $r['ok'] );
	}

	public static function do_settings(): void {
		self::guard( 'manage_options', 'rbx_notifier_settings' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,]+/', (string) wp_unslash( $_POST['recipients'] ?? '' ) ) ?: array() ) );
		update_option( 'rbx_notifier_recipients', implode( ', ', $emails ), false );
		update_option( 'rbx_notifier_interval', '30' === self::post( 'interval' ) ? 30 : 15, false );
		update_option( 'rbx_notifier_use_open_cloud', '1' === self::post( 'open_cloud' ) ? 1 : 0, false );
		self::back( __( 'Settings saved.', 'rbx-notifier' ) );
	}

	private static function form( string $action, string $inner, string $button, bool $primary = false ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rbx-inline-form">' . wp_nonce_field( 'rbx_notifier_' . $action, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="rbx_notifier_' . esc_attr( $action ) . '">' . $inner
			. '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $button ) . '</button></form>';
	}

	private static function badge( string $h ): string {
		$c = array(
			'green' => '#00a32a',
			'amber' => '#dba617',
			'red'   => '#d63638',
			'none'  => '#646970',
		);
		$t = array(
			'green' => __( 'Healthy', 'rbx-notifier' ),
			'amber' => __( 'Late', 'rbx-notifier' ),
			'red'   => __( 'Blind', 'rbx-notifier' ),
			'none'  => __( 'Not polled yet', 'rbx-notifier' ),
		);
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;background:' . esc_attr( $c[ $h ] ) . '">' . esc_html( $t[ $h ] ) . '</span>';
	}

	private static function user( int $id ): string {
		$u = $id ? get_userdata( $id ) : false;
		return $u ? (string) $u->display_name : '—';
	}

	public static function render(): void {
		if ( ! current_user_can( 'rbx_notifier_view' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Update notifier', 'rbx-notifier' ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rbx_msg'] ) ) {
			echo '<div class="notice notice-' . ( isset( $_GET['rbx_err'] ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['rbx_msg'] ) ) ) ) . '</p></div>';
		}
		$event = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0;
		// phpcs:enable
		if ( $event ) {
			self::checklist( $event );
			echo '</div>';
			return;
		}
		self::status();
		echo '</div>';
	}

	private static function status(): void {
		$universes = State::universes();
		$next      = wp_next_scheduled( 'rbx_notifier_poll' );
		if ( ! $universes ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No Roblox universe IDs yet. Fill them on RBX Data, Setup, Roblox IDs (use "Look up universe" below if you only have the place ID).', 'rbx-notifier' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=rbx-setup' ) ) . '">' . esc_html__( 'Setup', 'rbx-notifier' ) . '</a></p></div>';
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Game', 'rbx-notifier' ), __( 'Health', 'rbx-notifier' ), __( 'Universe / root place', 'rbx-notifier' ), __( 'Last seen publish', 'rbx-notifier' ), __( 'Last poll', 'rbx-notifier' ), __( 'Last success', 'rbx-notifier' ), __( 'Failures', 'rbx-notifier' ), __( 'Next allowed poll', 'rbx-notifier' ), __( 'Last error', 'rbx-notifier' ), __( 'Rate limit', 'rbx-notifier' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $universes as $id => $g ) {
			$u = State::universe( (string) $id );
			echo '<tr><td>' . esc_html( $g->name() ) . '</td><td>' . self::badge( State::health( $u['last_success_at'], $u['last_poll_at'] ) ) . '</td><td>' . esc_html( $id . ' / ' . ( $u['root_place_id'] ?? $g->place_id() ?? '—' ) ) . '</td><td>' . esc_html( Clock::human( $u['last_seen_updated'] ) ) . '</td><td>' . esc_html( Clock::human( $u['last_poll_at'] ) ) . '</td><td>' . esc_html( Clock::human( $u['last_success_at'] ) ) . '</td><td>' . (int) $u['consecutive_failures'] . '</td><td>' . esc_html( Clock::human( $u['next_allowed_at'] ) ) . '</td><td>' . esc_html( $u['last_error_kind'] ? $u['last_error_kind'] . ', ' . Clock::human( $u['last_error_at'] ) : '—' ) . '</td><td>' . esc_html( ( $u['ratelimit_remaining'] ?? '' ) !== '' && null !== $u['ratelimit_remaining'] ? $u['ratelimit_remaining'] . ' left, reset ' . Clock::human( $u['ratelimit_reset_at'] ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		}
		echo '</tbody></table>';
		foreach ( $universes as $id => $g ) {
			if ( (string) $id === (string) $g->place_id() ) {
				/* translators: %s: game name */
				echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( '%s: the universe ID is the same as the place ID. They are different numbers: use "Look up universe" below, then save the result on RBX Data, Setup.', 'rbx-notifier' ), $g->name() ) ) . '</p></div>';
			}
		}
		$cron_seen = '';
		foreach ( State::get()['u'] as $u ) {
			$cron_seen = max( $cron_seen, (string) ( $u['last_cron_seen_at'] ?? '' ) );
		}
		/* translators: 1: next cron run, 2: last time cron ran the poller, 3: minutes */
		echo '<p>' . esc_html( sprintf( __( 'Cron next run: %1$s. Cron last seen: %2$s. Interval: %3$d min.', 'rbx-notifier' ), $next ? Clock::human( Clock::iso( (int) $next ) ) : __( 'not scheduled', 'rbx-notifier' ), Clock::human( '' !== $cron_seen ? $cron_seen : null ), State::interval() ) ) . '</p>';

		echo '<p style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">';
		if ( current_user_can( 'rbx_notifier_ack' ) ) {
			echo self::form( 'poll', '<label><input type="checkbox" name="force" value="1"> ' . esc_html__( 'ignore backoff', 'rbx-notifier' ) . '</label> ', __( 'Poll now', 'rbx-notifier' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( current_user_can( 'manage_options' ) ) {
			echo self::form( 'test', '', __( 'Send test alert', 'rbx-notifier' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::form( 'lookup', '<label>' . esc_html__( 'Place ID', 'rbx-notifier' ) . ' <input name="place" inputmode="numeric" size="14" required></label> ', __( 'Look up universe', 'rbx-notifier' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</p>';

		foreach ( $universes as $id => $g ) {
			echo '<h2>' . esc_html( $g->name() ) . ': ' . esc_html__( 'last 20 events', 'rbx-notifier' ) . '</h2>';
			$rows = Events::recent( (string) $id, 20 );
			if ( ! $rows ) {
				echo '<p>' . esc_html__( 'No events yet. The first poll only records a baseline.', 'rbx-notifier' ) . '</p>';
				continue;
			}
			echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Detected', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'Publish time (old → new)', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'Publishes', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'Suggested', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'State', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'Decision', 'rbx-notifier' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $ev ) {
				$link = esc_url( add_query_arg( array( 'page' => 'rbx-notifier', 'event' => $ev['id'] ), admin_url( 'admin.php' ) ) );
				echo '<tr><td>' . (int) $ev['id'] . '</td><td>' . esc_html( Clock::human( Clock::iso( Events::detected_ts( $ev ) ) ) ) . '</td><td>' . esc_html( Clock::human( (string) $ev['old_updated'] ) . ' → ' . Clock::human( (string) $ev['last_merged_updated'] ) ) . '</td><td>' . (int) $ev['publish_count'] . '</td><td>' . esc_html( Classifier::label( (string) $ev['classification'] ) ) . '</td><td>' . esc_html( (string) $ev['state'] ) . '</td><td>'
					. ( 'open' === $ev['state'] && current_user_can( 'rbx_notifier_ack' ) ? AdminNotice::ack_form( $ev ) : esc_html( (string) $ev['ack_kind'] . ( $ev['ack_by'] ? ', ' . self::user( (int) $ev['ack_by'] ) : '' ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. '</td><td><a href="' . $link . '">' . esc_html__( 'Checklist', 'rbx-notifier' ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}

		if ( current_user_can( 'manage_options' ) ) {
			$i = State::interval();
			echo '<h2>' . esc_html__( 'Settings', 'rbx-notifier' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'rbx_notifier_settings', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="rbx_notifier_settings"><table class="form-table"><tr><th><label for="rbx-rcpt">' . esc_html__( 'Alert emails', 'rbx-notifier' ) . '</label></th><td><input id="rbx-rcpt" name="recipients" class="regular-text" value="' . esc_attr( (string) get_option( 'rbx_notifier_recipients', '' ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"><p class="description">' . esc_html__( 'Comma separated. Empty = site admin email. SMTP and Telegram/Discord secrets live in wp-config.php.', 'rbx-notifier' ) . '</p></td></tr>'
				. '<tr><th>' . esc_html__( 'Poll every', 'rbx-notifier' ) . '</th><td><label><input type="radio" name="interval" value="15"' . checked( 15, $i, false ) . '> 15 min</label> <label><input type="radio" name="interval" value="30"' . checked( 30, $i, false ) . '> 30 min</label></td></tr>'
				. '<tr><th>' . esc_html__( 'Open Cloud fallback', 'rbx-notifier' ) . '</th><td><label><input type="checkbox" name="open_cloud" value="1"' . checked( 1, (int) get_option( 'rbx_notifier_use_open_cloud' ), false ) . '> ' . esc_html__( 'Use RBX_ROBLOX_OPEN_CLOUD_KEY instead of the public endpoint (only if Roblox restricts public polling).', 'rbx-notifier' ) . '</label></td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			submit_button( __( 'Save settings', 'rbx-notifier' ) );
			echo '</form>';
		}
	}

	private static function checklist( int $id ): void {
		$ev = Events::get( $id );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=rbx-notifier' ) ) . '">← ' . esc_html__( 'Back to status', 'rbx-notifier' ) . '</a></p>';
		if ( ! $ev ) {
			echo '<p>' . esc_html__( 'Event not found.', 'rbx-notifier' ) . '</p>';
			return;
		}
		$game = class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::game( (string) $ev['game'] ) : null;
		/* translators: 1: event number, 2: game */
		echo '<h2>' . esc_html( sprintf( __( 'Event #%1$d: %2$s', 'rbx-notifier' ), $ev['id'], $game ? $game->name() : $ev['game'] ) ) . '</h2>';
		echo '<p>' . esc_html( Classifier::label( (string) $ev['classification'] ) . ', ' . $ev['state'] . ', ' . $ev['publish_count'] . '×' ) . '</p>';
		echo '<p>' . esc_html__( 'roblox_updated_at for the UPD row:', 'rbx-notifier' ) . ' <input readonly size="24" value="' . esc_attr( (string) $ev['last_merged_updated'] ) . '" onclick="this.select()"> <button type="button" class="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.previousElementSibling.value)">' . esc_html__( 'Copy', 'rbx-notifier' ) . '</button></p>';
		$can    = current_user_can( 'rbx_notifier_ack' ) && 'closed' !== $ev['state'];
		$req    = array_flip( Checklist::REQUIRED );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Step', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'Done', 'rbx-notifier' ) . '</th><th>' . esc_html__( 'By / when / note', 'rbx-notifier' ) . '</th></tr></thead><tbody>';
		foreach ( Checklist::items( (string) $ev['game'] ) as $n => $label ) {
			$t    = (array) ( $ev['checklist'][ $n ] ?? array() );
			$done = ! empty( $t['done'] );
			echo '<tr><td>' . esc_html( $n ) . ( isset( $req[ $n ] ) ? ' *' : '' ) . '</td><td>' . esc_html( $label ) . '</td><td>';
			if ( $can ) {
				echo self::form( 'tick', '<input type="hidden" name="event" value="' . (int) $ev['id'] . '"><input type="hidden" name="item" value="' . esc_attr( $n ) . '"><input type="hidden" name="done" value="' . ( $done ? '0' : '1' ) . '"><input name="note" size="18" placeholder="' . esc_attr__( 'note (optional)', 'rbx-notifier' ) . '" value="' . esc_attr( (string) ( $t['note'] ?? '' ) ) . '"> ', $done ? __( 'Untick', 'rbx-notifier' ) : __( 'Tick', 'rbx-notifier' ), ! $done ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo $done ? '✓' : '—';
			}
			echo '</td><td>' . ( $t ? esc_html( ( $done ? '✓ ' : '✗ ' ) . self::user( (int) ( $t['by'] ?? 0 ) ) . ', ' . Clock::human( (string) ( $t['at'] ?? '' ) ) . ( ! empty( $t['note'] ) ? ', ' . $t['note'] : '' ) ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( '* Needed before a content update can be closed.', 'rbx-notifier' ) . '</p>';
		if ( $can ) {
			echo '<h3>' . esc_html__( 'Close the event', 'rbx-notifier' ) . '</h3>' . self::form( 'close', '<input type="hidden" name="event" value="' . (int) $ev['id'] . '"><label>' . esc_html__( 'UPD record ID', 'rbx-notifier' ) . ' <input name="update_record_id" placeholder="SAE-UPD-0001" value="' . esc_attr( (string) $ev['update_record_id'] ) . '"></label> ', __( 'Close event', 'rbx-notifier' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'closed' === $ev['state'] && '' !== $ev['update_record_id'] ) {
			echo '<p>' . esc_html__( 'Linked UPD record:', 'rbx-notifier' ) . ' ' . esc_html( (string) $ev['update_record_id'] ) . '</p>';
		}
	}
}
