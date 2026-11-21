<?php
/**
 * Fusion planner: the player ticks the pets they own and the planner shows which fusion recipes
 * they can make now, which pets each other recipe still needs and where to get them, and what a
 * fusion does to their income (the result's base income minus the ingredients it uses up) and to
 * their pen space. Everything comes from the live FUS recipes and the pets' base income and
 * sources, so it follows every import; there is no recipe list in code.
 *
 * The server renders the result with nothing ticked; fusion.js recomputes it as the player ticks.
 * compute() here and in fusion.js are twins, checked by tests/fusion-vectors.json in CI.
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

use RBX\Data\Api;
use RBX\Data\Content\Lang;
use RBX\Data\Data\Dataset;
use RBX\Data\Format\Number;
use RBX\Data\Render\Breadcrumbs;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [rbx_fusion_planner game="sae"].
 */
final class FusionPlanner {

	/** Most of one pet a player can enter when a recipe needs more than one. */
	public const MAX_QTY = 99;

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts( array( 'game' => '' ), (array) $atts );
		return self::render( (string) $a['game'] );
	}

	public static function render( string $game_key ): string {
		$game = Api::game( $game_key );
		if ( ! $game || null === $game->entity( 'FUS' ) ) {
			return '';
		}
		$ds   = Api::dataset( $game );
		$data = self::data( $ds );
		if ( ! $data['recipes'] ) {
			return '<p class="rbx-note">' . esc_html__( 'No fusion recipes are listed yet.', 'rbx-tools' ) . '</p>';
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$ladder = $ds->meta( 'suffix_ladder' );
		$ladder = is_array( $ladder ) ? $ladder : null;
		$loc    = Lang::num();
		$id     = 'rbx-fusion-' . wp_unique_id();
		$owned  = array_fill( 0, count( $data['pets'] ), 0 );
		$r      = self::compute( $data, $owned );
		$t      = self::strings();
		$fmt    = static fn( ?float $v ): string => null === $v ? '—' : Number::format( $v, 'abbr', $loc, $ladder );

		$fields = '';
		foreach ( $data['pets'] as $i => $p ) {
			$fid   = $id . '-p' . $i;
			$label = esc_html( $p['name'] ) . ( null === $p['income'] ? '' : ' <span class="rbx-fusion__inc">' . esc_html( sprintf( $t['per_s'], $fmt( $p['income'] ) ) ) . '</span>' );
			if ( $p['max_qty'] > 1 ) {
				$fields .= '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . $label . '</label><span class="rbx-calc__num"><input type="number" id="' . esc_attr( $fid ) . '" min="0" max="' . (int) self::MAX_QTY . '" step="1" value="0" inputmode="numeric" data-rbx-pet="' . (int) $i . '"></span></div>';
			} else {
				$fields .= '<div class="rbx-calc__field rbx-calc__field--check"><input type="checkbox" id="' . esc_attr( $fid ) . '" value="1" data-rbx-pet="' . (int) $i . '"> <label for="' . esc_attr( $fid ) . '">' . $label . '</label></div>';
			}
		}

		$config = array(
			'locale'  => $loc,
			'ladder'  => $ladder,
			'game'    => $game->key(),
			'max'     => self::MAX_QTY,
			'pets'    => $data['pets'],
			'recipes' => $data['recipes'],
			'i18n'    => $t,
		);

		$out  = '<section class="rbx-calc rbx-fusion" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Fusion planner', 'rbx-tools' ) . '" data-rbx-fusion>';
		$out .= '<form class="rbx-calc__form" novalidate><fieldset class="rbx-fusion__pets"><legend>' . esc_html__( 'Tick the pets you own', 'rbx-tools' ) . '</legend>' . $fields . '</fieldset>'
			. '<p class="rbx-calc__actions"><button type="reset" class="button button-secondary">' . esc_html__( 'Clear', 'rbx-tools' ) . '</button></p></form>';
		$out .= '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to tick your pets. The tables below show every recipe and what it needs.', 'rbx-tools' ) . '</p></noscript>';
		$out .= '<div class="rbx-calc__result"><p class="rbx-fusion__summary" data-rbx-out="summary">' . esc_html( self::summary( $r, $t ) ) . '</p></div>';
		$out .= '<h3>' . esc_html__( 'Fusions and what they need', 'rbx-tools' ) . '</h3>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table rbx-fusion__table"><thead><tr><th scope="col">' . esc_html( $t['result'] ) . '</th><th scope="col">' . esc_html( $t['ingredients'] ) . '</th><th scope="col">' . esc_html( $t['status'] ) . '</th><th scope="col">' . esc_html( $t['change'] ) . '</th></tr></thead><tbody data-rbx-recipes>' . self::recipe_rows( $data, $r, $fmt, $t ) . '</tbody></table></div>';
		$out .= '<h3>' . esc_html__( 'Pets you still need', 'rbx-tools' ) . '</h3>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><thead><tr><th scope="col">' . esc_html( $t['pet'] ) . '</th><th scope="col">' . esc_html( $t['for'] ) . '</th><th scope="col">' . esc_html( $t['income'] ) . '</th><th scope="col">' . esc_html( $t['how'] ) . '</th></tr></thead><tbody data-rbx-missing>' . self::missing_rows( $data, $r, $fmt, $t ) . '</tbody></table></div>';
		$out .= '<p class="rbx-calc__meta">' . esc_html__( 'Recipes and base incomes come from the community wiki. We have not tested every fusion in the game ourselves. Base income only: mutations and weight are left out.', 'rbx-tools' ) . '</p>';
		$out .= '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		$out .= '<script type="application/json" class="rbx-fusion-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-fusion' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * The live recipes whose result and ingredients are all known pets, and every ingredient pet
	 * once, in the order the recipes first name them.
	 *
	 * @return array{pets: list<array{name: string, url: string, income: ?float, how: string, max_qty: int}>, recipes: list<array{name: string, url: string, income: ?float, station: string, also: string, inputs: list<array{pet: int, qty: int}>}>}
	 */
	public static function data( Dataset $ds ): array {
		$pets    = array();
		$index   = array();
		$recipes = array();
		$income  = static function ( array $rec ): ?float {
			$v = $rec['data']['base_income_per_s'] ?? null;
			return is_numeric( $v ) ? (float) $v : null;
		};
		foreach ( $ds->all( 'FUS', array( 'live', 'limited' ) ) as $f ) {
			$res = $ds->get( (string) ( $f['data']['output_pet_id'] ?? '' ) );
			$ins = array();
			$ok  = $res && 'PET' === $res['entity'];
			foreach ( (array) ( $f['data']['inputs'] ?? array() ) as $in ) {
				$pet = $ds->get( (string) ( $in['pet_id'] ?? '' ) );
				$qty = max( 1, (int) ( $in['qty'] ?? 1 ) );
				if ( ! $pet || 'PET' !== $pet['entity'] ) {
					$ok = false;
					break;
				}
				$ins[] = array( $pet, $qty );
			}
			if ( ! $ok || ! $ins ) {
				continue;
			}
			$inputs = array();
			foreach ( $ins as [ $pet, $qty ] ) {
				$pid = (string) $pet['id'];
				if ( ! isset( $index[ $pid ] ) ) {
					$index[ $pid ] = count( $pets );
					$pets[]        = array(
						'name'    => (string) $pet['name'],
						'url'     => self::url( $pet ),
						'income'  => $income( $pet ),
						'how'     => self::how( $ds, $pet ),
						'max_qty' => 1,
					);
				}
				$pets[ $index[ $pid ] ]['max_qty'] = max( $pets[ $index[ $pid ] ]['max_qty'], $qty );
				$inputs[]                          = array(
					'pet' => $index[ $pid ],
					'qty' => $qty,
				);
			}
			$recipes[] = array(
				'name'    => (string) $res['name'],
				'url'     => self::url( $res ),
				'income'  => $income( $res ),
				'station' => wp_strip_all_tags( Lang::t( html_entity_decode( (string) ( $f['data']['station_text__en'] ?? '' ), ENT_QUOTES ) ) ),
				'also'    => self::how( $ds, $res, false ),
				'inputs'  => $inputs,
			);
		}
		return array(
			'pets'    => $pets,
			'recipes' => $recipes,
		);
	}

	/**
	 * Twin of compute() in fusion.js. $owned holds how many of each data pet the player has.
	 * Recipes come back sorted: ready first, then fewest missing pets, then biggest income change.
	 *
	 * @param array{pets: array<int, array<string, mixed>>, recipes: array<int, array<string, mixed>>} $data  data().
	 * @param array<int, int|float>                                                                   $owned Pet index => count.
	 * @return array{ready: int, total: int, rows: list<array{recipe: int, ready: bool, times: int, missing: list<array{pet: int, need: int}>, cost: ?float, change: ?float, slots: int}>, missing: list<array{pet: int, need: int, for: list<int>}>}
	 */
	public static function compute( array $data, array $owned ): array {
		$rows = array();
		foreach ( $data['recipes'] as $ri => $rec ) {
			$missing = array();
			$times   = PHP_INT_MAX;
			$cost    = 0.0;
			$slots   = -1;
			foreach ( $rec['inputs'] as $in ) {
				$have = max( 0, (int) ( $owned[ $in['pet'] ] ?? 0 ) );
				$qty  = (int) $in['qty'];
				if ( $have < $qty ) {
					$missing[] = array(
						'pet'  => (int) $in['pet'],
						'need' => $qty - $have,
					);
				}
				$times  = min( $times, intdiv( $have, $qty ) );
				$inc    = $data['pets'][ $in['pet'] ]['income'];
				$cost   = null === $cost || null === $inc ? null : $cost + $inc * $qty;
				$slots += $qty;
			}
			$rows[] = array(
				'recipe'  => (int) $ri,
				'ready'   => ! $missing,
				'times'   => PHP_INT_MAX === $times ? 0 : $times,
				'missing' => $missing,
				'cost'    => $cost,
				'change'  => null === $cost || null === $rec['income'] ? null : $rec['income'] - $cost,
				'slots'   => $slots,
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( $a['ready'] !== $b['ready'] ) {
					return $a['ready'] ? -1 : 1;
				}
				if ( count( $a['missing'] ) !== count( $b['missing'] ) ) {
					return count( $a['missing'] ) <=> count( $b['missing'] );
				}
				if ( $a['change'] !== $b['change'] ) {
					if ( null === $a['change'] || null === $b['change'] ) {
						return null === $a['change'] ? 1 : -1;
					}
					return $b['change'] <=> $a['change'];
				}
				return $a['recipe'] <=> $b['recipe'];
			}
		);
		// Each missing pet once, with the recipes (in table order) that need it.
		$need = array();
		foreach ( $rows as $row ) {
			foreach ( $row['missing'] as $m ) {
				if ( ! isset( $need[ $m['pet'] ] ) ) {
					$need[ $m['pet'] ] = array(
						'pet'  => $m['pet'],
						'need' => 0,
						'for'  => array(),
					);
				}
				$need[ $m['pet'] ]['need'] = max( $need[ $m['pet'] ]['need'], $m['need'] );
				$need[ $m['pet'] ]['for'][] = $row['recipe'];
			}
		}
		return array(
			'ready'   => count( array_filter( $rows, static fn( $r ) => $r['ready'] ) ),
			'total'   => count( $rows ),
			'rows'    => $rows,
			'missing' => array_values( $need ),
		);
	}

	/** Pet page URL, or '' while the page is not published. */
	private static function url( array $rec ): string {
		$pid = (int) ( $rec['post_id'] ?? 0 );
		return $pid && 'publish' === get_post_status( $pid ) ? (string) get_permalink( $pid ) : '';
	}

	/** Plain-text "where to get it": the egg and its biome with the Speed it needs, or the source note. */
	private static function how( Dataset $ds, array $pet, bool $fusion = true ): string {
		$parts = array();
		foreach ( $ds->children( 'SRC', 'pet_id', (string) $pet['id'] ) as $s ) {
			$ref = $ds->get( (string) ( $s['data']['source_ref_id'] ?? '' ) );
			if ( $ref && 'EGG' === $ref['entity'] ) {
				$bio  = null;
				$bids = $ref['data']['biome_ids'] ?? array();
				foreach ( is_array( $bids ) ? $bids : explode( '|', (string) $bids ) as $bid ) {
					$bio = $ds->get( (string) $bid );
					if ( $bio ) {
						break;
					}
				}
				$speed = $bio ? ( $bio['data']['unlock_speed_required'] ?? null ) : null;
				if ( $bio && is_numeric( $speed ) && (float) $speed > 0 ) {
					$ladder = $ds->meta( 'suffix_ladder' );
					/* translators: 1: egg name, 2: biome name, 3: Speed the biome needs */
					$parts[] = sprintf( __( '%1$s in %2$s (needs %3$s Speed)', 'rbx-tools' ), (string) $ref['name'], (string) $bio['name'], Number::format( (float) $speed, 'abbr', Lang::num(), is_array( $ladder ) ? $ladder : null ) );
				} elseif ( $bio ) {
					/* translators: 1: egg name, 2: biome name */
					$parts[] = sprintf( __( '%1$s in %2$s', 'rbx-tools' ), (string) $ref['name'], (string) $bio['name'] );
				} else {
					$parts[] = (string) $ref['name'];
				}
			} elseif ( 'fusion' === (string) ( $s['data']['source_kind'] ?? '' ) ) {
				if ( $fusion ) {
					$parts[] = __( 'Fusion', 'rbx-tools' );
				}
			} elseif ( ! empty( $s['data']['note__en'] ) && ! Lang::is_id() ) {
				$parts[] = rtrim( wp_strip_all_tags( (string) $s['data']['note__en'] ), '. ' );
			}
		}
		return implode( '; ', array_unique( $parts ) );
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function summary( array $r, array $t ): string {
		if ( 0 === $r['ready'] ) {
			return sprintf( $t['none'], number_format_i18n( $r['total'] ) );
		}
		return sprintf( $t['some'], number_format_i18n( $r['ready'] ), number_format_i18n( $r['total'] ) );
	}

	private static function link( string $name, string $url ): string {
		return '' === $url ? esc_html( $name ) : '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
	}

	/** @param array<string, mixed> $data data(). @param array<string, mixed> $r compute() result. */
	private static function recipe_rows( array $data, array $r, callable $fmt, array $t ): string {
		$out = '';
		foreach ( $r['rows'] as $row ) {
			$rec = $data['recipes'][ $row['recipe'] ];
			$ins = array();
			$miss = array();
			foreach ( $row['missing'] as $m ) {
				$miss[ $m['pet'] ] = $m['need'];
			}
			foreach ( $rec['inputs'] as $in ) {
				$p     = $data['pets'][ $in['pet'] ];
				$ins[] = '<li>' . ( $in['qty'] > 1 ? esc_html( number_format_i18n( $in['qty'] ) ) . ' × ' : '' ) . self::link( $p['name'], $p['url'] )
					. ' <span class="rbx-fusion__' . ( isset( $miss[ $in['pet'] ] ) ? 'no' : 'yes' ) . '">' . esc_html( isset( $miss[ $in['pet'] ] ) ? $t['missing'] : $t['have'] ) . '</span></li>';
			}
			$out .= '<tr' . ( $row['ready'] ? ' class="is-ready"' : '' ) . '><th scope="row">' . self::link( $rec['name'], $rec['url'] ) . ( null === $rec['income'] ? '' : '<br><span class="rbx-fusion__inc">' . esc_html( sprintf( $t['per_s'], $fmt( $rec['income'] ) ) ) . '</span>' ) . '</th>'
				. '<td data-label="' . esc_attr( $t['ingredients'] ) . '"><ul class="rbx-fusion__ins">' . implode( '', $ins ) . '</ul>' . ( '' === $rec['station'] ? '' : '<span class="rbx-fusion__station">' . esc_html( $rec['station'] ) . '</span>' ) . ( '' === $rec['also'] ? '' : '<span class="rbx-fusion__station">' . esc_html( sprintf( $t['also'], $rec['also'] ) ) . '</span>' ) . '</td>'
				. '<td data-label="' . esc_attr( $t['status'] ) . '">' . esc_html( self::status_text( $row, $t ) ) . '</td>'
				. '<td data-label="' . esc_attr( $t['change'] ) . '">' . esc_html( self::change_text( $row, $fmt, $t ) ) . '</td></tr>';
		}
		return $out;
	}

	/** @param array<string, mixed> $row One compute() row. */
	public static function status_text( array $row, array $t ): string {
		if ( $row['ready'] ) {
			return $row['times'] > 1 ? sprintf( $t['ready_n'], number_format_i18n( $row['times'] ) ) : $t['ready'];
		}
		$n = 0;
		foreach ( $row['missing'] as $m ) {
			$n += $m['need'];
		}
		return 1 === $n ? $t['need_1'] : sprintf( $t['need_n'], number_format_i18n( $n ) );
	}

	/** "+4B/s. Uses 2 pets, makes 1." @param array<string, mixed> $row One compute() row. */
	public static function change_text( array $row, callable $fmt, array $t ): string {
		$slots = 1 === $row['slots'] ? $t['slot_1'] : sprintf( $t['slot_n'], number_format_i18n( $row['slots'] ) );
		if ( null === $row['change'] ) {
			return $t['unknown'] . ' ' . $slots;
		}
		if ( $row['change'] >= 0 ) {
			return sprintf( $t['gain'], $fmt( $row['change'] ) ) . ' ' . $slots;
		}
		return sprintf( $t['loss'], $fmt( -$row['change'] ) ) . ' ' . $slots . ' ' . sprintf( $t['worth'], $fmt( -$row['change'] ) );
	}

	/** @param array<string, mixed> $data data(). @param array<string, mixed> $r compute() result. */
	private static function missing_rows( array $data, array $r, callable $fmt, array $t ): string {
		$out = '';
		foreach ( $r['missing'] as $m ) {
			$p    = $data['pets'][ $m['pet'] ];
			$for  = array_map( static fn( int $ri ) => $data['recipes'][ $ri ]['name'], $m['for'] );
			$out .= '<tr><th scope="row">' . ( $m['need'] > 1 ? esc_html( number_format_i18n( $m['need'] ) ) . ' × ' : '' ) . self::link( $p['name'], $p['url'] ) . '</th>'
				. '<td data-label="' . esc_attr( $t['for'] ) . '">' . esc_html( implode( ', ', $for ) ) . '</td>'
				. '<td data-label="' . esc_attr( $t['income'] ) . '">' . esc_html( null === $p['income'] ? '—' : sprintf( $t['per_s'], $fmt( $p['income'] ) ) ) . '</td>'
				. '<td data-label="' . esc_attr( $t['how'] ) . '">' . esc_html( '' === $p['how'] ? '—' : $p['how'] ) . '</td></tr>';
		}
		if ( '' === $out ) {
			$out = '<tr><td colspan="4">' . esc_html( $t['all'] ) . '</td></tr>';
		}
		return $out;
	}

	/** @return array<string, string> */
	public static function strings(): array {
		return array(
			/* translators: %s: income such as 1.3B */
			'per_s'       => __( '%s/s', 'rbx-tools' ),
			'result'      => __( 'Fusion result', 'rbx-tools' ),
			'ingredients' => __( 'Ingredients', 'rbx-tools' ),
			'status'      => __( 'Can you make it?', 'rbx-tools' ),
			'change'      => __( 'Income change', 'rbx-tools' ),
			'pet'         => __( 'Pet', 'rbx-tools' ),
			'for'         => __( 'Needed for', 'rbx-tools' ),
			'income'      => __( 'Base income', 'rbx-tools' ),
			'how'         => __( 'How to get it', 'rbx-tools' ),
			'have'        => __( '(you have it)', 'rbx-tools' ),
			'missing'     => __( '(missing)', 'rbx-tools' ),
			'ready'       => __( 'Ready to fuse', 'rbx-tools' ),
			/* translators: %s: how many times */
			'ready_n'     => __( 'Ready to fuse (%s times)', 'rbx-tools' ),
			'need_1'      => __( 'Missing 1 pet', 'rbx-tools' ),
			/* translators: %s: number of pets */
			'need_n'      => __( 'Missing %s pets', 'rbx-tools' ),
			/* translators: %s: income such as 4B */
			'gain'        => __( '+%s/s.', 'rbx-tools' ),
			/* translators: %s: income such as 800M */
			'loss'        => __( '−%s/s.', 'rbx-tools' ),
			'unknown'     => __( 'Not known yet.', 'rbx-tools' ),
			'slot_1'      => __( 'Frees 1 pen slot.', 'rbx-tools' ),
			/* translators: %s: number of pen slots */
			'slot_n'      => __( 'Frees %s pen slots.', 'rbx-tools' ),
			/* translators: %s: income such as 800M */
			'worth'       => __( 'Worth it for income only if the free slot then earns more than %s/s.', 'rbx-tools' ),
			/* translators: %s: number of recipes */
			'none'        => __( 'You cannot make any of the %s fusions yet. The tables show what each one still needs.', 'rbx-tools' ),
			/* translators: 1: recipes ready, 2: all recipes */
			'some'        => __( 'You can make %1$s of the %2$s fusions now.', 'rbx-tools' ),
			'all'         => __( 'You have every pet the fusions need.', 'rbx-tools' ),
			/* translators: %s: where the result pet also comes from, such as its egg */
			'also'        => __( 'Without fusing: %s.', 'rbx-tools' ),
		);
	}
}
