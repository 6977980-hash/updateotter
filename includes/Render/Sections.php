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

	public const NAMES = array( 'count', 'list', 'mutations', 'sizes', 'stacking', 'codes', 'updates', 'tier_list', 'hub_tiles', 'hub_glance', 'glossary', 'biomes', 'mounts', 'latest_update', 'top_tier', 'corrections', 'report_form', 'changes', 'home_games', 'home_latest' );

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
		if ( 'home_games' === $section || 'home_latest' === $section || 'corrections' === $section || 'report_form' === $section ) {
			$game = null;
		} else {
			$game = Loader::game( (string) ( $a['game'] ?? '' ) );
			if ( null === $game ) {
				return '';
			}
			\RBX\Data\Cache\Purge::tag_game( $game );
		}
		try {
			switch ( $section ) {
				case 'count':
					return self::count( $game, (string) ( $a['entity'] ?? '' ) );
				case 'list':
					return self::list( $game, (string) ( $a['entity'] ?? '' ) );
				case 'mutations':
					return self::mutations( $game );
				case 'sizes':
					return self::sizes( $game );
				case 'stacking':
					return self::mechanic_text( $game, 'mutation_stacking' );
				case 'codes':
					return self::codes( $game );
				case 'updates':
					return self::updates( $game );
				case 'tier_list':
					return self::tier_list( $game );
				case 'hub_tiles':
					return self::hub_tiles( $game );
				case 'hub_glance':
					return self::hub_glance( $game );
				case 'glossary':
					return self::glossary( $game );
				case 'biomes':
					return self::biomes( $game );
				case 'mounts':
					return self::mounts( $game );
				case 'latest_update':
					return self::latest_update( $game );
				case 'top_tier':
					return self::top_tier( $game, (int) ( $a['limit'] ?? 5 ) );
				case 'changes':
					return self::page_changes( $game );
				case 'corrections':
					return self::corrections();
				case 'report_form':
					return Report::form();
				case 'home_games':
					return self::home_games();
				case 'home_latest':
					return self::home_latest();
			}
		} catch ( \Throwable $e ) {
			\RBX\Data\Log\ErrorLog::add( 'render', $section . ': ' . $e->getMessage() );
		}
		return '';
	}

	private static function ds( Game $game ): Dataset {
		return Repository::dataset( $game );
	}

	/** Newest last_verified among records (for count lines). */
	private static function newest_verified( array $recs ): string {
		$max = '';
		foreach ( $recs as $r ) {
			$max = max( $max, (string) ( $r['data']['last_verified'] ?? '' ) );
		}
		return $max;
	}

	/** "{n} pets, last verified {date}" (UX-08). */
	private static function count( Game $game, string $entity ): string {
		if ( null === $game->entity( $entity ) ) {
			return '';
		}
		$recs = self::ds( $game )->all( $entity, array( 'live', 'limited' ) );
		$n    = count( $recs );
		$lv   = self::newest_verified( $recs );
		$txt  = sprintf( '%s %s', number_format_i18n( $n ), strtolower( $game->entity_label( $entity, 1 !== $n ) ) );
		return '<p class="rbx-count">' . esc_html( $txt ) . ( '' !== $lv ? ', ' . esc_html__( 'last verified', 'rbx-data' ) . ' ' . Ui::date( $lv ) : '' ) . '</p>';
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
			if ( ! empty( $fd['list'] ) ) {
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
			$head   .= '<th scope="col"' . ( $numeric ? ' data-sort-key="' . esc_attr( $f ) . '"' : '' ) . '>' . esc_html( (string) ( $fd['label__en'] ?? $f ) ) . '</th>';
		}
		if ( 'PET' === $entity ) {
			$head .= '<th scope="col">' . esc_html__( 'Source egg', 'rbx-data' ) . '</th>';
		}
		if ( 'EGG' === $entity ) {
			$head .= '<th scope="col">' . esc_html__( 'Pets', 'rbx-data' ) . '</th>';
		}
		$head .= '<th scope="col">' . esc_html__( 'Confidence', 'rbx-data' ) . '</th>';

		$body       = '';
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
				$sort = is_numeric( $st['value'] ) && 'verified' === $st['state'] ? ' data-sort="' . esc_attr( (string) $st['value'] ) . '"' : '';
				$row .= '<td data-label="' . esc_attr( (string) ( $fd['label__en'] ?? $f ) ) . '"' . $sort . '>' . Ui::value( $ds, $r, $f, false ) . '</td>';
			}
			if ( 'PET' === $entity ) {
				$eggs = array();
				foreach ( $ds->children( 'DRP', 'pet_id', (string) $id ) as $d ) {
					$eggs[] = Ui::link( $ds->get( (string) $d['data']['egg_id'] ) );
				}
				$row .= '<td data-label="' . esc_attr__( 'Source egg', 'rbx-data' ) . '">' . ( $eggs ? implode( ', ', array_unique( $eggs ) ) : '<span class="rbx-v--unknown">' . esc_html__( 'Not confirmed yet', 'rbx-data' ) . '</span>' ) . '</td>';
			}
			if ( 'EGG' === $entity ) {
				$row .= '<td data-label="' . esc_attr__( 'Pets', 'rbx-data' ) . '">' . esc_html( number_format_i18n( count( $ds->children( 'DRP', 'egg_id', (string) $id ) ) ) ) . '</td>';
			}
			$row  .= '<td data-label="' . esc_attr__( 'Confidence', 'rbx-data' ) . '">' . self::confidence( $r ) . '</td></tr>';
			$body .= $row;
		}

		$chips = '';
		foreach ( $facets as $f => $fd ) {
			$vals = array_keys( $facet_vals[ $f ] ?? array() );
			if ( count( $vals ) < 2 ) {
				continue;
			}
			$chips .= '<fieldset class="rbx-facet"><legend>' . esc_html( (string) ( $fd['label__en'] ?? $f ) ) . '</legend>';
			foreach ( $vals as $v ) {
				$label = 'ref' === ( $fd['type'] ?? '' ) || 'ref_list' === ( $fd['type'] ?? '' ) ? (string) ( $ds->get( $v )['name'] ?? $v ) : ( 'bool' === ( $fd['type'] ?? '' ) ? ( 'yes' === $v ? __( 'Yes', 'rbx-data' ) : __( 'No', 'rbx-data' ) ) : Ui::enum_label( $v ) );
				$chips .= '<button type="button" class="rbx-chip-btn" aria-pressed="false" data-facet="' . esc_attr( str_replace( '_', '-', $f ) ) . '" data-value="' . esc_attr( sanitize_title( $v ) ) . '">' . esc_html( $label ) . '</button>';
			}
			$chips .= '</fieldset>';
		}
		$has_removed = (bool) array_filter( $recs, static fn( $r ) => 'removed_from_game' === $r['status'] );
		$label       = $game->entity_label( $entity, true );
		$controls    = '<div class="rbx-list__controls" hidden data-rbx-controls>'
			. '<label class="rbx-search"><span>' . esc_html__( 'Search this list', 'rbx-data' ) . '</span><input type="search" data-rbx-q autocomplete="off"></label>'
			. $chips
			. '<label class="rbx-sort"><span>' . esc_html__( 'Sort', 'rbx-data' ) . '</span><select data-rbx-sort><option value="">' . esc_html__( 'Default order', 'rbx-data' ) . '</option><option value="name-asc">' . esc_html__( 'Name A to Z', 'rbx-data' ) . '</option>';
		foreach ( $fields as $f => $fd ) {
			if ( $ds->is_numeric_field( $entity, $f ) ) {
				/* translators: %s: field label */
				$controls .= '<option value="' . esc_attr( $f ) . '-desc">' . esc_html( sprintf( __( '%s, highest first', 'rbx-data' ), (string) ( $fd['label__en'] ?? $f ) ) ) . '</option>';
			}
		}
		$controls .= '</select></label>'
			. ( $has_removed ? '<button type="button" class="rbx-chip-btn" aria-pressed="false" data-rbx-removed>' . esc_html__( 'Show removed', 'rbx-data' ) . '</button>' : '' )
			. '<button type="button" class="rbx-link-btn" data-rbx-clear hidden>' . esc_html__( 'Clear filters', 'rbx-data' ) . '</button>'
			. '<p class="rbx-live" role="status" data-rbx-status></p></div>';

		$table = '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $label ) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
		$empty = '<p class="rbx-empty" hidden data-rbx-empty>' . esc_html__( 'No results. Try fewer filters.', 'rbx-data' ) . ' <a href="' . esc_url( home_url( '/report/' ) ) . '">' . esc_html__( 'Report a missing item', 'rbx-data' ) . '</a></p>';
		self::enqueue_list_js();
		return '<div class="rbx-list" data-rbx-list data-entity="' . esc_attr( strtolower( $entity ) ) . '">' . $controls . $table . $empty . '</div>';
	}

	/** Record-level confidence chip. */
	private static function confidence( array $r ): string {
		$st = array(
			'state'             => null === ( $r['data']['source_type'] ?? null ) ? 'unknown' : ( 'community' === $r['data']['source_type'] ? 'community' : 'verified' ),
			'mechanic_ok'       => true,
			'source_type'       => $r['data']['source_type'] ?? null,
			'source_url'        => null,
			'evidence_media_id' => null,
		);
		$st['label'] = Dataset::state_label( $st['state'] );
		return Ui::chip( $st );
	}

	public static function enqueue_list_js(): void {
		wp_enqueue_script_module( 'rbx-data-list', plugins_url( 'assets/front/list.js', RBX_DATA_FILE ), array(), RBX_DATA_VERSION );
	}

	/** Mechanic text by token (CONT-24 item 2, ED-11). */
	private static function mechanic_text( Game $game, string $key ): string {
		$ds = self::ds( $game );
		$m  = $ds->mechanic( $key );
		if ( null === $m ) {
			return '';
		}
		$txt = (string) ( $m['data']['definition__en'] ?? $m['data']['description__en'] ?? '' );
		$out = '' !== $txt ? wpautop( Ui::text( $txt ) ) : '';
		if ( 'confirmed' !== ( $m['data']['status'] ?? '' ) ) {
			$out .= '<p class="rbx-note"><span class="rbx-chip rbx-chip--warning">' . esc_html__( 'Unconfirmed', 'rbx-data' ) . '</span> ' . esc_html__( 'This is not confirmed yet. We will update it once we test it in the game.', 'rbx-data' ) . '</p>';
		}
		self::show( array( (string) $m['id'] ) );
		return $out;
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
		$rows = '';
		foreach ( $recs as $r ) {
			$rows .= '<tr id="' . esc_attr( (string) $r['slug'] ) . '"><th scope="row">' . Ui::link( $r ) . '</th>'
				. '<td data-label="' . esc_attr( $game->label( 'MUT', 'income_mult' ) ) . '">' . Ui::value( $ds, $r, 'income_mult', false ) . '</td>'
				. '<td data-label="' . esc_attr( $game->label( 'MUT', 'obtain_method_text__en' ) ) . '">' . ( empty( $r['data']['obtain_method_text__en'] ) ? '<span class="rbx-v--unknown">' . esc_html__( 'Not confirmed yet', 'rbx-data' ) . '</span>' : Ui::text( (string) $r['data']['obtain_method_text__en'] ) ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Confidence', 'rbx-data' ) . '">' . Ui::chip( $ds->field_state( $r, 'income_mult' ), $ds ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Last verified', 'rbx-data' ) . '">' . Ui::date( (string) ( $r['data']['last_verified'] ?? '' ) ) . '</td></tr>';
		}
		return '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $game->entity_label( 'MUT', true ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Mutation', 'rbx-data' ) . '</th><th scope="col">' . esc_html( $game->label( 'MUT', 'income_mult' ) ) . '</th><th scope="col">' . esc_html( $game->label( 'MUT', 'obtain_method_text__en' ) ) . '</th><th scope="col">' . esc_html__( 'Confidence', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Last verified', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
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
		$last    = CheckLog::last_checked( $game );
		self::show( array_keys( $active + $expired ) );
		$out = '<div class="rbx-codes-status" role="region" aria-label="' . esc_attr__( 'Code status', 'rbx-data' ) . '">';
		if ( ! $active ) {
			/* translators: %s: game name */
			$out .= '<p class="rbx-codes-status__head"><strong>' . esc_html__( 'No working codes right now', 'rbx-data' ) . '</strong></p><p>' . sprintf( esc_html__( 'There are no %s codes right now.', 'rbx-data' ), esc_html( $game->name() ) ) . ( '' !== $last ? ' ' . esc_html__( 'We last checked on', 'rbx-data' ) . ' ' . Ui::date( $last ) . '.' : '' ) . '</p>';
		} else {
			$out .= '<p class="rbx-codes-status__head"><strong>' . esc_html( sprintf( /* translators: %s: number */ _n( 'Working codes: %s', 'Working codes: %s', count( $active ), 'rbx-data' ), number_format_i18n( count( $active ) ) ) ) . '</strong>' . ( '' !== $last ? ' ' . esc_html__( 'We tested them on', 'rbx-data' ) . ' ' . Ui::date( $last ) . '.' : '' ) . '</p>';
		}
		$out .= '</div>';
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
				$rows .= '<tr><th scope="row"><code class="rbx-code">' . esc_html( (string) $d['code'] ) . '</code> <button type="button" class="rbx-copy" data-copy="' . esc_attr( (string) $d['code'] ) . '" hidden>' . esc_html__( 'Copy', 'rbx-data' ) . '</button></th><td data-label="' . esc_attr__( 'Reward', 'rbx-data' ) . '">' . Ui::text( (string) ( $d['reward_text__en'] ?? '' ) ) . '</td><td data-label="' . esc_attr__( 'First seen', 'rbx-data' ) . '">' . Ui::date( (string) ( $d['first_seen'] ?? '' ) ) . '</td><td data-label="' . esc_attr__( 'Tested', 'rbx-data' ) . '">' . $state . ( ! empty( $d['tested_by'] ) ? ' ' . esc_html__( 'by', 'rbx-data' ) . ' ' . esc_html( (string) $d['tested_by'] ) : '' ) . '</td><td data-label="' . esc_attr__( 'Source', 'rbx-data' ) . '">' . Ui::source_link( $d['source_type'] ?? null, $d['source_url'] ?? null, null ) . '</td></tr>';
			}
			$out .= Ui::h2( __( 'Active codes', 'rbx-data' ), 'active-codes' ) . '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><thead><tr><th scope="col">' . esc_html__( 'Code', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Reward', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'First seen', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Tested', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Source', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div><p class="rbx-live" role="status" data-rbx-copy-status></p>';
			wp_enqueue_script_module( 'rbx-data-copy', plugins_url( 'assets/front/copy.js', RBX_DATA_FILE ), array(), RBX_DATA_VERSION );
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
			: ( 'no' === $redeem ? esc_html__( 'No. The game has no place to type codes right now, so any site that says it does is wrong.', 'rbx-data' ) : esc_html__( 'We have not confirmed this yet.', 'rbx-data' ) ) ) . '</p>';
		$channels = (array) ( $ds->meta( 'official_channels' ) ?? array() );
		$out     .= Ui::h2( __( 'How we check for codes', 'rbx-data' ), 'how-we-check' ) . '<p>' . esc_html__( 'We check the channels below at least once a week and after every game update. We only list a code after we redeem it in the game or the developer posts it.', 'rbx-data' ) . '</p>';
		if ( $channels ) {
			$out .= '<ul>';
			foreach ( $channels as $u ) {
				$out .= '<li><a href="' . esc_url( (string) $u ) . '" rel="noopener nofollow">' . esc_html( (string) wp_parse_url( (string) $u, PHP_URL_HOST ) ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		$out .= '<aside class="rbx-scam" role="note"><h2 id="fake-code-scams">' . esc_html__( 'Stay safe: fake code scams', 'rbx-data' ) . '</h2><p>' . esc_html__( "No code, website, or video can give free Robux. Never enter your password or run scripts from 'code' sites.", 'rbx-data' ) . '</p><ul><li>' . esc_html__( 'Codes are only typed inside the game.', 'rbx-data' ) . '</li><li>' . esc_html__( 'Never share your password with anyone.', 'rbx-data' ) . '</li><li>' . esc_html__( 'Do not download anything to get codes or pets.', 'rbx-data' ) . '</li></ul></aside>';
		return $out;
	}

	/** Updates hub list, newest first (CONT-26). */
	private static function updates( Game $game ): string {
		$ds   = self::ds( $game );
		$upds = $ds->all( 'UPD', array( 'live', 'limited' ) );
		uasort( $upds, static fn( $a, $b ) => strcmp( (string) ( $b['data']['release_date'] ?? '' ), (string) ( $a['data']['release_date'] ?? '' ) ) ?: strcmp( (string) $b['id'], (string) $a['id'] ) );
		$cadence = (string) ( $ds->meta( 'update_cadence_text' ) ?? '' );
		$out     = '';
		$first   = reset( $upds );
		if ( $first ) {
			/* translators: 1: update name, 2: date */
			$out .= '<p class="rbx-summary">' . sprintf( esc_html__( 'The latest update is %1$s, released on %2$s.', 'rbx-data' ), Ui::link( $first ), Ui::date( (string) ( $first['data']['release_date'] ?? '' ) ) );
			if ( '' !== $cadence ) {
				/* translators: %s: cadence text from the dataset */
				$out .= ' ' . sprintf( esc_html__( 'New updates usually come %s.', 'rbx-data' ), esc_html( rtrim( wp_strip_all_tags( $cadence ), '.' ) ) );
			}
			$out .= '</p>';
		} else {
			$out .= '<p class="rbx-empty">' . esc_html__( 'No updates are listed yet.', 'rbx-data' ) . '</p>';
			return $out;
		}
		self::show( array_keys( $upds ) );
		$out .= '<ol class="rbx-updates" reversed>';
		foreach ( $upds as $u ) {
			$out .= '<li class="rbx-update-card"><h3>' . Ui::link( $u ) . '</h3><p class="rbx-meta">' . Ui::date( (string) ( $u['data']['release_date'] ?? '' ) ) . '</p>' . ( ! empty( $u['data']['summary__en'] ) ? '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $u['data']['summary__en'] ), 40 ) ) . '</p>' : '' ) . '</li>';
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
			$out .= '<section class="rbx-tier rbx-tier--' . esc_attr( strtolower( $t ) ) . '" aria-labelledby="tier-' . esc_attr( strtolower( $t ) ) . '"><h2 id="tier-' . esc_attr( strtolower( $t ) ) . '"><span class="rbx-tier__badge">' . esc_html( $t ) . '</span> ' . esc_html( sprintf( /* translators: %s: tier letter */ __( '%s tier', 'rbx-data' ), $t ) ) . '</h2><ul class="rbx-tier__list">';
			foreach ( $tiers[ $t ] as $r ) {
				$why  = (string) ( $r['data']['tier_rationale__en'] ?? '' );
				$out .= '<li>' . Ui::icon( $ds, $r, 48 ) . '<span class="rbx-tier__name">' . Ui::link( $r ) . '</span>' . ( '' !== $why ? '<span class="rbx-tier__why">' . esc_html( wp_strip_all_tags( $why ) ) . '</span>' : '' ) . '</li>';
			}
			$out .= '</ul></section>';
		}
		return $out . '<p class="rbx-note">' . esc_html__( 'This is our opinion based on testing; your best pet depends on your goal.', 'rbx-data' ) . '</p>';
	}

	/** Hub tiles (UX-10): tools first, then sections, from menu_order; only existing Pages link. */
	private static function hub_tiles( Game $game ): string {
		$items = '';
		foreach ( $game->menu_order() as $slug ) {
			$s   = $game->section( $slug );
			$url = Breadcrumbs::page_url( $game->folder() . '/' . $slug );
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
		$cad = (string) ( $ds->meta( 'update_cadence_text' ) ?? '' );
		if ( '' !== $cad ) {
			$rows .= '<tr><th scope="row">' . esc_html__( 'Updates', 'rbx-data' ) . '</th><td>' . esc_html__( 'Usually', 'rbx-data' ) . ' ' . esc_html( wp_strip_all_tags( $cad ) ) . '</td></tr>';
		}
		foreach ( array( 'PET', 'EGG', 'BIO', 'MUT' ) as $e ) {
			if ( $game->entity( $e ) ) {
				$n = $ds->count( $e );
				if ( $n > 0 ) {
					$rows .= '<tr><th scope="row">' . esc_html( $game->entity_label( $e, true ) ) . '</th><td>' . esc_html( number_format_i18n( $n ) ) . '</td></tr>';
				}
			}
		}
		return '' === $rows ? '' : '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--kv"><caption>' . esc_html__( 'Game at a glance', 'rbx-data' ) . '</caption><tbody>' . $rows . '</tbody></table></div>';
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
			$dl  .= '<div id="term-' . esc_attr( sanitize_title( $term ) ) . '"><dt>' . esc_html( $term ) . '</dt><dd>' . Ui::text( (string) $m['data']['definition__en'] ) . ( 'confirmed' !== ( $m['data']['status'] ?? '' ) ? ' <span class="rbx-chip rbx-chip--warning">' . esc_html__( 'Unconfirmed', 'rbx-data' ) . '</span>' : '' ) . '</dd></div>';
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
			$rows .= '<tr id="biome-' . esc_attr( (string) $r['slug'] ) . '"><th scope="row">' . esc_html( (string) $r['name'] ) . '</th><td data-label="' . esc_attr( $game->label( 'BIO', 'unlock_requirement_text__en' ) ) . '">' . ( empty( $r['data']['unlock_requirement_text__en'] ) ? '' : Ui::text( (string) $r['data']['unlock_requirement_text__en'] ) ) . '</td><td data-label="' . esc_attr( $game->label( 'BIO', 'unlock_speed_required' ) ) . '">' . Ui::value( $ds, $r, 'unlock_speed_required' ) . '</td></tr>';
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
		return '<div class="rbx-update-card rbx-update-card--latest"><p class="rbx-eyebrow">' . esc_html__( 'Latest update', 'rbx-data' ) . '</p><h3>' . Ui::link( $u ) . '</h3><p class="rbx-meta">' . Ui::date( (string) ( $u['data']['release_date'] ?? '' ) ) . '</p>' . ( ! empty( $u['data']['summary__en'] ) ? '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $u['data']['summary__en'] ), 30 ) ) . '</p>' : '' ) . '</div>';
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

	/** Home game cards (UX-11). */
	private static function home_games(): string {
		$out = '';
		foreach ( Loader::games() as $game ) {
			$hub = Breadcrumbs::page_url( $game->folder() );
			if ( '' === $hub ) {
				continue;
			}
			$links = '';
			foreach ( array( 'calculator', 'codes', 'pets' ) as $s ) {
				$u = Breadcrumbs::page_url( $game->folder() . '/' . $s );
				if ( '' !== $u && $game->section( $s ) ) {
					$links .= '<li><a href="' . esc_url( $u ) . '">' . esc_html( (string) $game->section( $s )['label__en'] ) . '</a></li>';
				}
			}
			$lc   = Changelog::last_changed( array_keys( self::ds( $game )->records() ) );
			$mono = mb_strtoupper( mb_substr( $game->short_name(), 0, 1 ) );
			$out .= '<li class="rbx-game-card" style="--game-accent:' . esc_attr( $game->accent() ) . '"><span class="rbx-game-card__mono" aria-hidden="true">' . esc_html( $mono ) . '</span><h3><a href="' . esc_url( $hub ) . '">' . esc_html( $game->name() ) . '</a></h3>' . ( $lc ? '<p class="rbx-meta">' . esc_html__( 'Updated', 'rbx-data' ) . ' ' . Ui::date( substr( (string) $lc, 0, 10 ) ) . '</p>' : '' ) . ( '' !== $links ? '<ul class="rbx-game-card__links">' . $links . '</ul>' : '' ) . '</li>';
		}
		return '' === $out ? '' : '<ul class="rbx-game-cards">' . $out . '</ul>';
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
		return '' === $li ? '' : '<ul class="rbx-latest">' . $li . '</ul>';
	}
}
