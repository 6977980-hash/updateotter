<?php
/**
 * Launch steps through git (Phase 1c, owner rule 2026-10-23: no manual steps, everything ships
 * from main). `content/launch.json` names two one-time steps, each keyed by an id:
 *
 * - `publish`: publishes every bundled Page (content/pages) that is still a draft. A Page that
 *   still has a Phase 1b todo box or fails the number lint stays a draft and is reported.
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
	foreach ( array( 'publish', 'go_live', 'purge', 'llms', 'indexnow' ) as $step ) {
		if ( isset( $plan[ $step ] ) && is_string( $plan[ $step ] ) && '' !== $plan[ $step ] ) {
			$out[ $step ] = $plan[ $step ];
		}
	}
	return $out;
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
		if ( isset( $todo['purge'] ) ) {
			do_action( 'litespeed_purge_all' );
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
					return new WP_REST_Response(
						array(
							'plan'          => updateotter_launch_plan(),
							'state'         => updateotter_launch_state(),
							'locked'        => updateotter_locked(),
							'blog_public'   => (bool) get_option( 'blog_public' ),
							'page_on_front' => (int) get_option( 'page_on_front' ),
						)
					);
				},
			)
		);
	}
);
