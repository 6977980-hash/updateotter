<?php
/**
 * Bootstrap (ENG-40, ENG-44).
 *
 * @package RBX\Notifier
 */

declare( strict_types=1 );

namespace RBX\Notifier;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks, schedules and activation.
 */
final class Plugin {

	public static function boot(): void {
		if ( ! class_exists( '\RBX\Data\Api' ) ) {
			return;
		}
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 min is the spec (ENG-44).
		add_action( 'rbx_notifier_poll', array( Poller::class, 'cron' ) );
		add_action( 'rbx_notifier_digest', array( self::class, 'digest' ) );
		Alerts\Email::boot();
		if ( is_admin() ) {
			add_action( 'admin_init', array( self::class, 'heal' ) );
			Alerts\AdminNotice::boot();
			Admin\StatusPage::boot();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'rbx-notifier', Cli\Command::class );
		}
	}

	/** @param array<string, array{interval: int, display: string}> $s Schedules. */
	public static function schedules( array $s ): array {
		$s['rbx_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (RBX notifier)',
		);
		return $s;
	}

	/** Next 09:00 Asia/Karachi (04:00 UTC). */
	private static function digest_time(): int {
		$t = strtotime( 'today 04:00 UTC' );
		return $t <= time() ? $t + DAY_IN_SECONDS : $t;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'rbx_notifier_poll' ) ) {
			wp_schedule_event( time() + 60, 'rbx_15min', 'rbx_notifier_poll' );
		}
		if ( ! wp_next_scheduled( 'rbx_notifier_digest' ) ) {
			wp_schedule_event( self::digest_time(), 'daily', 'rbx_notifier_digest' );
		}
	}

	/** Self-healing (ENG-44): reschedule if missing; migrate if needed. */
	public static function heal(): void {
		if ( RBX_NOTIFIER_DB_VERSION !== get_option( 'rbx_notifier_db_version' ) ) {
			Schema::migrate();
		}
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		self::schedule();
	}

	public static function digest(): void {
		Alerts\Dispatcher::digest();
		Schema::prune();
	}

	public static function activate(): void {
		Schema::migrate();
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		self::schedule();
		$state = State::get();
		if ( empty( $state['g']['installed_at'] ) ) {
			$state['g']['installed_at'] = Clock::iso();
			State::put( $state );
		}
		add_option( 'rbx_notifier_interval', 15, '', false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rbx_notifier_poll' );
		wp_clear_scheduled_hook( 'rbx_notifier_digest' );
		delete_option( 'rbx_notifier_lock' );
	}
}
