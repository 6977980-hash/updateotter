<?php
/**
 * "What can I catch here?" (Fisch, 2026-11-23). The player picks a fishing spot and, if they like,
 * the time of day, weather and season they are in; the tool lists every fish that can bite there
 * under those conditions, best sell value first, marks the ones that need exactly these conditions
 * ("catch it now") and counts the fish at that spot that need other conditions.
 *
 * Data: fish `location_ids`, `time_of_day` / `weather` / `season` (string lists, "Any" means no
 * requirement, empty means the wiki does not say), `bait_rule` / `bait_ids`, and the sell value at
 * average weight from the game's calculator formula. The server renders the example;
 * catch.js recomputes as the player changes a pick (twin of compute()).
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

use RBX\Data\Api;
use RBX\Data\Data\Dataset;
use RBX\Data\Format\Number;
use RBX\Data\Render\FishValue;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [rbx_catch_finder game="fsch" spot="the-ocean"].
 */
final class CatchFinder {

	/** Condition keys in the order they show (data field => input key). */
	public const DIMS = array(
		'time_of_day' => 'time',
		'weather'     => 'weather',
		'season'      => 'season',
	);

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'game' => '',
				'spot' => '',
			),
			(array) $atts
		);
		return self::render( (string) $a['game'], (string) $a['spot'] );
	}

	public static function render( string $game_key, string $spot_slug ): string {
		$game = Api::game( $game_key );
		if ( ! $game || null === $game->field( 'FSH', 'location_ids' ) || null === $game->field( 'FSH', 'weather' ) ) {
			return '';
		}
		$ds   = Api::dataset( $game );
		$data = self::data( $game, $ds );
		if ( ! $data['spots'] || ! $data['fish'] ) {
			return '';
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$spot = 0;
		foreach ( $data['spots'] as $i => $s ) {
			if ( $s['slug'] === $spot_slug ) {
				$spot = $i;
			}
		}
		$ladder = $ds->meta( 'suffix_ladder' );
		$ladder = is_array( $ladder ) ? $ladder : null;
		$loc    = \RBX\Data\Content\Lang::num();
		$id     = 'rbx-catch-' . wp_unique_id();
		$pick   = array(
			'spot'    => $spot,
			'time'    => '',
			'weather' => '',
			'season'  => '',
		);
		$r      = self::compute( $data, $pick );
		$t      = self::strings();
		$fmt    = static fn( float $v ): string => Number::format( $v, 'abbr', $loc, $ladder );

		$select = static function ( string $key, string $label, array $options, string $any ) use ( $id ): string {
			$fid  = $id . '-' . $key;
			$html = '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" data-rbx-catch="' . esc_attr( $key ) . '">';
			if ( '' !== $any ) {
				$html .= '<option value="">' . esc_html( $any ) . '</option>';
			}
			foreach ( $options as $v => $text ) {
				$html .= '<option value="' . esc_attr( (string) $v ) . '">' . esc_html( (string) $text ) . '</option>';
			}
			return $html . '</select></div>';
		};
		$spots = array();
		foreach ( $data['spots'] as $i => $s ) {
			/* translators: 1: fishing spot, 2: number of fish */
			$spots[ $i ] = sprintf( __( '%1$s (%2$s fish)', 'rbx-tools' ), $s['name'], number_format_i18n( $s['n'] ) );
		}
		$opts = static fn( array $vals ): array => array_combine( $vals, array_map( static fn( $v ) => \RBX\Data\Content\Lang::t( $v ), $vals ) );

		$out  = '<section class="rbx-calc rbx-advisor rbx-catch" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'What can I catch here', 'rbx-tools' ) . '" data-rbx-catch-finder>';
		$out .= '<form class="rbx-calc__form" novalidate>';
		$out .= str_replace( '<option value="' . $spot . '">', '<option value="' . $spot . '" selected>', $select( 'spot', __( 'Fishing spot', 'rbx-tools' ), $spots, '' ) );
		$out .= $select( 'time', __( 'Time of day', 'rbx-tools' ), $opts( $data['values']['time'] ), __( 'Any time', 'rbx-tools' ) );
		$out .= $select( 'weather', __( 'Weather', 'rbx-tools' ), $opts( $data['values']['weather'] ), __( 'Any weather', 'rbx-tools' ) );
		$out .= $select( 'season', __( 'Season', 'rbx-tools' ), $opts( $data['values']['season'] ), __( 'Any season', 'rbx-tools' ) );
		$out .= '</form>';
		$out .= '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to change the picks; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>';
		$x    = self::texts( $r, $data, $t, $fmt );
		$out .= '<div class="rbx-calc__result"><dl class="rbx-calc__outs">'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Fish that can bite now', 'rbx-tools' ) . '</dt><dd data-rbx-out="count">' . esc_html( $x['count'] ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Worth the most here', 'rbx-tools' ) . '</dt><dd data-rbx-out="best">' . esc_html( $x['best'] ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Only with these conditions', 'rbx-tools' ) . '</dt><dd data-rbx-out="only">' . esc_html( $x['only'] ) . '</dd></div>'
			. '</dl></div>';
		$out .= '<h3>' . esc_html__( 'Fish you can catch, best sell value first', 'rbx-tools' ) . '</h3>';
		$out .= '<p class="rbx-advisor__empty" data-rbx-empty' . ( $r['rows'] ? ' hidden' : '' ) . '>' . esc_html( $t['none'] ) . '</p>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Fish', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Rarity', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Needs', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Bait', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Value at average weight', 'rbx-tools' ) . '</th>'
			. '</tr></thead><tbody data-rbx-rows>' . self::rows_html( $x['rows'], $t ) . '</tbody></table></div>';
		$out .= '<p class="rbx-calc__meta">' . esc_html__( 'Spots, times, weather, seasons and baits come from the community wiki, and we have not tested them in the game ourselves. Values are worked out by us with our calculator formula at the fish\'s average weight, with no mutation. "TBA" means the wiki numbers for that fish disagree, so we show no value.', 'rbx-tools' ) . '</p>';
		$out .= '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		$config = array(
			'locale' => $loc,
			'ladder' => $ladder,
			'spots'  => array_map( static fn( $s ) => array( 'name' => $s['name'] ), $data['spots'] ),
			'fish'   => $data['fish'],
			'i18n'   => $t,
		);
		$out .= '<script type="application/json" class="rbx-catch-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-catch' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * Fishing spots with at least one live fish (most fish first), the condition values seen in
	 * the data, and one compact row per live fish with a spot.
	 *
	 * @return array{spots: array<int, array{slug: string, name: string, n: int}>, values: array<string, array<int, string>>, fish: array<int, array<string, mixed>>}
	 */
	public static function data( \RBX\Data\Config\Game $game, Dataset $ds ): array {
		$spot_ix = array();
		$spots   = array();
		$count   = array();
		$rows    = array();
		$seen    = array(
			'time'    => array(),
			'weather' => array(),
			'season'  => array(),
		);
		$fishes  = $ds->all( 'FSH', array( 'live' ) );
		foreach ( $fishes as $f ) {
			foreach ( (array) ( $f['data']['location_ids'] ?? array() ) as $lid ) {
				$l = $ds->get( (string) $lid );
				if ( $l && 'live' === $l['status'] ) {
					$count[ (string) $lid ] = ( $count[ (string) $lid ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $count as $lid => $n ) {
			$l       = $ds->get( (string) $lid );
			$spots[] = array(
				'id'   => (string) $lid,
				'slug' => (string) $l['slug'],
				'name' => (string) $l['name'],
				'n'    => $n,
			);
		}
		usort( $spots, static fn( $a, $b ) => array( $b['n'], $a['name'] ) <=> array( $a['n'], $b['name'] ) );
		foreach ( $spots as $i => $s ) {
			$spot_ix[ $s['id'] ] = $i;
		}
		$calc = FishValue::supports( $game );
		foreach ( $fishes as $fid => $f ) {
			$l = array();
			foreach ( (array) ( $f['data']['location_ids'] ?? array() ) as $lid ) {
				if ( isset( $spot_ix[ (string) $lid ] ) ) {
					$l[] = $spot_ix[ (string) $lid ];
				}
			}
			if ( ! $l ) {
				continue;
			}
			sort( $l );
			$row = array(
				'name' => (string) $f['name'],
				'url'  => FishValue::calc_url( $game, $f ),
				'r'    => '',
				'ro'   => 0,
				'l'    => $l,
			);
			$rar = $ds->get( is_string( $f['data']['rarity_id'] ?? null ) ? $f['data']['rarity_id'] : null );
			if ( $rar ) {
				$row['r']  = (string) $rar['name'];
				$row['ro'] = (int) ( $rar['data']['sort_order'] ?? 0 );
			}
			foreach ( self::DIMS as $field => $key ) {
				$vals = array_values( array_filter( array_map( 'strval', (array) ( $f['data'][ $field ] ?? array() ) ), static fn( $v ) => '' !== $v ) );
				// null = the wiki does not say; [] = no requirement ("Any").
				$row[ $key ] = $vals ? ( in_array( 'Any', $vals, true ) ? array() : $vals ) : null;
				foreach ( $row[ $key ] ?? array() as $v ) {
					$seen[ $key ][ $v ] = true;
				}
			}
			$bait = (string) ( $f['data']['bait_rule'] ?? 'unknown' );
			if ( 'none' === $bait ) {
				$row['b'] = '';
			} elseif ( 'specific' === $bait ) {
				$names = array();
				foreach ( (array) ( $f['data']['bait_ids'] ?? array() ) as $bid ) {
					$b = $ds->get( (string) $bid );
					if ( $b ) {
						$names[] = (string) $b['name'];
					}
				}
				$row['b'] = $names ? implode( ', ', $names ) : null;
			} else {
				$row['b'] = null;
			}
			$row['v'] = $calc ? FishValue::value( $game, array( 'fish' => (string) $fid ) ) : null;
			$rows[]   = $row;
		}
		$order = array(
			'time'    => array( 'Day', 'Night' ),
			'season'  => array( 'Spring', 'Summer', 'Autumn', 'Winter' ),
			'weather' => array( 'Clear', 'Windy', 'Foggy', 'Rain', 'Stormy' ),
		);
		$values = array();
		foreach ( $seen as $key => $vals ) {
			$vals = array_keys( $vals );
			usort(
				$vals,
				static function ( $a, $b ) use ( $order, $key ) {
					$ia = array_search( $a, $order[ $key ], true );
					$ib = array_search( $b, $order[ $key ], true );
					return array( false === $ia ? 99 : $ia, $a ) <=> array( false === $ib ? 99 : $ib, $b );
				}
			);
			$values[ $key ] = $vals;
		}
		return array(
			'spots'  => array_map( static fn( $s ) => array( 'slug' => $s['slug'], 'name' => $s['name'], 'n' => $s['n'] ), $spots ),
			'values' => $values,
			'fish'   => $rows,
		);
	}

	/** Same order as catch.js: value (TBA last), then rarity (rarest first), then name. */
	public static function order( array $a, array $b ): int {
		if ( ( null === $a['v'] ) !== ( null === $b['v'] ) ) {
			return null === $a['v'] ? 1 : -1;
		}
		$c = ( $b['v'] ?? 0 ) <=> ( $a['v'] ?? 0 );
		if ( 0 !== $c ) {
			return $c;
		}
		$c = $b['ro'] <=> $a['ro'];
		return 0 !== $c ? $c : strcmp( (string) $a['name'], (string) $b['name'] );
	}

	/**
	 * Pure rules (twin of compute() in catch.js).
	 * A fish bites when it lives at the spot and, for each condition the player picked, it has no
	 * requirement, the wiki does not say (flagged "unknown"), or its list holds the pick.
	 * "Only now" = the fish needs at least one of the picked conditions (its list is not "Any").
	 *
	 * @param array{spots?: array<int, mixed>, fish: array<int, array<string, mixed>>} $data Data.
	 * @param array{spot: int, time: string, weather: string, season: string}           $pick Picks.
	 * @return array{rows: array<int, array<string, mixed>>, here: int, other: int, only: int, best: ?array<string, mixed>}
	 */
	public static function compute( array $data, array $pick ): array {
		$rows  = array();
		$here  = 0;
		$only  = 0;
		foreach ( $data['fish'] as $f ) {
			if ( ! in_array( $pick['spot'], $f['l'], true ) ) {
				continue;
			}
			++$here;
			$ok      = true;
			$needs   = false;
			$unknown = false;
			foreach ( self::DIMS as $key ) {
				$want = (string) ( $pick[ $key ] ?? '' );
				$has  = $f[ $key ];
				if ( null === $has ) {
					$unknown = true;
					continue;
				}
				if ( '' === $want || ! $has ) {
					continue;
				}
				if ( in_array( $want, $has, true ) ) {
					$needs = true;
				} else {
					$ok = false;
				}
			}
			if ( ! $ok ) {
				continue;
			}
			$only  += $needs ? 1 : 0;
			$rows[] = $f + array(
				'needs'   => $needs,
				'unknown' => $unknown,
			);
		}
		usort( $rows, array( self::class, 'order' ) );
		$best = null;
		foreach ( $rows as $r ) {
			if ( null !== $r['v'] ) {
				$best = $r;
				break;
			}
		}
		return array(
			'rows'  => $rows,
			'here'  => $here,
			'other' => $here - count( $rows ),
			'only'  => $only,
			'best'  => $best,
		);
	}

	private static function fill( string $s, string ...$args ): string {
		if ( 1 === count( $args ) ) {
			return str_replace( '%s', $args[0], $s );
		}
		foreach ( $args as $i => $a ) {
			$s = str_replace( '%' . ( $i + 1 ) . '$s', $a, $s );
		}
		return $s;
	}

	/** "Night, Rain, Winter" or "Any time, weather and season" (twin of needsText in catch.js). */
	private static function needs_text( array $f, array $t ): string {
		$parts = array();
		foreach ( self::DIMS as $key ) {
			if ( is_array( $f[ $key ] ) && $f[ $key ] ) {
				$parts[] = implode( ' ' . $t['or'] . ' ', $f[ $key ] );
			}
		}
		$txt = $parts ? implode( ', ', $parts ) : $t['any'];
		if ( ! empty( $f['unknown'] ) ) {
			$txt .= ' (' . $t['unknown'] . ')';
		}
		return $txt;
	}

	/**
	 * Display texts (twin of texts() in catch.js).
	 *
	 * @return array{count: string, best: string, only: string, rows: array<int, array<string, string>>}
	 */
	public static function texts( array $r, array $data, array $t, callable $fmt ): array {
		$n     = count( $r['rows'] );
		$count = self::fill( 1 === $n ? $t['count_one'] : $t['count'], number_format_i18n( $n ) );
		if ( $r['other'] > 0 ) {
			$count .= ' ' . self::fill( 1 === $r['other'] ? $t['other_one'] : $t['other'], number_format_i18n( $r['other'] ) );
		}
		$best = null === $r['best'] ? $t['no_best'] : self::fill( $t['best'], (string) $r['best']['name'], $fmt( (float) $r['best']['v'] ) );
		$only = 0 === $r['only'] ? $t['no_only'] : self::fill( 1 === $r['only'] ? $t['only_one'] : $t['only'], number_format_i18n( $r['only'] ) );
		$rows = array();
		foreach ( $r['rows'] as $f ) {
			$rows[] = array(
				'name'  => (string) $f['name'],
				'url'   => (string) $f['url'],
				'r'     => '' === $f['r'] ? '—' : (string) $f['r'],
				'needs' => self::needs_text( $f, $t ),
				'now'   => $f['needs'] ? $t['now'] : '',
				'b'     => null === $f['b'] ? '—' : ( '' === $f['b'] ? $t['any_bait'] : (string) $f['b'] ),
				'v'     => null === $f['v'] ? $t['tba'] : $fmt( (float) $f['v'] ),
			);
		}
		return array(
			'count' => $count,
			'best'  => $best,
			'only'  => $only,
			'rows'  => $rows,
		);
	}

	/** @param array<int, array<string, string>> $rows Rows from texts(). */
	private static function rows_html( array $rows, array $t ): string {
		$out = '';
		foreach ( $rows as $e ) {
			$name = '' !== $e['url'] ? '<a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['name'] ) . '</a>' : esc_html( $e['name'] );
			$out .= '<tr><th scope="row" class="rbx-cell-name">' . $name . '</th>'
				. '<td data-label="' . esc_attr( $t['col_rarity'] ) . '">' . esc_html( $e['r'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_needs'] ) . '">' . esc_html( $e['needs'] ) . ( '' !== $e['now'] ? ' <span class="rbx-chip">' . esc_html( $e['now'] ) . '</span>' : '' ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_bait'] ) . '">' . esc_html( $e['b'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_value'] ) . '">' . esc_html( $e['v'] ) . '</td></tr>';
		}
		return $out;
	}

	/** @return array<string, string> Texts shared with catch.js. */
	public static function strings(): array {
		return array(
			/* translators: %s: number of fish */
			'count'      => __( '%s fish can bite here right now.', 'rbx-tools' ),
			/* translators: %s: number of fish (one) */
			'count_one'  => __( '%s fish can bite here right now.', 'rbx-tools' ),
			/* translators: %s: number of fish */
			'other'      => __( '%s more fish here need a different time, weather or season.', 'rbx-tools' ),
			/* translators: %s: number of fish (one) */
			'other_one'  => __( '%s more fish here needs a different time, weather or season.', 'rbx-tools' ),
			/* translators: 1: fish name, 2: sell value in C$ */
			'best'       => __( '%1$s, about %2$s C$ at its average weight.', 'rbx-tools' ),
			'no_best'    => __( 'None of these fish has a value we trust yet (TBA).', 'rbx-tools' ),
			/* translators: %s: number of fish */
			'only'       => __( '%s of them need the time, weather or season you picked. Catch them while it lasts.', 'rbx-tools' ),
			/* translators: %s: number of fish (one) */
			'only_one'   => __( '%s of them needs the time, weather or season you picked. Catch it while it lasts.', 'rbx-tools' ),
			'no_only'    => __( 'Pick a time, weather or season to see which fish need it.', 'rbx-tools' ),
			'none'       => __( 'No fish bite here with these picks. Try "Any" for one of them.', 'rbx-tools' ),
			'any'        => __( 'Any time, weather and season', 'rbx-tools' ),
			'or'         => __( 'or', 'rbx-tools' ),
			'unknown'    => __( 'some not listed on the wiki', 'rbx-tools' ),
			'now'        => __( 'Now', 'rbx-tools' ),
			'any_bait'   => __( 'Any bait', 'rbx-tools' ),
			'tba'        => __( 'TBA', 'rbx-tools' ),
			'col_rarity' => __( 'Rarity', 'rbx-tools' ),
			'col_needs'  => __( 'Needs', 'rbx-tools' ),
			'col_bait'   => __( 'Bait', 'rbx-tools' ),
			'col_value'  => __( 'Value at average weight', 'rbx-tools' ),
		);
	}
}
