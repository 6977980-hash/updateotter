<?php
/**
 * Records (read-only), Changelog (+ CSV), Codes check log, Reports queue (ENG-25 items 3–6).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Admin;

use RBX\Data\Codes\CheckLog;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Changelog;
use RBX\Data\Reports\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Smaller screens.
 */
final class DataScreens {

	public static function boot(): void {
		add_action( 'admin_post_rbx_changelog_csv', array( self::class, 'csv' ) );
		add_action( 'admin_post_rbx_code_check', array( self::class, 'add_check' ) );
		add_action( 'admin_post_rbx_report_status', array( self::class, 'report_status' ) );
	}

	private static function deny( string $cap ): bool {
		if ( current_user_can( $cap ) ) {
			return false;
		}
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'rbx-data' ) );
	}

	public static function records(): void {
		self::deny( 'rbx_view_health' );
		$game = Menu::current_game();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$q      = sanitize_text_field( (string) wp_unslash( $_GET['q'] ?? '' ) );
		$entity = sanitize_text_field( (string) wp_unslash( $_GET['entity'] ?? '' ) );
		// phpcs:enable
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'Records', 'rbx-data' ) . '</h1>';
		if ( ! $game ) {
			echo '</div>';
			return;
		}
		echo Menu::game_tabs( 'rbx-records', $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<form method="get"><input type="hidden" name="page" value="rbx-records"><input type="hidden" name="game" value="' . esc_attr( $game->key() ) . '"><select name="entity"><option value="">' . esc_html__( 'All types', 'rbx-data' ) . '</option>';
		foreach ( array_merge( array_keys( $game->entities() ), array( 'MEC', 'FRM', 'TST', 'MED', 'PRV' ) ) as $e ) {
			echo '<option value="' . esc_attr( $e ) . '"' . selected( $entity, $e, false ) . '>' . esc_html( $e ) . '</option>';
		}
		echo '</select> <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'ID or name', 'rbx-data' ) . '"> <button class="button">' . esc_html__( 'Filter', 'rbx-data' ) . '</button></form>';
		$ds   = Repository::dataset( $game );
		$rows = '';
		$n    = 0;
		foreach ( $ds->records() as $id => $r ) {
			if ( ( '' !== $entity && $r['entity'] !== $entity ) || ( '' !== $q && false === stripos( $id . ' ' . $r['name'], $q ) ) ) {
				continue;
			}
			if ( ++$n > 500 ) {
				break;
			}
			$pid   = (int) ( $r['post_id'] ?? 0 );
			$state = $pid ? (string) get_post_meta( $pid, '_rbx_page_state', true ) : '';
			$rs    = $pid ? implode( ', ', (array) json_decode( (string) get_post_meta( $pid, '_rbx_gate_reasons', true ), true ) ) : '';
			$links = '<a href="' . esc_url( admin_url( 'admin.php?page=rbx-changelog&record_id=' . rawurlencode( (string) $id ) ) ) . '">' . esc_html__( 'changelog', 'rbx-data' ) . '</a>';
			if ( $pid ) {
				$links .= ' · <a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html__( 'edit page', 'rbx-data' ) . '</a> · <a href="' . esc_url( (string) get_permalink( $pid ) ) . '">' . esc_html__( 'view', 'rbx-data' ) . '</a>';
			}
			$rows .= '<tr><td><code>' . esc_html( (string) $id ) . '</code></td><td>' . esc_html( (string) $r['name'] ) . '</td><td>' . esc_html( (string) $r['status'] ) . '</td><td>' . esc_html( (string) ( $r['data']['last_verified'] ?? '' ) ) . '</td><td>' . esc_html( $state ) . ( '' !== $rs ? ' <small>' . esc_html( $rs ) . '</small>' : '' ) . '</td><td>' . $links . '</td></tr>';
		}
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Name', 'rbx-data' ) . '</th><th>' . esc_html__( 'Status', 'rbx-data' ) . '</th><th>' . esc_html__( 'Last verified', 'rbx-data' ) . '</th><th>' . esc_html__( 'Page state (failing rules)', 'rbx-data' ) . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** @return array<string, mixed> */
	private static function changelog_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f = array(
			'game'        => sanitize_text_field( (string) wp_unslash( $_GET['fgame'] ?? '' ) ),
			'record_id'   => sanitize_text_field( (string) wp_unslash( $_GET['record_id'] ?? '' ) ),
			'update_id'   => sanitize_text_field( (string) wp_unslash( $_GET['update_id'] ?? '' ) ),
			'run'         => absint( $_GET['run'] ?? 0 ),
			'corrections' => ! empty( $_GET['corrections'] ),
		);
		// phpcs:enable
		return $f;
	}

	public static function changelog(): void {
		self::deny( 'rbx_view_health' );
		$f = self::changelog_filters();
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'Changelog', 'rbx-data' ) . '</h1><form method="get"><input type="hidden" name="page" value="rbx-changelog"><select name="fgame"><option value="">' . esc_html__( 'All games', 'rbx-data' ) . '</option>';
		foreach ( Loader::games() as $g ) {
			echo '<option value="' . esc_attr( $g->code() ) . '"' . selected( $f['game'], $g->code(), false ) . '>' . esc_html( $g->name() ) . '</option>';
		}
		echo '</select> <input name="record_id" placeholder="' . esc_attr__( 'Record ID', 'rbx-data' ) . '" value="' . esc_attr( $f['record_id'] ) . '"> <input name="update_id" placeholder="' . esc_attr__( 'Update ID', 'rbx-data' ) . '" value="' . esc_attr( $f['update_id'] ) . '"> <input name="run" type="number" min="0" placeholder="' . esc_attr__( 'Run', 'rbx-data' ) . '" value="' . esc_attr( $f['run'] ? (string) $f['run'] : '' ) . '"> <label><input type="checkbox" name="corrections" value="1"' . checked( $f['corrections'], true, false ) . '> ' . esc_html__( 'Corrections only', 'rbx-data' ) . '</label> <button class="button">' . esc_html__( 'Filter', 'rbx-data' ) . '</button> <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'rbx_changelog_csv' ), array_filter( array_map( 'strval', array( 'fgame' => $f['game'], 'record_id' => $f['record_id'], 'update_id' => $f['update_id'], 'run' => $f['run'] ?: '', 'corrections' => $f['corrections'] ? '1' : '' ) ) ) ), admin_url( 'admin-post.php' ) ), 'rbx_changelog_csv' ) ) . '">' . esc_html__( 'Download CSV', 'rbx-data' ) . '</a></form>';
		$rows = Changelog::search( $f, 300 );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When (UTC)', 'rbx-data' ) . '</th><th>ID</th><th>' . esc_html__( 'Field', 'rbx-data' ) . '</th><th>' . esc_html__( 'Old', 'rbx-data' ) . '</th><th>' . esc_html__( 'New', 'rbx-data' ) . '</th><th>' . esc_html__( 'Kind', 'rbx-data' ) . '</th><th>' . esc_html__( 'Update', 'rbx-data' ) . '</th><th>' . esc_html__( 'Run', 'rbx-data' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['changed_at'] ) . '</td><td><code>' . esc_html( (string) $r['record_id'] ) . '</code></td><td>' . esc_html( (string) $r['field'] ) . '</td><td>' . esc_html( mb_substr( (string) $r['old_value'], 0, 120 ) ) . '</td><td>' . esc_html( mb_substr( (string) $r['new_value'], 0, 120 ) ) . '</td><td>' . esc_html( (string) $r['change_kind'] ) . ( $r['is_correction'] ? ' · ' . esc_html__( 'correction', 'rbx-data' ) : '' ) . '</td><td>' . esc_html( (string) $r['update_id'] ) . '</td><td>' . (int) $r['import_run_id'] . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function csv(): void {
		if ( ! current_user_can( 'rbx_view_health' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rbx_changelog_csv' );
		$rows = Changelog::search( self::changelog_filters(), 50000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rbx-changelog-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'changed_at', 'game', 'record_id', 'field', 'old_value', 'new_value', 'change_kind', 'update_id', 'import_run_id', 'is_correction' ) );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( array( 'changed_at', 'game', 'record_id', 'field', 'old_value', 'new_value', 'change_kind', 'update_id', 'import_run_id', 'is_correction' ) as $k ) {
				$v      = (string) ( $r[ $k ] ?? '' );
				$line[] = preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; // CSV injection guard.
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function codes(): void {
		self::deny( 'rbx_codes_log' );
		$game = Menu::current_game();
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'Codes check log', 'rbx-data' ) . '</h1>';
		Menu::notice_from_query();
		if ( ! $game ) {
			echo '</div>';
			return;
		}
		echo Menu::game_tabs( 'rbx-codes', $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$days = CheckLog::days_since( $game );
		/* translators: %s: days */
		echo '<p class="' . esc_attr( null === $days || $days > 7 ? 'rbx-bad' : '' ) . '">' . esc_html( null === $days ? __( 'No checks yet. Check at least every 7 days and within 24 hours of a game update.', 'rbx-data' ) : sprintf( __( 'Last check %s days ago. Check at least every 7 days.', 'rbx-data' ), $days ) ) . '</p>';
		$ds       = Repository::dataset( $game );
		$channels = implode( "\n", array_map( 'strval', (array) ( $ds->meta( 'official_channels' ) ?? array() ) ) );
		$user     = wp_get_current_user();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rbx-box"><input type="hidden" name="action" value="rbx_code_check"><input type="hidden" name="game" value="' . esc_attr( $game->key() ) . '">';
		wp_nonce_field( 'rbx_code_check' );
		echo '<table class="form-table"><tr><th><label for="cc-on">' . esc_html__( 'Checked on (UTC)', 'rbx-data' ) . '</label></th><td><input id="cc-on" type="datetime-local" name="checked_on" value="' . esc_attr( gmdate( 'Y-m-d\TH:i' ) ) . '" required></td></tr>';
		echo '<tr><th><label for="cc-by">' . esc_html__( 'Checked by', 'rbx-data' ) . '</label></th><td><input id="cc-by" name="checked_by" value="' . esc_attr( $user->display_name ) . '" required></td></tr>';
		echo '<tr><th><label for="cc-ch">' . esc_html__( 'Channels checked', 'rbx-data' ) . '</label></th><td><textarea id="cc-ch" name="channels_checked" rows="3" class="large-text" required>' . esc_textarea( $channels ) . '</textarea></td></tr>';
		echo '<tr><th><label for="cc-res">' . esc_html__( 'Result', 'rbx-data' ) . '</label></th><td><select id="cc-res" name="result">';
		foreach ( array( 'no_codes' => __( 'No codes', 'rbx-data' ), 'codes_found' => __( 'New codes found (add them to the Sheet)', 'rbx-data' ), 'codes_expired' => __( 'Some codes expired (update the Sheet)', 'rbx-data' ), 'no_change' => __( 'No change', 'rbx-data' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr><tr><th><label for="cc-note">' . esc_html__( 'Note', 'rbx-data' ) . '</label></th><td><input id="cc-note" name="note" class="large-text"></td></tr></table>';
		submit_button( __( 'Add check', 'rbx-data' ) );
		echo '</form><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Checked on', 'rbx-data' ) . '</th><th>' . esc_html__( 'By', 'rbx-data' ) . '</th><th>' . esc_html__( 'Result', 'rbx-data' ) . '</th><th>' . esc_html__( 'Note', 'rbx-data' ) . '</th></tr></thead><tbody>';
		foreach ( CheckLog::recent( $game, 30 ) as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['checked_on'] ) . '</td><td>' . esc_html( (string) $r['checked_by'] ) . '</td><td>' . esc_html( (string) $r['result'] ) . '</td><td>' . esc_html( (string) $r['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function add_check(): void {
		Menu::guard( 'rbx_codes_log', 'rbx_code_check' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$game = Loader::game( sanitize_key( (string) wp_unslash( $_POST['game'] ?? '' ) ) );
		if ( ! $game ) {
			wp_die( esc_html__( 'Unknown game.', 'rbx-data' ) );
		}
		$on = str_replace( 'T', ' ', sanitize_text_field( (string) wp_unslash( $_POST['checked_on'] ?? '' ) ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $on ) || strtotime( $on . ' UTC' ) > time() + 300 ) {
			Menu::back( admin_url( 'admin.php?page=rbx-codes&game=' . $game->key() ), __( 'Enter a valid date that is not in the future.', 'rbx-data' ), true );
		}
		CheckLog::add(
			$game,
			array(
				'checked_on'       => $on . ':00',
				'checked_by'       => (string) wp_unslash( $_POST['checked_by'] ?? '' ),
				'channels_checked' => (string) wp_unslash( $_POST['channels_checked'] ?? '' ),
				'result'           => sanitize_key( (string) wp_unslash( $_POST['result'] ?? '' ) ),
				'note'             => (string) wp_unslash( $_POST['note'] ?? '' ),
			)
		);
		// phpcs:enable
		\RBX\Data\Cache\Purge::game( $game );
		$codes = get_page_by_path( $game->folder() . '/codes' );
		if ( $codes ) {
			update_post_meta( $codes->ID, '_rbx_last_substantive', current_time( 'mysql', true ) );
			\RBX\Data\Render\Pages::refresh( $codes );
		}
		Menu::back( admin_url( 'admin.php?page=rbx-codes&game=' . $game->key() ), __( 'Check saved.', 'rbx-data' ) );
	}

	public static function reports(): void {
		self::deny( 'rbx_reports_manage' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( (string) wp_unslash( $_GET['status'] ?? '' ) );
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'Reports', 'rbx-data' ) . '</h1>';
		Menu::notice_from_query();
		echo '<p>' . esc_html__( 'Check each report against the game. A confirmed report leads to a Sheet edit; mark that field as a correction in the next import so it appears on /corrections/. Closed reports are deleted after 180 days.', 'rbx-data' ) . '</p><p>';
		foreach ( array_merge( array( '' ), Queue::STATUSES ) as $s ) {
			echo '<a class="button' . ( $s === $status ? ' button-primary' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=rbx-reports' . ( '' !== $s ? '&status=' . $s : '' ) ) ) . '">' . esc_html( '' === $s ? __( 'All', 'rbx-data' ) : $s ) . '</a> ';
		}
		echo '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'When (UTC)', 'rbx-data' ) . '</th><th>' . esc_html__( 'Page / record', 'rbx-data' ) . '</th><th>' . esc_html__( 'Kind', 'rbx-data' ) . '</th><th>' . esc_html__( 'Report', 'rbx-data' ) . '</th><th>' . esc_html__( 'Status', 'rbx-data' ) . '</th></tr></thead><tbody>';
		foreach ( Queue::list( $status, 200 ) as $r ) {
			$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rbx_report_status"><input type="hidden" name="id" value="' . (int) $r['id'] . '">' . wp_nonce_field( 'rbx_report_status_' . (int) $r['id'], '_wpnonce', true, false ) . '<select name="status">';
			foreach ( Queue::STATUSES as $s ) {
				$form .= '<option value="' . esc_attr( $s ) . '"' . selected( $r['status'], $s, false ) . '>' . esc_html( $s ) . '</option>';
			}
			$form .= '</select> <input name="reason" placeholder="' . esc_attr__( 'Reason', 'rbx-data' ) . '" value="' . esc_attr( (string) $r['reason'] ) . '"> <button class="button">' . esc_html__( 'Save', 'rbx-data' ) . '</button></form>';
			$where = ( '' !== $r['page_url'] ? '<a href="' . esc_url( (string) $r['page_url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) wp_parse_url( (string) $r['page_url'], PHP_URL_PATH ) ) . '</a><br>' : '' ) . '<code>' . esc_html( trim( $r['record_id'] . ' ' . $r['field'] ) ) . '</code>' . ( '' !== $r['shown_value'] ? ' ' . esc_html__( 'showed', 'rbx-data' ) . ' <code>' . esc_html( (string) $r['shown_value'] ) . '</code>' : '' );
			echo '<tr><td>' . esc_html( (string) $r['created_at'] ) . '</td><td>' . $where . '</td><td>' . esc_html( (string) $r['kind'] ) . '</td><td>' . esc_html( (string) $r['text'] ) . ( '' !== $r['update_id'] ? '<br><small>' . esc_html( (string) $r['update_id'] ) . '</small>' : '' ) . '</td><td>' . $form . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
	}

	public static function report_status(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = absint( $_POST['id'] ?? 0 );
		Menu::guard( 'rbx_reports_manage', 'rbx_report_status_' . $id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		Queue::set_status( $id, sanitize_key( (string) wp_unslash( $_POST['status'] ?? '' ) ), (string) wp_unslash( $_POST['reason'] ?? '' ) );
		// phpcs:enable
		Menu::back( admin_url( 'admin.php?page=rbx-reports' ), __( 'Report updated.', 'rbx-data' ) );
	}
}
