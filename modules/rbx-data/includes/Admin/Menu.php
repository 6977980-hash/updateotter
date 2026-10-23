<?php
/**
 * RBX Data admin menu (ENG-25). Every screen checks its capability; every form has a nonce.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Admin;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Registers screens and admin-post handlers.
 */
final class Menu {

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		ImportScreen::boot();
		HealthScreen::boot();
		DataScreens::boot();
		SetupScreen::boot();
	}

	public static function menu(): void {
		$badge = \RBX\Data\Log\ErrorLog::count_since( 7 );
		$title = __( 'RBX Data', 'rbx-data' ) . ( $badge ? ' <span class="awaiting-mod">' . (int) $badge . '</span>' : '' );
		add_menu_page( __( 'RBX Data', 'rbx-data' ), $title, 'rbx_view_health', 'rbx-data', array( HealthScreen::class, 'render' ), 'dashicons-database', 58 );
		add_submenu_page( 'rbx-data', __( 'Data health', 'rbx-data' ), __( 'Data health', 'rbx-data' ), 'rbx_view_health', 'rbx-data', array( HealthScreen::class, 'render' ) );
		add_submenu_page( 'rbx-data', __( 'Import', 'rbx-data' ), __( 'Import', 'rbx-data' ), 'rbx_import', 'rbx-import', array( ImportScreen::class, 'render' ) );
		add_submenu_page( 'rbx-data', __( 'Records', 'rbx-data' ), __( 'Records', 'rbx-data' ), 'rbx_view_health', 'rbx-records', array( DataScreens::class, 'records' ) );
		add_submenu_page( 'rbx-data', __( 'Changelog', 'rbx-data' ), __( 'Changelog', 'rbx-data' ), 'rbx_view_health', 'rbx-changelog', array( DataScreens::class, 'changelog' ) );
		add_submenu_page( 'rbx-data', __( 'Codes check log', 'rbx-data' ), __( 'Codes', 'rbx-data' ), 'rbx_codes_log', 'rbx-codes', array( DataScreens::class, 'codes' ) );
		$open = \RBX\Data\Reports\Queue::count_open();
		add_submenu_page( 'rbx-data', __( 'Reports', 'rbx-data' ), __( 'Reports', 'rbx-data' ) . ( $open ? ' <span class="awaiting-mod">' . (int) $open . '</span>' : '' ), 'rbx_reports_manage', 'rbx-reports', array( DataScreens::class, 'reports' ) );
		add_submenu_page( 'rbx-data', __( 'Setup', 'rbx-data' ), __( 'Setup', 'rbx-data' ), 'manage_options', 'rbx-setup', array( SetupScreen::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'rbx-' ) && 'toplevel_page_rbx-data' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rbx-data-admin', RBX_DATA_URL . 'assets/admin/admin.css', array(), RBX_DATA_VERSION );
		wp_enqueue_script( 'rbx-data-admin', RBX_DATA_URL . 'assets/admin/import.js', array(), RBX_DATA_VERSION, true );
		wp_add_inline_script(
			'rbx-data-admin',
			'window.rbxAdmin = ' . wp_json_encode(
				array(
					'rest'  => esc_url_raw( rest_url( 'rbx/v1/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'  => array(
						'applying' => __( 'Applying batch %1$s of %2$s…', 'rbx-data' ),
						'done'     => __( 'Done. Exports, cache and page states are updated.', 'rbx-data' ),
						'failed'   => __( 'Stopped:', 'rbx-data' ),
						'confirm'  => __( 'Roll back this run? A new run will restore the previous values.', 'rbx-data' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/** Game picker for screens: ?game=key, defaults to the first game. */
	public static function current_game(): ?Game {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['game'] ) ? sanitize_key( wp_unslash( $_GET['game'] ) ) : '';
		$g   = '' !== $key ? Loader::game( $key ) : null;
		if ( $g ) {
			return $g;
		}
		$all = Loader::games();
		return $all ? reset( $all ) : null;
	}

	public static function game_tabs( string $page, ?Game $current ): string {
		$games = Loader::games();
		if ( count( $games ) < 2 ) {
			return '';
		}
		$out = '<nav class="nav-tab-wrapper">';
		foreach ( $games as $g ) {
			$out .= '<a class="nav-tab' . ( $current && $current->key() === $g->key() ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $page . '&game=' . $g->key() ) ) . '">' . esc_html( $g->name() ) . '</a>';
		}
		return $out . '</nav>';
	}

	/** Checks cap + nonce for admin-post handlers. */
	public static function guard( string $cap, string $action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rbx-data' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	public static function notice_from_query(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rbx_msg'] ) ) {
			$type = isset( $_GET['rbx_err'] ) ? 'error' : 'success';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['rbx_msg'] ) ) ) . '</p></div>';
		}
		// phpcs:enable
	}

	public static function back( string $url, string $msg, bool $error = false ): void {
		$url = add_query_arg( 'rbx_msg', rawurlencode( $msg ), $url );
		if ( $error ) {
			$url = add_query_arg( 'rbx_err', '1', $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
