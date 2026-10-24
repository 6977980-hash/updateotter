<?php
/**
 * Launch steps through git (Phase 1c, owner rule 2026-10-23: no manual steps, everything ships
 * from main). `content/launch.json` names two one-time steps, each keyed by an id:
 *
 * - `publish`: publishes every bundled Page (content/pages) that is still a draft. A Page that
 *   still has a Phase 1b todo box or fails the number lint stays a draft and is reported. Guides
 *   queued in content/guides.json are skipped: they go live one a week (RBX\Data\Content\Guides).
 * - `go_live`: turns the pre-launch lock off and "Discourage search engines" off in the same
 *   step (P1-45), flushes rewrite rules and purges the page cache.
 *
 * - `purge`: purges the whole page cache once. Change its id in a PR whose deploy changes
 *   output on cached pages (head tags, robots.txt, sitemaps).
 * - `llms`: deletes the orphan static /llms.txt a Hostinger plugin once wrote into the site root
 *   (it lists "Hello world!"), so the theme's generated /llms.txt is served (audit UO-21). Only a
 *   file with that plugin's signature is deleted.
 * - `indexnow`: sends the home, hub, tool, list, codes and guide URLs to IndexNow once and keeps
 *   the answer code in the step state (audit UO-22).
 * - `search_index`: rebuilds the header search index file once (audit UO-31), for changes to
 *   what goes into it that come with code rather than with a data import.
 * - `dates`: recomputes every page's "content changed" time once (GR-14), for changes to how
 *   that time is worked out. A Page with no text-change time yet gets its modified time. Runs
 *   before `purge`, so the purged pages show the new dates.
 *
 * - `gate`: re-runs the index gate for every game once (GR-15), for changes to the gate rules or
 *   to a game config's index scope that come with code rather than with a data import. Purges
 *   the cached pages of a game whose page states changed and rebuilds the search index.
 * - `db_error`: copies inc/dropins/db-error.php to wp-content/db-error.php (GR-02), so a lost
 *   database connection answers 503 + Retry-After instead of WordPress's 500 page. A different
 *   db-error.php already there (not ours) is left alone.
 *
 * `purge` empties the page cache only, marked stale, and then warms the pages in search one at a
 * time through WP-Cron (RBX\Data\Cache\Purge::pages(), Warm). It no longer calls
 * `litespeed_purge_all`, which also emptied the object cache and reset OPcache on every deploy
 * (the CPU spikes Ali saw on 24 Oct).
 *
 * A step runs once per id, on the first request after the deploy. Turning the lock back on in
 * RBX Data → Setup is not undone by a later request. Status: GET /wp-json/rbx/v1/launch.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const UPDATEOTTER_LAUNCH_OPTION = 'updateotter_launch';

/** @return array<string, string> step => id */
function updateotter_launch_plan(): array {
	$file = get_template_directory() . '/content/launch.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$plan = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_array( $plan ) ) {
		return array();
	}
	$out = array();
	foreach ( array( 'publish', 'go_live', 'search_index', 'dates', 'gate', 'purge', 'llms', 'favicon', 'db_error', 'indexnow' ) as $step ) {
		if ( isset( $plan[ $step ] ) && is_string( $plan[ $step ] ) && '' !== $plan[ $step ] ) {
			$out[ $step ] = $plan[ $step ];
		}
	}
	return $out;
}

/**
 * GR-14: re-runs the gate dates for item pages and the page dates for every game's Pages.
 *
 * @return array{games: int, seeded: int}
 */
function updateotter_launch_dates(): array {
	$seeded = 0;
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $page ) {
		if ( '' === (string) get_post_meta( $page->ID, \RBX\Data\Content\BundledPages::CHANGED, true ) ) {
			update_post_meta( $page->ID, \RBX\Data\Content\BundledPages::CHANGED, $page->post_modified_gmt );
			++$seeded;
		}
	}
	$games = 0;
	foreach ( \RBX\Data\Config\Loader::games() as $game ) {
		\RBX\Data\Gate\Runner::run( $game );
		++$games;
	}
	// Every Page, including /id/ twins and pages outside a game folder.
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $page ) {
		\RBX\Data\Render\Pages::refresh( $page );
	}
	return array(
		'games'  => $games,
		'seeded' => $seeded,
	);
}

/**
 * GR-15: re-runs the index gate for every game; purges a game's pages when a page state changed.
 *
 * @return array<string, int> game => pages whose state changed
 */
function updateotter_launch_gate(): array {
	$out = array();
	foreach ( \RBX\Data\Config\Loader::games() as $game ) {
		$changed = array_keys( array_filter( \RBX\Data\Gate\Runner::run( $game ), static fn( array $t ): bool => $t['old'] !== $t['new'] ) );
		$out[ $game->key() ] = count( $changed );
		if ( $changed ) {
			\RBX\Data\Cache\Purge::after_run( $game, $changed );
		}
	}
	if ( array_sum( $out ) && class_exists( '\RBX\Data\Export\SearchIndex' ) ) {
		\RBX\Data\Export\SearchIndex::write();
	}
	return $out;
}

/**
 * GR-02: installs the 503 db-error drop-in. Returns what happened.
 */
function updateotter_launch_db_error(): string {
	$src  = get_template_directory() . '/inc/dropins/db-error.php';
	$dest = WP_CONTENT_DIR . '/db-error.php';
	if ( ! is_readable( $src ) ) {
		return 'no source';
	}
	if ( is_readable( $dest ) ) {
		$old = (string) file_get_contents( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( md5( $old ) === md5_file( $src ) ) {
			return 'up to date';
		}
		if ( ! str_contains( $old, 'UpdateOtter db-error drop-in' ) ) {
			return 'kept: another db-error.php is installed';
		}
	}
	return copy( $src, $dest ) ? 'copied' : 'copy failed'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
}

/**
 * LiteSpeed Cache settings that decide how deploys and crawlers load the server (GR-02, GR-23).
 * Read-only: shown on the launch status so they can be checked without an admin login.
 *
 * @return array<string, mixed>
 */
function updateotter_cache_settings(): array {
	if ( ! defined( 'LSCWP_V' ) ) {
		return array( 'litespeed' => false );
	}
	$opt = static fn( string $id ) => get_option( 'litespeed.conf.' . $id, null );
	$ua  = $opt( 'cache-exc_useragents' );
	return array(
		'litespeed'           => LSCWP_V,
		'cache'               => (bool) $opt( 'cache' ),
		'purge_stale'         => (bool) $opt( 'purge-stale' ),
		'exclude_user_agents' => is_array( $ua ) ? array_values( $ua ) : (string) $ua,
		'crawler'             => (bool) $opt( 'crawler' ),
		'object_cache'        => (bool) $opt( 'object' ),
		'cache_mobile'        => (bool) $opt( 'cache-mobile' ),
		'ttl_pub'             => (int) $opt( 'cache-ttl_pub' ),
		'purge_upgrade'       => (bool) $opt( 'purge-upgrade' ),
	);
}

/** @return array<string, mixed> */
function updateotter_launch_state(): array {
	$s = get_option( UPDATEOTTER_LAUNCH_OPTION, array() );
	return is_array( $s ) ? $s : array();
}

/**
 * Publishes the bundled Pages that are still drafts.
 *
 * @return array{published: array<int, string>, kept_draft: array<string, string>}
 */
function updateotter_launch_publish(): array {
	$published = array();
	$kept      = array();
	if ( ! class_exists( '\RBX\Data\Content\BundledPages' ) ) {
		return array(
			'published'  => $published,
			'kept_draft' => array( '*' => 'RBX Data module not loaded' ),
		);
	}
	foreach ( array_keys( \RBX\Data\Content\BundledPages::files() ) as $path ) {
		$front = (int) get_option( 'page_on_front' );
		$page  = 'home' === $path && $front ? get_post( $front ) : get_page_by_path( $path, OBJECT, 'page' );
		if ( ! $page instanceof WP_Post ) {
			$kept[ $path ] = 'no such page';
			continue;
		}
		if ( 'publish' === $page->post_status ) {
			continue;
		}
		// Queued guides go live one a week through RBX\Data\Content\Guides, not here.
		if ( class_exists( '\RBX\Data\Content\Guides' ) && \RBX\Data\Content\Guides::held( $path ) ) {
			continue;
		}
		if ( str_contains( (string) $page->post_content, 'rbx-todo' ) ) {
			$kept[ $path ] = 'still has a Phase 1b todo box';
			continue;
		}
		if ( class_exists( '\RBX\Data\Lint\NumberLint' ) ) {
			$issues = \RBX\Data\Lint\NumberLint::check( (string) $page->post_content, (string) $page->post_title, (string) $page->post_excerpt );
			if ( $issues ) {
				$kept[ $path ] = 'lint: ' . implode( ', ', array_map( static fn( array $i ): string => $i['rule'] . ' "' . $i['match'] . '"', $issues ) );
				continue;
			}
		}
		// wp_publish_post() changes the status only, so the trusted repo markup is not re-filtered.
		wp_publish_post( $page );
		$published[] = $path;
	}
	return array(
		'published'  => $published,
		'kept_draft' => $kept,
	);
}

function updateotter_launch_go_live(): void {
	update_option( 'rbx_prelaunch_lock', 0, true );
	update_option( 'blog_public', 1 );
	flush_rewrite_rules( false );
	do_action( 'litespeed_purge_all' );
}

add_action(
	'init',
	static function (): void {
		$plan  = updateotter_launch_plan();
		$state = updateotter_launch_state();
		$todo  = array_filter( $plan, static fn( string $id, string $step ): bool => ( $state[ $step ]['id'] ?? '' ) !== $id, ARRAY_FILTER_USE_BOTH );
		if ( ! $todo ) {
			return;
		}
		// Only a normal front-end page view runs the steps: LiteSpeed sends its purge header with
		// page responses, not with REST, cron, AJAX or admin requests, so a purge there is lost and
		// pages cached mid-deploy stay stale (seen 2026-10-23 after the phase 2 deploys).
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || str_contains( $uri, '/wp-json/' ) || str_contains( $uri, 'rest_route=' ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		// One attempt per 5 minutes; parallel first requests and retries skip.
		if ( get_transient( 'updateotter_launch_wait' ) ) {
			return;
		}
		set_transient( 'updateotter_launch_wait', 1, 5 * MINUTE_IN_SECONDS );
		if ( isset( $todo['publish'] ) ) {
			$r                = updateotter_launch_publish();
			$state['publish'] = array(
				// A Page kept as a draft leaves the step open, so the next attempt tries again.
				'id'      => $r['kept_draft'] ? '' : $todo['publish'],
				'pending' => $todo['publish'],
				'at'      => gmdate( 'Y-m-d H:i:s' ),
			) + $r;
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		// Rebuilds the header search index when its contents changed in code, not only after an import (UO-31).
		if ( isset( $todo['search_index'] ) && class_exists( '\RBX\Data\Export\SearchIndex' ) ) {
			$state['search_index'] = array(
				'id'   => $todo['search_index'],
				'at'   => gmdate( 'Y-m-d H:i:s' ),
				'file' => \RBX\Data\Export\SearchIndex::write(),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['dates'] ) && class_exists( '\RBX\Data\Gate\Runner' ) ) {
			$state['dates'] = array(
				'id' => $todo['dates'],
				'at' => gmdate( 'Y-m-d H:i:s' ),
			) + updateotter_launch_dates();
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['gate'] ) && class_exists( '\RBX\Data\Gate\Runner' ) ) {
			$state['gate'] = array(
				'id'      => $todo['gate'],
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'changed' => updateotter_launch_gate(),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['purge'] ) ) {
			if ( class_exists( '\RBX\Data\Cache\Purge' ) ) {
				\RBX\Data\Cache\Purge::pages();
			} else {
				do_action( 'litespeed_purge_all' );
			}
			$state['purge'] = array(
				'id' => $todo['purge'],
				'at' => gmdate( 'Y-m-d H:i:s' ),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['llms'] ) ) {
			$file = ABSPATH . 'llms.txt';
			$old  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$gone = '' === $old;
			if ( ! $gone && ( str_contains( $old, 'Hello world' ) || str_contains( $old, 'Hostinger Tools' ) ) ) {
				wp_delete_file( $file );
				$gone = ! file_exists( $file );
			}
			$state['llms'] = array(
				'id'      => $todo['llms'],
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'removed' => '' !== $old && $gone,
				'static'  => file_exists( $file ),
			);
			if ( class_exists( '\RBX\Data\Index\Llms' ) ) {
				\RBX\Data\Index\Llms::flush();
			}
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['favicon'] ) ) {
			// The server answers /favicon.ico itself (static 404) before WordPress runs, so the theme's copy goes into the site root.
			$src  = get_template_directory() . '/root-files/favicon.ico';
			$dest = ABSPATH . 'favicon.ico';
			if ( is_readable( $src ) && ( ! is_readable( $dest ) || md5_file( $src ) !== md5_file( $dest ) ) ) {
				copy( $src, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			}
			$state['favicon'] = array(
				'id'     => $todo['favicon'],
				'at'     => gmdate( 'Y-m-d H:i:s' ),
				'copied' => is_readable( $dest ) && md5_file( $src ) === md5_file( $dest ),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['db_error'] ) ) {
			$state['db_error'] = array(
				'id'     => $todo['db_error'],
				'at'     => gmdate( 'Y-m-d H:i:s' ),
				'result' => updateotter_launch_db_error(),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		if ( isset( $todo['indexnow'] ) && class_exists( '\RBX\Data\Index\IndexNow' ) && \RBX\Data\Index\IndexNow::enabled() ) {
			$urls              = \RBX\Data\Index\IndexNow::site_urls();
			$sent              = \RBX\Data\Index\IndexNow::submit( $urls );
			$log               = (array) get_option( 'rbx_indexnow_log', array() );
			$state['indexnow'] = array(
				'id'   => $todo['indexnow'],
				'at'   => gmdate( 'Y-m-d H:i:s' ),
				'urls' => count( $urls ),
				'sent' => $sent,
				'code' => (int) ( end( $log )['code'] ?? 0 ),
				'key'  => home_url( '/' . \RBX\Data\Index\IndexNow::key() . '.txt' ),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
		// Go-live waits until every Page is published, so the site never opens with missing pages.
		if ( isset( $todo['go_live'] ) && ( $state['publish']['id'] ?? '' ) !== '' && ( $plan['publish'] ?? '' ) === $state['publish']['id'] ) {
			updateotter_launch_go_live();
			$state['go_live'] = array(
				'id' => $todo['go_live'],
				'at' => gmdate( 'Y-m-d H:i:s' ),
			);
			update_option( UPDATEOTTER_LAUNCH_OPTION, $state, false );
		}
	},
	30 // After BundledPages (21) and the post types.
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'rbx/v1',
			'/launch',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): WP_REST_Response {
					$state = updateotter_launch_state();
					// UO-60: without login only each step's id and time; the details are for admins.
					if ( ! current_user_can( 'manage_options' ) ) {
						$state = array_map(
							static fn( $s ) => is_array( $s ) ? array_intersect_key( $s, array_flip( array( 'id', 'at' ) ) ) : $s,
							$state
						);
					}
					return new WP_REST_Response(
						array(
							'plan'          => updateotter_launch_plan(),
							'state'         => $state,
							'locked'        => updateotter_locked(),
							'blog_public'   => (bool) get_option( 'blog_public' ),
							'page_on_front' => (int) get_option( 'page_on_front' ),
							'cache'         => updateotter_cache_settings(),
							'warm'          => class_exists( '\RBX\Data\Cache\Warm' ) ? \RBX\Data\Cache\Warm::summary() : array(),
							'db_error'      => is_readable( WP_CONTENT_DIR . '/db-error.php' ),
						)
					);
				},
			)
		);
	}
);
