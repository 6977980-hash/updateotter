<?php
/**
 * Data sections placed on WP Pages with the `rbx/data-section` block or `[rbx_section]`:
 * hub parts, lists (UX-08, DATA-23b), mutations + sizes (CONT-24), codes (CONT-25, POL-51),
 * updates hub (CONT-26), tier list (CONT-27), corrections log (CONT-45), report form (CONT-46).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Codes\CheckLog;
use RBX\Data\Content\Lang;
use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Changelog;
use RBX\Data\Gate\PageState;
use RBX\Data\Import\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Section name => renderer. Each renderer returns HTML and the record IDs it displayed.
 */
final class Sections {

	/** Rows a list renders in the page; the rest load on demand (GR-24). */
	public const LIST_ROWS = 100;

	public const NAMES = array( 'count', 'list', 'mutations', 'sizes', 'stacking', 'codes', 'updates', 'tier_list', 'tier_stages', 'reference_note', 'hub_tiles', 'hub_glance', 'glossary', 'biomes', 'mounts', 'latest_update', 'top_tier', 'corrections', 'image_credits', 'report_form', 'changes', 'home_games', 'home_latest', 'home_guides' );

	/** @var array<int, string> Record IDs displayed during this request (for trust strip and last_changed). */
	private static array $shown = array();

	/** @return array<int, string> */
	public static function shown(): array {
		return array_values( array_unique( self::$shown ) );
	}

	public static function remember( string $id ): void {
		self::$shown[] = $id;
	}

	public static function reset(): void {
		self::$shown = array();
	}

	/** @param array<int, string> $ids IDs. */
	private static function show( array $ids ): void {
		self::$shown = array_merge( self::$shown, array_map( 'strval', $ids ) );
	}

	/**
	 * @param array<string, mixed> $a Attributes: game, section, entity, limit.
	 */
	public static function render( array $a ): string {
		$section = (string) ( $a['section'] ?? '' );
		if ( 'home_games' === $section || 'home_latest' === $section || 'home_guides' === $section || 'corrections' === $section || 'image_credits' === $section || 'report_form' === $section ) {
			$game = null;
		} else {
			$game = Loader::game( (string) ( $a['game'] ?? '' ) );
			if ( null === $game ) {
				return '';
			}
			\RBX\Data\Cache\Purge::tag_game( $game );
		}
		return self::table_roles( self::dispatch( $section, $game, $a ) );
	}

	/** The HTML of one section; '' when it is unknown or fails. */
	private static function dispatch( string $section, ?Game $game, array $a ): string {
		try {
			switch ( $section ) {
				case 'count':
					return self::count( $game, (string) ( $a['entity'] ?? '' ) );
				case 'list':
					$list = self::list( $game, (string) ( $a['entity'] ?? '' ) );
					return '' === $list ? '' : self::full_guides( $game, (string) ( $a['entity'] ?? '' ) ) . self::licensed( $game, $list );
				case 'mutations':
					return self::licensed( $game, self::mutations( $game ) );
				case 'sizes':
					return self::licensed( $game, self::sizes( $game ) );
				case 'stacking':
					return self::mechanic_text( $game, 'mutation_stacking' );
				case 'codes':
					return self::codes( $game );
				case 'updates':
					return self::updates( $game );
				case 'tier_list':
					return self::licensed( $game, self::tier_list( $game ) );
				case 'tier_stages':
					return self::licensed( $game, self::tier_stages( $game ) );
				case 'hub_tiles':
					return self::hub_tiles( $game );
				case 'hub_glance':
					return self::hub_glance( $game );
				case 'reference_note':
					return self::reference_note( $game );
				case 'glossary':
					return self::glossary( $game );
				case 'biomes':
					return self::licensed( $game, self::biomes( $game ) );
				case 'mounts':
					return self::licensed( $game, self::mounts( $game ) );
				case 'latest_update':
					return self::latest_update( $game );
				case 'top_tier':
					return self::top_tier( $game, (int) ( $a['limit'] ?? 5 ) );
				case 'changes':
					return self::page_changes( $game );
				case 'corrections':
					return self::corrections();
				case 'image_credits':
					return self::image_credits();
				case 'report_form':
					return Report::form();
				case 'home_games':
					return self::home_games();
				case 'home_latest':
					return self::home_latest();
				case 'home_guides':
					return self::home_guides();
			}
		} catch ( \Throwable $e ) {
			\RBX\Data\Log\ErrorLog::add( 'render', $section . ': ' . $e->getMessage() );
		}
		return '';
	}

	/**
	 * UO-51: list tables are restyled as cards below 600 px (display:block/flex), which makes
	 * Safari/VoiceOver drop the table semantics. Explicit ARIA roles keep them.
	 */
	private static function table_roles( string $html ): string {
		if ( false === strpos( $html, 'rbx-table--list' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#<table class="rbx-table rbx-table--list">.*?</table>#s',
			static function ( $m ) {
				return strtr(
					$m[0],
					array(
						'<table class="rbx-table rbx-table--list">' => '<table class="rbx-table rbx-table--list" role="table">',
						'<thead>'                                  => '<thead role="rowgroup">',
						'<tbody>'                                  => '<tbody role="rowgroup">',
						'<tr>'                                     => '<tr role="row">',
						'<tr '                                     => '<tr role="row" ',
						'<th scope="col"'                          => '<th role="columnheader" scope="col"',
						'<th scope="row"'                          => '<th role="rowheader" scope="row"',
						'<td>'                                     => '<td role="cell">',
						'<td '                                     => '<td role="cell" ',
					)
				);
			},
			$html
		);
	}

	/** Data table plus its CC BY-SA credit (UO-04); nothing when the table is empty. */
	private static function licensed( Game $game, string $html ): string {
		return '' === $html ? '' : $html . Ui::license_line( $game );
	}

		private static function ds( Game $game ): Dataset {
		return Repository::dataset( $game );
	}

	/**
	 * Oldest last_verified among records (for count lines): the same date the page's trust strip
	 * shows as "Wiki data as of", so the header and the list caption agree (GR-14).
	 */
	private static function oldest_verified( array $recs ): string {
		$min = '';
		foreach ( $recs as $r ) {
			$lv = (string) ( $r['data']['last_verified'] ?? '' );
			if ( '' !== $lv && ( '' === $min || $lv < $min ) ) {
				$min = $lv;
			}
		}
		return $min;
	}

	/** "{n} pets, wiki data as of {date}" (UX-08, UO-01). */
	private static function count( Game $game, string $entity ): string {
		if ( null === $game->entity( $entity ) ) {
			return '';
		}
		$recs = self::ds( $game )->all( $entity, array( 'live', 'limited' ) );
		$n    = count( $recs );
		$lv   = self::oldest_verified( $recs );
		$txt  = sprintf( '%s %s', number_format_i18n( $n ), strtolower( $game->entity_label( $entity, 1 !== $n ) ) );
		return '<p class="rbx-count">' . esc_html( $txt ) . ( '' !== $lv ? ', ' . esc_html__( 'wiki data as of', 'rbx-data' ) . ' ' . Ui::date( $lv ) : '' ) . '</p>';
	}

	/**
	 * Full list in the default sort, server-rendered; filters/sort in the URL fragment (DATA-23b).
	 */
	private static function list( Game $game, string $entity ): string {
		$def = $game->entity( $entity );
		if ( null === $def ) {
			return '';
		}
		$ds     = self::ds( $game );
		$recs   = $ds->all( $entity, array( 'live', 'limited', 'removed_from_game' ) );
		$fields = array();
		$facets = array();
		foreach ( (array) $def['fields'] as $f => $fd ) {
			// A column no item has a value for is left out (GR-11).
			if ( ! empty( $fd['list'] ) && self::ds( $game )->field_has_values( $entity, $f ) ) {
				$fields[ $f ] = $fd;
			}
			if ( ! empty( $fd['facet'] ) ) {
				$facets[ $f ] = $fd;
			}
		}
		if ( ! $recs ) {
			return '<p class="rbx-empty">' . esc_html__( 'Nothing here yet. We add items only after checking them in the game.', 'rbx-data' ) . '</p>';
		}
		self::show( array_keys( $recs ) );

		$head = '<th scope="col" data-sort-key="name">' . esc_html__( 'Name', 'rbx-data' ) . '</th>';
		foreach ( $fields as $f => $fd ) {
			$numeric = $ds->is_numeric_field( $entity, $f );
			$head   .= '<th scope="col"' . ( $numeric ? ' data-sort-key="' . esc_attr( $f ) . '"' : '' ) . '>' . esc_html( Lang::t( (string) ( $fd['label__en'] ?? $f ) ) ) . '</th>';
		}
		if ( 'PET' === $entity ) {
			$head .= '<th scope="col">' . esc_html__( 'Source egg', 'rbx-data' ) . '</th>';
		}
		if ( 'EGG' === $entity ) {
			$head .= '<th scope="col">' . esc_html__( 'Pets', 'rbx-data' ) . '</th>';
		}
		// UO-30: when every row has the same confidence, one legend above the table replaces the
		// column (two chips per row made each phone card about 240 px tall).
		$conf     = array_map( static fn( $r ) => self::confidence( $r ), $recs );
		$one_conf = 1 === count( array_unique( $conf ) );
		if ( ! $one_conf ) {
			$head .= '<th scope="col">' . esc_html__( 'Confidence', 'rbx-data' ) . '</th>';
		}

		$body       = '';
		$rest       = '';
		$n_rows     = 0;
		$dash       = false;
		$facet_vals = array();
		foreach ( $recs as $id => $r ) {
			$attrs = ' data-name="' . esc_attr( strtolower( $r['name'] . ' ' . ( $r['data']['aliases'] ?? '' ) ) ) . '"';
			foreach ( $facets as $f => $fd ) {
				$v = $r['data'][ $f ] ?? null;
				$vals = array_map( static fn( $x ) => is_bool( $x ) ? ( $x ? 'yes' : 'no' ) : (string) $x, is_array( $v ) ? $v : ( null === $v ? array() : array( $v ) ) );
				foreach ( $vals as $x ) {
					$facet_vals[ $f ][ $x ] = true;
				}
				$attrs .= ' data-f-' . esc_attr( str_replace( '_', '-', $f ) ) . '="' . esc_attr( implode( ' ', array_map( 'sanitize_title', $vals ) ) ) . '"';
			}
			if ( 'removed_from_game' === $r['status'] ) {
				$attrs .= ' data-removed hidden';
			}
			$row = '<tr id="' . esc_attr( (string) $r['slug'] ) . '"' . $attrs . '><th scope="row" class="rbx-cell-name">' . Ui::icon( $ds, $r, 48 ) . Ui::link( $r ) . '</th>';
			foreach ( $fields as $f => $fd ) {
				$st   = $ds->field_state( $r, $f );
				// Every known number sorts; only conflicting reports are left out (UO-27: all data is wiki data).
				$sort = is_numeric( $st['value'] ) && 'disputed' !== $st['state'] ? ' data-sort="' . esc_attr( (string) $st['value'] ) . '"' : '';
				$cell = Ui::cell( $ds, $r, $f );
				$dash = $dash || str_contains( $cell, '—' );
				$row .= '<td data-label="' . esc_attr( Lang::t( (string) ( $fd['label__en'] ?? $f ) ) ) . '"' . $sort . '>' . $cell . '</td>';
			}
			if ( 'PET' === $entity ) {
				$eggs = array();
				foreach ( $ds->children( 'DRP', 'pet_id', (string) $id ) as $d ) {
					$eggs[] = Ui::link( $ds->get( (string) $d['data']['egg_id'] ) );
				}
				$dash = $dash || ! $eggs;
				$row .= '<td data-label="' . esc_attr__( 'Source egg', 'rbx-data' ) . '">' . ( $eggs ? implode( ', ', array_unique( $eggs ) ) : Ui::dash() ) . '</td>';
			}
			if ( 'EGG' === $entity ) {
				$row .= '<td data-label="' . esc_attr__( 'Pets', 'rbx-data' ) . '">' . esc_html( number_format_i18n( count( $ds->children( 'DRP', 'egg_id', (string) $id ) ) ) ) . '</td>';
			}
			$row .= ( $one_conf ? '' : '<td data-label="' . esc_attr__( 'Confidence', 'rbx-data' ) . '">' . $conf[ $id ] . '</td>' ) . '</tr>';
			// GR-24: the first rows are in the page; the rest wait in a <template> (not part of the
			// page's DOM) until a search, filter, sort or "Show all" needs them.
			if ( ++$n_rows <= self::LIST_ROWS ) {
				$body .= $row;
			} else {
				$rest .= $row;
			}
		}
		if ( '' !== $rest ) {
			$body .= '<template data-rbx-rest>' . $rest . '</template>';
		}

		$chips = '';
		foreach ( $facets as $f => $fd ) {
			$vals = array_keys( $facet_vals[ $f ] ?? array() );
			if ( count( $vals ) < 2 ) {
				continue;
			}
			$chips .= '<fieldset class="rbx-facet"><legend>' . esc_html( Lang::t( (string) ( $fd['label__en'] ?? $f ) ) ) . '</legend>';
			foreach ( $vals as $v ) {
				$label = 'ref' === ( $fd['type'] ?? '' ) || 'ref_list' === ( $fd['type'] ?? '' ) ? (string) ( $ds->get( $v )['name'] ?? $v ) : ( 'bool' === ( $fd['type'] ?? '' ) ? ( 'yes' === $v ? __( 'Yes', 'rbx-data' ) : __( 'No', 'rbx-data' ) ) : Ui::enum_label( $v ) );
				$chips .= '<button type="button" class="rbx-chip-btn" aria-pressed="false" data-facet="' . esc_attr( str_replace( '_', '-', $f ) ) . '" data-value="' . esc_attr( sanitize_title( $v ) ) . '">' . esc_html( $label ) . '</button>';
			}
			$chips .= '</fieldset>';
		}
		$has_removed = (bool) array_filter( $recs, static fn( $r ) => 'removed_from_game' === $r['status'] );
		$label       = $game->entity_label( $entity, true );
		// Search and sort stay in view; the facet chips fold into "Filters" on phones (UO-30).
		$controls    = '<div class="rbx-list__controls" hidden data-rbx-controls>'
			. '<label class="rbx-search"><span>' . esc_html__( 'Search this list', 'rbx-data' ) . '</span><input type="search" data-rbx-q autocomplete="off"></label>'
			. ( '' !== $chips ? '<details class="rbx-filters" data-rbx-filters><summary>' . esc_html__( 'Filters', 'rbx-data' ) . ' <span data-rbx-filter-count></span></summary><div class="rbx-filters__body">' . $chips . '</div></details>' : '' )
			. '<label class="rbx-sort"><span>' . esc_html__( 'Sort', 'rbx-data' ) . '</span><select data-rbx-sort><option value="">' . esc_html__( 'Default order', 'rbx-data' ) . '</option><option value="name-asc">' . esc_html__( 'Name A to Z', 'rbx-data' ) . '</option>';
		foreach ( $fields as $f => $fd ) {
			if ( $ds->is_numeric_field( $entity, $f ) ) {
				/* translators: %s: field label */
				$controls .= '<option value="' . esc_attr( $f ) . '-desc">' . esc_html( sprintf( __( '%s, highest first', 'rbx-data' ), Lang::t( (string) ( $fd['label__en'] ?? $f ) ) ) ) . '</option>';
			}
		}
		$controls .= '</select></label>'
			. ( $has_removed ? '<button type="button" class="rbx-chip-btn" aria-pressed="false" data-rbx-removed>' . esc_html__( 'Show removed', 'rbx-data' ) . '</button>' : '' )
			. '<button type="button" class="rbx-link-btn" data-rbx-clear hidden>' . esc_html__( 'Clear filters', 'rbx-data' ) . '</button>'
			/* translators: 1: rows shown, 2: rows in the list, 3: plural item label, e.g. pets */
			. '<p class="rbx-live" role="status" data-rbx-status data-template="' . esc_attr( sprintf( __( 'Showing %1$s of %2$s %3$s', 'rbx-data' ), '{shown}', '{total}', strtolower( Lang::t( $label ) ) ) ) . '"></p></div>';

		$legend = $one_conf ? '<p class="rbx-legend">' . esc_html__( 'Confidence for every row:', 'rbx-data' ) . ' ' . reset( $conf ) . '</p>' : '';
		$table  = $legend . '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( Lang::t( $label ) ) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
		$empty = '<p class="rbx-empty" hidden data-rbx-empty>' . esc_html__( 'No results. Try fewer filters.', 'rbx-data' ) . ' <a href="' . esc_url( home_url( '/report/' ) ) . '">' . esc_html__( 'Report a missing item', 'rbx-data' ) . '</a></p>';
		// UO-55: after a filter or sort, long lists show the first 100 matches and this button.
		if ( count( $recs ) > 100 ) {
			$live = count( array_filter( $recs, static fn( $r ) => 'removed_from_game' !== $r['status'] ) );
			/* translators: {n}: number of matching rows, filled in by list.js */
			$empty .= '<p class="rbx-more" hidden><button type="button" class="button-secondary" data-rbx-more data-total="' . esc_attr( (string) $live ) . '" data-template="' . esc_attr__( 'Show all {n}', 'rbx-data' ) . '"></button></p>';
		}
		if ( '' !== $rest ) {
			/* translators: 1: rows shown, 2: rows in the list */
			$empty .= '<noscript><p class="rbx-note">' . esc_html( sprintf( __( 'Showing the first %1$s of %2$s. Turn on JavaScript to see the full list, or use the search at the top of the site.', 'rbx-data' ), number_format_i18n( self::LIST_ROWS ), number_format_i18n( count( $recs ) ) ) ) . '</p></noscript>';
		}
		self::enqueue_list_js();
		$top = '<a class="rbx-backtop" href="#top" hidden data-rbx-backtop>' . esc_html__( 'Back to top', 'rbx-data' ) . '</a>';
		$note = $dash ? '<p class="rbx-note">' . esc_html__( 'A dash means the community wiki does not list that value yet.', 'rbx-data' ) . '</p>' : '';
		return '<div class="rbx-list" data-rbx-list data-entity="' . esc_attr( strtolower( $entity ) ) . '">' . $controls . $table . $empty . $top . '</div>' . $note . self::sources_line( $game, $recs );
	}

	/**
	 * Links to the items of a list that have a full page in search (UO-25), above the table, so
	 * the pages that can rank get links from the list page that is about them.
	 */
	public static function full_guides( Game $game, string $entity ): string {
		$links = array();
		foreach ( self::ds( $game )->all( $entity, array( 'live', 'limited' ) ) as $r ) {
			$pid = (int) ( $r['post_id'] ?? 0 );
			if ( $pid && 'publish' === get_post_status( $pid ) && PageState::INDEX === get_post_meta( $pid, '_rbx_page_state', true ) ) {
				$links[ (string) $r['name'] ] = '<a href="' . esc_url( (string) get_permalink( $pid ) ) . '">' . esc_html( (string) $r['name'] ) . '</a>';
			}
		}
		if ( ! $links ) {
			return '';
		}
		ksort( $links, SORT_NATURAL | SORT_FLAG_CASE );
		/* translators: %s: plural entity label, e.g. pets */
		$label = sprintf( __( 'Full guides to %s', 'rbx-data' ), strtolower( Lang::t( $game->entity_label( $entity, true ) ) ) );
		return '<nav class="rbx-full-guides" aria-label="' . esc_attr( $label ) . '"><p><strong>' . esc_html( $label ) . ':</strong> ' . implode( ' <span aria-hidden="true">·</span> ', $links ) . '</p></nav>';
	}

	/**
	 * One "Sources" line under a list table (UO-24): the wiki pages its rows come from, linked.
	 *
	 * @param array<string, array<string, mixed>> $recs Records in the table.
	 */
	private static function sources_line( Game $game, array $recs ): string {
		$urls = array();
		foreach ( $recs as $r ) {
			$u = (string) ( $r['data']['source_url'] ?? '' );
			if ( str_starts_with( $u, 'https://' ) ) {
				$urls[ $u ] = ( $urls[ $u ] ?? 0 ) + 1;
			}
		}
		if ( ! $urls ) {
			return '';
		}
		if ( count( $urls ) > 5 && '' !== $game->wiki_url() ) {
			// One wiki page per row: link the wiki itself; each item page links its own source.
			return '<p class="rbx-sources">' . esc_html__( 'Sources:', 'rbx-data' ) . ' ' . sprintf(
				/* translators: %s: linked wiki name */
				esc_html__( 'each item\'s page on the %s', 'rbx-data' ),
				'<a href="' . esc_url( $game->wiki_url() ) . '" rel="noopener">' . esc_html( $game->wiki_name() ) . '</a>'
			) . '</p>';
		}
		arsort( $urls );
		$links = array();
		foreach ( array_slice( array_keys( $urls ), 0, 5 ) as $u ) {
			$page    = str_replace( '_', ' ', rawurldecode( basename( (string) wp_parse_url( $u, PHP_URL_PATH ) ) ) );
			$host    = (string) wp_parse_url( $u, PHP_URL_HOST );
			$links[] = '<a href="' . esc_url( $u ) . '" rel="noopener">' . esc_html( ( str_ends_with( $host, 'fandom.com' ) && '' !== $game->wiki_name() ? $game->wiki_name() : $host ) . ': ' . $page ) . '</a>';
		}
		$more = '';
		return '<p class="rbx-sources">' . esc_html__( 'Sources:', 'rbx-data' ) . ' ' . implode( ', ', $links ) . $more . '</p>';
	}

	/** Record-level confidence chip. */
	private static function confidence( array $r ): string {
		$st = array(
			'state'             => null === ( $r['data']['source_type'] ?? null ) ? 'unknown' : ( 'community' === $r['data']['source_type'] ? 'community' : 'verified' ),
			'mechanic_ok'       => true,
			'source_type'       => null, // One "Sources" line per table instead (UO-24, GR-26), see sources_line().
			'source_url'        => null,
			'evidence_media_id' => null,
		);
		$st['label'] = Dataset::state_label( $st['state'] );
		return Ui::chip( $st );
	}

	/** Copy button for a code, named after it for screen readers (UO-46). */
	private static function copy_button( string $code ): string {
		/* translators: %s: game code */
		$label = sprintf( __( 'Copy code %s', 'rbx-data' ), $code );
		/* translators: %s: game code */
		$done = sprintf( __( 'Copied %s', 'rbx-data' ), $code );
		return '<button type="button" class="rbx-copy" data-copy="' . esc_attr( $code ) . '" data-copied="' . esc_attr( $done ) . '" aria-label="' . esc_attr( $label ) . '" hidden>' . esc_html__( 'Copy', 'rbx-data' ) . '</button>';
	}

	public static function enqueue_list_js(): void {
		wp_enqueue_script_module( 'rbx-data-list', RBX_DATA_URL . 'assets/front/list.js', array(), Ui::asset_ver( 'assets/front/list.js' ) );
	}

	/** Mechanic text by token (CONT-24 item 2, ED-11). */
	private static function mechanic_text( Game $game, string $key ): string {
		$ds = self::ds( $game );
		$m  = $ds->mechanic( $key );
		if ( null === $m ) {
			return '';
		}
		$txt = Lang::t( (string) ( $m['data']['definition__en'] ?? $m['data']['description__en'] ?? '' ) ); // GR-18.
		$out = '' !== $txt ? wpautop( Ui::text( $txt ) ) : '';
		if ( 'confirmed' !== ( $m['data']['status'] ?? '' ) ) {
			$out .= '<p class="rbx-note">' . self::mechanic_chip( $m ) . ' ' . ( self::mechanic_tested( $m ) || 'partially_confirmed' !== ( $m['data']['status'] ?? '' )
				? esc_html__( 'This is not confirmed yet. We will update it once we test it in the game.', 'rbx-data' )
				: esc_html__( 'The community wiki gives this rule. We have not tested it in the game ourselves.', 'rbx-data' ) ) . '</p>';
		}
		self::show( array( (string) $m['id'] ) );
		return $out;
	}

	/** A mechanic has an in-game test when it has a screenshot of a reading (GR-03). */
	private static function mechanic_tested( array $m ): bool {
		return 'in_game' === ( $m['data']['source_type'] ?? '' ) && ! empty( $m['data']['evidence_media_id'] );
	}

	/**
	 * Badge for a mechanic that is not fully confirmed (UO-07). "Partly tested" only with an
	 * in-game reading behind it (GR-03); a rule taken from the wiki says so.
	 */
	private static function mechanic_chip( array $m ): string {
		if ( 'partially_confirmed' !== ( $m['data']['status'] ?? '' ) ) {
			return '<span class="rbx-chip rbx-chip--warning">' . esc_html__( 'Not tested in game yet', 'rbx-data' ) . '</span>';
		}
		return '<span class="rbx-chip rbx-chip--warning">' . esc_html( self::mechanic_tested( $m ) ? __( 'Partly tested in game', 'rbx-data' ) : __( 'Wiki rule, not tested by us', 'rbx-data' ) ) . '</span>';
	}

	/** Mutation table with an id anchor per row (CONT-24, REV C-39). */
	private static function mutations( Game $game ): string {
		if ( null === $game->entity( 'MUT' ) ) {
			return '';
		}
		$ds   = self::ds( $game );
		$recs = $ds->all( 'MUT', array( 'live', 'limited', 'removed_from_game' ) );
		if ( ! $recs ) {
			return '<p class="rbx-empty">' . esc_html__( 'No mutations are listed yet. We add them only after checking them in the game.', 'rbx-data' ) . '</p>';
		}
		self::show( array_keys( $recs ) );
		// "Added in" column only for games that track it (GR-05).
		$added = null !== $game->field( 'MUT', 'added_in' );
		// GR-26: when every row has the same confidence and date, one line above the table says so
		// instead of a chip and a wiki link on every row (196 chips on the GAG mutations page).
		$conf = array();
		$asof = array();
		foreach ( $recs as $id => $r ) {
			$st          = $ds->field_state( $r, 'income_mult' );
			$conf[ $id ] = Ui::chip( array( 'source_type' => null, 'source_url' => null ) + $st );
			$asof[ $id ] = (string) ( $r['data']['last_verified'] ?? '' );
		}
		$one  = 1 === count( array_unique( $conf ) ) && 1 === count( array_unique( $asof ) );
		$dash = false;
		$rows = '';
		foreach ( $recs as $id => $r ) {
			$how   = empty( $r['data']['obtain_method_text__en'] ) ? Ui::dash() : Ui::text( (string) $r['data']['obtain_method_text__en'] );
			$mult  = Ui::cell( $ds, $r, 'income_mult' );
			$dash  = $dash || empty( $r['data']['obtain_method_text__en'] ) || str_contains( $mult, '—' );
			$rows .= '<tr id="' . esc_attr( (string) $r['slug'] ) . '"><th scope="row">' . Ui::link( $r ) . '</th>'
				. '<td data-label="' . esc_attr( $game->label( 'MUT', 'income_mult' ) ) . '">' . $mult . '</td>'
				. '<td data-label="' . esc_attr( $game->label( 'MUT', 'obtain_method_text__en' ) ) . '">' . $how . '</td>'
				. ( $added ? '<td data-label="' . esc_attr( $game->label( 'MUT', 'added_in' ) ) . '">' . ( empty( $r['data']['added_in'] ) ? Ui::dash() : esc_html( (string) $r['data']['added_in'] ) ) . '</td>' : '' )
				. ( $one ? '' : '<td data-label="' . esc_attr__( 'Confidence', 'rbx-data' ) . '">' . $conf[ $id ] . '</td><td data-label="' . esc_attr__( 'Data as of', 'rbx-data' ) . '">' . Ui::date( $asof[ $id ] ) . '</td>' ) . '</tr>';
		}
		$legend = $one ? '<p class="rbx-legend">' . esc_html__( 'Confidence for every row:', 'rbx-data' ) . ' ' . reset( $conf ) . ( '' !== reset( $asof ) ? ' <span class="rbx-lv">' . esc_html__( 'Wiki data as of', 'rbx-data' ) . ' ' . Ui::date( reset( $asof ) ) . '</span>' : '' ) . '</p>' : '';
		$note   = $dash ? '<p class="rbx-note">' . esc_html__( 'A dash means the community wiki does not list that value yet.', 'rbx-data' ) . '</p>' : '';
		return $legend . '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $game->entity_label( 'MUT', true ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Mutation', 'rbx-data' ) . '</th><th scope="col">' . esc_html( $game->label( 'MUT', 'income_mult' ) ) . '</th><th scope="col">' . esc_html( $game->label( 'MUT', 'obtain_method_text__en' ) ) . '</th>' . ( $added ? '<th scope="col">' . esc_html( $game->label( 'MUT', 'added_in' ) ) . '</th>' : '' ) . ( $one ? '' : '<th scope="col">' . esc_html__( 'Confidence', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Data as of', 'rbx-data' ) . '</th>' ) . '</tr></thead><tbody>' . $rows . '</tbody></table></div>' . $note . self::sources_line( $game, $recs );
	}

	private static function sizes( Game $game ): string {
		if ( null === $game->entity( 'SIZ' ) ) {
			return '';
		}
		$ds   = self::ds( $game );
		$recs = $ds->all( 'SIZ', array( 'live', 'limited' ) );
		if ( ! $recs ) {
			return '<p class="rbx-empty">' . esc_html__( 'No sizes are listed yet.', 'rbx-data' ) . '</p>';
		}
		self::show( array_keys( $recs ) );
		$rows = '';
		foreach ( $recs as $r ) {
			$rows .= '<tr id="size-' . esc_attr( (string) ( $r['slug'] ?? sanitize_title( (string) $r['name'] ) ) ) . '"><th scope="row">' . esc_html( (string) $r['name'] ) . '</th><td data-label="' . esc_attr( $game->label( 'SIZ', 'income_mult' ) ) . '">' . Ui::value( $ds, $r, 'income_mult', false ) . '</td><td data-label="' . esc_attr__( 'Confidence', 'rbx-data' ) . '">' . Ui::chip( $ds->field_state( $r, 'income_mult' ), $ds ) . '</td></tr>';
		}
		return '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $game->entity_label( 'SIZ', true ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Size', 'rbx-data' ) . '</th><th scope="col">' . esc_html( $game->label( 'SIZ', 'income_mult' ) ) . '</th><th scope="col">' . esc_html__( 'Confidence', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
	}

	/** Codes page body (CONT-25, POL-51, UX-09). */
	private static function codes( Game $game ): string {
		$ds      = self::ds( $game );
		$codes   = $game->entity( 'COD' ) ? $ds->all( 'COD', null ) : array();
		$active  = array_filter( $codes, static fn( $c ) => 'active' === ( $c['data']['code_status'] ?? '' ) && 'live' === $c['status'] );
		$expired = array_filter( $codes, static fn( $c ) => 'expired' === ( $c['data']['code_status'] ?? '' ) && in_array( $c['status'], array( 'live', 'removed_from_game' ), true ) );
		$reported = array_filter( $codes, static fn( $c ) => 'unverified' === ( $c['data']['code_status'] ?? '' ) && 'live' === $c['status'] );
		$last     = CheckLog::last_checked( $game );
		$checked  = self::codes_checked_line( $ds, $last );
		// Say "community wiki" only when every reported code comes from it (GR-07).
		$wiki = ! array_filter( $reported, static fn( $c ) => ! str_contains( (string) ( $c['data']['source_url'] ?? '' ), 'fandom.com' ) );
		self::show( array_keys( $active + $expired + $reported ) );
		$out = '<div class="rbx-codes-status" role="region" aria-label="' . esc_attr__( 'Code status', 'rbx-data' ) . '">';
		if ( ! $active && $reported ) {
			$out .= '<p class="rbx-codes-status__head"><strong>' . esc_html__( 'No codes tested by us yet', 'rbx-data' ) . '</strong></p><p>' . esc_html(
				$wiki
					/* translators: %s: number */
					? sprintf( _n( '%s code is reported as working on the community wiki. We have not redeemed it ourselves, so it may not work.', '%s codes are reported as working on the community wiki. We have not redeemed them ourselves, so some may not work.', count( $reported ), 'rbx-data' ), number_format_i18n( count( $reported ) ) )
					/* translators: %s: number */
					: sprintf( _n( '%s code is reported as working by community code lists. We have not redeemed it ourselves, so it may not work.', '%s codes are reported as working by community code lists. We have not redeemed them ourselves, so some may not work.', count( $reported ), 'rbx-data' ), number_format_i18n( count( $reported ) ) )
			) . '</p>';
		} elseif ( ! $active ) {
			/* translators: %s: game name */
			$out .= '<p class="rbx-codes-status__head"><strong>' . esc_html__( 'No working codes right now', 'rbx-data' ) . '</strong></p><p>' . sprintf( esc_html__( 'There are no %s codes right now.', 'rbx-data' ), esc_html( $game->name() ) ) . '</p>';
		} else {
			$out .= '<p class="rbx-codes-status__head"><strong>' . esc_html( sprintf( /* translators: %s: number */ _n( 'Working codes: %s', 'Working codes: %s', count( $active ), 'rbx-data' ), number_format_i18n( count( $active ) ) ) ) . '</strong>' . ( '' !== $last ? ' ' . esc_html__( 'We tested them on', 'rbx-data' ) . ' ' . Ui::date( $last ) . '.' : '' ) . '</p>';
		}
		$out .= $checked . '</div>';
		if ( $active ) {
			$rows = '';
			foreach ( $active as $c ) {
				$d     = $c['data'];
				$t     = (string) ( $d['last_tested_at'] ?? '' );
				$fresh = '' !== $t && strtotime( $t . ' UTC' ) >= time() - 72 * HOUR_IN_SECONDS;
				$state = $fresh
					? esc_html__( 'Last tested', 'rbx-data' ) . ' ' . Ui::date( $t )
					/* translators: %s: date */
					: '<span class="rbx-chip rbx-chip--warning">' . sprintf( esc_html__( 'Not re-checked since %s', 'rbx-data' ), Ui::date( '' !== $t ? $t : (string) ( $d['first_seen'] ?? '' ) ) ) . '</span>';
				$rows .= '<tr><th scope="row"><code class="rbx-code">' . esc_html( (string) $d['code'] ) . '</code> ' . self::copy_button( (string) $d['code'] ) . '</th><td data-label="' . esc_attr__( 'Reward', 'rbx-data' ) . '">' . Ui::text( (string) ( $d['reward_text__en'] ?? '' ) ) . '</td><td data-label="' . esc_attr__( 'First seen', 'rbx-data' ) . '">' . Ui::date( (string) ( $d['first_seen'] ?? '' ) ) . '</td><td data-label="' . esc_attr__( 'Tested', 'rbx-data' ) . '">' . $state . ( ! empty( $d['tested_by'] ) ? ' ' . esc_html__( 'by', 'rbx-data' ) . ' ' . esc_html( (string) $d['tested_by'] ) : '' ) . '</td><td data-label="' . esc_attr__( 'Source', 'rbx-data' ) . '">' . Ui::source_link( $d['source_type'] ?? null, $d['source_url'] ?? null, null ) . '</td></tr>';
			}
			$out .= Ui::h2( __( 'Active codes', 'rbx-data' ), 'active-codes' ) . '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><thead><tr><th scope="col">' . esc_html__( 'Code', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Reward', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'First seen', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Tested', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Source', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div><p class="rbx-live" role="status" data-rbx-copy-status></p>';
			wp_enqueue_script_module( 'rbx-data-copy', RBX_DATA_URL . 'assets/front/copy.js', array(), Ui::asset_ver( 'assets/front/copy.js' ) );
		}
		if ( $reported ) {
			$rows = '';
			foreach ( $reported as $c ) {
				$d     = $c['data'];
				$rows .= '<tr><th scope="row"><code class="rbx-code">' . esc_html( (string) $d['code'] ) . '</code> ' . self::copy_button( (string) $d['code'] ) . '</th><td data-label="' . esc_attr__( 'Reward', 'rbx-data' ) . '">' . Ui::text( (string) ( $d['reward_text__en'] ?? '' ) ) . '</td><td data-label="' . esc_attr__( 'Status', 'rbx-data' ) . '"><span class="rbx-chip rbx-chip--warning">' . esc_html__( 'Not tested by us', 'rbx-data' ) . '</span></td><td data-label="' . esc_attr__( 'Source', 'rbx-data' ) . '">' . Ui::source_link( $d['source_type'] ?? null, $d['source_url'] ?? null, null ) . '</td></tr>';
			}
			$out .= Ui::h2( __( 'Reported codes', 'rbx-data' ), 'reported-codes' ) . '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><thead><tr><th scope="col">' . esc_html__( 'Code', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Reward', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Status', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Source', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div><p class="rbx-live" role="status" data-rbx-copy-status></p>';
			wp_enqueue_script_module( 'rbx-data-copy', RBX_DATA_URL . 'assets/front/copy.js', array(), Ui::asset_ver( 'assets/front/copy.js' ) );
		}
		if ( $expired ) {
			$li = '';
			foreach ( $expired as $c ) {
				$li .= '<li><code>' . esc_html( (string) $c['data']['code'] ) . '</code>' . ( ! empty( $c['data']['expires_at'] ) ? ', ' . esc_html__( 'expired', 'rbx-data' ) . ' ' . Ui::date( (string) $c['data']['expires_at'] ) : '' ) . '</li>';
			}
			$out .= '<details class="rbx-expired"><summary>' . esc_html__( 'Expired codes', 'rbx-data' ) . '</summary><ul>' . $li . '</ul></details>';
		}
		$redeem = (string) ( $ds->meta( 'redeem_ui_exists' ) ?? 'unknown' );
		/* translators: %s: game name */
		$out .= Ui::h2( sprintf( __( 'Does %s have a code redeem button?', 'rbx-data' ), $game->name() ), 'redeem-button' );
		$out .= '<p>' . ( 'yes' === $redeem
			? esc_html__( 'Yes. The game has a place to type codes.', 'rbx-data' )
			: ( 'no' === $redeem ? esc_html__( 'Community reports and the game page show no code box right now.', 'rbx-data' ) : esc_html__( 'We have not confirmed this yet.', 'rbx-data' ) ) ) . '</p>';
		$channels = (array) ( $ds->meta( 'official_channels' ) ?? array() );
		$out     .= Ui::h2( __( 'How we check for codes', 'rbx-data' ), 'how-we-check' ) . '<p>' . esc_html__( 'We look at the channels below and the community wiki for new codes. We mark a code as working only after we redeem it in the game or the developer posts it. Codes that other players report, but we have not tested, are listed separately as reported codes.', 'rbx-data' ) . '</p>';
		if ( $channels ) {
			$out .= '<ul>';
			foreach ( $channels as $u ) {
				$path = (string) wp_parse_url( (string) $u, PHP_URL_PATH );
				$name = str_contains( $path, '/games/' ) ? __( 'Game page on Roblox', 'rbx-data' ) : ( str_contains( $path, '/communities/' ) || str_contains( $path, '/groups/' ) ? __( 'Developer group on Roblox', 'rbx-data' ) : (string) wp_parse_url( (string) $u, PHP_URL_HOST ) );
				$out .= '<li><a href="' . esc_url( (string) $u ) . '" rel="noopener nofollow">' . esc_html( $name ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		$out .= '<aside class="rbx-scam" role="note"><h2 id="fake-code-scams">' . esc_html__( 'Stay safe: fake code scams', 'rbx-data' ) . '</h2><p>' . esc_html__( 'No code, website, or video can give free Robux. Never enter your password or run scripts from “code” sites.', 'rbx-data' ) . '</p><ul><li>' . esc_html__( 'Codes are only typed inside the game.', 'rbx-data' ) . '</li><li>' . esc_html__( 'Never share your password with anyone.', 'rbx-data' ) . '</li><li>' . esc_html__( 'Do not download anything to get codes or pets.', 'rbx-data' ) . '</li></ul></aside>';
		return $out;
	}

	/**
	 * "Last checked: 24 Oct 2026, 19:00 UTC" (GR-22): the newer of the codes check log (a date)
	 * and `_meta.codes_checked_at` (a UTC time).
	 */
	private static function codes_checked_line( Dataset $ds, string $log_date ): string {
		$at = (string) ( $ds->meta( 'codes_checked_at' ) ?? '' );
		$t  = '' !== $at ? (int) strtotime( $at ) : 0;
		if ( '' !== $log_date && ( ! $t || $log_date > gmdate( 'Y-m-d', $t ) ) ) {
			return '<p class="rbx-meta">' . esc_html__( 'Last checked:', 'rbx-data' ) . ' ' . Ui::date( $log_date ) . '</p>';
		}
		if ( ! $t ) {
			return '';
		}
		return '<p class="rbx-meta">' . esc_html__( 'Last checked:', 'rbx-data' ) . ' <time datetime="' . esc_attr( gmdate( 'c', $t ) ) . '">' . esc_html( wp_date( 'j M Y, H:i', $t, new \DateTimeZone( 'UTC' ) ) ) . ' UTC</time></p>';
	}

	/** Updates hub list, newest first (CONT-26). */
	private static function updates( Game $game ): string {
		$ds   = self::ds( $game );
		$upds = $ds->all( 'UPD', array( 'live', 'limited' ) );
		uasort( $upds, static fn( $a, $b ) => strcmp( (string) ( $b['data']['release_date'] ?? '' ), (string) ( $a['data']['release_date'] ?? '' ) ) ?: strcmp( (string) $b['id'], (string) $a['id'] ) );
		$pub     = Ui::last_publish( $game );
		/* translators: %s: date and time in UTC */
		$out     = '' !== $pub ? '<p class="rbx-live-now"><strong>' . esc_html__( 'Live now:', 'rbx-data' ) . '</strong> ' . sprintf( esc_html__( 'Roblox shows the newest version of the game was published on %s.', 'rbx-data' ), $pub ) . '</p>' : '';
		$first   = reset( $upds );
		if ( $first ) {
			/* translators: 1: update name, 2: date */
			// GR-21: the page intro already gives the cadence, so it is not repeated here.
			$out .= '<p class="rbx-summary">' . sprintf( esc_html__( 'The latest update is %1$s, released on %2$s.', 'rbx-data' ), Ui::link( $first ), Ui::date( (string) ( $first['data']['release_date'] ?? '' ) ) ) . '</p>';
		} else {
			$out .= '<p class="rbx-empty">' . esc_html__( 'No updates are listed yet.', 'rbx-data' ) . '</p>';
			return $out;
		}
		self::show( array_keys( $upds ) );
		$out .= '<ol class="rbx-updates" reversed>';
		foreach ( $upds as $u ) {
			$guide = trim( (string) ( $u['data']['guide_path'] ?? '' ) );
			$out  .= '<li class="rbx-update-card"><h3>' . Ui::link( $u, '', false ) . '</h3><p class="rbx-meta">' . Ui::date( (string) ( $u['data']['release_date'] ?? '' ) ) . ( '' !== $guide ? ' · <a href="' . esc_url( home_url( $guide ) ) . '">' . esc_html__( 'Event guide', 'rbx-data' ) . '</a>' : '' ) . '</p>' . ( ! empty( $u['data']['summary__en'] ) ? '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $u['data']['summary__en'] ), 40 ) ) . '</p>' : '' ) . '</li>';
		}
		return $out . '</ol>';
	}

	/** Tier list S..D with one-line reasons (CONT-27, UX-12). */
	private static function tier_list( Game $game ): string {
		if ( null === $game->field( 'PET', 'tier_list_rank' ) ) {
			return '';
		}
		$ds    = self::ds( $game );
		$tiers = array();
		foreach ( $ds->all( 'PET', array( 'live', 'limited' ) ) as $id => $r ) {
			$t = (string) ( $r['data']['tier_list_rank'] ?? '' );
			if ( '' !== $t ) {
				$tiers[ $t ][ $id ] = $r;
			}
		}
		if ( ! $tiers ) {
			return '<p class="rbx-empty">' . esc_html__( 'The tier list is not ready yet. We rank pets only after testing them.', 'rbx-data' ) . '</p>';
		}
		$out = '';
		foreach ( array( 'S', 'A', 'B', 'C', 'D' ) as $t ) {
			if ( empty( $tiers[ $t ] ) ) {
				continue;
			}
			self::show( array_keys( $tiers[ $t ] ) );
			$out .= '<section class="rbx-tier rbx-tier--' . esc_attr( strtolower( $t ) ) . '" aria-labelledby="tier-' . esc_attr( strtolower( $t ) ) . '"><h2 id="tier-' . esc_attr( strtolower( $t ) ) . '"><span class="rbx-tier__badge" aria-hidden="true">' . esc_html( $t ) . '</span> ' . esc_html( sprintf( /* translators: %s: tier letter */ __( '%s tier', 'rbx-data' ), $t ) ) . '</h2><ul class="rbx-tier__list">';
			foreach ( $tiers[ $t ] as $r ) {
				$why  = (string) ( ( Lang::is_id() ? ( $r['data']['tier_rationale__id'] ?? '' ) : '' ) ?: ( $r['data']['tier_rationale__en'] ?? '' ) ); // Indonesian reason on /id/ when written.
				$out .= '<li>' . Ui::icon( $ds, $r, 48 ) . '<span class="rbx-tier__name">' . Ui::link( $r ) . '</span>' . ( '' !== $why ? '<span class="rbx-tier__why">' . esc_html( wp_strip_all_tags( $why ) ) . '</span>' : '' ) . '</li>';
			}
			$out .= '</ul></section>';
		}
		return $out . '<p class="rbx-note">' . esc_html__( 'Ranked by base income from the community wiki; your best pet depends on your goal.', 'rbx-data' ) . '</p>';
	}

	/**
	 * Tier list by stage (UO-70): the zones grouped by the Speed they need (stages from the game
	 * config's tier_stages), the best pet of each zone, and a faster pick whose egg hatches in half
	 * the time or less and that still earns at least a twentieth as much. Then the event zones. All from the dataset, so it follows every import.
	 */
	private static function tier_stages( Game $game ): string {
		$stages = (array) ( $game->raw()['tier_stages'] ?? array() );
		if ( ! $stages || null === $game->entity( 'BIO' ) ) {
			return '';
		}
		$ds     = self::ds( $game );
		$hatch  = static fn( array $e ): ?float => is_numeric( $e['data']['hatch_time_s'] ?? null ) && (float) $e['data']['hatch_time_s'] > 0 ? (float) $e['data']['hatch_time_s'] : null;
		$groups = array();
		foreach ( $ds->all( 'BIO', array( 'live' ) ) as $bio ) {
			$speed = $bio['data']['unlock_speed_required'] ?? null;
			if ( ! is_numeric( $speed ) ) {
				continue;
			}
			foreach ( $stages as $i => $st ) {
				if ( ! isset( $st['max_speed'] ) || (float) $speed < (float) $st['max_speed'] ) {
					$groups[ $i ][] = $bio;
					break;
				}
			}
		}
		$out = '';
		foreach ( $stages as $i => $st ) {
			if ( empty( $groups[ $i ] ) ) {
				continue;
			}
			$rows = '';
			$top  = null;
			foreach ( $groups[ $i ] as $bio ) {
				$pets = Insights::zone_pets( $ds, (string) $bio['id'] );
				if ( ! $pets ) {
					continue;
				}
				$best = reset( $pets );
				$bh   = $hatch( $best['egg'] );
				$fast = null;
				foreach ( array_slice( $pets, 1, null, true ) as $p ) {
					$h = $hatch( $p['egg'] );
					if ( null !== $bh && null !== $h && $h * 2 <= $bh && $p['income'] * 20 >= $best['income'] ) {
						$fast = $p;
						break;
					}
				}
				$top   = null === $top || $best['income'] > $top['income'] ? $best : $top;
				$ids   = array( (string) $best['record']['id'] );
				$cell  = static fn( array $p ) => Ui::link( $p['record'] ) . ' <span class="rbx-meta">' . Ui::number( $ds, $p['income'], 'abbr' ) . ( null !== $hatch( $p['egg'] ) ? ', ' . Ui::raw( $ds, array( 'type' => 'duration_s' ), $hatch( $p['egg'] ) ) : '' ) . '</span>';
				if ( $fast ) {
					$ids[] = (string) $fast['record']['id'];
				}
				self::show( $ids );
				$speed = (float) $bio['data']['unlock_speed_required'];
				$rows .= '<tr><th scope="row">' . esc_html( (string) $bio['name'] ) . '</th><td class="rbx-num-cell">' . ( $speed > 0 ? Ui::number( $ds, $speed, 'abbr' ) : esc_html__( 'Open from the start', 'rbx-data' ) ) . '</td><td>' . $cell( $best ) . '</td><td>' . ( $fast ? $cell( $fast ) : '' ) . '</td></tr>';
			}
			if ( '' === $rows ) {
				continue;
			}
			$label = Lang::t( (string) ( $st['label__en'] ?? '' ) );
			$slug  = sanitize_title( (string) ( $st['label__en'] ?? 'stage-' . $i ) );
			$lead  = sprintf(
				/* translators: 1: stage such as "Early game", 2: pet link */
				esc_html__( 'Best pet you can hatch in the %1$s zones: %2$s.', 'rbx-data' ),
				esc_html( function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label ) ),
				Ui::link( $top['record'] )
			);
			$out .= '<section class="rbx-stage" aria-labelledby="stage-' . esc_attr( $slug ) . '"><h3 id="stage-' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</h3><p>' . $lead . '</p>';
			$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( sprintf( /* translators: %s: stage */ __( 'Best pet of each %s zone', 'rbx-data' ), $label ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Zone', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Speed needed', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Best pet (income, hatch time)', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Faster pick (half the hatch time or less)', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
		}
		// Event zones: the strongest pets whose eggs come from a limited zone.
		foreach ( $ds->all( 'BIO', array( 'limited' ) ) as $bio ) {
			$pets = array();
			foreach ( $ds->all( 'EGG', array( 'live', 'limited' ) ) as $egg ) {
				if ( ! in_array( (string) $bio['id'], (array) ( $egg['data']['biome_ids'] ?? array() ), true ) ) {
					continue;
				}
				foreach ( $ds->children( 'DRP', 'egg_id', (string) $egg['id'] ) as $d ) {
					$pet = $ds->get( (string) $d['data']['pet_id'] );
					$st  = $pet ? $ds->field_state( $pet, 'base_income_per_s' ) : null;
					if ( $pet && $st['calc_usable'] && is_numeric( $st['value'] ) ) {
						$pets[ (string) $pet['id'] ] = array( 'record' => $pet, 'income' => (float) $st['value'] );
					}
				}
			}
			if ( count( $pets ) < 3 ) {
				continue;
			}
			uasort( $pets, static fn( $a, $b ) => $b['income'] <=> $a['income'] );
			$pets = array_slice( $pets, 0, 3, true );
			self::show( array_keys( $pets ) );
			$li = '';
			foreach ( $pets as $p ) {
				$li .= '<li>' . Ui::icon( $ds, $p['record'], 48 ) . Ui::link( $p['record'] ) . ' <span class="rbx-meta">' . Ui::number( $ds, $p['income'], 'abbr' ) . '</span></li>';
			}
			$slug = 'stage-' . sanitize_title( (string) $bio['slug'] );
			/* translators: %s: event zone */
			$out .= '<section class="rbx-stage" aria-labelledby="' . esc_attr( $slug ) . '"><h3 id="' . esc_attr( $slug ) . '">' . esc_html( sprintf( __( 'Event: %s', 'rbx-data' ), (string) $bio['name'] ) ) . '</h3><p>' . esc_html__( 'The strongest event pets while the event zone is open. They are limited, so they leave the game when the event ends.', 'rbx-data' ) . ( '' !== trim( (string) ( $bio['data']['guide_path'] ?? '' ) ) ? ' <a href="' . esc_url( home_url( (string) $bio['data']['guide_path'] ) ) . '">' . esc_html__( 'Read the event guide', 'rbx-data' ) . '</a>.' : '' ) . '</p><ol class="rbx-top">' . $li . '</ol></section>';
		}
		return '' === $out ? '' : $out . '<p class="rbx-note">' . esc_html__( 'Best pet means the highest base income from the community wiki. Hatch times are from the wiki too; the 2x Growth Speed pass halves them.', 'rbx-data' ) . '</p>';
	}

	/**
	 * "Reference" note for games that get no weekly checks (UO-71, Ali 2026-10-24): the game config
	 * sets "reference": true, and the date is the newest wiki date in the dataset.
	 */
	private static function reference_note( Game $game ): string {
		if ( empty( $game->raw()['reference'] ) ) {
			return '';
		}
		$newest = '';
		foreach ( self::ds( $game )->records() as $r ) {
			$d = (string) ( $r['data']['last_verified'] ?? '' );
			$newest = $d > $newest ? $d : $newest;
		}
		/* translators: %s: date */
		$when = '' !== $newest ? ' ' . sprintf( esc_html__( 'Data last refreshed %s.', 'rbx-data' ), Ui::date( substr( $newest, 0, 10 ) ) ) : '';
		return '<p class="rbx-note rbx-note--reference" role="note"><span class="rbx-chip rbx-chip--warning">' . esc_html__( 'Reference', 'rbx-data' ) . '</span> ' . esc_html__( 'We keep this game as a reference and do not re-check it after every update.', 'rbx-data' ) . $when . '</p>';
	}

	/** Hub tiles (UX-10): tools first, then sections, from menu_order; only existing Pages link. */
	private static function hub_tiles( Game $game ): string {
		$items = '';
		foreach ( $game->menu_order() as $slug ) {
			$s   = $game->section( $slug );
			$url = 'updates' === ( $s['type'] ?? '' ) ? Ui::updates_url( $game ) : Breadcrumbs::page_url( $game->folder() . '/' . $slug );
			if ( null === $s || '' === $url ) {
				continue;
			}
			$items .= '<li><a class="rbx-tile' . ( 'tool' === ( $s['type'] ?? '' ) ? ' rbx-tile--tool' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( (string) $s['label__en'] ) . '</a></li>';
		}
		return '' === $items ? '' : '<nav class="rbx-tiles" aria-label="' . esc_attr( $game->name() ) . '"><ul>' . $items . '</ul></nav>';
	}

	/** "Game at a glance" table (CONT-20 item 4). */
	private static function hub_glance( Game $game ): string {
		$ds   = self::ds( $game );
		$rows = '';
		$dev  = (string) ( $ds->meta( 'developer_name' ) ?? '' );
		if ( '' !== $dev ) {
			$rows .= '<tr><th scope="row">' . esc_html__( 'Developer', 'rbx-data' ) . '</th><td>' . esc_html( $dev ) . '</td></tr>';
		}
		if ( $ds->meta( 'release_date' ) ) {
			$rows .= '<tr><th scope="row">' . esc_html__( 'Release date', 'rbx-data' ) . '</th><td>' . Ui::date( (string) $ds->meta( 'release_date' ) ) . '</td></tr>';
		}
		$place = $game->place_id() ?? (string) ( $ds->meta( 'roblox_place_id' ) ?? '' );
		if ( $place ) {
			$rows .= '<tr><th scope="row">' . esc_html__( 'Play on Roblox', 'rbx-data' ) . '</th><td><a href="' . esc_url( 'https://www.roblox.com/games/' . rawurlencode( (string) $place ) ) . '" rel="noopener nofollow">roblox.com</a></td></tr>';
		}
		$pub = Ui::last_publish( $game );
		if ( '' !== $pub ) {
			$upd   = Ui::updates_url( $game );
			$rows .= '<tr><th scope="row">' . esc_html__( 'Latest publish on Roblox', 'rbx-data' ) . '</th><td>' . $pub . ( '' !== $upd ? ' <a href="' . esc_url( $upd ) . '">' . esc_html__( 'Updates', 'rbx-data' ) . '</a>' : '' ) . '</td></tr>';
		}
		$cad = Lang::t( (string) ( $ds->meta( 'update_cadence_text' ) ?? '' ) ); // UO-43: /id/ wording from content/i18n/id.json.
		if ( '' !== $cad ) {
			$rows .= '<tr><th scope="row">' . esc_html__( 'Updates', 'rbx-data' ) . '</th><td>' . esc_html__( 'Usually', 'rbx-data' ) . ' ' . esc_html( wp_strip_all_tags( $cad ) ) . '</td></tr>';
		}
		foreach ( array( 'PET', 'EGG', 'BIO', 'PLT', 'MUT' ) as $e ) {
			if ( $game->entity( $e ) ) {
				$n = $ds->count( $e );
				if ( $n > 0 ) {
					$rows .= '<tr><th scope="row">' . esc_html( $game->entity_label( $e, true ) ) . '</th><td>' . esc_html( number_format_i18n( $n ) ) . '</td></tr>';
				}
			}
		}
		return '' === $rows ? '' : '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--kv"><caption class="screen-reader-text">' . esc_html__( 'Game at a glance', 'rbx-data' ) . '</caption><tbody>' . $rows . '</tbody></table></div>';
	}

	/** Key terms from MEC rows with in_glossary (CONT-15). */
	private static function glossary( Game $game ): string {
		$ds = self::ds( $game );
		$dl = '';
		foreach ( $ds->all( 'MEC', null ) as $m ) {
			if ( empty( $m['data']['in_glossary'] ) || empty( $m['data']['definition__en'] ) ) {
				continue;
			}
			$term = (string) ( $m['data']['term__en'] ?? Ui::enum_label( (string) $m['data']['mechanic_key'] ) );
			$dl  .= '<div id="term-' . esc_attr( sanitize_title( $term ) ) . '"><dt>' . esc_html( $term ) . '</dt><dd>' . Ui::text( (string) $m['data']['definition__en'] ) . ( 'confirmed' !== ( $m['data']['status'] ?? '' ) ? ' ' . self::mechanic_chip( $m ) : '' ) . '</dd></div>';
		}
		return '' === $dl ? '' : '<dl class="rbx-glossary">' . $dl . '</dl>';
	}

	/** Biomes table (no biome URLs, REV C-27). */
	private static function biomes( Game $game ): string {
		if ( null === $game->entity( 'BIO' ) ) {
			return '';
		}
		$ds   = self::ds( $game );
		$recs = $ds->all( 'BIO', array( 'live', 'limited' ) );
		if ( ! $recs ) {
			return '';
		}
		self::show( array_keys( $recs ) );
		$rows = '';
		foreach ( $recs as $r ) {
			$rows .= '<tr id="biome-' . esc_attr( (string) $r['slug'] ) . '"><th scope="row">' . esc_html( (string) $r['name'] ) . '</th><td data-label="' . esc_attr( $game->label( 'BIO', 'unlock_requirement_text__en' ) ) . '">' . ( empty( $r['data']['unlock_requirement_text__en'] ) ? '' : Ui::text( Lang::t( (string) $r['data']['unlock_requirement_text__en'] ) ) ) . '</td><td data-label="' . esc_attr( $game->label( 'BIO', 'unlock_speed_required' ) ) . '">' . Ui::value( $ds, $r, 'unlock_speed_required' ) . '</td></tr>';
		}
		return '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $game->entity_label( 'BIO', true ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Biome', 'rbx-data' ) . '</th><th scope="col">' . esc_html( $game->label( 'BIO', 'unlock_requirement_text__en' ) ) . '</th><th scope="col">' . esc_html( $game->label( 'BIO', 'unlock_speed_required' ) ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
	}

	private static function mounts( Game $game ): string {
		if ( null === $game->entity( 'MNT' ) ) {
			return '';
		}
		$ds   = self::ds( $game );
		$recs = $ds->all( 'MNT', array( 'live', 'limited' ) );
		if ( ! $recs ) {
			return '<p class="rbx-note">' . esc_html__( 'No mounts are listed yet.', 'rbx-data' ) . '</p>';
		}
		self::show( array_keys( $recs ) );
		$rows = '';
		foreach ( $recs as $r ) {
			$field = null !== ( $r['data']['speed_mult'] ?? null ) ? 'speed_mult' : 'speed_bonus';
			$upd   = $ds->get( (string) ( $r['data']['added_in_update'] ?? '' ) );
			$rows .= '<tr><th scope="row">' . esc_html( (string) $r['name'] ) . '</th><td data-label="' . esc_attr( $game->label( 'MNT', $field ) ) . '">' . Ui::value( $ds, $r, $field ) . '</td><td data-label="' . esc_attr( $game->label( 'MNT', 'obtain_method_text__en' ) ) . '">' . ( empty( $r['data']['obtain_method_text__en'] ) ? '' : Ui::text( (string) $r['data']['obtain_method_text__en'] ) ) . ( $upd ? ' (' . Ui::link( $upd ) . ')' : '' ) . '</td></tr>';
		}
		return '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $game->entity_label( 'MNT', true ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Mount', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Speed', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'How to get', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
	}

	private static function latest_update( Game $game ): string {
		$ds   = self::ds( $game );
		$upds = $ds->all( 'UPD', array( 'live', 'limited' ) );
		uasort( $upds, static fn( $a, $b ) => strcmp( (string) ( $b['data']['release_date'] ?? '' ), (string) ( $a['data']['release_date'] ?? '' ) ) );
		$u = reset( $upds );
		if ( ! $u ) {
			return '';
		}
		self::show( array( (string) $u['id'] ) );
		return '<div class="rbx-update-card rbx-update-card--latest"><p class="rbx-eyebrow">' . esc_html__( 'Latest update', 'rbx-data' ) . '</p><h3>' . Ui::link( $u ) . '</h3><p class="rbx-meta">' . Ui::date( (string) ( $u['data']['release_date'] ?? '' ) ) . '</p>' . ( ! empty( $u['data']['summary__en'] ) && ! Lang::is_id() ? '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $u['data']['summary__en'] ), 30 ) ) . '</p>' : '' ) . '</div>';
	}

	private static function top_tier( Game $game, int $limit ): string {
		if ( null === $game->field( 'PET', 'tier_list_rank' ) ) {
			return '';
		}
		$ds    = self::ds( $game );
		$order = array_flip( array( 'S', 'A', 'B', 'C', 'D' ) );
		$pets  = array_filter( $ds->all( 'PET', array( 'live', 'limited' ) ), static fn( $r ) => isset( $order[ (string) ( $r['data']['tier_list_rank'] ?? '' ) ] ) );
		uasort( $pets, static fn( $a, $b ) => ( $order[ $a['data']['tier_list_rank'] ] <=> $order[ $b['data']['tier_list_rank'] ] ) ?: $ds->compare_records( $a, $b ) );
		$pets = array_slice( $pets, 0, max( 1, $limit ), true );
		if ( ! $pets ) {
			return '';
		}
		$li = '';
		foreach ( $pets as $r ) {
			$li .= '<li>' . Ui::icon( $ds, $r, 48 ) . Ui::link( $r ) . ' <span class="rbx-chip rbx-chip--tier">' . esc_html( (string) $r['data']['tier_list_rank'] ) . '</span></li>';
		}
		return '<ol class="rbx-top">' . $li . '</ol><p><a href="' . esc_url( home_url( '/' . $game->folder() . '/tier-list/' ) ) . '">' . esc_html__( 'See the full tier list', 'rbx-data' ) . '</a></p>';
	}

	/** "What changed" for the records this page displayed (rendered after the other sections). */
	private static function page_changes( Game $game ): string {
		return Ui::changes( self::ds( $game ), self::shown(), 5 );
	}

	/** Public corrections log from changelog rows with is_correction = 1 (CONT-45). */
	private static function corrections(): string {
		$rows = Changelog::search( array( 'corrections' => true ), 200 );
		if ( ! $rows ) {
			return '<p class="rbx-empty">' . esc_html__( 'No corrections so far.', 'rbx-data' ) . '</p>';
		}
		$tr = '';
		foreach ( $rows as $r ) {
			$game = Loader::by_code( (string) $r['game'] );
			if ( ! $game ) {
				continue;
			}
			$ds  = self::ds( $game );
			$rec = $ds->get( (string) $r['record_id'] );
			$def = $rec ? ( $game->field( (string) $rec['entity'], (string) $r['field'] ) ?? array() ) : array();
			$tr .= '<tr><td>' . Ui::date( substr( (string) $r['changed_at'], 0, 10 ) ) . '</td><th scope="row">' . ( $rec ? Ui::link( $rec ) : esc_html( (string) $r['record_id'] ) ) . '</th><td>' . esc_html( $rec ? $game->label( (string) $rec['entity'], (string) $r['field'] ) : (string) $r['field'] ) . '</td><td>' . Ui::raw( $ds, $def, self::decode( $r['old_value'] ), false ) . '</td><td>' . Ui::raw( $ds, $def, self::decode( $r['new_value'] ), false ) . '</td><td>' . esc_html( $game->name() ) . '</td></tr>';
		}
		return '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html__( 'Corrections', 'rbx-data' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Date', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Item', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Field', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Old', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'New', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Game', 'rbx-data' ) . '</th></tr></thead><tbody>' . $tr . '</tbody></table></div>';
	}

	/**
	 * Every wiki image the site shows, with its credit (Phase E, owner decision 2026-10-24). List
	 * icons are too small for a caption, so this page carries their CC BY-SA attribution.
	 */
	private static function image_credits(): string {
		$li = '';
		foreach ( Loader::games() as $game ) {
			$ds = self::ds( $game );
			foreach ( $ds->all( 'MED', array( 'live' ) ) as $m ) {
				$d = $m['data'];
				if ( 'wiki_cc' !== ( $d['image_source'] ?? '' ) ) {
					continue;
				}
				$what = array();
				foreach ( (array) ( $d['depicts_record_ids'] ?? array() ) as $rid ) {
					$rec = $ds->get( (string) $rid );
					if ( $rec ) {
						$what[] = Ui::link( $rec );
					}
				}
				$img = Media::img( (string) $d['file_name'], (string) $d['alt__en'], 48, 'rbx-icon-img', true );
				$li .= '<li>' . $img . '<span><strong>' . ( $what ? implode( ', ', $what ) : esc_html( (string) $d['file_name'] ) ) . '</strong> (' . esc_html( $game->name() ) . '). ' . Item::wiki_credit( $d ) . '</span></li>';
			}
		}
		if ( '' === $li ) {
			return '<p class="rbx-empty">' . esc_html__( 'No wiki images are in use.', 'rbx-data' ) . '</p>';
		}
		return '<ul class="rbx-credits">' . $li . '</ul>';
	}

	private static function decode( mixed $stored ): mixed {
		if ( null === $stored ) {
			return null;
		}
		if ( 'TRUE' === $stored || 'FALSE' === $stored ) {
			return 'TRUE' === $stored;
		}
		$v = json_decode( (string) $stored, true );
		return null === $v ? (string) $stored : $v;
	}

	/**
	 * Section slugs of a game, most used first: the tool, codes, the tier list, then the lists in
	 * menu order (UO-38, UO-42). Phones show the first few without a sideways swipe.
	 *
	 * @return array<int, string>
	 */
	public static function priority_sections( Game $game ): array {
		$rank  = static function ( string $slug ) use ( $game ): int {
			$s = $game->section( $slug );
			return 'tool' === ( $s['type'] ?? '' ) ? 0 : ( 'codes' === $slug ? 1 : ( 'tier-list' === $slug ? 2 : 3 ) );
		};
		$order = array_values( $game->menu_order() );
		$pos   = array_flip( $order );
		usort( $order, static fn( $a, $b ) => ( $rank( $a ) <=> $rank( $b ) ) ?: ( $pos[ $a ] <=> $pos[ $b ] ) );
		return $order;
	}

	/** Home game cards (UX-11) with a search box and a "Popular now" row on top (UO-38). */
	private static function home_games(): string {
		$out     = '';
		$popular = array();
		foreach ( Loader::games() as $game ) {
			$hub = Breadcrumbs::page_url( $game->folder() );
			if ( '' === $hub ) {
				continue;
			}
			$links = '';
			// Every section page of the game, so GAG2 and Fish It list pages get a home link too (UO-25).
			foreach ( array_slice( self::priority_sections( $game ), 0, 6 ) as $s ) {
				$sec = $game->section( $s );
				$u   = $sec && 'updates' === ( $sec['type'] ?? '' ) ? Ui::updates_url( $game ) : Breadcrumbs::page_url( $game->folder() . '/' . $s );
				if ( '' !== $u && $sec ) {
					$links .= '<li><a href="' . esc_url( $u ) . '">' . esc_html( Lang::t( (string) $sec['label__en'] ) ) . '</a></li>';
					if ( 'tool' === ( $sec['type'] ?? '' ) && count( $popular ) < 3 ) {
						/* translators: %s: short game name */
						$popular[] = '<a href="' . esc_url( $u ) . '">' . esc_html( sprintf( __( '%s calculator', 'rbx-data' ), $game->short_name() ) ) . '</a>';
					}
					if ( 'tier-list' === $s && count( $popular ) < 3 ) {
						/* translators: %s: short game name */
						$popular[] = '<a href="' . esc_url( $u ) . '">' . esc_html( sprintf( __( '%s tier list', 'rbx-data' ), $game->short_name() ) ) . '</a>';
					}
				}
			}
			$gu = Breadcrumbs::page_url( $game->folder() . '/guides' );
			if ( '' !== $gu ) {
				$links .= '<li><a href="' . esc_url( $gu ) . '">' . esc_html__( 'Guides', 'rbx-data' ) . '</a></li>';
			}
			$lc   = Changelog::last_changed( array_keys( self::ds( $game )->records() ) );
			$mono = mb_strtoupper( mb_substr( $game->short_name(), 0, 1 ) );
			$out .= '<li class="rbx-game-card" style="--game-accent:' . esc_attr( $game->accent() ) . '"><span class="rbx-game-card__mono" aria-hidden="true">' . esc_html( $mono ) . '</span><h3><a href="' . esc_url( $hub ) . '">' . esc_html( $game->name() ) . '</a></h3>' . ( $lc ? '<p class="rbx-meta">' . esc_html__( 'Updated', 'rbx-data' ) . ' ' . Ui::date( substr( (string) $lc, 0, 10 ) ) . '</p>' : '' ) . ( '' !== $links ? '<ul class="rbx-game-card__links">' . $links . '</ul>' : '' ) . '</li>';
		}
		if ( '' === $out ) {
			return '';
		}
		$search = '<form class="rbx-home-search" role="search" action="' . esc_url( home_url( '/' ) ) . '" method="get"><label for="rbx-home-q">' . esc_html__( 'Search pets, crops and pages', 'rbx-data' ) . '</label><span class="rbx-home-search__row"><input type="search" id="rbx-home-q" name="s" autocomplete="off"><button type="submit" class="button">' . esc_html__( 'Search', 'rbx-data' ) . '</button></span></form>';
		$pop    = $popular ? '<p class="rbx-popular"><strong>' . esc_html__( 'Popular now:', 'rbx-data' ) . '</strong> ' . implode( ' ', $popular ) . '</p>' : '';
		// UO-49: an H2 between the page H1 and the H3 card titles.
		return $search . $pop . '<h2 class="wp-block-heading" id="games">' . esc_html__( 'Games', 'rbx-data' ) . '</h2><ul class="rbx-game-cards">' . $out . '</ul>';
	}

	/** Newest published guides across games: the children of each game's guides page. */
	private static function home_guides(): string {
		$all = array();
		foreach ( Loader::games() as $game ) {
			$idx = get_page_by_path( \RBX\Data\Content\Lang::path( $game->folder() . '/guides' ) );
			if ( ! $idx || 'publish' !== $idx->post_status ) {
				continue;
			}
			foreach ( get_pages( array( 'parent' => $idx->ID, 'post_status' => 'publish', 'sort_column' => 'post_date', 'sort_order' => 'DESC' ) ) as $p ) {
				$all[] = array( $game, $p );
			}
		}
		usort( $all, static fn( $a, $b ) => strcmp( (string) $b[1]->post_date_gmt, (string) $a[1]->post_date_gmt ) );
		$li = '';
		foreach ( array_slice( $all, 0, 6 ) as [ $game, $p ] ) {
			$li .= '<li><a href="' . esc_url( (string) get_permalink( $p ) ) . '">' . esc_html( html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ) ) . '</a> <span class="rbx-meta">' . esc_html( $game->short_name() ) . '</span></li>';
		}
		// The heading comes with the list so an empty list leaves no empty section.
		return '' === $li ? '' : '<h2 class="wp-block-heading" id="guides">' . esc_html__( 'Guides', 'rbx-data' ) . '</h2><ul class="rbx-latest">' . $li . '</ul>';
	}

	/** Latest updates across games (CONT-19 item 4). */
	private static function home_latest(): string {
		$all = array();
		foreach ( Loader::games() as $game ) {
			foreach ( self::ds( $game )->all( 'UPD', array( 'live', 'limited' ) ) as $u ) {
				if ( ! empty( $u['post_id'] ) && 'publish' === get_post_status( (int) $u['post_id'] ) ) {
					$all[] = array( $game, $u );
				}
			}
		}
		usort( $all, static fn( $a, $b ) => strcmp( (string) ( $b[1]['data']['release_date'] ?? '' ), (string) ( $a[1]['data']['release_date'] ?? '' ) ) );
		$li = '';
		foreach ( array_slice( $all, 0, 5 ) as [ $game, $u ] ) {
			$li .= '<li>' . Ui::link( $u ) . ' <span class="rbx-meta">' . esc_html( $game->short_name() ) . ', ' . Ui::date( (string) ( $u['data']['release_date'] ?? '' ) ) . '</span></li>';
		}
		// The heading comes with the list so an empty list leaves no empty section.
		return '' === $li ? '' : '<h2 class="wp-block-heading" id="latest-updates">' . esc_html__( 'Latest updates', 'rbx-data' ) . '</h2><ul class="rbx-latest">' . $li . '</ul>';
	}
}
