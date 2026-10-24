<?php
/**
 * Search output from data (TECH-11..27, CONT-06/08, DATA-20/31): robots, titles, descriptions,
 * breadcrumbs, JSON-LD and sitemap entries. Rank Math hooks when it is active; core fallbacks
 * (wp_robots, document title, a minimal JSON-LD graph) when it is not.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Content\Lang;
use RBX\Data\Codes\CheckLog;
use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Gate\PageState;
use RBX\Data\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Hook names verified against Rank Math 1.0.280 (§12.2 item).
 */
final class Seo {

	public const BRAND = 'UpdateOtter';

	public static function boot(): void {
		add_filter( 'rank_math/frontend/robots', array( self::class, 'rm_robots' ) );
		add_filter( 'rank_math/frontend/title', array( self::class, 'rm_title' ) );
		add_filter( 'rank_math/frontend/description', array( self::class, 'rm_description' ) );
		add_filter( 'rank_math/frontend/breadcrumb/items', array( self::class, 'rm_breadcrumbs' ), 10, 1 );
		add_filter( 'rank_math/json_ld', array( self::class, 'rm_json_ld' ), 99, 1 );
		add_filter( 'rank_math/sitemap/entry', array( self::class, 'rm_sitemap_entry' ), 10, 3 );
		add_action( 'rank_math/vars/register_extra_replacements', array( self::class, 'rm_vars' ) );
		add_filter( 'wp_robots', array( self::class, 'core_robots' ) );
		add_filter( 'pre_get_document_title', array( self::class, 'core_title' ) );
		add_action( 'wp_head', array( self::class, 'core_head' ), 2 );
		add_filter( 'request', array( self::class, 'feed' ) );
		// Core sitemap (used when Rank Math's sitemap is off): TECH-13 contents, index pages only.
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'core_sitemap_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( self::class, 'core_sitemap_types' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'core_sitemap_query' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_entry', array( self::class, 'core_sitemap_entry' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'sitemap_index_redirect' ), 1 );
		add_action( 'template_redirect', array( self::class, 'last_modified_header' ), 20 );
		add_action( 'template_redirect', array( self::class, 'clean_urls' ), 0 );
		// GR-32: sitemaps carry their own cache tag, purged with every page-state change.
		add_action(
			'template_redirect',
			static function (): void {
				if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
					\RBX\Data\Cache\Purge::tag( \RBX\Data\Cache\Purge::SITEMAP_TAG );
				}
			},
			1
		);
	}

	/**
	 * GR-31: one address per page. A path with capital letters 301s to its lower-case form, and
	 * /page/N/ on a page that has no pages answers 404 instead of a copy of the page.
	 */
	public static function clean_urls(): void {
		if ( is_admin() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' !== $path && strtolower( $path ) !== $path && ! str_contains( $path, '/wp-' ) ) {
			$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
			wp_safe_redirect( home_url( strtolower( $path ) ) . ( '' !== $query ? '?' . $query : '' ), 301 );
			exit;
		}
		$post = get_queried_object();
		if ( is_singular() && $post instanceof \WP_Post && ( (int) get_query_var( 'page' ) > 1 || (int) get_query_var( 'paged' ) > 1 ) && ! str_contains( (string) $post->post_content, '<!--nextpage-->' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * True only once Rank Math has printed its head. An installed Rank Math whose front end does
	 * not run (seen on live at launch, 2026-10-23) must not switch the core fallbacks off.
	 */
	private static function rank_math(): bool {
		return defined( 'RANK_MATH_VERSION' ) && did_action( 'rank_math/head' ) > 0;
	}

	/**
	 * What the current request shows.
	 *
	 * @return array{kind: string, game: ?Game, rec: ?array<string, mixed>, post: ?\WP_Post, section: string}
	 */
	public static function context(): array {
		$ctx  = array(
			'kind'    => 'other',
			'game'    => null,
			'rec'     => null,
			'post'    => null,
			'section' => '',
		);
		$post = get_queried_object();
		// The Indonesian root /id/ is the home page of that language (UO-16).
		if ( is_front_page() || ( is_page() && $post instanceof \WP_Post && Lang::is_root( $post ) ) ) {
			$ctx['kind'] = 'home';
			return $ctx;
		}
		if ( ! is_singular() || ! $post instanceof \WP_Post ) {
			return $ctx;
		}
		$ctx['post'] = $post;
		if ( in_array( $post->post_type, Plugin::post_types(), true ) ) {
			$found = Repository::for_post( $post->ID );
			if ( $found ) {
				$ctx['game'] = $found[0];
				$ctx['rec']  = $found[1];
				$ctx['kind'] = 'UPD' === $found[1]['entity'] ? 'update' : 'item';
			}
			return $ctx;
		}
		if ( 'page' !== $post->post_type ) {
			return $ctx;
		}
		if ( 'report' === $post->post_name && 0 === (int) $post->post_parent ) {
			$ctx['kind'] = 'report';
			return $ctx;
		}
		if ( 'guide' === get_post_meta( $post->ID, '_rbx_template', true ) ) {
			$ctx['kind'] = 'guide';
		}
		$game = Breadcrumbs::game_for( $post );
		if ( null === $game ) {
			$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
			if ( $parent && 'authors' === $parent->post_name ) {
				$ctx['kind'] = 'author';
			}
			return $ctx;
		}
		$ctx['game'] = $game;
		if ( 0 === Lang::parent( $post ) ) {
			$ctx['kind'] = 'hub';
			return $ctx;
		}
		$parent = get_post( Lang::parent( $post ) );
		if ( 'guide' !== $ctx['kind'] && $parent && $parent->post_name === $game->folder() ) {
			$s = $game->section( $post->post_name );
			if ( $s ) {
				$ctx['section'] = $post->post_name;
				$ctx['kind']    = match ( (string) ( $s['type'] ?? '' ) ) {
					'tool' => 'tool',
					'list', 'mutations', 'tier_list' => 'list',
					'codes' => 'codes',
					'updates' => 'updates',
					default => 'page',
				};
				return $ctx;
			}
		}
		if ( 'guide' !== $ctx['kind'] ) {
			$ctx['kind'] = 'page';
		}
		return $ctx;
	}

	/** Page state for the current shell: index | noindex | '' (not a shell). */
	private static function state(): string {
		$c = self::context();
		if ( in_array( $c['kind'], array( 'item', 'update' ), true ) && $c['post'] ) {
			$s = (string) get_post_meta( $c['post']->ID, '_rbx_page_state', true );
			return '' === $s ? PageState::NOINDEX : $s;
		}
		if ( 'report' === $c['kind'] ) {
			return PageState::NOINDEX;
		}
		// POL-15: an updates list with fewer than three published updates is thin; keep it out of the index (UO-09).
		if ( 'updates' === $c['kind'] && $c['game'] && ! self::has_updates( $c['game'] ) ) {
			return PageState::NOINDEX;
		}
		if ( is_page() && in_array( (int) get_queried_object_id(), self::thin_pages(), true ) ) {
			return PageState::NOINDEX;
		}
		return '';
	}

	/** @return int[] Page IDs marked `"noindex": true` in content/seo.json until they have enough content (UO-12). */
	public static function thin_pages(): array {
		static $ids = null;
		if ( null === $ids ) {
			$ids  = array();
			$file = get_template_directory() . '/content/seo.json';
			$map  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( is_array( $map ) ? $map : array() as $path => $row ) {
				$page = is_array( $row ) && ! empty( $row['noindex'] ) ? get_page_by_path( (string) $path, OBJECT, 'page' ) : null;
				if ( $page ) {
					$ids[] = (int) $page->ID;
				}
			}
		}
		return $ids;
	}

	/** True when the game has at least $min published update pages. */
	public static function has_updates( Game $game, int $min = 3 ): bool {
		$n = 0;
		foreach ( Repository::dataset( $game )->all( 'UPD', array( 'live', 'limited' ) ) as $u ) {
			if ( ! empty( $u['post_id'] ) && 'publish' === get_post_status( (int) $u['post_id'] ) && ++$n >= $min ) {
				return true;
			}
		}
		return false;
	}

	/** @return int[] Page IDs of updates lists that are still empty (kept out of sitemaps). */
	public static function empty_update_pages(): array {
		$ids = self::thin_pages();
		foreach ( Loader::games() as $game ) {
			$page = get_page_by_path( $game->folder() . '/updates', OBJECT, 'page' );
			if ( $page && ! self::has_updates( $game ) ) {
				$ids[] = (int) $page->ID;
			}
		}
		return $ids;
	}

	/* ---------------------------------------------------------------- robots */

	/** @param array<string, string> $robots Rank Math robots. */
	public static function rm_robots( array $robots ): array {
		$s = self::state();
		if ( PageState::NOINDEX === $s || is_404() ) { // TECH-11: the 404 template is noindex, follow.
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
			unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
		} elseif ( PageState::INDEX === $s ) {
			$robots['index']  = 'index';
			$robots['follow'] = 'follow';
		}
		return $robots;
	}

	/** @param array<string, bool|string> $robots Core robots. */
	public static function core_robots( array $robots ): array {
		if ( self::rank_math() ) {
			return $robots;
		}
		if ( PageState::NOINDEX === self::state() || is_404() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['max-image-preview'] );
		} elseif ( ! isset( $robots['noindex'] ) ) {
			$robots['max-image-preview'] = 'large';
			$robots['max-snippet']       = '-1';
			$robots['max-video-preview'] = '-1';
		}
		return $robots;
	}

	/* ---------------------------------------------------------------- titles and descriptions */

	/** Generated title for shells (CONT-06); null leaves the CMS title alone. */
	public static function title(): ?string {
		$c = self::context();
		if ( ! $c['game'] || ! $c['rec'] ) {
			return null;
		}
		$game = $c['game'];
		$rec  = $c['rec'];
		$ds   = Repository::dataset( $game );
		if ( 'update' === $c['kind'] ) {
			return Update::heading( $game, $rec ) . ' | ' . self::BRAND;
		}
		$name  = (string) $rec['name'];
		$parts = array();
		switch ( $rec['entity'] ) {
			case 'PET':
				// Community values are labelled on the page, so they can name the page too.
				$st = $ds->field_state( $rec, 'base_income_per_s' );
				if ( $st['calc_usable'] ) {
					$parts[] = __( 'Income', 'rbx-data' );
					$parts[] = __( 'Mutations', 'rbx-data' );
				}
				if ( $ds->children( 'DRP', 'pet_id', (string) $rec['id'] ) ) {
					$parts[] = __( 'Egg', 'rbx-data' );
				}
				$lead = sprintf( /* translators: 1: item, 2: game */ __( '%1$s in %2$s', 'rbx-data' ), $name, $game->name() );
				break;
			case 'EGG':
				if ( $ds->children( 'DRP', 'egg_id', (string) $rec['id'] ) ) {
					$parts[] = __( 'Pets', 'rbx-data' );
					foreach ( $ds->children( 'DRP', 'egg_id', (string) $rec['id'] ) as $d ) {
						$st = $ds->field_state( $d, 'chance_prob' );
						if ( 'verified' === $st['state'] && 'official' === $st['source_type'] ) {
							$parts[] = __( 'Odds', 'rbx-data' );
							break;
						}
					}
				}
				if ( 'verified' === $ds->field_state( $rec, 'hatch_time_s' )['state'] ) {
					$parts[] = __( 'Hatch Time', 'rbx-data' );
				}
				$lead = sprintf( /* translators: 1: item, 2: game */ __( '%1$s in %2$s', 'rbx-data' ), $name, $game->name() );
				break;
			case 'MUT':
				if ( in_array( $ds->field_state( $rec, 'income_mult' )['state'], array( 'verified', 'community' ), true ) ) {
					$parts[] = __( 'Multiplier', 'rbx-data' );
				}
				if ( ! empty( $rec['data']['obtain_method_text__en'] ) ) {
					$parts[] = __( 'How to Get It', 'rbx-data' );
				}
				$lead = sprintf( /* translators: 1: mutation, 2: game */ __( '%1$s Mutation in %2$s', 'rbx-data' ), preg_replace( '/\s+mutation$/i', '', $name ), $game->name() );
				break;
			case 'PLT':
				if ( $ds->field_state( $rec, 'base_value' )['calc_usable'] ) {
					$parts[] = __( 'Price', 'rbx-data' );
					$parts[] = __( 'Weight', 'rbx-data' );
					$parts[] = __( 'Mutations', 'rbx-data' );
				}
				$lead = sprintf( /* translators: 1: crop, 2: game */ __( '%1$s Value in %2$s', 'rbx-data' ), $name, $game->name() );
				break;
			default:
				$lead = sprintf( '%1$s in %2$s', $name, $game->name() );
		}
		$parts = array_slice( $parts, 0, 3 );
		$last  = array_pop( $parts );
		$list  = $parts ? implode( ', ', $parts ) . ' & ' . $last : (string) $last;
		return $lead . ( null !== $last ? ': ' . $list : '' ) . ' | ' . self::BRAND;
	}

	/** Generated description for shells (CONT-08): what the page gives, a fact, freshness. */
	public static function description(): ?string {
		return self::description_from( self::context() );
	}

	/** Meta description of an item or update page by post ID, outside its own request (llms.txt). */
	public static function item_description( int $post_id ): string {
		$found = Repository::for_post( $post_id );
		$post  = get_post( $post_id );
		if ( ! $found || ! $post ) {
			return '';
		}
		return (string) self::description_from(
			array(
				'kind'    => 'UPD' === $found[1]['entity'] ? 'update' : 'item',
				'game'    => $found[0],
				'rec'     => $found[1],
				'post'    => $post,
				'section' => '',
			)
		);
	}

	/** @param array<string, mixed> $c Context. */
	private static function description_from( array $c ): ?string {
		if ( ! $c['game'] || ! $c['rec'] || ! $c['post'] ) {
			return self::page_seo( 'description' );
		}
		$ex = trim( (string) $c['post']->post_excerpt );
		if ( '' !== $ex ) {
			return wp_strip_all_tags( $ex );
		}
		$game = $c['game'];
		$rec  = $c['rec'];
		$ds   = Repository::dataset( $game );
		if ( 'update' === $c['kind'] ) {
			return wp_trim_words( wp_strip_all_tags( (string) ( $rec['data']['summary__en'] ?? '' ) ), 26 );
		}
		$bits = array();
		$rar  = $ds->get( (string) ( $rec['data']['rarity_id'] ?? '' ) );
		$inc  = 'PET' === $rec['entity'] ? $ds->field_state( $rec, 'base_income_per_s' ) : null;
		$mul  = 'MUT' === $rec['entity'] ? $ds->field_state( $rec, 'income_mult' ) : null;
		$val  = 'PLT' === $rec['entity'] ? $ds->field_state( $rec, 'base_value' ) : null;
		$crop = null !== $game->entity( 'PLT' );
		// UO-18: the number first, "a"/"an" by sound, and at most 155 characters with the date.
		$rname = $rar ? (string) $rar['name'] : '';
		$extra = '';
		if ( $val && $val['calc_usable'] && is_numeric( $val['value'] ) ) {
			$cur = $ds->get( (string) ( $rec['data']['currency_id'] ?? '' ) );
			/* translators: 1: crop, 2: value, 3: currency, 4: game */
			$bits[] = sprintf( __( '%1$s sells for %2$s %3$s at base weight in %4$s.', 'rbx-data' ), $rec['name'], \RBX\Data\Format\Number::format( $val['value'] + 0, 'abbr', 'en' ), $cur ? $cur['name'] : 'Sheckles', $game->name() );
			/* translators: %s: "a Common" / "an Uncommon" */
			$extra = '' !== $rname ? sprintf( __( '%s crop; value with every mutation and weight.', 'rbx-data' ), ucfirst( self::a_an( $rname ) ) ) : __( 'Value with every mutation and weight.', 'rbx-data' );
		} elseif ( $crop && $mul && is_numeric( $mul['value'] ) ) {
			/* translators: 1: mutation, 2: multiplier, 3: game */
			$bits[] = sprintf( __( 'The %1$s mutation multiplies a crop\'s sell value by %2$s in %3$s.', 'rbx-data' ), preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ), \RBX\Data\Format\Number::format( $mul['value'] + 0, 'mult', 'en' ), $game->name() );
			$extra  = __( 'How to get it, how it stacks and what it does to real crops.', 'rbx-data' );
		} elseif ( $inc && $inc['calc_usable'] && is_numeric( $inc['value'] ) ) {
			/* translators: 1: pet, 2: rarity, 3: income per second, 4: game */
			$bits[] = sprintf( __( '%1$s (%2$s) earns %3$s Cash/s in %4$s.', 'rbx-data' ), $rec['name'], '' !== $rname ? $rname : $game->entity_label( 'PET' ), \RBX\Data\Format\Number::format( $inc['value'] + 0, 'abbr', 'en' ), $game->name() );
			$extra  = __( 'Income for every mutation and weight.', 'rbx-data' );
		} elseif ( $mul && is_numeric( $mul['value'] ) ) {
			/* translators: 1: mutation, 2: multiplier, 3: game */
			$bits[] = sprintf( __( 'The %1$s mutation multiplies income by %2$s in %3$s.', 'rbx-data' ), preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ), \RBX\Data\Format\Number::format( $mul['value'] + 0, 'mult', 'en' ), $game->name() );
			$extra  = __( 'How to get it, how it compares and what it does to real pets.', 'rbx-data' );
		} else {
			/* translators: 1: item name, 2: "a common fish" etc., 3: game */
			$bits[] = sprintf( __( 'Stats, sources and change history for %1$s, %2$s in %3$s.', 'rbx-data' ), $rec['name'], self::a_an( strtolower( ( '' !== $rname ? $rname . ' ' : '' ) . $game->entity_label( (string) $rec['entity'] ) ) ), $game->name() );
		}
		if ( '' !== $extra ) {
			$bits[] = $extra;
		}
		if ( 'PET' === $rec['entity'] ) {
			$eggs = array();
			foreach ( $ds->children( 'DRP', 'pet_id', (string) $rec['id'] ) as $d ) {
				$eggs[] = (string) ( $ds->get( (string) $d['data']['egg_id'] )['name'] ?? '' );
			}
			$eggs = array_values( array_filter( array_unique( $eggs ) ) );
			if ( $eggs ) {
				/* translators: %s: egg names */
				$bits[] = sprintf( __( 'Hatches from %s.', 'rbx-data' ), implode( ', ', array_slice( $eggs, 0, 2 ) ) );
			}
		}
		$plv = (string) get_post_meta( $c['post']->ID, '_rbx_page_last_verified', true );
		if ( '' !== $plv ) {
			/* translators: %s: date */
			$bits[] = sprintf( __( 'Wiki data as of %s.', 'rbx-data' ), wp_date( 'j M Y', (int) strtotime( $plv . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ) );
		}
		// Too long: drop the middle sentences (never the number or the date) until it fits.
		while ( count( $bits ) > 2 && mb_strlen( implode( ' ', $bits ) ) > 155 ) {
			array_splice( $bits, count( $bits ) - 2, 1 );
		}
		return implode( ' ', $bits );
	}

	/** "a Common", "an Epic", "an Uncommon" (UO-18). */
	public static function a_an( string $word ): string {
		return ( preg_match( '/^[aeiou]/i', $word ) ? 'an ' : 'a ' ) . $word;
	}

	public static function rm_title( string $title ): string {
		return self::fit_title( self::title() ?? self::page_seo( 'title' ) ?? $title );
	}

	/**
	 * Title or description of a site Page from `content/seo.json` (CONT-06/08), keyed by page
	 * path, `home` for the front page. `{month}` becomes the current month, e.g. October 2026. On a
	 * codes page it is the month of the newest codes check instead, and is dropped when that check
	 * is older than the game's freshness window (UO-05), so the title never claims a check nobody did.
	 */
	public static function page_seo( string $field ): ?string {
		if ( is_front_page() ) {
			$key = 'home';
		} elseif ( is_page() ) {
			$key = get_page_uri( get_queried_object_id() );
		} else {
			return null;
		}
		return self::seo_text( (string) $key, $field );
	}

	/** Title or description from content/seo.json by page path (`home` for the front page). */
	public static function seo_text( string $key, string $field ): ?string {
		static $map = null;
		if ( null === $map ) {
			$file = get_template_directory() . '/content/seo.json';
			$map  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$map  = is_array( $map ) ? $map : array();
		}
		$v = $map[ $key ][ $field ] ?? null;
		if ( ! is_string( $v ) || '' === $v ) {
			return null;
		}
		if ( ! str_contains( $v, '{month}' ) ) {
			return $v;
		}
		$month = Lang::month_year();
		if ( preg_match( '#^(?:id/)?([a-z0-9-]+)/codes$#', (string) $key, $m ) ) {
			$game  = Loader::by_folder( $m[1] );
			$last  = $game ? self::codes_checked( $game ) : '';
			$fresh = '' !== $last && strtotime( $last . ' 12:00 UTC' ) >= time() - $game->freshness_days() * DAY_IN_SECONDS;
			$month = $fresh ? Lang::month_year( (int) strtotime( $last . ' 12:00 UTC' ) ) : '';
		}
		if ( '' === $month ) {
			return trim( (string) preg_replace( '/\s*\(\{month\}\)|\s*\{month\}/', '', $v ) );
		}
		return str_replace( '{month}', $month, $v );
	}

	/** Newest codes check (Y-m-d): the Codes screen log, a code row's dates, or the redeem-box row. */
	public static function codes_checked( Game $game ): string {
		$dates = array( CheckLog::last_checked( $game ) );
		$ds    = Repository::dataset( $game );
		if ( $game->entity( 'COD' ) ) {
			foreach ( $ds->all( 'COD', null ) as $c ) {
				$dates[] = substr( (string) ( $c['data']['last_tested_at'] ?? '' ), 0, 10 );
				$dates[] = (string) ( $c['data']['last_verified'] ?? '' );
			}
		}
		$dates[] = (string) ( $ds->meta_all()['redeem_ui_exists']['last_verified'] ?? '' );
		$dates[] = substr( (string) ( $ds->meta( 'codes_checked_at' ) ?? '' ), 0, 10 );
		return (string) max( $dates );
	}

	public static function rm_description( string $desc ): string {
		return self::description() ?? $desc;
	}

	public static function core_title( string $title ): string {
		if ( self::rank_math() ) {
			return $title;
		}
		$page = self::page_seo( 'title' );
		$t    = self::title() ?? ( null === $page ? null : esc_html( $page ) );
		return null === $t ? $title : self::fit_title( $t );
	}

	/** Over 60 characters the brand suffix goes: Google shows the site name on its own line (UO-19). */
	public static function fit_title( string $title ): string {
		$suffix = ' | ' . self::BRAND;
		if ( mb_strlen( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) > 60 && str_ends_with( $title, $suffix ) ) {
			return substr( $title, 0, -strlen( $suffix ) );
		}
		return $title;
	}

	/* ---------------------------------------------------------------- breadcrumbs */

	/** @param array<int, array<int|string, mixed>> $crumbs Rank Math crumbs. */
	public static function rm_breadcrumbs( array $crumbs ): array {
		$trail = Breadcrumbs::trail();
		if ( ! $trail ) {
			return $crumbs;
		}
		$out = array();
		$n   = count( $trail );
		foreach ( $trail as $i => $c ) {
			$link  = $i === $n - 1 ? ( is_singular() ? (string) get_permalink() : '' ) : $c[1];
			$out[] = array( $c[0], $link, 'hide_in_schema' => '' === $link );
		}
		return $out;
	}

	/* ---------------------------------------------------------------- JSON-LD (TECH-24/25) */

	/**
	 * @param array<string, mixed> $data Rank Math graph pieces keyed by name.
	 * @return array<string, mixed>
	 */
	public static function rm_json_ld( array $data ): array {
		$c = self::context();
		foreach ( $data as $k => $node ) {
			$t = is_array( $node ) ? ( $node['@type'] ?? '' ) : '';
			if ( in_array( $t, array( 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'FAQPage', 'HowTo' ), true ) && 'guide' !== $c['kind'] ) {
				unset( $data[ $k ] );
			}
		}
		$url  = is_singular() ? (string) get_permalink() : home_url( '/' );
		$page = self::page_node( $c, $url );
		if ( null === $page ) {
			return $data;
		}
		$key = isset( $data['WebPage'] ) ? 'WebPage' : null;
		if ( null !== $key && is_array( $data[ $key ] ) ) {
			$data[ $key ] = array_merge( $data[ $key ], $page );
		} else {
			$data['WebPage'] = $page;
		}
		foreach ( self::extra_nodes( $c, $url ) as $name => $node ) {
			$data[ $name ] = $node;
		}
		return $data;
	}

	/**
	 * WebPage overrides: type, about, isPartOf, dateModified.
	 *
	 * @param array<string, mixed> $c Context.
	 * @return array<string, mixed>|null
	 */
	private static function page_node( array $c, string $url ): ?array {
		if ( 'other' === $c['kind'] && ! $c['post'] ) {
			return null; // 404, search: no page node.
		}
		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'inLanguage' => Lang::is_id() ? Lang::ID : 'en',
			'isPartOf'   => array( '@id' => home_url( '/#website' ) ),
		);
		if ( $c['post'] ) {
			$node['name'] = html_entity_decode( wp_strip_all_tags( get_the_title( $c['post'] ) ), ENT_QUOTES, 'UTF-8' );
		}
		if ( 'home' === $c['kind'] ) {
			$node['name']  = self::BRAND;
			$node['about'] = array( '@id' => home_url( '/#organization' ) );
			$desc          = self::description();
			if ( null !== $desc && '' !== $desc ) {
				$node['description'] = $desc;
			}
			return $node;
		}
		if ( 'other' === $c['kind'] ) {
			// Site pages (about, contact, methodology, policies): typed where schema.org has a type (UO-17).
			$slug = (string) $c['post']->post_name;
			if ( 'about' === $slug ) {
				$node['@type'] = 'AboutPage';
				$node['about'] = array( '@id' => home_url( '/#organization' ) );
			} elseif ( 'contact' === $slug ) {
				$node['@type'] = 'ContactPage';
			}
			return $node;
		}
		if ( $c['post'] ) {
			$node['dateModified'] = self::modified( $c['post'] );
		}
		$game = $c['game'];
		$hub  = $game ? Breadcrumbs::page_url( $game->folder() ) : '';
		switch ( $c['kind'] ) {
			case 'hub':
				$node['@type'] = 'CollectionPage';
				$node['about'] = self::video_game( $game );
				break;
			case 'list':
				$node['@type'] = 'CollectionPage';
				if ( '' !== $hub ) {
					$node['isPartOf'] = array( '@id' => $hub . '#webpage' );
				}
				break;
			case 'item':
				$node['about'] = array(
					'@type' => 'Thing',
					'name'  => (string) $c['rec']['name'],
				);
				if ( '' !== $hub ) {
					$node['isPartOf'] = array( '@id' => $hub . '#webpage' );
				}
				$img = self::item_image( $game, $c['rec'] );
				if ( '' !== $img ) {
					$node['primaryImageOfPage'] = array(
						'@type' => 'ImageObject',
						'url'   => $img,
					);
				}
				break;
			case 'tool':
			case 'codes':
			case 'updates':
			case 'update':
			case 'page':
			case 'guide':
			case 'report':
				if ( '' !== $hub && $game ) {
					$node['isPartOf'] = array( '@id' => $hub . '#webpage' );
				}
				break;
			case 'author':
				$node['@type']      = 'ProfilePage';
				$node['mainEntity'] = self::person( (string) ( $node['name'] ?? '' ), $url );
				break;
		}
		return $node;
	}

	/**
	 * @param array<string, mixed> $c Context.
	 * @return array<string, array<string, mixed>>
	 */
	private static function extra_nodes( array $c, string $url ): array {
		$out  = array();
		$game = $c['game'];
		if ( 'tool' === $c['kind'] && $c['post'] && $game ) {
			$out['WebApplication'] = array(
				'@type'               => 'WebApplication',
				'@id'                 => $url . '#app',
				'name'                => html_entity_decode( wp_strip_all_tags( get_the_title( $c['post'] ) ), ENT_QUOTES, 'UTF-8' ),
				'url'                 => $url,
				'applicationCategory' => 'GameApplication',
				'operatingSystem'     => 'Any (web browser)',
				'browserRequirements' => 'Requires HTML5. Works without JavaScript; JavaScript makes the inputs live.',
				'isAccessibleForFree' => true,
				'offers'              => array(
					'@type'         => 'Offer',
					'price'         => '0',
					'priceCurrency' => 'USD',
				),
				'about'               => self::video_game( $game ),
			);
		}
		if ( 'list' === $c['kind'] && $game ) {
			$s      = $game->section( $c['section'] );
			$entity = (string) ( $s['entity'] ?? '' );
			$items  = array();
			if ( '' !== $entity && 'tier_list' !== ( $s['type'] ?? '' ) ) {
				$pos = 0;
				foreach ( Repository::dataset( $game )->all( $entity, array( 'live', 'limited' ) ) as $r ) {
					$pid = (int) ( $r['post_id'] ?? 0 );
					if ( $pid && 'publish' === get_post_status( $pid ) && PageState::INDEX === get_post_meta( $pid, '_rbx_page_state', true ) ) {
						$items[] = array(
							'@type'    => 'ListItem',
							'position' => ++$pos,
							'url'      => (string) get_permalink( $pid ),
							'name'     => (string) $r['name'],
						);
					}
				}
			}
			if ( $items ) {
				$out['ItemList'] = array(
					'@type'           => 'ItemList',
					'@id'             => $url . '#items',
					'itemListElement' => $items,
				);
			}
		}
		if ( 'update' === $c['kind'] && $c['post'] && $game ) {
			$post   = $c['post'];
			$node   = array(
				'@type'            => 'BlogPosting',
				'@id'              => $url . '#article',
				'headline'         => Update::heading( $game, $c['rec'] ),
				'datePublished'    => get_post_time( 'c', true, $post ),
				'dateModified'     => self::modified( $post ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			);
			if ( '' === $node['dateModified'] ) {
				unset( $node['dateModified'] );
			}
			$au = Ui::author( $post->ID );
			if ( $au ) {
				$node['author'] = self::person_ref( $au );
			}
			$thumb = (int) get_post_thumbnail_id( $post );
			if ( $thumb ) {
				$imgs = array();
				foreach ( array( 'rbx-feat-169', 'rbx-feat-43', 'rbx-feat-11' ) as $size ) {
					$src = wp_get_attachment_image_src( $thumb, $size );
					if ( $src ) {
						$imgs[] = $src[0];
					}
				}
				if ( $imgs ) {
					$node['image'] = array_values( array_unique( $imgs ) );
				}
			}
			if ( empty( $node['image'] ) ) {
				$node['image'] = self::og_image( $game );
			}
			$out['BlogPosting'] = $node;
		}
		// Guides: Article node from the page template flag (CONT-28, TECH-25, REV-G28).
		if ( 'guide' === $c['kind'] && $c['post'] ) {
			$post = $c['post'];
			$node = array(
				'@type'            => 'Article',
				'@id'              => $url . '#article',
				'headline'         => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'datePublished'    => get_post_time( 'c', true, $post ) ?: get_post_time( 'c', false, $post ),
				'dateModified'     => self::modified( $post ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			);
			$au   = Ui::author( $post->ID );
			if ( $au ) {
				$node['author'] = self::person_ref( $au );
			}
			if ( $game ) {
				$node['about'] = self::video_game( $game );
			}
			$node['image']  = self::og_image( $game ); // GR-29.
			$out['Article'] = $node;
		}
		return $out;
	}

	/**
	 * The author as a full Person node, the mainEntity of their profile page (UO-17).
	 *
	 * @return array<string, mixed>
	 */
	private static function person( string $name, string $url ): array {
		$node = array(
			'@type'    => 'Person',
			'@id'      => $url . '#person',
			'name'     => $name,
			'url'      => $url,
			'worksFor' => array( '@id' => home_url( '/#organization' ) ),
		);
		$same = self::author_json()['same_as'] ?? array();
		if ( is_array( $same ) && $same ) {
			$node['sameAs'] = array_values( array_map( 'strval', $same ) );
		}
		return $node;
	}

	/**
	 * Author of an Article/BlogPosting, with the same @id as the Person on the profile page.
	 *
	 * @param array{name: string, url: string} $au Author from Ui::author().
	 * @return array<string, string>
	 */
	private static function person_ref( array $au ): array {
		return array(
			'@type' => 'Person',
			'@id'   => $au['url'] . '#person',
			'name'  => $au['name'],
			'url'   => $au['url'],
		);
	}

	/** @return array<string, mixed> content/author.json */
	private static function author_json(): array {
		static $a = null;
		if ( null === $a ) {
			$file = get_template_directory() . '/content/author.json';
			$a    = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$a    = is_array( $a ) ? $a : array();
		}
		return $a;
	}

	/**
	 * Organization node (UO-17). sameAs comes from content/author.json `org_same_as` once the brand
	 * accounts exist (UO-73).
	 *
	 * @return array<string, mixed>
	 */
	private static function organization(): array {
		$a    = self::author_json();
		$node = array(
			'@type'       => 'Organization',
			'@id'         => home_url( '/#organization' ),
			'name'        => self::BRAND,
			'url'         => home_url( '/' ),
			'logo'        => home_url( '/logo-organization.png' ),
			'description' => 'Fan-made calculators, item databases, codes and guides for popular Roblox games. Not affiliated with Roblox or the game developers.',
			'email'       => (string) ( $a['org_email'] ?? 'contact@updateotter.com' ),
		);
		if ( ! empty( $a['url'] ) ) {
			$node['founder'] = array( '@id' => home_url( (string) $a['url'] ) . '#person' );
		}
		if ( ! empty( $a['org_same_as'] ) && is_array( $a['org_same_as'] ) ) {
			$node['sameAs'] = array_values( array_map( 'strval', $a['org_same_as'] ) );
		}
		return $node;
	}

	/**
	 * The game's share image (or the site's) as an ImageObject, for Article and BlogPosting (GR-29).
	 *
	 * @return array<string, mixed>
	 */
	private static function og_image( ?Game $game ): array {
		$url = home_url( '/og-default.jpg' );
		if ( $game && function_exists( 'updateotter_root_file_exists' ) && updateotter_root_file_exists( 'og-' . $game->folder() . '.jpg' ) ) {
			$url = home_url( '/og-' . $game->folder() . '.jpg' );
		}
		return array(
			'@type'  => 'ImageObject',
			'url'    => $url,
			'width'  => 1200,
			'height' => 630,
		);
	}

	/** @return array<string, string> */
	private static function video_game( ?Game $game ): array {
		if ( ! $game ) {
			return array();
		}
		$node  = array(
			'@type'        => 'VideoGame',
			'name'         => $game->name(),
			'gamePlatform' => 'Roblox',
		);
		$place = $game->place_id() ?? (string) ( Repository::dataset( $game )->meta( 'roblox_place_id' ) ?? '' );
		if ( '' !== (string) $place ) {
			$node['url'] = 'https://www.roblox.com/games/' . rawurlencode( (string) $place );
		}
		return $node;
	}

	private static function item_image( Game $game, array $rec ): string {
		foreach ( Repository::dataset( $game )->media_for( (string) $rec['id'] ) as $m ) {
			$u = Media::url( (string) $m['data']['file_name'] );
			if ( '' !== $u ) {
				return $u;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------- sitemap (DATA-31, TECH-13/14) */

	/**
	 * @param array<string, mixed>|false $url  Entry.
	 * @param string                     $type Entry type.
	 * @param mixed                      $obj  Post/term/user.
	 * @return array<string, mixed>|false
	 */
	public static function rm_sitemap_entry( $url, $type, $obj ) {
		if ( 'post' !== $type || ! $obj instanceof \WP_Post || ! is_array( $url ) ) {
			return $url;
		}
		if ( in_array( $obj->post_type, Plugin::post_types(), true ) ) {
			if ( PageState::INDEX !== get_post_meta( $obj->ID, '_rbx_page_state', true ) ) {
				return false;
			}
		}
		if ( 'page' === $obj->post_type && ( ( 'report' === $obj->post_name && 0 === (int) $obj->post_parent ) || in_array( (int) $obj->ID, self::empty_update_pages(), true ) ) ) {
			return false;
		}
		$url['mod'] = self::modified( $obj );
		return $url;
	}

	public static function core_sitemaps_on(): bool {
		return function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled();
	}

	/**
	 * @param \WP_Sitemaps_Provider|mixed $provider Provider.
	 * @return \WP_Sitemaps_Provider|false|mixed
	 */
	public static function core_sitemap_provider( $provider, string $name ) {
		return in_array( $name, array( 'users', 'taxonomies' ), true ) ? false : $provider;
	}

	/**
	 * @param array<string, \WP_Post_Type> $types Post types.
	 * @return array<string, \WP_Post_Type>
	 */
	public static function core_sitemap_types( array $types ): array {
		return array_intersect_key( $types, array_flip( array_merge( array( 'page' ), Plugin::post_types() ) ) );
	}

	/**
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function core_sitemap_query( array $args, string $post_type ): array {
		if ( in_array( $post_type, Plugin::post_types(), true ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				array(
					'key'   => '_rbx_page_state',
					'value' => PageState::INDEX,
				),
			);
		} elseif ( 'page' === $post_type ) {
			$report  = get_page_by_path( 'report', OBJECT, 'page' );
			$exclude = self::empty_update_pages();
			if ( $report ) {
				$exclude[] = (int) $report->ID;
			}
			if ( $exclude ) {
				$args['post__not_in'] = $exclude;
			}
		}
		return $args;
	}

	/**
	 * @param array<string, string> $entry Entry.
	 * @return array<string, string>
	 */
	public static function core_sitemap_entry( array $entry, \WP_Post $post ): array {
		$entry['lastmod'] = self::modified( $post );
		return $entry;
	}

	/**
	 * The page's one "content changed" time as ISO 8601 (GR-14): `_rbx_last_changed` (UTC), else
	 * the post's modified time. Older values stored as a bare date count from midnight UTC.
	 */
	public static function modified( \WP_Post $post ): string {
		$lc = (string) get_post_meta( $post->ID, '_rbx_last_changed', true );
		$ts = '' !== $lc ? strtotime( ( 10 === strlen( $lc ) ? $lc . ' 00:00:00' : $lc ) . ' UTC' ) : false;
		if ( false === $ts ) {
			$ts = strtotime( $post->post_modified_gmt . ' UTC' );
		}
		return false === $ts ? '' : gmdate( 'c', $ts );
	}

	/** `Last-Modified` from the same time (GR-14, GR-23), on single pages for visitors. */
	public static function last_modified_header(): void {
		if ( headers_sent() || is_user_logged_in() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		$iso = self::modified( $post );
		if ( '' === $iso ) {
			return;
		}
		$ts = (int) strtotime( $iso );
		// A deploy that purges the cache changes output on every page, so the last purge counts as
		// a change too: a crawler then re-reads pages after a template or robots change.
		$purged = function_exists( 'updateotter_launch_state' ) ? strtotime( (string) ( updateotter_launch_state()['purge']['at'] ?? '' ) . ' UTC' ) : false;
		if ( false !== $purged && $purged > $ts ) {
			$ts = (int) $purged;
		}
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $ts ) . ' GMT' );
		// GR-23: a crawler that already has this version gets "304 Not Modified" without the page
		// being rendered. Never stored by the page cache.
		$since = (string) ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$since = '' !== $since ? strtotime( $since ) : false;
		if ( false !== $since && $since >= $ts && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && empty( $_SERVER['QUERY_STRING'] ) ) {
			do_action( 'litespeed_control_set_nocache', 'rbx 304 not modified' );
			status_header( 304 );
			header( 'Cache-Control: no-cache' );
			exit;
		}
	}

	/** /sitemap_index.xml (the address in the spec) goes to the core index when Rank Math's sitemap is off. */
	public static function sitemap_index_redirect(): void {
		if ( ! is_404() || ! self::core_sitemaps_on() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '/sitemap_index.xml' === $path ) {
			wp_safe_redirect( home_url( '/wp-sitemap.xml' ), 301 );
			exit;
		}
	}

	/* ---------------------------------------------------------------- Rank Math variables (DATA-30) */

	public static function rm_vars(): void {
		if ( ! function_exists( 'rank_math_register_var_replacement' ) ) {
			return;
		}
		rank_math_register_var_replacement(
			'rbx_value',
			array(
				'name'        => __( 'Game data value', 'rbx-data' ),
				'description' => __( 'A value from the dataset: %rbx_value(SAE-MUT-0003,income_mult)%', 'rbx-data' ),
				'variable'    => 'rbx_value(id,field)',
				'example'     => '×2.5',
			),
			static function ( $args = '' ) {
				$p = array_map( 'trim', explode( ',', (string) $args ) );
				$v = Shortcodes::lookup( (string) ( $p[0] ?? '' ), sanitize_key( (string) ( $p[1] ?? '' ) ), (string) ( $p[2] ?? '' ) );
				return null === $v ? '' : $v['text'];
			}
		);
		rank_math_register_var_replacement(
			'rbx_count',
			array(
				'name'        => __( 'Game data count', 'rbx-data' ),
				'description' => __( 'Number of live records: %rbx_count(sae,PET)%', 'rbx-data' ),
				'variable'    => 'rbx_count(game,entity)',
				'example'     => '208',
			),
			static function ( $args = '' ) {
				$p    = array_map( 'trim', explode( ',', (string) $args ) );
				$game = \RBX\Data\Config\Loader::game( sanitize_key( (string) ( $p[0] ?? '' ) ) );
				return $game ? (string) Repository::dataset( $game )->count( strtoupper( (string) ( $p[1] ?? '' ) ) ) : '';
			}
		);
		rank_math_register_var_replacement(
			'rbx_checked_month',
			array(
				'name'        => __( 'Codes last-check month', 'rbx-data' ),
				'description' => __( 'Month of the newest codes check: %rbx_checked_month(sae)% (CONT-07)', 'rbx-data' ),
				'variable'    => 'rbx_checked_month(game)',
				'example'     => 'October 2026',
			),
			static function ( $args = '' ) {
				$game = \RBX\Data\Config\Loader::game( sanitize_key( trim( (string) $args ) ) );
				$d    = $game ? CheckLog::last_checked( $game ) : '';
				return '' === $d ? '' : wp_date( 'F Y', (int) strtotime( $d . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			}
		);
		rank_math_register_var_replacement(
			'rbx_last_verified',
			array(
				'name'        => __( 'Page last verified', 'rbx-data' ),
				'description' => __( 'The page\'s Verified date', 'rbx-data' ),
				'variable'    => 'rbx_last_verified',
				'example'     => '22 Oct 2026',
			),
			static function () {
				$id = get_queried_object_id();
				$d  = $id ? (string) get_post_meta( $id, '_rbx_page_last_verified', true ) : '';
				return '' === $d ? '' : wp_date( 'j M Y', (int) strtotime( $d . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			}
		);
	}

	/* ---------------------------------------------------------------- fallback head without Rank Math */

	/**
	 * Open Graph and Twitter card tags (UO-13). Image per game from the root files, else the
	 * site default; an update post with a featured image uses that image's 1200×630 crop.
	 *
	 * @param array<string, mixed> $c Context.
	 */
	private static function social_tags( array $c, ?string $desc ): void {
		if ( is_404() || is_search() || ( 'other' === $c['kind'] && ! $c['post'] ) ) {
			return;
		}
		$url   = 'home' === $c['kind'] && ! Lang::is_id() ? home_url( '/' ) : (string) ( wp_get_canonical_url() ?: get_permalink() );
		$title = html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' );
		$game  = $c['game'];
		$img   = home_url( '/og-default.jpg' );
		$alt   = self::BRAND . ': Roblox game tools, updates and guides';
		if ( $game && function_exists( 'updateotter_root_file_exists' ) && updateotter_root_file_exists( 'og-' . $game->folder() . '.jpg' ) ) {
			$img = home_url( '/og-' . $game->folder() . '.jpg' );
			$alt = self::BRAND . ': ' . $game->name();
		}
		$w = 1200;
		$h = 630;
		if ( 'update' === $c['kind'] && $c['post'] && has_post_thumbnail( $c['post'] ) ) {
			$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $c['post'] ), 'rbx-og' );
			if ( $src ) {
				[ $img, $w, $h ] = array( (string) $src[0], (int) $src[1], (int) $src[2] );
			}
		}
		$type = in_array( $c['kind'], array( 'guide', 'update' ), true ) ? 'article' : 'website';
		$tags = array(
			'og:type'        => $type,
			'og:site_name'   => self::BRAND,
			'og:title'       => $title,
			'og:description' => (string) $desc,
			'og:url'         => $url,
			'og:locale'      => Lang::is_id() ? 'id_ID' : 'en_US',
			'og:image'       => $img,
			'og:image:width' => (string) $w,
			'og:image:height' => (string) $h,
			'og:image:alt'   => $alt,
		);
		foreach ( $tags as $k => $v ) {
			if ( '' !== $v ) {
				echo '<meta property="' . esc_attr( $k ) . '" content="' . esc_attr( $v ) . "\">\n";
			}
		}
		if ( null !== Lang::pair() ) {
			echo '<meta property="og:locale:alternate" content="' . ( Lang::is_id() ? 'en_US' : 'id_ID' ) . "\">\n";
		}
		if ( 'article' === $type && $c['post'] ) {
			echo '<meta property="article:modified_time" content="' . esc_attr( self::modified( $c['post'] ) ) . "\">\n";
		}
		$tw = array(
			'twitter:card'        => 'summary_large_image',
			'twitter:title'       => $title,
			'twitter:description' => (string) $desc,
			'twitter:image'       => $img,
			'twitter:image:alt'   => $alt,
		);
		foreach ( $tw as $k => $v ) {
			if ( '' !== $v ) {
				echo '<meta name="' . esc_attr( $k ) . '" content="' . esc_attr( $v ) . "\">\n";
			}
		}
	}

	public static function core_head(): void {
		if ( self::rank_math() || is_admin() ) {
			return;
		}
		$desc = self::description();
		if ( null !== $desc && '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n";
		}
		$c   = self::context();
		self::social_tags( $c, $desc );
		$url = is_singular() ? (string) get_permalink() : home_url( '/' );
		$graph = array();
		if ( 'home' === $c['kind'] ) {
			// GR-29: the Indonesian root has its own WebSite node in its language.
			$root    = Lang::is_id() ? home_url( '/' . Lang::ID . '/' ) : home_url( '/' );
			$graph[] = array(
				'@type'         => 'WebSite',
				'@id'           => $root . '#website',
				'name'          => self::BRAND,
				'alternateName' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'url'           => $root,
				'inLanguage'    => Lang::is_id() ? 'id' : 'en',
				'publisher'     => array( '@id' => home_url( '/#organization' ) ),
			);
		}
		// The publisher every page points at by @id, printed on every page so the reference
		// resolves (GR-29).
		$graph[] = self::organization();
		$page = self::page_node( $c, $url );
		if ( $page ) {
			$graph[] = $page;
		}
		foreach ( self::extra_nodes( $c, $url ) as $n ) {
			$graph[] = $n;
		}
		$trail = Breadcrumbs::trail();
		if ( count( $trail ) >= 2 ) {
			$items = array();
			foreach ( $trail as $i => $t ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $t[0],
					'item'     => '' !== $t[1] ? $t[1] : $url,
				);
			}
			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $items,
			);
		}
		if ( $graph ) {
			echo '<script type="application/ld+json">' . wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . "</script>\n";
		}
	}

	/* ---------------------------------------------------------------- feed (REV-G14) */

	/** @param array<string, mixed> $qv Query vars. */
	public static function feed( array $qv ): array {
		if ( isset( $qv['feed'] ) && ! isset( $qv['post_type'] ) && empty( $qv['withcomments'] ) ) {
			$qv['post_type'] = array( 'rbx_update' );
			// GR-33: only update pages that are in search, with the site author and a summary.
			add_action(
				'pre_get_posts',
				static function ( \WP_Query $q ): void {
					if ( $q->is_main_query() && $q->is_feed() ) {
						$q->set(
							'meta_query',
							array(
								array(
									'key'   => '_rbx_page_state',
									'value' => PageState::INDEX,
								),
							)
						);
					}
				}
			);
			add_filter(
				'the_author',
				static function ( $name ) {
					$au = is_feed() ? Ui::author( (int) get_the_ID() ) : null;
					return $au ? $au['name'] : $name;
				}
			);
			add_filter(
				'the_excerpt_rss',
				static function ( $text ) {
					return '' !== trim( wp_strip_all_tags( (string) $text ) ) ? $text : esc_html( self::item_description( (int) get_the_ID() ) );
				}
			);
		}
		return $qv;
	}
}
