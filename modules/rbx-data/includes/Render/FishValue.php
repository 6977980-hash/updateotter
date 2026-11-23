<?php
/**
 * Our own computed numbers for fishing games that sell by weight (Fisch, 2026-11-23): every value
 * here is worked out with the game's live calculator formula (`fish_value`), so the tables, the
 * worked examples in page copy and the calculator always agree and follow every import.
 *
 * - `value_leaders`: the fish worth the most at their average weight (optionally one rarity).
 * - `mutation_values`: one fish's sell value with each of the strongest mutations.
 * - `appraise_odds`: from the appraiser's mutation chances, how often one appraisal gives any
 *   mutation (and a strong one), and what that costs at 30% of the fish's value per try.
 * - `[rbx_calc game="fsch" formula="fish_value" fish="FSCH-FSH-0825" weight="100" mutation="..."]`
 *   for numbers in page copy.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Api;
use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Format\Number;

defined( 'ABSPATH' ) || exit;

final class FishValue {

	/** Formula key of the sell value and of the appraisal fee. */
	public const VALUE = 'fish_value';
	public const FEE   = 'appraise_cost';

	/** A mutation counts as "strong" for the appraise odds from this multiplier. */
	public const STRONG = 5.0;

	/** True when the game has the sell-by-weight formula. */
	public static function supports( Game $game ): bool {
		return isset( Repository::dataset( $game )->formulas()[ self::VALUE ] ) && null !== $game->field( 'FSH', 'value_per_kg' );
	}

	/**
	 * Formula result for record-ID inputs, or null when the formula cannot give one (TBA values).
	 *
	 * @param array<string, mixed> $inputs Inputs keyed like the tool (fish, weight, mutation, ...).
	 */
	public static function value( Game $game, array $inputs, string $formula = self::VALUE ): ?float {
		$r = Api::evaluate( $formula, $inputs, $game );
		return ! empty( $r['ok'] ) && is_numeric( $r['value'] ) ? (float) $r['value'] : null;
	}

	/** Calculator link with the fish filled in ('' while the calculator page is not live). */
	public static function calc_url( Game $game, array $fish ): string {
		$u = Breadcrumbs::page_url( $game->folder() . '/calculator' );
		return '' === $u ? '' : $u . '#v=1&fish=' . rawurlencode( (string) $fish['slug'] );
	}

	/** Fish name, linked to the calculator for that fish when it is live. */
	public static function fish_link( Game $game, array $fish ): string {
		$u = self::calc_url( $game, $fish );
		return '' === $u ? esc_html( (string) $fish['name'] ) : '<a href="' . esc_url( $u ) . '">' . esc_html( (string) $fish['name'] ) . '</a>';
	}

	/** Where to catch a fish: linked location names, else the wiki's own wording. */
	public static function where( Dataset $ds, array $fish ): string {
		$names = array();
		foreach ( (array) ( $fish['data']['location_ids'] ?? array() ) as $lid ) {
			$l = $ds->get( (string) $lid );
			if ( $l ) {
				$names[] = (string) $l['name'];
			}
		}
		if ( $names ) {
			return esc_html( implode( ', ', $names ) );
		}
		// Short wiki labels such as "Admin Events" stay; longer wiki sentences are not copied.
		$t = trim( (string) ( $fish['data']['location_text__en'] ?? '' ) );
		if ( '' === $t || 'None' === $t ) {
			return Ui::dash();
		}
		return mb_strlen( $t ) <= 30 ? esc_html( $t ) : esc_html__( 'Special event or spot', 'rbx-data' );
	}

	/**
	 * Live fish that have a value, with their value at average and at the heaviest weight.
	 *
	 * @return array<int, array{rec: array<string, mixed>, avg: float, max: ?float}>
	 */
	public static function valued( Game $game, string $rarity = '', bool $spots_only = false ): array {
		$ds  = Repository::dataset( $game );
		$out = array();
		foreach ( $ds->all( 'FSH', array( 'live' ) ) as $id => $f ) {
			if ( '' !== $rarity && $rarity !== (string) ( $f['data']['rarity_id'] ?? '' ) ) {
				continue;
			}
			if ( $spots_only && ! array_filter( (array) ( $f['data']['location_ids'] ?? array() ) ) ) {
				continue;
			}
			// Our QA: the wiki's lightest <= average <= heaviest, else its weights contradict each other.
			if ( ! self::weights_ok( $f ) ) {
				continue;
			}
			$avg = self::value( $game, array( 'fish' => (string) $id ) );
			if ( null === $avg || $avg <= 0 ) {
				continue;
			}
			$mx    = $f['data']['weight_max_kg'] ?? null;
			$out[] = array(
				'rec' => $f,
				'avg' => $avg,
				'max' => is_numeric( $mx ) && (float) $mx > 0 ? self::value(
					$game,
					array(
						'fish'   => (string) $id,
						'weight' => (float) $mx,
					)
				) : null,
			);
		}
		usort( $out, static fn( $a, $b ) => array( $b['avg'], (string) $a['rec']['name'] ) <=> array( $a['avg'], (string) $b['rec']['name'] ) );
		return $out;
	}

	/** False when the wiki's lightest, average and heaviest weights contradict each other. */
	public static function weights_ok( array $fish ): bool {
		$d   = $fish['data'];
		$avg = $d['weight_avg_kg'] ?? null;
		if ( ! is_numeric( $avg ) ) {
			return true;
		}
		$min = $d['weight_min_kg'] ?? null;
		$max = $d['weight_max_kg'] ?? null;
		return ! ( is_numeric( $min ) && (float) $min > (float) $avg ) && ! ( is_numeric( $max ) && (float) $max < (float) $avg );
	}

	/** Top fish by value at average weight. `$rarity` is a rarity record ID or ''. */
	public static function leaders( Game $game, int $limit, string $rarity = '' ): string {
		if ( ! self::supports( $game ) ) {
			return '';
		}
		$ds   = Repository::dataset( $game );
		// Fish you can go and catch: event-only and admin fish (no fishing spot) are left out.
		$all  = self::valued( $game, $rarity, true );
		$rows = array_slice( $all, 0, max( 3, $limit ) );
		if ( count( $rows ) < 3 ) {
			return '';
		}
		$html = '';
		foreach ( $rows as $i => $r ) {
			$f     = $r['rec'];
			Sections::remember( (string) $f['id'] );
			$rar   = $ds->get( is_string( $f['data']['rarity_id'] ?? null ) ? $f['data']['rarity_id'] : null );
			$html .= '<tr><td class="rbx-num-cell">' . esc_html( number_format_i18n( $i + 1 ) ) . '</td>'
				. '<th scope="row">' . self::fish_link( $game, $f ) . '</th>'
				. '<td data-label="' . esc_attr__( 'Rarity', 'rbx-data' ) . '">' . ( $rar ? esc_html( (string) $rar['name'] ) : Ui::dash() ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Where to catch', 'rbx-data' ) . '">' . self::where( $ds, $f ) . '</td>'
				. '<td data-label="' . esc_attr__( 'C$ per kg', 'rbx-data' ) . '" class="rbx-num-cell">' . Ui::number( $ds, (float) $f['data']['value_per_kg'], 'abbr' ) . '</td>'
				. '<td data-label="' . esc_attr__( 'At average weight', 'rbx-data' ) . '" class="rbx-num-cell">' . Ui::number( $ds, $r['avg'], 'abbr' ) . '</td>'
				. '<td data-label="' . esc_attr__( 'At the heaviest weight', 'rbx-data' ) . '" class="rbx-num-cell">' . ( null === $r['max'] ? Ui::dash() : Ui::number( $ds, $r['max'], 'abbr' ) ) . '</td></tr>';
		}
		$scope = '';
		if ( '' !== $rarity ) {
			$rr    = $ds->get( $rarity );
			$scope = $rr ? (string) $rr['name'] . ' ' : '';
		}
		/* translators: 1: number of fish, 2: rarity name with a trailing space, or nothing */
		$cap   = sprintf( __( 'The %1$s %2$sfish worth the most C$ at their average weight', 'rbx-data' ), number_format_i18n( count( $rows ) ), $scope );
		$table = '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">#</th><th scope="col">' . esc_html__( 'Fish', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Rarity', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Where to catch', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'C$ per kg', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'At average weight', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'At the heaviest weight', 'rbx-data' ) . '</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
		/* translators: %s: number of fish */
		$note = sprintf( __( 'Worked out by us with the calculator formula (C$ per kg × weight, no mutation) from community wiki numbers. Out of the %s fish with a fishing spot and wiki numbers that agree with each other; event-only fish and fish with conflicting wiki numbers (value or weights) are left out.', 'rbx-data' ), number_format_i18n( count( $all ) ) );
		if ( '' !== $rarity ) {
			$note = __( 'Worked out by us with the calculator formula (C$ per kg × weight, no mutation) from community wiki numbers. Event-only fish and fish whose wiki numbers (value or weights) disagree with each other are left out.', 'rbx-data' );
		}
		return $table . '<p class="rbx-note">' . esc_html( $note ) . '</p>';
	}

	/**
	 * Live permanent mutations with a fixed multiplier, strongest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mutations( Dataset $ds ): array {
		$out = array_filter(
			$ds->all( 'MUT', array( 'live' ) ),
			static fn( $m ) => is_numeric( $m['data']['value_mult'] ?? null ) && 'standard' === ( $m['data']['mutation_group'] ?? 'standard' )
		);
		usort( $out, static fn( $a, $b ) => array( (float) $b['data']['value_mult'], (string) $a['name'] ) <=> array( (float) $a['data']['value_mult'], (string) $b['name'] ) );
		return array_values( $out );
	}

	/** One fish's sell value at its average weight with each of the strongest mutations. */
	public static function mutation_values( Game $game, string $fish_id, int $limit ): string {
		if ( ! self::supports( $game ) ) {
			return '';
		}
		$ds   = Repository::dataset( $game );
		$fish = $ds->get( $fish_id );
		$base = $fish ? self::value( $game, array( 'fish' => $fish_id ) ) : null;
		if ( null === $base ) {
			return '';
		}
		Sections::remember( $fish_id );
		$rows = '';
		foreach ( array_slice( self::mutations( $ds ), 0, max( 3, $limit ) ) as $m ) {
			$v = self::value(
				$game,
				array(
					'fish'     => $fish_id,
					'mutation' => (string) $m['id'],
				)
			);
			if ( null === $v ) {
				continue;
			}
			Sections::remember( (string) $m['id'] );
			$rows .= '<tr><th scope="row">' . Ui::link( $m ) . '</th><td data-label="' . esc_attr__( 'Multiplier', 'rbx-data' ) . '" class="rbx-num-cell">' . Ui::value( $ds, $m, 'value_mult', false ) . '</td><td data-label="' . esc_attr__( 'Sell value', 'rbx-data' ) . '" class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td><td data-label="' . esc_attr__( 'More than no mutation', 'rbx-data' ) . '" class="rbx-num-cell">+' . Ui::number( $ds, $v - $base, 'abbr' ) . '</td></tr>';
		}
		if ( '' === $rows ) {
			return '';
		}
		/* translators: 1: fish name, 2: sell value */
		$cap = sprintf( __( 'A %1$s at its average weight sells for %2$s C$ with no mutation. With a mutation:', 'rbx-data' ), (string) $fish['name'], Number::format( $base, 'abbr', \RBX\Data\Content\Lang::num(), self::ladder( $ds ) ) );
		return '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Mutation', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Multiplier', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Sell value', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'More than no mutation', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
			. '<p class="rbx-note">' . esc_html__( 'Worked out by us with the calculator formula. Multipliers come from the community wiki. Event and admin mutations are left out, because you cannot get them most of the time.', 'rbx-data' ) . '</p>';
	}

	/**
	 * Appraisal odds from the mutations' `appraise_chance_prob` (live rows only).
	 *
	 * @return array{any: float, strong: float, n: int, n_strong: int}|null
	 */
	public static function odds( Dataset $ds ): ?array {
		$any    = 0.0;
		$strong = 0.0;
		$n      = 0;
		$ns     = 0;
		foreach ( $ds->all( 'MUT', array( 'live' ) ) as $m ) {
			$p = $m['data']['appraise_chance_prob'] ?? null;
			if ( empty( $m['data']['appraisable'] ) || ! is_numeric( $p ) || (float) $p <= 0 ) {
				continue;
			}
			$any += (float) $p;
			++$n;
			if ( is_numeric( $m['data']['value_mult'] ?? null ) && (float) $m['data']['value_mult'] >= self::STRONG ) {
				$strong += (float) $p;
				++$ns;
			}
		}
		return $n > 0 && $any < 1 ? array(
			'any'      => $any,
			'strong'   => $strong,
			'n'        => $n,
			'n_strong' => $ns,
		) : null;
	}

	/** "Is appraising worth it?" box: odds per appraisal and the expected number of tries. */
	public static function appraise_odds( Game $game ): string {
		if ( ! self::supports( $game ) ) {
			return '';
		}
		$ds = Repository::dataset( $game );
		$o  = self::odds( $ds );
		if ( null === $o ) {
			return '';
		}
		$pct  = static fn( float $p ): string => Number::format( $p * 100, 'decimal(2)', \RBX\Data\Content\Lang::num() ) . '%';
		$one  = static fn( float $p ): string => number_format_i18n( (int) round( 1 / $p ) );
		$rows = '<tr><th scope="row">' . esc_html__( 'Any mutation', 'rbx-data' ) . '</th><td class="rbx-num-cell">' . esc_html( $pct( $o['any'] ) ) . '</td><td class="rbx-num-cell">' . esc_html( $one( $o['any'] ) ) . '</td><td class="rbx-num-cell">' . esc_html( Number::format( 0.3 / $o['any'], 'decimal(1)', \RBX\Data\Content\Lang::num() ) ) . '×</td></tr>';
		if ( $o['strong'] > 0 ) {
			/* translators: %s: multiplier such as 5 */
			$rows .= '<tr><th scope="row">' . esc_html( sprintf( __( 'A mutation of ×%s or more', 'rbx-data' ), Number::format( self::STRONG, 'decimal(0)', \RBX\Data\Content\Lang::num() ) ) ) . '</th><td class="rbx-num-cell">' . esc_html( $pct( $o['strong'] ) ) . '</td><td class="rbx-num-cell">' . esc_html( $one( $o['strong'] ) ) . '</td><td class="rbx-num-cell">' . esc_html( Number::format( 0.3 / $o['strong'], 'decimal(1)', \RBX\Data\Content\Lang::num() ) ) . '×</td></tr>';
		}
		$cap = __( 'Chance per appraisal, worked out by us from the community wiki appraiser table', 'rbx-data' );
		return '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'What you hope for', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Chance per try', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'About 1 in', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Fees until you get one, in fish values', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
			/* translators: %s: number of mutations */
			. '<p class="rbx-note">' . esc_html( sprintf( __( 'Adds up the normal chances of the %s mutations the wiki lists for the appraiser (no Mutation Surge, no appraiser luck). Each try costs 30%% of the fish\'s value, and the fish\'s value changes with its new weight, so the fee column is a rough guide. Appraising removes any mutation the fish already has; Shiny and Sparkling stay.', 'rbx-data' ), number_format_i18n( $o['n'] ) ) ) . '</p>';
	}

	/**
	 * [rbx_calc game="fsch" formula="fish_value" fish="FSCH-FSH-0825" weight="100" mutation="FSCH-MUT-0001"]
	 * Every attribute other than game and formula is a formula input (record IDs for picks).
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function calc_sc( $atts ): string {
		$atts = (array) $atts;
		$game = Loader::game( (string) ( $atts['game'] ?? '' ) );
		if ( null === $game ) {
			return '';
		}
		$formula = (string) ( $atts['formula'] ?? self::VALUE );
		$inputs  = array();
		$ds      = Repository::dataset( $game );
		foreach ( $atts as $k => $v ) {
			if ( 'game' === $k || 'formula' === $k || ! is_string( $k ) ) {
				continue;
			}
			$v = (string) $v;
			if ( is_numeric( $v ) ) {
				$inputs[ $k ] = (float) $v;
			} elseif ( '' !== $v ) {
				if ( null === $ds->get( $v ) ) {
					return '';
				}
				$inputs[ $k ] = $v;
			}
		}
		$v = self::value( $game, $inputs, $formula );
		if ( null === $v ) {
			return esc_html__( 'TBA', 'rbx-data' );
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		return Ui::number( $ds, $v, 'abbr' );
	}

	/** @return array<int, array{s: string, e: int}>|null */
	private static function ladder( Dataset $ds ): ?array {
		$l = $ds->meta( 'suffix_ladder' );
		return is_array( $l ) ? $l : null;
	}
}
