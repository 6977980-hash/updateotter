<?php
/**
 * Data-driven insight blocks for item pages (Phase 2 ranking work): facts at a glance, income by
 * mutation and by weight for pets, and the effect on real pets for mutations. Every number comes
 * from the dataset or from the live calculator formula, so the blocks stay correct after an import.
 * A block whose inputs are missing is omitted (DATA-28).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Api;
use RBX\Data\Data\Dataset;

defined( 'ABSPATH' ) || exit;

/**
 * Pure renderers; Item::render() places them between "How to get it" and the notes.
 */
final class Insights {

	/** Weight steps for the weight table, as multiples of the base weight. */
	private const WEIGHT_STEPS = array( 1, 2, 10, 50, 125, 500 );

	/** Most pets shown in a mutation's effect table. */
	private const MUT_TABLE_MAX = 20;

	public static function render( Dataset $ds, array $rec ): string {
		switch ( (string) $rec['entity'] ) {
			case 'PET':
				return self::pet_glance( $ds, $rec ) . self::pet_income( $ds, $rec ) . self::pet_weight( $ds, $rec );
			case 'MUT':
				return $ds->game()->entity( 'PLT' ) ? self::crop_mutation_effect( $ds, $rec ) : self::mutation_effect( $ds, $rec );
			case 'PLT':
				return self::plant_glance( $ds, $rec ) . self::plant_value( $ds, $rec ) . self::plant_weight( $ds, $rec );
		}
		return '';
	}

	/* ---------------------------------------------------------------- pets */

	private static function pet_glance( Dataset $ds, array $rec ): string {
		$name  = (string) $rec['name'];
		$facts = array();

		// Rank by base income within the rarity and overall.
		$income = self::base_income( $ds, $rec );
		$rar    = $ds->get( (string) ( $rec['data']['rarity_id'] ?? '' ) );
		if ( null !== $income && $rar ) {
			$same = self::ranked_pets( $ds, (string) $rar['id'] );
			$pos  = array_search( (string) $rec['id'], array_keys( $same ), true );
			if ( false !== $pos && count( $same ) > 1 ) {
				$facts[] = sprintf(
					/* translators: 1: pet, 2: count, 3: rarity, 4: ordinal */
					esc_html__( 'The %1$s is one of %2$s %3$s pets in our list and has %4$s base income among them.', 'rbx-data' ),
					esc_html( $name ),
					esc_html( number_format_i18n( count( $same ) ) ),
					esc_html( (string) $rar['name'] ),
					esc_html( count( $same ) === (int) $pos + 1 ? __( 'the lowest', 'rbx-data' ) : self::nth( (int) $pos + 1, __( 'highest', 'rbx-data' ) ) )
				);
				$top = array_key_first( $same );
				// Event and shop pets can sit far above the rest of a rarity; skip silly ratios.
				if ( $top !== $rec['id'] && (float) $same[ $top ]['income'] / $income <= 50 ) {
					$tr      = $same[ $top ];
					$ratio   = (float) $tr['income'] / $income;
					$facts[] = sprintf(
						/* translators: 1: strongest pet link, 2: rarity, 3: ratio, 4: pet */
						esc_html__( 'The strongest %2$s pet, %1$s, earns about %3$s times as much as the %4$s.', 'rbx-data' ),
						Ui::link( $tr['record'] ),
						esc_html( (string) $rar['name'] ),
						esc_html( self::ratio( $ratio ) ),
						esc_html( $name )
					);
				}
			}
			$all = self::ranked_pets( $ds, null );
			$pos = array_search( (string) $rec['id'], array_keys( $all ), true );
			if ( false !== $pos ) {
				$facts[] = count( $all ) === (int) $pos + 1 && $pos > 0
					/* translators: %s: count */
					? sprintf( esc_html__( 'It has the lowest base income of all %s pets with a known income.', 'rbx-data' ), esc_html( number_format_i18n( count( $all ) ) ) )
					: sprintf(
						/* translators: 1: ordinal, 2: count */
						esc_html__( 'Across all %2$s pets with a known income it ranks %1$s.', 'rbx-data' ),
						esc_html( self::ordinal( (int) $pos + 1 ) ),
						esc_html( number_format_i18n( count( $all ) ) )
					);
			}
			$facts[] = sprintf(
				/* translators: 1: pet, 2: per minute, 3: per hour */
				esc_html__( 'At its base weight one %1$s earns %2$s Cash per minute, or %3$s per hour.', 'rbx-data' ),
				esc_html( $name ),
				Ui::number( $ds, $income * 60, 'abbr' ),
				Ui::number( $ds, $income * 3600, 'abbr' )
			);
		}

		// Egg, biome, hatch time and reward.
		foreach ( $ds->children( 'DRP', 'pet_id', (string) $rec['id'] ) as $d ) {
			$egg = $ds->get( (string) $d['data']['egg_id'] );
			if ( ! $egg ) {
				continue;
			}
			$where = array();
			foreach ( (array) ( $egg['data']['biome_ids'] ?? array() ) as $bid ) {
				$bio = $ds->get( (string) $bid );
				if ( ! $bio ) {
					continue;
				}
				$speed   = $bio['data']['unlock_speed_required'] ?? null;
				$where[] = Ui::link( $bio ) . ( is_numeric( $speed ) && (float) $speed > 0
					/* translators: %s: speed */
					? ' (' . sprintf( esc_html__( 'needs %s Speed', 'rbx-data' ), Ui::number( $ds, $speed + 0, 'abbr' ) ) . ')'
					: ( is_numeric( $speed ) ? ' (' . esc_html__( 'open from the start', 'rbx-data' ) . ')' : '' ) );
			}
			$line = sprintf(
				/* translators: %s: egg link */
				esc_html__( 'It hatches from the %s', 'rbx-data' ),
				Ui::link( $egg )
			);
			if ( $where ) {
				/* translators: %s: biome list */
				$line .= sprintf( esc_html__( ', found in %s', 'rbx-data' ), implode( ', ', $where ) );
			}
			$hatch = $egg['data']['hatch_time_s'] ?? null;
			if ( is_numeric( $hatch ) && (float) $hatch > 0 ) {
				/* translators: %s: duration */
				$line .= sprintf( esc_html__( ', and takes %s to hatch in your pen', 'rbx-data' ), Ui::raw( $ds, array( 'type' => 'duration_s' ), $hatch + 0 ) );
			}
			$facts[] = $line . '.';
			$rs      = $egg['data']['reward_speed'] ?? null;
			$rc      = $egg['data']['reward_cash'] ?? null;
			if ( is_numeric( $rs ) && is_numeric( $rc ) ) {
				$facts[] = sprintf(
					/* translators: 1: speed, 2: cash */
					esc_html__( 'The community wiki lists a reward of %1$s Speed and %2$s Cash for this egg.', 'rbx-data' ),
					Ui::number( $ds, $rs + 0, 'abbr' ),
					Ui::number( $ds, $rc + 0, 'abbr' )
				);
			}
			break;
		}

		if ( ! $facts ) {
			return '';
		}
		$li = '';
		foreach ( $facts as $f ) {
			$li .= '<li>' . $f . '</li>';
		}
		/* translators: %s: pet */
		return '<section class="rbx-glance">' . Ui::h2( sprintf( __( '%s at a glance', 'rbx-data' ), $name ), 'at-a-glance' ) . '<ul>' . $li . '</ul>' . self::source_note() . '</section>';
	}

	private static function pet_income( Dataset $ds, array $rec ): string {
		$base = self::base_income( $ds, $rec );
		if ( null === $base ) {
			return '';
		}
		$name = (string) $rec['name'];
		$rows = '<tr><th scope="row">' . esc_html( $name ) . ' ' . esc_html__( '(no mutation)', 'rbx-data' ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, 1.0, 'mult' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $base, 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $base * 3600, 'abbr' ) . '</td></tr>';
		$best = null;
		foreach ( self::mutations( $ds ) as $m ) {
			$v = self::income( $ds, (string) $rec['id'], (string) $m['record']['id'], null );
			if ( null === $v ) {
				continue;
			}
			$best  = array( $m['record'], $v );
			$rows .= '<tr><th scope="row">' . Ui::link( $m['record'] ) . ' ' . esc_html( $name ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, $m['mult'], 'mult' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $v * 3600, 'abbr' ) . '</td></tr>';
		}
		if ( null === $best ) {
			return '';
		}
		$intro = sprintf(
			/* translators: 1: pet, 2: best mutation name, 3: income */
			esc_html__( 'A plain %1$s earns its base income. A mutation multiplies it, so a %2$s %1$s at the same weight earns %3$s per second, the most of any mutation in our list.', 'rbx-data' ),
			esc_html( $name ),
			esc_html( (string) $best[0]['name'] ),
			Ui::number( $ds, $best[1], 'abbr' )
		);
		/* translators: %s: pet */
		$cap   = sprintf( __( 'Income of one %s at its base weight, by mutation', 'rbx-data' ), $name );
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Version', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Multiplier', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Cash per second', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Cash per hour', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: pet */
		return '<section class="rbx-income">' . Ui::h2( sprintf( __( 'How much does a %s make?', 'rbx-data' ), $name ), 'income' ) . '<p>' . $intro . '</p>' . $table . self::source_note() . '</section>';
	}

	private static function pet_weight( Dataset $ds, array $rec ): string {
		$bw = $rec['data']['base_weight_kg'] ?? null;
		if ( ! is_numeric( $bw ) || (float) $bw <= 0 || null === self::base_income( $ds, $rec ) ) {
			return '';
		}
		$bw   = (float) $bw;
		$name = (string) $rec['name'];
		$base = self::base_income( $ds, $rec );
		$rows = '';
		foreach ( self::WEIGHT_STEPS as $k ) {
			$v = self::income( $ds, (string) $rec['id'], null, $bw * $k );
			if ( null === $v ) {
				return '';
			}
			$rows .= '<tr><th scope="row">' . Ui::number( $ds, $bw * $k, 'abbr' ) . ' kg</th><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, round( $v / $base, 2 ), 'mult' ) . '</td></tr>';
		}
		$ten   = self::income( $ds, (string) $rec['id'], null, $bw * 10 );
		$intro = sprintf(
			/* translators: 1: pet, 2: base weight, 3: ratio */
			esc_html__( 'Every %1$s hatches with its own weight. The base weight is %2$s kg. A heavier one earns more, but the gain per kg shrinks as the weight grows: at ten times the base weight it earns about %3$s times as much, not ten.', 'rbx-data' ),
			esc_html( $name ),
			Ui::number( $ds, $bw, 'abbr' ),
			esc_html( self::ratio( (float) $ten / $base ) )
		);
		/* translators: %s: pet */
		$cap   = sprintf( __( 'Income of one %s with no mutation at different weights', 'rbx-data' ), $name );
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Weight', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Cash per second', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Compared with base', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: pet */
		return '<section class="rbx-weight">' . Ui::h2( sprintf( __( 'Does a heavier %s earn more?', 'rbx-data' ), $name ), 'weight' ) . '<p>' . $intro . '</p>' . $table . '</section>';
	}

	/* ---------------------------------------------------------------- mutations */

	private static function mutation_effect( Dataset $ds, array $rec ): string {
		$mult = $ds->field_state( $rec, 'income_mult' )['value'] ?? null;
		if ( ! is_numeric( $mult ) ) {
			return '';
		}
		$mult  = (float) $mult;
		$name  = preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ) ?? (string) $rec['name'];
		$muts  = self::mutations( $ds );
		$order = array_reverse( array_keys( $muts ) );
		$pos   = isset( $muts[ (string) $rec['id'] ] ) ? count( array_filter( $muts, static fn( $m ) => $m['mult'] > $mult ) ) : false;
		$ties  = array();
		foreach ( $muts as $id => $m ) {
			if ( $id !== $rec['id'] && abs( $m['mult'] - $mult ) < 1e-9 ) {
				$ties[] = Ui::link( $m['record'] );
			}
		}
		$facts = array();
		if ( false !== $pos ) {
			$facts[] = sprintf(
				/* translators: 1: mutation, 2: multiplier, 3: ordinal, 4: count */
				esc_html__( '%1$s multiplies a pet\'s income by %2$s. That makes it %3$s of the %4$s mutations in our list.', 'rbx-data' ),
				esc_html( $name ),
				Ui::number( $ds, $mult, 'mult' ),
				esc_html( self::nth( (int) $pos + 1, __( 'strongest', 'rbx-data' ) ) ),
				esc_html( number_format_i18n( count( $muts ) ) )
			);
			if ( $ties ) {
				/* translators: %s: mutation links */
				$facts[] = sprintf( esc_html__( 'It gives the same boost as %s.', 'rbx-data' ), implode( ', ', $ties ) );
			}
			$top = $muts[ $order[0] ] ?? null;
			if ( $top && $top['record']['id'] !== $rec['id'] ) {
				$facts[] = sprintf(
					/* translators: 1: top mutation link, 2: ratio, 3: mutation */
					esc_html__( 'The strongest one, %1$s, gives about %2$s times the income of a %3$s pet.', 'rbx-data' ),
					Ui::link( $top['record'] ),
					esc_html( self::ratio( $top['mult'] / $mult ) ),
					esc_html( $name )
				);
			}
		}
		/* translators: 1: mutation, 2: percent */
		$facts[] = sprintf( esc_html__( 'On any pet, %1$s adds %2$s to the income the same pet would earn without a mutation, before weight is counted.', 'rbx-data' ), esc_html( $name ), esc_html( number_format_i18n( ( $mult - 1 ) * 100 ) . '%' ) );

		$rows = '';
		$n    = 0;
		foreach ( self::ranked_pets( $ds, null, true ) as $pid => $p ) {
			$v = self::income( $ds, (string) $pid, (string) $rec['id'], null );
			if ( null === $v ) {
				continue;
			}
			$rows .= '<tr><th scope="row">' . Ui::link( $p['record'] ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, (float) $p['income'], 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td></tr>';
			if ( ++$n >= self::MUT_TABLE_MAX ) {
				break;
			}
		}
		$li = '';
		foreach ( $facts as $f ) {
			$li .= '<li>' . $f . '</li>';
		}
		$out = '<section class="rbx-glance">' . Ui::h2( sprintf( /* translators: %s: mutation */ __( 'What %s does to income', 'rbx-data' ), $name ), 'effect' ) . '<ul>' . $li . '</ul>';
		if ( $n >= 2 ) {
			/* translators: %s: mutation */
			$cap  = sprintf( __( 'Income per second at base weight, without a mutation and with %s', 'rbx-data' ), $name );
			$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Pet', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'No mutation', 'rbx-data' ) . '</th><th scope="col">' . esc_html( $name ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		}
		return $out . self::source_note() . '</section>';
	}

	/* ---------------------------------------------------------------- crops (Grow a Garden games) */

	/** Weight steps for the crop weight table, as multiples of the base weight. */
	private const CROP_WEIGHT_STEPS = array( 1, 1.5, 2, 3, 5, 10 );

	/** Well-known crops used as examples on mutation pages when few crop pages are published. */
	private const EXAMPLE_CROPS = array( 'carrot', 'strawberry', 'blueberry', 'tomato', 'corn', 'watermelon', 'pumpkin', 'apple', 'bamboo', 'coconut', 'dragon-fruit', 'mango', 'grape', 'mushroom', 'pepper', 'cacao', 'beanstalk', 'candy-blossom' );

	private static function plant_glance( Dataset $ds, array $rec ): string {
		$name  = (string) $rec['name'];
		$base  = self::crop_value( $ds, (string) $rec['id'] );
		$cur   = self::currency( $ds, $rec );
		$facts = array();
		$rar   = $ds->get( (string) ( $rec['data']['rarity_id'] ?? '' ) );
		if ( null !== $base ) {
			$bw = $rec['data']['base_weight_kg'] ?? null;
			$facts[] = is_numeric( $bw )
				/* translators: 1: crop, 2: value, 3: currency, 4: weight */
				? sprintf( esc_html__( 'A plain %1$s at its base weight of %4$s kg sells for %2$s %3$s.', 'rbx-data' ), esc_html( $name ), Ui::number( $ds, $base, 'abbr' ), esc_html( $cur ), Ui::number( $ds, (float) $bw, 'decimal(2)' ) )
				/* translators: 1: crop, 2: value, 3: currency */
				: sprintf( esc_html__( 'A plain %1$s sells for %2$s %3$s at its base weight.', 'rbx-data' ), esc_html( $name ), Ui::number( $ds, $base, 'abbr' ), esc_html( $cur ) );
			if ( $rar ) {
				$same = self::ranked_crops( $ds, (string) $rar['id'] );
				$pos  = array_search( (string) $rec['id'], array_keys( $same ), true );
				if ( false !== $pos && count( $same ) > 1 ) {
					$facts[] = sprintf(
						/* translators: 1: crop, 2: count, 3: rarity, 4: ordinal */
						esc_html__( 'The %1$s is one of %2$s %3$s crops in our list and has %4$s base value among them.', 'rbx-data' ),
						esc_html( $name ),
						esc_html( number_format_i18n( count( $same ) ) ),
						esc_html( (string) $rar['name'] ),
						esc_html( count( $same ) === (int) $pos + 1 ? __( 'the lowest', 'rbx-data' ) : self::nth( (int) $pos + 1, __( 'highest', 'rbx-data' ) ) )
					);
				}
			}
		}
		if ( array_key_exists( 'is_multi_harvest', $rec['data'] ) && null !== $rec['data']['is_multi_harvest'] ) {
			$facts[] = ! empty( $rec['data']['is_multi_harvest'] )
				/* translators: %s: crop */
				? sprintf( esc_html__( 'The %s is a multi-harvest crop: the plant stays in your garden and keeps growing new fruit after you pick it.', 'rbx-data' ), esc_html( $name ) )
				/* translators: %s: crop */
				: sprintf( esc_html__( 'The %s is a single-harvest crop: you pick the whole plant once, so you need a new seed to grow it again.', 'rbx-data' ), esc_html( $name ) );
		}
		$huge = $rec['data']['huge_chance_prob'] ?? null;
		if ( is_numeric( $huge ) && (float) $huge > 0 ) {
			/* translators: 1: crop, 2: one in N */
			$facts[] = sprintf( esc_html__( 'The community wiki gives each %1$s about a 1 in %2$s chance to grow huge.', 'rbx-data' ), esc_html( $name ), esc_html( number_format_i18n( round( 1 / (float) $huge ) ) ) );
		}
		if ( ! empty( $rec['data']['never_decays'] ) ) {
			/* translators: %s: crop */
			$facts[] = sprintf( esc_html__( 'Harvested %s fruit never decays, so it keeps its full value until you sell it.', 'rbx-data' ), esc_html( $name ) );
		} elseif ( is_numeric( $rec['data']['prime_time_s'] ?? null ) && (float) $rec['data']['prime_time_s'] > 0 ) {
			/* translators: 1: crop, 2: duration */
			$facts[] = sprintf( esc_html__( 'A harvested %1$s stays fresh for %2$s, then slowly loses value as it decays, unless it has a mutation.', 'rbx-data' ), esc_html( $name ), Ui::raw( $ds, array( 'type' => 'duration_s' ), (float) $rec['data']['prime_time_s'] ) );
		}
		if ( array_key_exists( 'obtainable_now', $rec['data'] ) && empty( $rec['data']['obtainable_now'] ) && null !== $rec['data']['obtainable_now'] ) {
			$facts[] = esc_html__( 'The community wiki lists it as not obtainable right now; it came from an event, pack or limited shop.', 'rbx-data' );
		}
		if ( ! $facts ) {
			return '';
		}
		$li = '';
		foreach ( $facts as $f ) {
			$li .= '<li>' . $f . '</li>';
		}
		/* translators: %s: crop */
		return '<section class="rbx-glance">' . Ui::h2( sprintf( __( '%s at a glance', 'rbx-data' ), $name ), 'at-a-glance' ) . '<ul>' . $li . '</ul>' . self::source_note() . '</section>';
	}

	private static function plant_value( Dataset $ds, array $rec ): string {
		$base = self::crop_value( $ds, (string) $rec['id'] );
		if ( null === $base ) {
			return '';
		}
		$name  = (string) $rec['name'];
		$cur   = self::currency( $ds, $rec );
		$rows  = '';
		$intro = '';
		$game  = $ds->game();
		if ( $game->entity( 'VAR' ) ) {
			foreach ( self::variants( $ds ) as $v ) {
				$val = self::crop_value( $ds, (string) $rec['id'], array( 'variant' => (string) $v['record']['id'] ) );
				if ( null !== $val ) {
					$rows .= '<tr><th scope="row">' . esc_html( (string) $v['record']['name'] ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, $v['mult'], 'mult' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $val, 'abbr' ) . '</td></tr>';
				}
			}
			/* translators: 1: crop */
			$intro = sprintf( esc_html__( 'Every %1$s fruit has one variant. Normal is the most common; Gold, Rainbow and the other variants multiply the whole value, mutations included.', 'rbx-data' ), esc_html( $name ) );
			/* translators: 1: crop, 2: currency */
			$cap = sprintf( __( 'Value of one %1$s at its base weight with no mutation, by variant (%2$s)', 'rbx-data' ), $name, $cur );
			$head = '<th scope="col">' . esc_html__( 'Variant', 'rbx-data' ) . '</th>';
		} else {
			$rows = '<tr><th scope="row">' . esc_html__( 'No mutation', 'rbx-data' ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, 1.0, 'mult' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $base, 'abbr' ) . '</td></tr>';
			$best = null;
			foreach ( self::crop_mutations( $ds ) as $m ) {
				$val = self::crop_value( $ds, (string) $rec['id'], array( 'mutation' => (string) $m['record']['id'] ) );
				if ( null === $val ) {
					continue;
				}
				$best  = array( $m['record'], $val );
				$rows .= '<tr><th scope="row">' . Ui::link( $m['record'] ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, $m['mult'], 'mult' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $val, 'abbr' ) . '</td></tr>';
			}
			if ( null === $best ) {
				return '';
			}
			$intro = sprintf(
				/* translators: 1: crop, 2: best mutation, 3: value, 4: currency */
				esc_html__( 'A %1$s can carry one mutation at a time. The strongest in our list, %2$s, makes a base-weight %1$s worth %3$s %4$s.', 'rbx-data' ),
				esc_html( $name ),
				esc_html( (string) $best[0]['name'] ),
				Ui::number( $ds, $best[1], 'abbr' ),
				esc_html( $cur )
			);
			/* translators: 1: crop, 2: currency */
			$cap  = sprintf( __( 'Value of one fresh %1$s at its base weight, by mutation (%2$s)', 'rbx-data' ), $name, $cur );
			$head = '<th scope="col">' . esc_html__( 'Mutation', 'rbx-data' ) . '</th>';
		}
		if ( '' === $rows ) {
			return '';
		}
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr>' . $head . '<th scope="col">' . esc_html__( 'Multiplier', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Sell value', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: crop */
		return '<section class="rbx-income">' . Ui::h2( sprintf( __( 'How much is a %s worth?', 'rbx-data' ), $name ), 'value' ) . '<p>' . $intro . '</p>' . $table . self::source_note() . '</section>';
	}

	private static function plant_weight( Dataset $ds, array $rec ): string {
		$bw   = $rec['data']['base_weight_kg'] ?? null;
		$base = self::crop_value( $ds, (string) $rec['id'] );
		if ( ! is_numeric( $bw ) || (float) $bw <= 0 || null === $base || $base <= 0 ) {
			return '';
		}
		$bw   = (float) $bw;
		$name = (string) $rec['name'];
		$rows = '';
		foreach ( self::CROP_WEIGHT_STEPS as $k ) {
			$v = self::crop_value( $ds, (string) $rec['id'], array( 'weight' => round( $bw * $k, 4 ) ) );
			if ( null === $v ) {
				return '';
			}
			$rows .= '<tr><th scope="row">' . Ui::number( $ds, round( $bw * $k, 2 ), 'decimal(2)' ) . ' kg</th><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, round( $v / $base, 2 ), 'mult' ) . '</td></tr>';
		}
		$two   = self::crop_value( $ds, (string) $rec['id'], array( 'weight' => $bw * 2 ) );
		$intro = sprintf(
			/* translators: 1: crop, 2: base weight, 3: ratio */
			esc_html__( 'Each %1$s fruit grows to its own weight around the base weight of %2$s kg. Value climbs faster than weight: a fruit twice as heavy is worth about %3$s times as much.', 'rbx-data' ),
			esc_html( $name ),
			Ui::number( $ds, $bw, 'decimal(2)' ),
			esc_html( self::ratio( (float) $two / $base ) )
		);
		/* translators: %s: crop */
		$cap   = sprintf( __( 'Value of one %s with no mutation at different weights', 'rbx-data' ), $name );
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Weight', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Sell value', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Compared with base', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: crop */
		return '<section class="rbx-weight">' . Ui::h2( sprintf( __( 'Is a heavier %s worth more?', 'rbx-data' ), $name ), 'weight' ) . '<p>' . $intro . '</p>' . $table . '</section>';
	}

	private static function crop_mutation_effect( Dataset $ds, array $rec ): string {
		$mult = $ds->field_state( $rec, 'income_mult' )['value'] ?? null;
		if ( ! is_numeric( $mult ) ) {
			return '';
		}
		$mult  = (float) $mult;
		$name  = preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ) ?? (string) $rec['name'];
		$muts  = self::crop_mutations( $ds );
		$facts = array();
		if ( isset( $muts[ (string) $rec['id'] ] ) ) {
			$pos  = count( array_filter( $muts, static fn( $m ) => $m['mult'] > $mult ) );
			$ties = count( array_filter( $muts, static fn( $m ) => abs( $m['mult'] - $mult ) < 1e-9 ) ) - 1;
			$facts[] = sprintf(
				/* translators: 1: mutation, 2: multiplier, 3: ordinal, 4: count */
				esc_html__( '%1$s multiplies a fruit\'s sell value by %2$s. That makes it %3$s of the %4$s crop mutations in our list.', 'rbx-data' ),
				esc_html( $name ),
				Ui::number( $ds, $mult, 'mult' ),
				esc_html( self::nth( $pos + 1, __( 'strongest', 'rbx-data' ) ) ),
				esc_html( number_format_i18n( count( $muts ) ) )
			);
			if ( $ties > 0 ) {
				/* translators: %s: number */
				$facts[] = sprintf( esc_html( _n( '%s other mutation has the same multiplier.', '%s other mutations have the same multiplier.', $ties, 'rbx-data' ) ), esc_html( number_format_i18n( $ties ) ) );
			}
		}
		$stack = (string) ( $rec['data']['stack_rule'] ?? '' );
		if ( 'add_bonus' === $stack && $mult > 1 ) {
			$facts[] = sprintf(
				/* translators: 1: mutation, 2: bonus multiplier */
				esc_html__( 'Mutations add up rather than multiply: on a fruit with other mutations, %1$s adds %2$s to the total mutation multiplier. A Gold or Rainbow variant then multiplies that total.', 'rbx-data' ),
				esc_html( $name ),
				Ui::number( $ds, $mult - 1, 'mult' )
			);
		} elseif ( 'exclusive' === $stack ) {
			/* translators: %s: mutation */
			$facts[] = sprintf( esc_html__( 'A crop can carry only one mutation at a time, so %s never stacks with another mutation.', 'rbx-data' ), esc_html( $name ) );
		}

		$rows = '';
		$n    = 0;
		foreach ( self::example_crops( $ds ) as $pid => $p ) {
			$plain = self::crop_value( $ds, (string) $pid );
			$v     = self::crop_value( $ds, (string) $pid, array( 'mutation' => (string) $rec['id'] ) );
			if ( null === $plain || null === $v ) {
				continue;
			}
			$rows .= '<tr><th scope="row">' . Ui::link( $p ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, $plain, 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td></tr>';
			if ( ++$n >= self::MUT_TABLE_MAX ) {
				break;
			}
		}
		$li = '';
		foreach ( $facts as $f ) {
			$li .= '<li>' . $f . '</li>';
		}
		$out = '<section class="rbx-glance">' . Ui::h2( sprintf( /* translators: %s: mutation */ __( 'What %s does to a crop\'s value', 'rbx-data' ), $name ), 'effect' ) . ( $li ? '<ul>' . $li . '</ul>' : '' );
		if ( $n >= 2 ) {
			/* translators: %s: mutation */
			$cap  = sprintf( __( 'Sell value of one fruit at base weight, without a mutation and with only %s', 'rbx-data' ), $name );
			$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Crop', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'No mutation', 'rbx-data' ) . '</th><th scope="col">' . esc_html( $name ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		}
		return $out . self::source_note() . '</section>';
	}

	/**
	 * Sell value of one fruit from the live calculator formula, or null.
	 *
	 * @param array<string, mixed> $extra Extra inputs (weight, variant, mutation).
	 */
	private static function crop_value( Dataset $ds, string $plant, array $extra = array() ): ?float {
		$key = self::formula_key( $ds );
		if ( null === $key ) {
			return null;
		}
		$r = Api::evaluate( $key, array_merge( array( 'plant' => $plant, 'count' => 1 ), $extra ), $ds->game() );
		return $r['ok'] && is_numeric( $r['value'] ) ? (float) $r['value'] : null;
	}

	private static function currency( Dataset $ds, array $rec ): string {
		$c = $ds->get( (string) ( $rec['data']['currency_id'] ?? '' ) );
		return $c ? (string) $c['name'] : __( 'Sheckles', 'rbx-data' );
	}

	/**
	 * Crops with a usable base value, most valuable first.
	 *
	 * @return array<string, array{record: array<string, mixed>, value: float}>
	 */
	private static function ranked_crops( Dataset $ds, ?string $rarity ): array {
		$out = array();
		foreach ( $ds->all( 'PLT', array( 'live', 'limited' ) ) as $id => $p ) {
			if ( null !== $rarity && ( $p['data']['rarity_id'] ?? '' ) !== $rarity ) {
				continue;
			}
			$st = $ds->field_state( $p, 'base_value' );
			if ( ! $st['calc_usable'] || ! is_numeric( $st['value'] ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'record' => $p,
				'value'  => (float) $st['value'],
			);
		}
		uasort( $out, static fn( $a, $b ) => ( $b['value'] <=> $a['value'] ) ?: strcmp( (string) $a['record']['id'], (string) $b['record']['id'] ) );
		return $out;
	}

	/**
	 * Crops for mutation example tables: published crop pages first, then well-known crops.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function example_crops( Dataset $ds ): array {
		$out = array();
		foreach ( $ds->all( 'PLT', array( 'live', 'limited' ) ) as $id => $p ) {
			if ( ! empty( $p['post_id'] ) && 'publish' === get_post_status( (int) $p['post_id'] ) && '' !== trim( (string) ( $p['data']['notes__en'] ?? '' ) ) ) {
				$out[ (string) $id ] = $p;
			}
		}
		if ( count( $out ) < 8 ) {
			foreach ( $ds->all( 'PLT', array( 'live', 'limited' ) ) as $id => $p ) {
				if ( in_array( (string) $p['slug'], self::EXAMPLE_CROPS, true ) ) {
					$out[ (string) $id ] = $p;
				}
			}
		}
		uasort( $out, static fn( $a, $b ) => (float) ( $a['data']['base_value'] ?? 0 ) <=> (float) ( $b['data']['base_value'] ?? 0 ) );
		return $out;
	}

	/**
	 * Crop mutations with a usable multiplier above x1, weakest first.
	 *
	 * @return array<string, array{record: array<string, mixed>, mult: float}>
	 */
	private static function crop_mutations( Dataset $ds ): array {
		$out = array();
		foreach ( $ds->all( 'MUT', array( 'live', 'limited' ) ) as $id => $m ) {
			$st = $ds->field_state( $m, 'income_mult' );
			if ( ! $st['calc_usable'] || ! is_numeric( $st['value'] ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'record' => $m,
				'mult'   => (float) $st['value'],
			);
		}
		uasort( $out, static fn( $a, $b ) => ( $a['mult'] <=> $b['mult'] ) ?: strcmp( (string) $a['record']['name'], (string) $b['record']['name'] ) );
		return $out;
	}

	/**
	 * Variants in sort order.
	 *
	 * @return array<int, array{record: array<string, mixed>, mult: float}>
	 */
	private static function variants( Dataset $ds ): array {
		$out = array();
		foreach ( $ds->all( 'VAR', array( 'live', 'limited' ) ) as $v ) {
			$st = $ds->field_state( $v, 'value_mult' );
			if ( $st['calc_usable'] && is_numeric( $st['value'] ) ) {
				$out[] = array(
					'record' => $v,
					'mult'   => (float) $st['value'],
				);
			}
		}
		usort( $out, static fn( $a, $b ) => (int) ( $a['record']['data']['sort_order'] ?? 0 ) <=> (int) ( $b['record']['data']['sort_order'] ?? 0 ) );
		return $out;
	}

	/* ---------------------------------------------------------------- helpers */

	private static function source_note(): string {
		return '<p class="rbx-note">' . esc_html__( 'Worked out with our calculator formula from community wiki values (Source: community wiki).', 'rbx-data' ) . '</p>';
	}

	private static function formula_key( Dataset $ds ): ?string {
		$f = $ds->formulas( 'calculator' );
		return $f ? (string) array_key_first( $f ) : null;
	}

	/** Income per second from the live formula, or null when it cannot be computed. */
	private static function income( Dataset $ds, string $pet, ?string $mut, ?float $weight ): ?float {
		$key = self::formula_key( $ds );
		if ( null === $key ) {
			return null;
		}
		$in = array(
			'pet'   => $pet,
			'count' => 1,
		);
		if ( null !== $mut ) {
			$in['mutation'] = $mut;
		}
		if ( null !== $weight ) {
			$in['weight'] = $weight;
		}
		$r = Api::evaluate( $key, $in, $ds->game() );
		if ( ! $r['ok'] || ! is_numeric( $r['value'] ) ) {
			return null;
		}
		$v = (float) $r['value'];
		// Whole Cash above 100 per second; the game shows rounded amounts too.
		return $v >= 100 ? round( $v ) : round( $v, 2 );
	}

	private static function base_income( Dataset $ds, array $rec ): ?float {
		return self::income( $ds, (string) $rec['id'], null, null );
	}

	/**
	 * Pets with a usable base income, strongest first.
	 *
	 * @return array<string, array{record: array<string, mixed>, income: float}>
	 */
	private static function ranked_pets( Dataset $ds, ?string $rarity, bool $published_only = false ): array {
		$out = array();
		foreach ( $ds->all( 'PET', array( 'live', 'limited' ) ) as $id => $p ) {
			if ( null !== $rarity && ( $p['data']['rarity_id'] ?? '' ) !== $rarity ) {
				continue;
			}
			if ( $published_only && ( empty( $p['post_id'] ) || 'publish' !== get_post_status( (int) $p['post_id'] ) || '' === trim( (string) ( $p['data']['notes__en'] ?? '' ) ) ) ) {
				continue;
			}
			$st = $ds->field_state( $p, 'base_income_per_s' );
			if ( ! $st['calc_usable'] || ! is_numeric( $st['value'] ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'record' => $p,
				'income' => (float) $st['value'],
			);
		}
		uasort( $out, static fn( $a, $b ) => ( $b['income'] <=> $a['income'] ) ?: strcmp( (string) $a['record']['id'], (string) $b['record']['id'] ) );
		if ( $published_only ) {
			$out = array_reverse( $out, true );
		}
		return $out;
	}

	/**
	 * Live pet mutations with a usable multiplier, weakest first.
	 *
	 * @return array<string, array{record: array<string, mixed>, mult: float}>
	 */
	private static function mutations( Dataset $ds ): array {
		$out = array();
		foreach ( $ds->all( 'MUT', array( 'live', 'limited' ) ) as $id => $m ) {
			if ( 'pet' !== ( $m['data']['applies_to'] ?? 'pet' ) ) {
				continue;
			}
			$st = $ds->field_state( $m, 'income_mult' );
			if ( ! $st['calc_usable'] || ! is_numeric( $st['value'] ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'record' => $m,
				'mult'   => (float) $st['value'],
			);
		}
		uasort( $out, static fn( $a, $b ) => ( $a['mult'] <=> $b['mult'] ) ?: strcmp( (string) $a['record']['name'], (string) $b['record']['name'] ) );
		return $out;
	}

	/** "the highest", "the 2nd highest", ... */
	private static function nth( int $n, string $word ): string {
		/* translators: %s: superlative such as "highest" */
		return 1 === $n ? sprintf( __( 'the %s', 'rbx-data' ), $word ) : sprintf( /* translators: 1: ordinal, 2: superlative */ __( 'the %1$s %2$s', 'rbx-data' ), self::ordinal( $n ), $word );
	}

	private static function ordinal( int $n ): string {
		$v = $n % 100;
		if ( $v >= 11 && $v <= 13 ) {
			return $n . 'th';
		}
		return $n . ( array( 1 => 'st', 2 => 'nd', 3 => 'rd' )[ $n % 10 ] ?? 'th' );
	}

	private static function ratio( float $r ): string {
		if ( $r >= 10 ) {
			return number_format_i18n( round( $r ) );
		}
		$r = round( $r, 1 );
		return number_format_i18n( $r, floor( $r ) === $r ? 0 : 1 );
	}
}
