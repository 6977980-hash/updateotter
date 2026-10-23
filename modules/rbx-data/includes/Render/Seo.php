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
		if ( is_front_page() ) {
			$ctx['kind'] = 'home';
			return $ctx;
		}
		$post = get_queried_object();
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
		if ( 0 === (int) $post->post_parent ) {
			$ctx['kind'] = 'hub';
			return $ctx;
		}
		$parent = get_post( (int) $post->post_parent );
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
		// POL-15: an updates list with no published update is an empty page; keep it out of the index.
		if ( 'updates' === $c['kind'] && $c['game'] && ! self::has_updates( $c['game'] ) ) {
			return PageState::NOINDEX;
		}
		return '';
	}

	/** True when the game has at least one published update page. */
	public static function has_updates( Game $game ): bool {
		foreach ( Repository::dataset( $game )->all( 'UPD', array( 'live', 'limited' ) ) as $u ) {
			if ( ! empty( $u['post_id'] ) && 'publish' === get_post_status( (int) $u['post_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return int[] Page IDs of updates lists that are still empty (kept out of sitemaps). */
	private static function empty_update_pages(): array {
		$ids = array();
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
		$c = self::context();
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
		if ( $val && $val['calc_usable'] && is_numeric( $val['value'] ) ) {
			$cur = $ds->get( (string) ( $rec['data']['currency_id'] ?? '' ) );
			/* translators: 1: crop, 2: game, 3: rarity, 4: value, 5: currency */
			$bits[] = sprintf( __( 'How much a %1$s sells for in %2$s: a %3$s crop worth %4$s %5$s at its base weight, with every mutation and at heavier weights.', 'rbx-data' ), $rec['name'], $game->name(), $rar ? $rar['name'] : '', \RBX\Data\Format\Number::format( $val['value'] + 0, 'abbr', 'en' ), $cur ? $cur['name'] : 'Sheckles' );
		} elseif ( $crop && $mul && is_numeric( $mul['value'] ) ) {
			/* translators: 1: mutation, 2: game, 3: multiplier */
			$bits[] = sprintf( __( 'The %1$s mutation in %2$s multiplies a crop\'s sell value by %3$s. How to get it, how it stacks, and what it does to real crops.', 'rbx-data' ), preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ), $game->name(), \RBX\Data\Format\Number::format( $mul['value'] + 0, 'mult', 'en' ) );
		} elseif ( $inc && $inc['calc_usable'] && is_numeric( $inc['value'] ) ) {
			/* translators: 1: pet, 2: game, 3: rarity, 4: income per second */
			$bits[] = sprintf( __( 'How much the %1$s earns in %2$s: a %3$s pet with %4$s Cash per second base income, with every mutation and at higher weights.', 'rbx-data' ), $rec['name'], $game->name(), $rar ? $rar['name'] : '', \RBX\Data\Format\Number::format( $inc['value'] + 0, 'abbr', 'en' ) );
		} elseif ( $mul && is_numeric( $mul['value'] ) ) {
			/* translators: 1: mutation, 2: game, 3: multiplier */
			$bits[] = sprintf( __( 'The %1$s mutation in %2$s multiplies income by %3$s. How to get it, how it compares, and what it does to real pets.', 'rbx-data' ), preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ), $game->name(), \RBX\Data\Format\Number::format( $mul['value'] + 0, 'mult', 'en' ) );
		} else {
			/* translators: 1: item name, 2: entity label, 3: game */
			$bits[] = sprintf( __( 'Stats, sources and change history for %1$s, a %2$s in %3$s.', 'rbx-data' ), $rec['name'], strtolower( ( $rar ? $rar['name'] . ' ' : '' ) . $game->entity_label( (string) $rec['entity'] ) ), $game->name() );
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
			$bits[] = sprintf( __( 'Last checked %s.', 'rbx-data' ), wp_date( 'j M Y', (int) strtotime( $plv . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ) );
		}
		return implode( ' ', $bits );
	}

	public static function rm_title( string $title ): string {
		return self::title() ?? self::page_seo( 'title' ) ?? $title;
	}

	/**
	 * Title or description of a site Page from `content/seo.json` (CONT-06/08), keyed by page
	 * path, `home` for the front page. `{month}` becomes the current month, e.g. October 2026.
	 */
	public static function page_seo( string $field ): ?string {
		static $map = null;
		if ( null === $map ) {
			$file = get_template_directory() . '/content/seo.json';
			$map  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$map  = is_array( $map ) ? $map : array();
		}
		if ( is_front_page() ) {
			$key = 'home';
		} elseif ( is_page() ) {
			$key = get_page_uri( get_queried_object_id() );
		} else {
			return null;
		}
		$v = $map[ $key ][ $field ] ?? null;
		return is_string( $v ) && '' !== $v ? str_replace( '{month}', gmdate( 'F Y' ), $v ) : null;
	}

	public static function rm_description( string $desc ): string {
		return self::description() ?? $desc;
	}

	public static function core_title( string $title ): string {
		if ( self::rank_math() ) {
			return $title;
		}
		$page = self::page_seo( 'title' );
		return self::title() ?? ( null === $page ? $title : esc_html( $page ) );
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
		if ( 'other' === $c['kind'] || 'home' === $c['kind'] ) {
			return null;
		}
		$node = array(
			'@type' => 'WebPage',
			'@id'   => $url . '#webpage',
			'url'   => $url,
		);
		if ( $c['post'] ) {
			$lc = (string) get_post_meta( $c['post']->ID, '_rbx_last_changed', true );
			if ( '' !== $lc ) {
				$node['dateModified'] = $lc;
			}
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
				$node['@type'] = 'ProfilePage';
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
				'browserRequirements' => 'Requires JavaScript',
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
				'dateModified'     => (string) get_post_meta( $post->ID, '_rbx_last_changed', true ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			);
			if ( '' === $node['dateModified'] ) {
				unset( $node['dateModified'] );
			}
			$au = Ui::author( $post->ID );
			if ( $au ) {
				$node['author'] = array(
					'@type' => 'Person',
					'name'  => $au['name'],
					'url'   => $au['url'],
				);
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
				'dateModified'     => get_post_modified_time( 'c', true, $post ) ?: get_post_modified_time( 'c', false, $post ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			);
			$au   = Ui::author( $post->ID );
			if ( $au ) {
				$node['author'] = array(
					'@type' => 'Person',
					'name'  => $au['name'],
					'url'   => $au['url'],
				);
			}
			if ( $game ) {
				$node['about'] = self::video_game( $game );
			}
			$out['Article'] = $node;
		}
		return $out;
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
		$lc = (string) get_post_meta( $obj->ID, '_rbx_last_changed', true );
		if ( '' !== $lc ) {
			$url['mod'] = $lc . 'T00:00:00+00:00';
		}
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
		$lc = (string) get_post_meta( $post->ID, '_rbx_last_changed', true );
		if ( '' !== $lc ) {
			$entry['lastmod'] = $lc . 'T00:00:00+00:00';
		}
		return $entry;
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

	public static function core_head(): void {
		if ( self::rank_math() || is_admin() ) {
			return;
		}
		$desc = self::description();
		if ( null !== $desc && '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n";
		}
		$c   = self::context();
		$url = is_singular() ? (string) get_permalink() : home_url( '/' );
		$graph = array();
		if ( 'home' === $c['kind'] ) {
			$graph[] = array(
				'@type'         => 'WebSite',
				'@id'           => home_url( '/#website' ),
				'name'          => self::BRAND,
				'alternateName' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'url'           => home_url( '/' ),
			);
			$graph[] = array(
				'@type' => 'Organization',
				'@id'   => home_url( '/#organization' ),
				'name'  => self::BRAND,
				'url'   => home_url( '/' ),
				'logo'  => home_url( '/logo-organization.png' ),
			);
		}
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
		}
		return $qv;
	}
}
