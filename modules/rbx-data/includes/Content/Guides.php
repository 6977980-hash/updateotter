<?php
/**
 * Guide queue (owner decision 2026-10-24): evergreen guides are written ahead as drafts and go
 * live one a week. `content/guides.json` lists each game's guides in order:
 *
 *     {"steal-an-egg": {"start": "2026-10-31", "time": "06:00", "guides": [
 *         {"slug": "speed-guide", "label": "...", "summary": "...", "queue": true,
 *          "reviewed_by": "", "reviewed_on": "", "facts": [...]}]}}
 *
 * - A guide with `"queue": true` stays a draft: the `publish` launch step skips it.
 * - Every week from `start` (`time` UTC) one slot opens. The first queued guide in list order
 *   that the owner reviewed (`reviewed_by` set) is published in that slot. A week with no
 *   reviewed guide publishes nothing, and slots are never caught up, so it stays one a week.
 * - Before publishing, the copy is checked again: the latest file must be synced into the Page,
 *   the number lint must pass, and every [rbx_value] must resolve from the current data. A
 *   failed check keeps the guide a draft and is logged; the next reviewed guide is tried.
 * - Links to a guide that is not live render as plain text, so a draft never leaks a dead
 *   link, and an older guide links to a newer one by itself once it goes live.
 * - [uo_guides] lists the live guides of a game (hub page, "More guides" blocks).
 *
 * `facts` are checked in CI by tools/guides/check.py against the CSVs, not here.
 * Status: GET /wp-json/rbx/v1/guides.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Content;

use RBX\Data\Log\ErrorLog;

defined( 'ABSPATH' ) || exit;

/**
 * State in option `rbx_guide_queue`: slots (game => last slot used), log (last 20 entries).
 */
final class Guides {

	public const OPTION = 'rbx_guide_queue';
	public const WEEK   = 7 * 86400;

	/** Guide links: optional own host, then /{game}/guides/{slug}/. */
	private const LINK_RE = '#<a\s[^>]*?href="(?:https?://([^/"]+))?/((?:id/)?[a-z0-9-]+/guides/[a-z0-9-]+)/?(?:\#[^"]*)?"[^>]*>(.*?)</a>#s';

	/** @var array<string, bool> */
	private static array $live = array();

	public static function boot(): void {
		add_filter( 'the_content', array( self::class, 'filter_content' ), 20 );
		add_shortcode( 'uo_guides', array( self::class, 'list_sc' ) );
		add_action( 'init', array( self::class, 'maybe_publish' ), 31 ); // After the launch steps (30).
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/** @return array<string, array<string, mixed>> */
	public static function config(): array {
		$file = (string) apply_filters( 'rbx_data_guides_file', get_template_directory() . '/content/guides.json' );
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$cfg = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $cfg ) ? array_filter( $cfg, 'is_array' ) : array();
	}

	/** Entry for a page path such as `steal-an-egg/guides/speed-guide`, or null. @return array<string, mixed>|null */
	public static function entry( string $path, ?array $cfg = null ): ?array {
		if ( ! preg_match( '#^([a-z0-9-]+)/guides/([a-z0-9-]+)$#', $path, $m ) ) {
			return null;
		}
		foreach ( (array) ( ( $cfg ?? self::config() )[ $m[1] ]['guides'] ?? array() ) as $g ) {
			if ( is_array( $g ) && ( $g['slug'] ?? '' ) === $m[2] ) {
				return $g;
			}
		}
		return null;
	}

	/** True when the launch `publish` step must leave this page alone (a queued guide). */
	public static function held( string $path ): bool {
		return ! empty( self::entry( $path )['queue'] );
	}

	public static function is_live( string $path ): bool {
		if ( ! isset( self::$live[ $path ] ) ) {
			$p                    = get_page_by_path( $path, OBJECT, 'page' );
			self::$live[ $path ] = $p instanceof \WP_Post && 'publish' === $p->post_status;
		}
		return self::$live[ $path ];
	}

	/**
	 * Unwraps links to guides that are not live. Pure: `$live` answers for a page path.
	 *
	 * @param callable(string): bool $live Is the page at this path published.
	 */
	public static function unlink( string $html, callable $live, string $host ): string {
		if ( ! str_contains( $html, '/guides/' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			self::LINK_RE,
			static function ( array $m ) use ( $live, $host ): string {
				if ( '' !== $m[1] && strtolower( $m[1] ) !== strtolower( $host ) ) {
					return $m[0];
				}
				return $live( $m[2] ) ? $m[0] : $m[3];
			},
			$html
		);
	}

	public static function filter_content( string $html ): string {
		return self::unlink( $html, array( self::class, 'is_live' ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * [uo_guides game="steal-an-egg" heading="More guides"] lists the live guides in list order.
	 * The current page is left out. Nothing renders (not even the heading) when none are live.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function list_sc( $atts ): string {
		$a    = shortcode_atts(
			array(
				'game'    => '',
				'heading' => '',
			),
			(array) $atts
		);
		$game = sanitize_key( (string) $a['game'] );
		$cur  = get_post();
		$li   = '';
		foreach ( (array) ( self::config()[ $game ]['guides'] ?? array() ) as $g ) {
			$path = $game . '/guides/' . (string) ( $g['slug'] ?? '' );
			$page = get_page_by_path( $path, OBJECT, 'page' );
			if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status || ( $cur && $cur->ID === $page->ID ) ) {
				continue;
			}
			$label = (string) ( $g['label'] ?? '' ) ?: get_the_title( $page );
			$sum   = (string) ( $g['summary'] ?? '' );
			$li   .= '<li><a href="' . esc_url( (string) get_permalink( $page ) ) . '"><strong>' . esc_html( $label ) . '</strong></a>' . ( '' !== $sum ? '. ' . esc_html( rtrim( $sum, '.' ) ) . '.' : '' ) . '</li>';
		}
		if ( '' === $li ) {
			return '';
		}
		$h = '' !== $a['heading'] ? '<h2 class="wp-block-heading" id="more-guides">' . esc_html( (string) $a['heading'] ) . '</h2>' : '';
		return $h . '<ul class="wp-block-list uo-guide-list">' . $li . '</ul>';
	}

	/** Index of the weekly slot at `$now`, or -1 before the first one. */
	public static function slot( string $start, string $time, int $now ): int {
		$t0 = strtotime( $start . ' ' . ( '' !== $time ? $time : '06:00' ) . ':00 UTC' );
		if ( false === $t0 || $now < $t0 ) {
			return -1;
		}
		return intdiv( $now - $t0, self::WEEK );
	}

	/**
	 * Queued guides that may go live in this game's open slot, in order. Pure.
	 *
	 * @param array<string, mixed>   $game_cfg One game's entry of guides.json.
	 * @param callable(string): bool $live     Is this slug live already.
	 * @return array<int, string> Slugs.
	 */
	public static function candidates( array $game_cfg, int $last_slot, int $now, callable $live ): array {
		$slot = self::slot( (string) ( $game_cfg['start'] ?? '' ), (string) ( $game_cfg['time'] ?? '' ), $now );
		if ( $slot < 0 || $slot <= $last_slot ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( $game_cfg['guides'] ?? array() ) as $g ) {
			$slug = (string) ( $g['slug'] ?? '' );
			if ( '' === $slug || empty( $g['queue'] ) || '' === trim( (string) ( $g['reviewed_by'] ?? '' ) ) || $live( $slug ) ) {
				continue;
			}
			$out[] = $slug;
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public static function state(): array {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Why a queued guide may not go live now, or '' when it may. */
	public static function blocker( string $path ): string {
		$page = get_page_by_path( $path, OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post ) {
			return 'no such page yet (bundled copy not synced)';
		}
		$file = BundledPages::files()[ $path ] ?? '';
		if ( '' === $file || ( BundledPages::state()['hash'] ?? '' ) !== BundledPages::hash() ) {
			return 'latest copy not synced yet';
		}
		$body = (string) $page->post_content;
		if ( '' === trim( $body ) ) {
			return 'page is empty';
		}
		if ( class_exists( '\RBX\Data\Lint\NumberLint' ) ) {
			$issues = \RBX\Data\Lint\NumberLint::check( $body, (string) $page->post_title, (string) $page->post_excerpt );
			if ( $issues ) {
				return 'lint: ' . implode( ', ', array_map( static fn( array $i ): string => $i['rule'] . ' "' . $i['match'] . '"', $issues ) );
			}
		}
		// Numbers are re-read from today's data: every value token must still resolve.
		if ( preg_match_all( '/\[rbx_value\s+([^\]]*)\]/', $body, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$at = shortcode_parse_atts( $m[1] );
				$v  = \RBX\Data\Render\Shortcodes::lookup( (string) ( $at['id'] ?? '' ), sanitize_key( (string) ( $at['field'] ?? '' ) ), (string) ( $at['format'] ?? '' ) );
				if ( null === $v || '' === $v['text'] || '' === $v['exact'] && preg_match( '/^(?:not confirmed yet|conflicting reports)$/', $v['text'] ) ) {
					return 'value ' . trim( $m[1] ) . ' does not resolve';
				}
			}
		}
		return '';
	}

	/** Publishes a draft Page with today's date, without the kses pass of wp_update_post(). */
	private static function go_live( \WP_Post $page ): void {
		global $wpdb;
		$gmt = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array(
				'post_date'         => get_date_from_gmt( $gmt ),
				'post_date_gmt'     => $gmt,
				'post_modified'     => get_date_from_gmt( $gmt ),
				'post_modified_gmt' => $gmt,
			),
			array( 'ID' => $page->ID )
		);
		clean_post_cache( $page->ID );
		update_post_meta( $page->ID, BundledPages::CHANGED, $gmt );
		wp_publish_post( $page->ID );
	}

	public static function maybe_publish(): void {
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// Front-end GET only, like the launch steps: LiteSpeed drops a purge sent from REST, cron or admin.
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || str_contains( $uri, '/wp-json/' ) || str_contains( $uri, 'rest_route=' ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		if ( get_transient( 'rbx_guides_wait' ) ) {
			return;
		}
		set_transient( 'rbx_guides_wait', 1, 10 * MINUTE_IN_SECONDS );
		$state = self::state();
		$done  = false;
		foreach ( self::config() as $game => $gc ) {
			$last = (int) ( $state['slots'][ $game ] ?? -1 );
			$now  = time();
			foreach ( self::candidates( $gc, $last, $now, static fn( string $slug ): bool => self::is_live( $game . '/guides/' . $slug ) ) as $slug ) {
				$path = $game . '/guides/' . $slug;
				$why  = self::blocker( $path );
				if ( '' !== $why ) {
					$state['blocked'][ $path ] = $why;
					ErrorLog::add( 'content', 'Queued guide ' . $path . ' not published: ' . $why );
					continue;
				}
				$page = get_page_by_path( $path, OBJECT, 'page' );
				self::go_live( $page );
				unset( $state['blocked'][ $path ] );
				$state['slots'][ $game ] = self::slot( (string) ( $gc['start'] ?? '' ), (string) ( $gc['time'] ?? '' ), $now );
				$state['log'][]          = array(
					'path' => $path,
					'at'   => gmdate( 'Y-m-d H:i:s', $now ),
					'slot' => $state['slots'][ $game ],
				);
				$done = true;
				break;
			}
		}
		if ( isset( $state['log'] ) ) {
			$state['log'] = array_slice( $state['log'], -20 );
		}
		$state['checked'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION, $state, false );
		if ( $done ) {
			self::$live = array();
			if ( class_exists( '\RBX\Data\Export\SearchIndex' ) ) {
				\RBX\Data\Export\SearchIndex::write();
			}
			// Hub list, "More guides" blocks and links in older guides change with the new guide.
			\RBX\Data\Cache\Purge::pages();
		}
	}

	/** Next opening and the queue, for the status route. @return array<string, mixed> */
	public static function status(): array {
		$out   = array();
		$state = self::state();
		foreach ( self::config() as $game => $gc ) {
			$start = (string) ( $gc['start'] ?? '' );
			$time  = (string) ( $gc['time'] ?? '' ) ?: '06:00';
			$slot  = self::slot( $start, $time, time() );
			$t0    = strtotime( $start . ' ' . $time . ':00 UTC' );
			$q     = array();
			foreach ( (array) ( $gc['guides'] ?? array() ) as $g ) {
				$slug = (string) ( $g['slug'] ?? '' );
				$path = $game . '/guides/' . $slug;
				$q[]  = array(
					'slug'     => $slug,
					'live'     => self::is_live( $path ),
					'queued'   => ! empty( $g['queue'] ),
					'reviewed' => '' !== trim( (string) ( $g['reviewed_by'] ?? '' ) ),
					'blocked'  => (string) ( $state['blocked'][ $path ] ?? '' ),
				);
			}
			$used         = (int) ( $state['slots'][ $game ] ?? -1 );
			$next         = false === $t0 ? 0 : $t0 + max( $slot + ( $used >= $slot ? 1 : 0 ), 0 ) * self::WEEK;
			$out[ $game ] = array(
				'next_slot' => $next ? gmdate( 'Y-m-d H:i', $next ) . ' UTC' : '',
				'guides'    => $q,
			);
		}
		return array(
			'games' => $out,
			'log'   => $state['log'] ?? array(),
		);
	}

	public static function routes(): void {
		register_rest_route(
			'rbx/v1',
			'/guides',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				// UO-60: the queue and block reasons are for admins; others see the next opening only.
				'callback'            => static function (): \WP_REST_Response {
					$s = self::status();
					if ( ! current_user_can( 'manage_options' ) ) {
						$s = array( 'games' => array_map( static fn( array $g ): array => array( 'next_slot' => $g['next_slot'] ), $s['games'] ) );
					}
					return new \WP_REST_Response( $s );
				},
			)
		);
	}
}
