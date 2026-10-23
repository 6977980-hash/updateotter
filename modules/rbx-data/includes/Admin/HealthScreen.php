<?php
/**
 * Data health (ENG-25 item 2, DATA-37, REV-G29): environment, cron, page states, exports vs
 * budgets, config, error log, maintenance buttons.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Admin;

use RBX\Data\Codes\CheckLog;
use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Schema;
use RBX\Data\Export\Writer;
use RBX\Data\Gate\PageState;
use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * admin.php?page=rbx-data
 */
final class HealthScreen {

	public static function boot(): void {
		add_action( 'admin_post_rbx_health', array( self::class, 'action' ) );
	}

	public static function action(): void {
		Menu::guard( 'rbx_import', 'rbx_health' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$do   = sanitize_key( (string) wp_unslash( $_POST['do'] ?? '' ) );
		$back = admin_url( 'admin.php?page=rbx-data' );
		switch ( $do ) {
			case 'flush':
				flush_rewrite_rules( false );
				Menu::back( $back, __( 'Permalinks refreshed.', 'rbx-data' ) );
				break;
			case 'exports':
				foreach ( Loader::games() as $g ) {
					Writer::write( $g );
				}
				Menu::back( $back, __( 'Exports and search index regenerated.', 'rbx-data' ) );
				break;
			case 'purge':
				foreach ( Loader::games() as $g ) {
					\RBX\Data\Cache\Purge::game( $g );
				}
				Menu::back( $back, __( 'Cache tags purged.', 'rbx-data' ) );
				break;
			case 'gate':
				foreach ( Loader::games() as $g ) {
					\RBX\Data\Gate\Runner::run( $g );
					\RBX\Data\Render\Pages::refresh_game( $g );
				}
				\RBX\Data\Render\Media::sync();
				Menu::back( $back, __( 'Page states and dates recalculated.', 'rbx-data' ) );
				break;
			case 'clear_log':
				ErrorLog::clear();
				Menu::back( $back, __( 'Error log cleared.', 'rbx-data' ) );
				break;
			case 'backup':
				if ( current_user_can( 'manage_options' ) ) {
					update_option( 'rbx_backup_confirmed', 1, false );
				}
				Menu::back( $back, __( 'Thanks. The database update runs on the next page load.', 'rbx-data' ) );
				break;
		}
		Menu::back( $back, __( 'Unknown action.', 'rbx-data' ), true );
	}

	private static function button( string $do, string $label, string $class = 'secondary' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rbx-inline"><input type="hidden" name="action" value="rbx_health"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . wp_nonce_field( 'rbx_health', '_wpnonce', true, false ) . '<button class="button button-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'rbx_view_health' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'rbx-data' ) );
		}
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'Data health', 'rbx-data' ) . '</h1>';
		Menu::notice_from_query();
		self::environment();
		foreach ( Loader::games() as $game ) {
			self::game( $game );
		}
		$errs = Loader::errors();
		if ( $errs ) {
			echo '<h2 class="rbx-bad">' . esc_html__( 'Game config errors', 'rbx-data' ) . '</h2><ul>';
			foreach ( $errs as $file => $list ) {
				foreach ( $list as $e ) {
					echo '<li><code>' . esc_html( (string) $file ) . '</code>: ' . esc_html( (string) $e ) . '</li>';
				}
			}
			echo '</ul>';
		}
		self::errors();
		if ( current_user_can( 'rbx_import' ) ) {
			echo '<h2>' . esc_html__( 'Maintenance', 'rbx-data' ) . '</h2><p class="rbx-buttons">';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo self::button( 'gate', __( 'Recalculate page states', 'rbx-data' ) );
			echo self::button( 'exports', __( 'Regenerate exports', 'rbx-data' ) );
			echo self::button( 'purge', __( 'Purge rbx cache tags', 'rbx-data' ) );
			echo self::button( 'flush', __( 'Flush rewrites', 'rbx-data' ) );
			if ( Schema::needs_migration() && current_user_can( 'manage_options' ) ) {
				echo self::button( 'backup', __( 'I took a backup, update the database', 'rbx-data' ), 'primary' );
			}
			// phpcs:enable
			echo '</p>';
		}
		echo '</div>';
	}

	private static function environment(): void {
		global $wpdb;
		$rows = array(
			__( 'Environment', 'rbx-data' )        => wp_get_environment_type(),
			__( 'Pre-launch lock', 'rbx-data' )    => get_option( 'rbx_prelaunch_lock', 1 ) ? __( 'ON (visitors see the holding page)', 'rbx-data' ) : __( 'off', 'rbx-data' ),
			__( 'Search engines', 'rbx-data' )     => get_option( 'blog_public' ) ? __( 'allowed', 'rbx-data' ) : __( 'discouraged (Settings, Reading)', 'rbx-data' ),
			'PHP'                                   => PHP_VERSION,
			__( 'Database', 'rbx-data' )           => $wpdb->db_server_info(),
			__( 'Plugin / DB version', 'rbx-data' ) => RBX_DATA_VERSION . ' / ' . get_option( 'rbx_data_db_version', '-' ),
			__( 'Config hash', 'rbx-data' )        => substr( Loader::hash(), 0, 12 ),
			'Rank Math'                             => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : __( 'not active', 'rbx-data' ),
			'LiteSpeed Cache'                       => defined( 'LSCWP_V' ) ? LSCWP_V : __( 'not active', 'rbx-data' ),
			__( 'Object cache', 'rbx-data' )       => wp_using_ext_object_cache() ? __( 'yes', 'rbx-data' ) : __( 'no', 'rbx-data' ),
			'IndexNow'                              => \RBX\Data\Index\IndexNow::enabled() ? __( 'on', 'rbx-data' ) : __( 'off (local/development install or pre-launch lock on)', 'rbx-data' ),
		);
		foreach ( array( 'rbx_data_nightly_gate', 'rbx_data_prune', 'rbx_notifier_poll' ) as $hook ) {
			$next                      = wp_next_scheduled( $hook );
			$rows[ 'Cron: ' . $hook ] = $next ? gmdate( 'Y-m-d H:i', $next ) . ' UTC' : __( 'not scheduled', 'rbx-data' );
		}
		echo '<table class="widefat striped rbx-kv"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th scope="row">' . esc_html( (string) $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function game( Game $game ): void {
		$ds = Repository::dataset( $game );
		echo '<h2>' . esc_html( $game->name() ) . ' <code>' . esc_html( Repository::version( $game ) ) . '</code></h2>';
		$states = array(
			PageState::INDEX         => 0,
			PageState::NOINDEX       => 0,
			PageState::NOT_GENERATED => 0,
		);
		$reasons = array();
		$stale   = array();
		$cut     = gmdate( 'Y-m-d', (int) strtotime( current_time( 'Y-m-d' ) . ' -' . $game->freshness_days() . ' days' ) );
		foreach ( $game->page_entities() as $e ) {
			foreach ( $ds->all( $e, null ) as $r ) {
				$pid = (int) ( $r['post_id'] ?? 0 );
				$st  = $pid ? (string) get_post_meta( $pid, '_rbx_page_state', true ) : '';
				$st  = '' === $st ? PageState::NOT_GENERATED : $st;
				++$states[ $st ];
				$rs = $pid ? json_decode( (string) get_post_meta( $pid, '_rbx_gate_reasons', true ), true ) : array( 'G' );
				foreach ( (array) $rs as $x ) {
					$reasons[ (string) $x ] = ( $reasons[ (string) $x ] ?? 0 ) + 1;
				}
				$lv = (string) ( $r['data']['last_verified'] ?? '' );
				if ( '' !== $lv && $lv < $cut && in_array( $r['status'], array( 'live', 'limited' ), true ) ) {
					$stale[] = $r['id'] . ' ' . $r['name'] . ' (' . $lv . ')';
				}
			}
		}
		$counts = array();
		foreach ( $game->entities() as $code => $def ) {
			$counts[] = $game->entity_label( $code, true ) . ': ' . $ds->count( $code );
		}
		echo '<p>' . esc_html( implode( ' · ', $counts ) ) . '</p>';
		/* translators: 1: index, 2: noindex, 3: not generated */
		echo '<p>' . esc_html( sprintf( __( 'Pages: %1$d index, %2$d noindex, %3$d not generated.', 'rbx-data' ), $states['index'], $states['noindex'], $states['not_generated'] ) ) . '</p>';
		if ( $reasons ) {
			arsort( $reasons );
			$txt = array();
			foreach ( $reasons as $k => $n ) {
				$txt[] = $k . ' × ' . $n;
			}
			echo '<p>' . esc_html__( 'Most common reasons a page is not indexable:', 'rbx-data' ) . ' <code>' . esc_html( implode( ', ', array_slice( $txt, 0, 10 ) ) ) . '</code></p>';
		}
		if ( $stale ) {
			/* translators: %d: count */
			echo '<details><summary>' . esc_html( sprintf( __( '%d records need re-verification', 'rbx-data' ), count( $stale ) ) ) . '</summary><ul><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $stale, 0, 100 ) ) ) . '</li></ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$days = CheckLog::days_since( $game );
		if ( $game->entity( 'COD' ) ) {
			$cls = null === $days || $days > 7 ? 'rbx-bad' : '';
			/* translators: %s: days */
			echo '<p class="' . esc_attr( $cls ) . '">' . esc_html( null === $days ? __( 'Codes: never checked. Add a check on the Codes screen.', 'rbx-data' ) : sprintf( __( 'Codes: last checked %s days ago.', 'rbx-data' ), $days ) ) . '</p>';
		}
		$info = Writer::info( $game );
		if ( ! empty( $info['sizes'] ) ) {
			$parts = array();
			foreach ( $info['sizes'] as $slice => $bytes ) {
				$parts[] = $slice . ' ' . size_format( (int) $bytes, 1 ) . ( $bytes > Writer::SLICE_BUDGET ? ' ⚠' : '' );
			}
			echo '<p>' . esc_html__( 'Export slices (gzipped, budget 40 KB each):', 'rbx-data' ) . ' ' . esc_html( implode( ', ', $parts ) ) . '</p>';
		}
	}

	private static function errors(): void {
		$log = array_reverse( ErrorLog::all() );
		echo '<h2>' . esc_html__( 'Error log', 'rbx-data' ) . '</h2>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No errors logged.', 'rbx-data' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'rbx-data' ) . '</th><th>' . esc_html__( 'Area', 'rbx-data' ) . '</th><th>' . esc_html__( 'Message', 'rbx-data' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 50 ) as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['t'] ) . '</td><td>' . esc_html( (string) $r['area'] ) . '</td><td>' . esc_html( (string) $r['msg'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( current_user_can( 'rbx_import' ) ) {
			echo self::button( 'clear_log', __( 'Clear log', 'rbx-data' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
