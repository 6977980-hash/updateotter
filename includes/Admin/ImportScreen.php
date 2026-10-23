<?php
/**
 * Import screen (ENG-25 item 1, DATA-19..24): sources, fetch + dry run, preview with
 * acknowledgments, AJAX apply with resume, run history with rollback.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Admin;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Config\Tabs;
use RBX\Data\Db\Runs;
use RBX\Data\Import\Runner;
use RBX\Data\Import\Source;

defined( 'ABSPATH' ) || exit;

/**
 * admin.php?page=rbx-import[&game=][&run=]
 */
final class ImportScreen {

	public static function boot(): void {
		add_action( 'admin_post_rbx_sources', array( self::class, 'save_sources' ) );
		add_action( 'admin_post_rbx_fetch', array( self::class, 'fetch' ) );
		add_action( 'admin_post_rbx_upload', array( self::class, 'upload' ) );
		add_action( 'admin_post_rbx_approve', array( self::class, 'approve' ) );
		add_action( 'admin_post_rbx_discard', array( self::class, 'discard' ) );
	}

	private static function url( Game $game, int $run = 0 ): string {
		return admin_url( 'admin.php?page=rbx-import&game=' . $game->key() . ( $run ? '&run=' . $run : '' ) );
	}

	private static function game_from_post(): Game {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by Menu::guard before use.
		$g = Loader::game( sanitize_key( (string) wp_unslash( $_POST['game'] ?? '' ) ) );
		if ( ! $g ) {
			wp_die( esc_html__( 'Unknown game.', 'rbx-data' ) );
		}
		return $g;
	}

	public static function save_sources(): void {
		Menu::guard( 'rbx_import', 'rbx_sources' );
		$game = self::game_from_post();
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$tabs = array();
		foreach ( (array) wp_unslash( $_POST['tabs'] ?? array() ) as $tab => $url ) {
			$url = trim( (string) $url );
			if ( '' !== $url && ! Source::is_google_url( $url ) ) {
				Menu::back( self::url( $game ), __( 'Only docs.google.com publish links are accepted.', 'rbx-data' ), true );
			}
			$tabs[ sanitize_key( (string) $tab ) ] = $url;
		}
		$wb = trim( (string) wp_unslash( $_POST['workbook_url'] ?? '' ) );
		// phpcs:enable
		if ( '' !== $wb && ! Source::is_google_url( $wb ) ) {
			Menu::back( self::url( $game ), __( 'Only docs.google.com publish links are accepted.', 'rbx-data' ), true );
		}
		Source::save_settings(
			$game,
			array(
				'workbook_url' => $wb,
				'tabs'         => $tabs,
			)
		);
		Menu::back( self::url( $game ), __( 'Sheet links saved.', 'rbx-data' ) );
	}

	public static function fetch(): void {
		Menu::guard( 'rbx_import', 'rbx_fetch' );
		$game = self::game_from_post();
		try {
			$input = Source::from_sheet( $game );
		} catch ( \Throwable $e ) {
			Menu::back( self::url( $game ), $e->getMessage(), true );
		}
		$id = Runner::prepare( $game, $input, 'sheet', get_current_user_id() );
		wp_safe_redirect( self::url( $game, $id ) );
		exit;
	}

	public static function upload(): void {
		Menu::guard( 'rbx_import', 'rbx_upload' );
		$game = self::game_from_post();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file array checked below.
		$f = $_FILES['file'] ?? null;
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			Menu::back( self::url( $game ), __( 'Upload failed.', 'rbx-data' ), true );
		}
		try {
			$input = Source::from_file( (string) $f['tmp_name'], sanitize_file_name( (string) $f['name'] ) );
		} catch ( \Throwable $e ) {
			Menu::back( self::url( $game ), $e->getMessage(), true );
		}
		$id = Runner::prepare( $game, $input, 'zip', get_current_user_id() );
		wp_safe_redirect( self::url( $game, $id ) );
		exit;
	}

	public static function approve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run_id = absint( $_POST['run'] ?? 0 );
		Menu::guard( 'rbx_import', 'rbx_approve_' . $run_id );
		$game = self::game_from_post();
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$acks = array();
		foreach ( (array) wp_unslash( $_POST['ack'] ?? array() ) as $class => $reason ) {
			$acks[ sanitize_key( (string) $class ) ] = sanitize_text_field( (string) $reason );
		}
		$large   = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['large'] ?? array() ) );
		$corr    = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['correction'] ?? array() ) );
		$missing = array();
		foreach ( (array) wp_unslash( $_POST['missing'] ?? array() ) as $id => $c ) {
			$missing[ sanitize_text_field( (string) $id ) ] = sanitize_key( (string) $c );
		}
		// phpcs:enable
		$res = Runner::approve( $run_id, $acks, $large, $corr, $missing );
		if ( is_wp_error( $res ) ) {
			Menu::back( self::url( $game, $run_id ), $res->get_error_message(), true );
		}
		Menu::back( self::url( $game, $run_id ) . '&apply=1', __( 'Approved. Applying now…', 'rbx-data' ) );
	}

	public static function discard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run_id = absint( $_POST['run'] ?? 0 );
		Menu::guard( 'rbx_import', 'rbx_discard_' . $run_id );
		$game = self::game_from_post();
		Runner::discard( $run_id );
		Menu::back( self::url( $game ), __( 'Dry run discarded.', 'rbx-data' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'rbx_import' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'rbx-data' ) );
		}
		$game = Menu::current_game();
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'Import game data', 'rbx-data' ) . '</h1>';
		Menu::notice_from_query();
		if ( null === $game ) {
			echo '<p>' . esc_html__( 'No game config found.', 'rbx-data' ) . '</p></div>';
			return;
		}
		echo Menu::game_tabs( 'rbx-import', $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id = absint( $_GET['run'] ?? 0 );
		if ( $run_id ) {
			self::run_view( $game, $run_id );
		} else {
			self::start_view( $game );
		}
		echo '</div>';
	}

	private static function hidden( Game $game, string $action ): string {
		return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="game" value="' . esc_attr( $game->key() ) . '">';
	}

	private static function start_view( Game $game ): void {
		$holder = Runner::lock_holder();
		if ( $holder ) {
			/* translators: %d: run ID */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Run #%d is applying right now. Wait for it to finish (the lock expires after 15 minutes).', 'rbx-data' ), $holder ) ) . '</p></div>';
		}
		$s = Source::settings( $game );
		echo '<h2>' . esc_html__( '1. Fetch from Google Sheets', 'rbx-data' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::hidden( $game, 'rbx_fetch' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_fetch' );
		echo '<p>' . esc_html__( 'Downloads the published tabs, checks every row and shows what would change. Nothing is saved until you approve.', 'rbx-data' ) . '</p>';
		submit_button( __( 'Fetch + dry run', 'rbx-data' ), 'primary', 'submit', false, '' === $s['workbook_url'] && ! $s['tabs'] ? array( 'disabled' => 'disabled' ) : null );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Or upload a file', 'rbx-data' ) . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::hidden( $game, 'rbx_upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_upload' );
		echo '<p><input type="file" name="file" accept=".zip,.xlsx" required> ';
		submit_button( __( 'Upload + dry run', 'rbx-data' ), 'secondary', 'submit', false );
		echo '</p><p class="description">' . esc_html__( 'A .zip of {tab}.csv files, or the whole workbook as .xlsx (File, Download, Microsoft Excel). Max 10 MB.', 'rbx-data' ) . '</p></form>';

		echo '<details class="rbx-box"><summary><strong>' . esc_html__( 'Sheet links (Publish to web)', 'rbx-data' ) . '</strong></summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::hidden( $game, 'rbx_sources' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_sources' );
		echo '<p>' . esc_html__( 'Either one link for the whole workbook (Publish to web, Entire document, Microsoft Excel .xlsx), or one CSV link per tab. Never publish _internal or _README.', 'rbx-data' ) . '</p>';
		echo '<table class="form-table"><tr><th scope="row"><label for="rbx-wb">' . esc_html__( 'Workbook (.xlsx) link', 'rbx-data' ) . '</label></th><td><input id="rbx-wb" class="large-text" type="url" name="workbook_url" value="' . esc_attr( $s['workbook_url'] ) . '"></td></tr>';
		$tabs = array_merge( array( Tabs::META_TAB, Tabs::ENUMS_TAB ), array_keys( $game->tabs() ) );
		foreach ( $tabs as $tab ) {
			echo '<tr><th scope="row"><label for="rbx-tab-' . esc_attr( $tab ) . '">' . esc_html( $tab ) . '</label></th><td><input id="rbx-tab-' . esc_attr( $tab ) . '" class="large-text" type="url" name="tabs[' . esc_attr( $tab ) . ']" value="' . esc_attr( (string) ( $s['tabs'][ $tab ] ?? '' ) ) . '" placeholder="https://docs.google.com/spreadsheets/d/e/…/pub?gid=…&amp;single=true&amp;output=csv"></td></tr>';
		}
		echo '</table>';
		submit_button( __( 'Save links', 'rbx-data' ) );
		echo '</form></details>';

		self::history( $game );
	}

	private static function history( Game $game ): void {
		$runs = Runs::recent( $game->code(), 30 );
		echo '<h2>' . esc_html__( 'Run history', 'rbx-data' ) . '</h2>';
		if ( ! $runs ) {
			echo '<p>' . esc_html__( 'No runs yet.', 'rbx-data' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Started (UTC)', 'rbx-data' ) . '</th><th>' . esc_html__( 'Kind', 'rbx-data' ) . '</th><th>' . esc_html__( 'Status', 'rbx-data' ) . '</th><th>' . esc_html__( 'Changes', 'rbx-data' ) . '</th><th>' . esc_html__( 'Dataset version', 'rbx-data' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $runs as $r ) {
			$c       = $r['counts'];
			$summary = isset( $c['create'] ) ? sprintf( '%d create, %d update, %d unchanged, %d missing, %d errors', $c['create'], $c['update'], $c['unchanged'], $c['missing'], $c['errors'] ) : '';
			$act     = '<a href="' . esc_url( self::url( $game, $r['id'] ) ) . '">' . esc_html__( 'Open', 'rbx-data' ) . '</a>';
			if ( 'applied' === $r['status'] && Runs::has_snapshot( $r['id'] ) ) {
				$act .= ' · <button type="button" class="button-link rbx-rollback" data-run="' . esc_attr( (string) $r['id'] ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'rbx_import_rollback_' . $r['id'] ) ) . '">' . esc_html__( 'Roll back', 'rbx-data' ) . '</button>';
			}
			echo '<tr><td>' . (int) $r['id'] . '</td><td>' . esc_html( (string) $r['started_at'] ) . '</td><td>' . esc_html( (string) $r['kind'] ) . ( $r['rollback_of'] ? ' (#' . (int) $r['rollback_of'] . ')' : '' ) . '</td><td><span class="rbx-status rbx-status--' . esc_attr( (string) $r['status'] ) . '">' . esc_html( (string) $r['status'] ) . '</span></td><td>' . esc_html( $summary ) . '</td><td><code>' . esc_html( (string) $r['dataset_version'] ) . '</code></td><td>' . $act . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table><p class="rbx-live" role="status" id="rbx-rollback-status"></p>';
	}

	private static function run_view( Game $game, int $run_id ): void {
		$run = Runs::get( $run_id );
		if ( ! $run || $run['game'] !== $game->code() ) {
			echo '<p>' . esc_html__( 'Run not found.', 'rbx-data' ) . '</p>';
			return;
		}
		$plan   = $run['plan'];
		$counts = $run['counts'];
		echo '<p><a href="' . esc_url( self::url( $game ) ) . '">&larr; ' . esc_html__( 'Back to import', 'rbx-data' ) . '</a></p>';
		/* translators: 1: run ID, 2: status */
		echo '<h2>' . esc_html( sprintf( __( 'Run #%1$d: %2$s', 'rbx-data' ), $run_id, $run['status'] ) ) . '</h2>';
		if ( ! empty( $plan['max_lv'] ) ) {
			/* translators: %s: date */
			echo '<p class="description">' . esc_html( sprintf( __( 'Newest last_verified in the fetched rows: %s. If this looks old, Google may still be serving a cached copy; wait a few minutes and fetch again.', 'rbx-data' ), $plan['max_lv'] ) ) . '</p>';
		}
		echo '<div class="rbx-counts">';
		foreach ( array( 'create', 'update', 'unchanged', 'missing', 'errors', 'warnings' ) as $k ) {
			echo '<span class="rbx-count rbx-count--' . esc_attr( $k ) . '"><strong>' . (int) ( $counts[ $k ] ?? 0 ) . '</strong> ' . esc_html( $k ) . '</span>';
		}
		echo '</div>';
		if ( ! empty( $counts['tabs'] ) ) {
			echo '<table class="widefat striped rbx-tabcounts"><thead><tr><th>' . esc_html__( 'Tab', 'rbx-data' ) . '</th><th>create</th><th>update</th><th>unchanged</th><th>missing</th><th>errors</th><th>warnings</th></tr></thead><tbody>';
			foreach ( $counts['tabs'] as $tab => $c ) {
				echo '<tr><td>' . esc_html( (string) $tab ) . '</td><td>' . (int) $c['create'] . '</td><td>' . (int) $c['update'] . '</td><td>' . (int) $c['unchanged'] . '</td><td>' . (int) $c['missing'] . '</td><td>' . (int) $c['errors'] . '</td><td>' . (int) $c['warnings'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$issues = (array) ( $plan['issues'] ?? array() );
		if ( $issues ) {
			echo '<h3>' . esc_html__( 'Problems found', 'rbx-data' ) . '</h3><p><label><input type="checkbox" data-rbx-only-errors> ' . esc_html__( 'Show errors only', 'rbx-data' ) . '</label></p><table class="widefat striped rbx-issues"><thead><tr><th>' . esc_html__( 'Level', 'rbx-data' ) . '</th><th>' . esc_html__( 'Tab / row', 'rbx-data' ) . '</th><th>ID</th><th>' . esc_html__( 'Field', 'rbx-data' ) . '</th><th>' . esc_html__( 'What to fix', 'rbx-data' ) . '</th></tr></thead><tbody>';
			foreach ( $issues as $i ) {
				echo '<tr class="rbx-issue rbx-issue--' . esc_attr( (string) $i['sev'] ) . '"><td>' . esc_html( 'error' === $i['sev'] ? __( 'Error', 'rbx-data' ) : __( 'Warning', 'rbx-data' ) ) . ' <code>' . esc_html( (string) $i['class'] ) . '</code></td><td>' . esc_html( (string) $i['tab'] ) . ( $i['row'] ? ' #' . (int) $i['row'] : '' ) . '</td><td>' . esc_html( (string) $i['id'] ) . '</td><td>' . esc_html( (string) $i['field'] ) . '</td><td>' . esc_html( (string) $i['msg'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$tests = (array) ( $plan['tests'] ?? array() );
		if ( $tests ) {
			$fail = array_filter( $tests, static fn( $t ) => ! $t['pass'] );
			/* translators: 1: passed, 2: total */
			echo '<h3>' . esc_html__( 'Formula tests', 'rbx-data' ) . '</h3><p>' . esc_html( sprintf( __( '%1$d of %2$d passed.', 'rbx-data' ), count( $tests ) - count( $fail ), count( $tests ) ) ) . '</p>';
			foreach ( $fail as $t ) {
				echo '<p class="rbx-bad">' . esc_html( (string) $t['message'] ) . '</p>';
			}
		}
		if ( ! empty( $plan['predicted'] ) ) {
			echo '<h3>' . esc_html__( 'Page state changes', 'rbx-data' ) . '</h3><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Now', 'rbx-data' ) . '</th><th>' . esc_html__( 'After apply', 'rbx-data' ) . '</th><th>' . esc_html__( 'Failing rules', 'rbx-data' ) . '</th></tr></thead><tbody>';
			foreach ( $plan['predicted'] as $id => $p ) {
				echo '<tr><td>' . esc_html( (string) $id ) . '</td><td>' . esc_html( (string) $p['old'] ) . '</td><td><strong>' . esc_html( (string) $p['new'] ) . '</strong></td><td>' . esc_html( implode( ', ', (array) ( $p['reasons'] ?? array() ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$ready = 'ready' === $run['status'];
		if ( $ready && empty( $run['acks']['at'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rbx-approve">' . self::hidden( $game, 'rbx_approve' ) . '<input type="hidden" name="run" value="' . (int) $run_id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_nonce_field( 'rbx_approve_' . $run_id );
		}
		self::changes( $plan, $ready && empty( $run['acks']['at'] ) );
		if ( $ready && empty( $run['acks']['at'] ) ) {
			self::missing( $plan );
			if ( ! empty( $plan['warn_classes'] ) ) {
				echo '<h3>' . esc_html__( 'Acknowledge warnings', 'rbx-data' ) . '</h3><p>' . esc_html__( 'Each kind of warning needs a short reason before you can apply.', 'rbx-data' ) . '</p>';
				foreach ( $plan['warn_classes'] as $class ) {
					echo '<p><label>' . esc_html( (string) $class ) . ' <input type="text" class="regular-text" name="ack[' . esc_attr( (string) $class ) . ']" required placeholder="' . esc_attr__( 'Why this is OK', 'rbx-data' ) . '"></label></p>';
				}
			}
			echo '<p>';
			submit_button( __( 'Approve and apply', 'rbx-data' ), 'primary', 'submit', false );
			echo '</p></form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::hidden( $game, 'rbx_discard' ) . '<input type="hidden" name="run" value="' . (int) $run_id . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_nonce_field( 'rbx_discard_' . $run_id );
			submit_button( __( 'Discard dry run', 'rbx-data' ), 'link-delete', 'submit', false );
			echo '</form>';
		}
		if ( in_array( $run['status'], array( 'ready', 'applying' ), true ) && ! empty( $run['acks']['at'] ) ) {
			echo '<div class="rbx-apply" data-run="' . (int) $run_id . '" data-nonce="' . esc_attr( wp_create_nonce( 'rbx_import_apply_' . $run_id ) ) . '" data-auto="1"><p><button type="button" class="button button-primary" data-rbx-apply>' . esc_html( 'applying' === $run['status'] ? __( 'Resume apply', 'rbx-data' ) : __( 'Apply', 'rbx-data' ) ) . '</button></p><progress max="1" value="0"></progress><p class="rbx-live" role="status"></p></div>';
		}
		if ( 'blocked' === $run['status'] ) {
			echo '<p class="rbx-bad"><strong>' . esc_html__( 'Fix the errors in the Sheet, then fetch again. Nothing was saved.', 'rbx-data' ) . '</strong></p>';
		}
	}

	/** @param array<string, mixed> $plan Plan. */
	private static function changes( array $plan, bool $form ): void {
		$ops = (array) ( $plan['ops'] ?? array() );
		if ( ! $ops ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Changes', 'rbx-data' ) . '</h3><p><label><input type="checkbox" data-rbx-only-large> ' . esc_html__( 'Show large changes only', 'rbx-data' ) . '</label></p>';
		$by_tab = array();
		foreach ( $ops as $op ) {
			$by_tab[ (string) $op['tab'] ][] = $op;
		}
		foreach ( $by_tab as $tab => $list ) {
			echo '<details class="rbx-box" open><summary><strong>' . esc_html( $tab ) . '</strong> (' . count( $list ) . ')</summary>';
			foreach ( array_slice( $list, 0, 500 ) as $op ) {
				$large = ! empty( $op['large'] );
				echo '<div class="rbx-op' . ( $large ? ' rbx-op--large' : '' ) . '"><p><strong>' . esc_html( (string) $op['id'] ) . '</strong> ' . esc_html( (string) ( $op['rec']['name'] ?? '' ) ) . ' <span class="rbx-status">' . esc_html( (string) $op['op'] ) . '</span>' . ( ! empty( $op['url'] ) ? ' <a href="' . esc_url( (string) $op['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'page', 'rbx-data' ) . '</a>' : '' ) . '</p>';
				if ( $large && $form ) {
					echo '<p><label class="rbx-bad"><input type="checkbox" name="large[]" value="' . esc_attr( (string) $op['id'] ) . '" required> ' . esc_html__( 'I checked this large change', 'rbx-data' ) . '</label></p>';
				}
				if ( ! empty( $op['diff'] ) ) {
					echo '<table class="widefat rbx-diff"><thead><tr><th>' . esc_html__( 'Field', 'rbx-data' ) . '</th><th>' . esc_html__( 'Old', 'rbx-data' ) . '</th><th>' . esc_html__( 'New', 'rbx-data' ) . '</th><th>Δ%</th>' . ( $form ? '<th>' . esc_html__( 'Correction?', 'rbx-data' ) . '</th>' : '' ) . '</tr></thead><tbody>';
					foreach ( $op['diff'] as $d ) {
						$pct = array_key_exists( 'pct', $d ) ? ( null === $d['pct'] ? '∞' : $d['pct'] . '%' ) : '';
						echo '<tr' . ( ! empty( $d['large'] ) ? ' class="rbx-bad"' : '' ) . '><td><code>' . esc_html( (string) $d['field'] ) . '</code></td><td>' . esc_html( self::show( $d['old'] ) ) . '</td><td>' . esc_html( self::show( $d['new'] ) ) . '</td><td>' . esc_html( $pct ) . '</td>' . ( $form ? '<td><input type="checkbox" name="correction[]" value="' . esc_attr( $op['id'] . ':' . $d['field'] ) . '" aria-label="' . esc_attr__( 'Mark as correction', 'rbx-data' ) . '"></td>' : '' ) . '</tr>';
					}
					echo '</tbody></table>';
				}
				echo '</div>';
			}
			echo '</details>';
		}
	}

	private static function show( mixed $v ): string {
		if ( null === $v ) {
			return '—';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'TRUE' : 'FALSE';
		}
		if ( is_array( $v ) ) {
			return (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return mb_substr( (string) $v, 0, 300 );
	}

	/** @param array<string, mixed> $plan Plan. */
	private static function missing( array $plan ): void {
		$missing = (array) ( $plan['missing'] ?? array() );
		if ( ! $missing ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Missing from the Sheet', 'rbx-data' ) . '</h3><p>' . esc_html__( 'These records are on the site but not in the Sheet. Nothing is deleted. Choose what to do with each one.', 'rbx-data' ) . '</p><table class="widefat striped"><tbody>';
		foreach ( $missing as $m ) {
			$n = 'missing[' . $m['id'] . ']';
			echo '<tr><td><strong>' . esc_html( (string) $m['id'] ) . '</strong> ' . esc_html( (string) $m['name'] ) . '</td><td><label><input type="radio" name="' . esc_attr( $n ) . '" value="ignore" checked> ' . esc_html__( 'Ignore', 'rbx-data' ) . '</label> <label><input type="radio" name="' . esc_attr( $n ) . '" value="removed_from_game"> ' . esc_html__( 'Removed from game (page stays)', 'rbx-data' ) . '</label> <label><input type="radio" name="' . esc_attr( $n ) . '" value="draft"> ' . esc_html__( 'Back to draft (page hidden)', 'rbx-data' ) . '</label></td></tr>';
		}
		echo '</tbody></table>';
	}
}
