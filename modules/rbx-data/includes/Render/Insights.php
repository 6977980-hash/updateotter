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

	/** Most pets shown in a mutation's effect table. */
	private const MUT_TABLE_MAX = 20;

	public static function render( Dataset $ds, array $rec ): string {
		switch ( (string) $rec['entity'] ) {
			case 'PET':
				return self::event_guide( $ds, $rec ) . self::pet_glance( $ds, $rec ) . self::pet_zone( $ds, $rec ) . self::pet_income( $ds, $rec ) . self::pet_weight( $ds, $rec );
			case 'MUT':
				return $ds->game()->entity( 'PLT' ) ? self::crop_mutation_effect( $ds, $rec ) : self::mutation_effect( $ds, $rec ) . self::mutation_equivalents( $ds, $rec );
			case 'PLT':
				return self::plant_glance( $ds, $rec ) . self::plant_value( $ds, $rec ) . self::plant_weight( $ds, $rec );
		}
		return '';
	}

	/* ---------------------------------------------------------------- pets */

	/** "Part of the … event" line linking an event pet to its guide (GR-19). */
	private static function event_guide( Dataset $ds, array $rec ): string {
		$home = self::zone_of( $ds, (string) $rec['id'] );
		$path = null === $home ? '' : trim( (string) ( $home[0]['data']['guide_path'] ?? '' ) );
		if ( '' === $path ) {
			return '';
		}
		return '<p class="rbx-note">' . sprintf(
			/* translators: 1: pet, 2: guide link */
			esc_html__( 'The %1$s is part of a limited event. Times, eggs and the best pets are in our %2$s.', 'rbx-data' ),
			esc_html( (string) $rec['name'] ),
			'<a href="' . esc_url( home_url( $path ) ) . '">' . esc_html__( 'event guide', 'rbx-data' ) . '</a>'
		) . '</p>';
	}

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
		$bw     = $rec['data']['base_weight_kg'] ?? null;
		$answer = is_numeric( $bw ) && (float) $bw > 0
			? sprintf(
				/* translators: 1: pet, 2: game, 3: income per second, 4: income per hour, 5: base weight */
				esc_html__( 'A %1$s in %2$s earns %3$s Cash per second (%4$s per hour) at its base weight of %5$s kg with no mutation.', 'rbx-data' ),
				esc_html( $name ),
				esc_html( $ds->game()->name() ),
				Ui::number( $ds, $base, 'abbr' ),
				Ui::number( $ds, $base * 3600, 'abbr' ),
				Ui::number( $ds, (float) $bw, 'abbr' )
			)
			: sprintf(
				/* translators: 1: pet, 2: game, 3: income per second, 4: income per hour */
				esc_html__( 'A %1$s in %2$s earns %3$s Cash per second (%4$s per hour) at its base weight with no mutation.', 'rbx-data' ),
				esc_html( $name ),
				esc_html( $ds->game()->name() ),
				Ui::number( $ds, $base, 'abbr' ),
				Ui::number( $ds, $base * 3600, 'abbr' )
			);
		// UO-23: the answer with its unit comes first, then how mutations change it.
		$intro = $answer . ' ' . sprintf(
			/* translators: 1: pet, 2: best mutation name, 3: income */
			esc_html__( 'A mutation multiplies it, so a %2$s %1$s at the same weight earns %3$s per second, the most of any mutation in our list.', 'rbx-data' ),
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

	/**
	 * One sentence instead of a table (UO-69): the weight rule is the same for every pet, so the
	 * table repeated itself on every page. The calculator takes the player's own weight.
	 */
	private static function pet_weight( Dataset $ds, array $rec ): string {
		$bw   = $rec['data']['base_weight_kg'] ?? null;
		$base = self::base_income( $ds, $rec );
		if ( ! is_numeric( $bw ) || (float) $bw <= 0 || null === $base || $base <= 0 ) {
			return '';
		}
		$bw   = (float) $bw;
		$name = (string) $rec['name'];
		$two  = self::income( $ds, (string) $rec['id'], null, $bw * 2 );
		$ten  = self::income( $ds, (string) $rec['id'], null, $bw * 10 );
		if ( null === $two || null === $ten ) {
			return '';
		}
		$text = sprintf(
			/* translators: 1: pet, 2: base weight, 3: weight x2, 4: income at x2, 5: weight x10, 6: income at x10, 7: ratio at x10 */
			esc_html__( 'Yes, but not in step with the weight. A %1$s at its base weight of %2$s kg is the plain income above; at %3$s kg it earns %4$s per second, and at %5$s kg %6$s, about %7$s times the base, not ten.', 'rbx-data' ),
			esc_html( $name ),
			Ui::number( $ds, $bw, 'abbr' ),
			Ui::number( $ds, $bw * 2, 'abbr' ),
			Ui::number( $ds, $two, 'abbr' ),
			Ui::number( $ds, $bw * 10, 'abbr' ),
			Ui::number( $ds, $ten, 'abbr' ),
			esc_html( self::ratio( $ten / $base ) )
		);
		$chip = 'confirmed' !== $ds->mechanic_status( 'weight_scaling' ) ? ' <span class="rbx-chip rbx-chip--warning">' . esc_html__( 'Partly tested in game', 'rbx-data' ) . '</span>' : '';
		$calc = Item::calculator_link( $ds->game(), $rec );
		/* translators: %s: pet */
		return '<section class="rbx-weight">' . Ui::h2( sprintf( __( 'Does a heavier %s earn more?', 'rbx-data' ), $name ), 'weight' ) . '<p>' . $text . $chip . '</p>' . ( '' !== $calc ? '<p>' . $calc . '</p>' : '' ) . '</section>';
	}

	/**
	 * "Is it worth it?" (UO-69): where the pet sits among the pets of its own zone, the next pet up
	 * and down with the extra hatch time, and the Speed the zone and the next zone need. Built from
	 * the zone's biome eggs, so each page gets numbers no other page has.
	 */
	private static function pet_zone( Dataset $ds, array $rec ): string {
		$base = self::base_income( $ds, $rec );
		$home = self::zone_of( $ds, (string) $rec['id'] );
		if ( null === $base || $base <= 0 || null === $home ) {
			return '';
		}
		[ $bio, $egg ] = $home;
		$pets = self::zone_pets( $ds, (string) $bio['id'] );
		$ids  = array_keys( $pets );
		$pos  = array_search( (string) $rec['id'], $ids, true );
		if ( false === $pos || count( $pets ) < 2 ) {
			return '';
		}
		$name  = (string) $rec['name'];
		$facts = array();
		$facts[] = 0 === $pos
			/* translators: 1: pet, 2: zone, 3: count */
			? sprintf( esc_html__( 'The %1$s earns the most of the %3$s pets whose eggs spawn in %2$s.', 'rbx-data' ), esc_html( $name ), esc_html( (string) $bio['name'] ), esc_html( number_format_i18n( count( $pets ) ) ) )
			/* translators: 1: pet, 2: zone, 3: count, 4: ordinal */
			: sprintf( esc_html__( 'Of the %3$s pets whose eggs spawn in %2$s, the %1$s earns %4$s.', 'rbx-data' ), esc_html( $name ), esc_html( (string) $bio['name'] ), esc_html( number_format_i18n( count( $pets ) ) ), esc_html( count( $pets ) === $pos + 1 ? __( 'the least', 'rbx-data' ) : self::nth( $pos + 1, __( 'most', 'rbx-data' ) ) ) );
		$hatch = static fn( array $e ): ?float => is_numeric( $e['data']['hatch_time_s'] ?? null ) && (float) $e['data']['hatch_time_s'] > 0 ? (float) $e['data']['hatch_time_s'] : null;
		$mine  = $hatch( $egg );
		if ( $pos > 0 ) {
			$up      = $pets[ $ids[ $pos - 1 ] ];
			$uh      = $hatch( $up['egg'] );
			$line    = sprintf(
				/* translators: 1: stronger pet link, 2: ratio, 3: pet */
				esc_html__( 'The next step up in the same zone is %1$s, which earns about %2$s times as much as the %3$s', 'rbx-data' ),
				Ui::link( $up['record'] ),
				esc_html( self::ratio( $up['income'] / $base ) ),
				esc_html( $name )
			);
			if ( null !== $uh && null !== $mine ) {
				/* translators: 1: duration, 2: duration */
				$line .= sprintf( esc_html__( '; its egg takes %1$s to hatch instead of %2$s', 'rbx-data' ), Ui::raw( $ds, array( 'type' => 'duration_s' ), $uh ), Ui::raw( $ds, array( 'type' => 'duration_s' ), $mine ) );
			}
			$facts[] = $line . '.';
		}
		if ( $pos + 1 < count( $ids ) ) {
			$down    = $pets[ $ids[ $pos + 1 ] ];
			$facts[] = sprintf(
				/* translators: 1: pet, 2: ratio, 3: weaker pet link */
				esc_html__( 'Worth replacing? A %1$s earns about %2$s times as much as %3$s, the next pet down in this zone, so it is an upgrade over that pet and everything below it.', 'rbx-data' ),
				esc_html( $name ),
				esc_html( self::ratio( $base / max( $down['income'], 1e-9 ) ) ),
				Ui::link( $down['record'] )
			);
		}
		$speed = $bio['data']['unlock_speed_required'] ?? null;
		$next  = self::next_zone( $ds, $bio );
		if ( is_numeric( $speed ) ) {
			$line = (float) $speed > 0
				/* translators: 1: zone, 2: speed */
				? sprintf( esc_html__( 'To carry its egg home you first need %2$s Speed to reach %1$s', 'rbx-data' ), esc_html( (string) $bio['name'] ), Ui::number( $ds, $speed + 0, 'abbr' ) )
				/* translators: %s: zone */
				: sprintf( esc_html__( '%s is open from the start, so you can carry its egg home with no Speed upgrades', 'rbx-data' ), esc_html( (string) $bio['name'] ) );
			if ( $next ) {
				/* translators: 1: next zone, 2: speed */
				$line .= sprintf( esc_html__( '; the next zone, %1$s, needs %2$s Speed', 'rbx-data' ), esc_html( (string) $next['name'] ), Ui::number( $ds, $next['data']['unlock_speed_required'] + 0, 'abbr' ) );
			}
			$facts[] = $line . '.';
		}
		$rows = '';
		foreach ( $pets as $id => $p ) {
			$me    = $id === (string) $rec['id'];
			$h     = $hatch( $p['egg'] );
			$rows .= '<tr' . ( $me ? ' class="rbx-row--current" aria-current="true"' : '' ) . '><th scope="row">' . ( $me ? esc_html( (string) $p['record']['name'] ) : Ui::link( $p['record'] ) ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, $p['income'], 'abbr' ) . '</td><td class="rbx-num-cell">' . ( null !== $h ? Ui::raw( $ds, array( 'type' => 'duration_s' ), $h ) : '' ) . '</td><td class="rbx-num-cell">' . ( $me ? '' : Ui::number( $ds, round( $p['income'] / $base, 2 ), 'mult' ) ) . '</td></tr>';
		}
		$li = '';
		foreach ( $facts as $f ) {
			$li .= '<li>' . $f . '</li>';
		}
		/* translators: %s: zone */
		$cap   = sprintf( __( 'Pets whose eggs spawn in %s, by base income', 'rbx-data' ), (string) $bio['name'] );
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Pet', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Cash per second', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Hatch time', 'rbx-data' ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: pet */ __( 'Compared with %s', 'rbx-data' ), $name ) ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: pet */
		return '<section class="rbx-zone">' . Ui::h2( sprintf( __( 'Is the %s worth it?', 'rbx-data' ), $name ), 'worth-it' ) . '<ul>' . $li . '</ul>' . $table . self::source_note() . '</section>';
	}

	/**
	 * The zone a pet's egg spawns in: first biome-spawn egg with a live or limited biome.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null [biome, egg]
	 */
	private static function zone_of( Dataset $ds, string $pet ): ?array {
		foreach ( self::egg_index( $ds )['by_pet'][ $pet ] ?? array() as $egg_id ) {
			$egg = $ds->get( $egg_id );
			$how = (string) ( $egg['data']['obtain_method'] ?? '' );
			if ( ! $egg || ! in_array( $how, array( 'biome_spawn', 'event' ), true ) ) {
				continue;
			}
			foreach ( (array) ( $egg['data']['biome_ids'] ?? array() ) as $bid ) {
				$bio = $ds->get( (string) $bid );
				// Event eggs count only for an event zone (GR-06).
				if ( $bio && ( 'event' === $how ? 'limited' === $bio['status'] : in_array( $bio['status'], array( 'live', 'limited' ), true ) ) ) {
					return array( $bio, $egg );
				}
			}
		}
		return null;
	}

	/**
	 * Pets from a zone's biome-spawn eggs with a usable income, strongest first.
	 *
	 * @return array<string, array{record: array<string, mixed>, income: float, egg: array<string, mixed>}>
	 */
	public static function zone_pets( Dataset $ds, string $biome ): array {
		$ranked = self::ranked_pets( $ds, null );
		$out    = array();
		foreach ( $ranked as $id => $p ) {
			$z = self::zone_of( $ds, (string) $id );
			if ( null !== $z && (string) $z[0]['id'] === $biome ) {
				$out[ (string) $id ] = $p + array( 'egg' => $z[1] );
			}
		}
		return $out;
	}

	/** The zone after this one by order, with a Speed requirement, or null. */
	private static function next_zone( Dataset $ds, array $bio ): ?array {
		$best = null;
		foreach ( $ds->all( 'BIO', array( 'live' ) ) as $b ) {
			if ( (int) ( $b['data']['order_index'] ?? 0 ) > (int) ( $bio['data']['order_index'] ?? 0 ) && is_numeric( $b['data']['unlock_speed_required'] ?? null )
				&& ( null === $best || (int) $b['data']['order_index'] < (int) $best['data']['order_index'] ) ) {
				$best = $b;
			}
		}
		return $best;
	}

	/**
	 * Egg ↔ pet links from the drop table, built once per request.
	 *
	 * @return array{by_pet: array<string, array<int, string>>}
	 */
	private static function egg_index( Dataset $ds ): array {
		static $cache = array();
		$key = spl_object_id( $ds );
		if ( ! isset( $cache[ $key ] ) ) {
			$by_pet = array();
			foreach ( $ds->all( 'DRP' ) as $d ) {
				$by_pet[ (string) $d['data']['pet_id'] ][] = (string) $d['data']['egg_id'];
			}
			$cache[ $key ] = array( 'by_pet' => $by_pet );
		}
		return $cache[ $key ];
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
			// The row names the mutated pet ("Golden Chicken") so the table reads as this mutation's own.
			$rows .= '<tr><th scope="row">' . esc_html( $name ) . ' ' . Ui::link( $p['record'] ) . '</th><td class="rbx-num-cell">' . Ui::number( $ds, (float) $p['income'], 'abbr' ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td></tr>';
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
			$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html( sprintf( /* translators: %s: mutation */ __( 'Pet with %s', 'rbx-data' ), $name ) ) . '</th><th scope="col">' . esc_html__( 'Without it', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'With it', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		}
		return $out . self::source_note() . '</section>';
	}

	/**
	 * "What it is worth in plain pets" (UO-69): for the best pet of each zone, the mutated income
	 * and the plain pet that earns closest to it. The multiplier differs per mutation, so each
	 * mutation page gets its own pairs.
	 */
	private static function mutation_equivalents( Dataset $ds, array $rec ): string {
		$mult = $ds->field_state( $rec, 'income_mult' )['value'] ?? null;
		if ( ! is_numeric( $mult ) || (float) $mult <= 1 ) {
			return '';
		}
		$name  = preg_replace( '/\s+mutation$/i', '', (string) $rec['name'] ) ?? (string) $rec['name'];
		// Plain pets to match against: zone pets only, as the intro promises.
		$plain = array_filter( self::ranked_pets( $ds, null ), static fn( $p ) => null !== self::zone_of( $ds, (string) $p['record']['id'] ) );
		$rows  = '';
		$n     = 0;
		$first = '';
		foreach ( $ds->all( 'BIO', array( 'live' ) ) as $bio ) {
			$pets = self::zone_pets( $ds, (string) $bio['id'] );
			if ( ! $pets ) {
				continue;
			}
			$top = reset( $pets );
			$v   = self::income( $ds, (string) $top['record']['id'], (string) $rec['id'], null );
			if ( null === $v ) {
				continue;
			}
			$near = null;
			foreach ( $plain as $pid => $p ) {
				if ( $pid !== (string) $top['record']['id'] && ( null === $near || abs( log( max( $p['income'], 1e-9 ) / $v ) ) < abs( log( max( $near['income'], 1e-9 ) / $v ) ) ) ) {
					$near = $p;
				}
			}
			// Only real matches: within a quarter either way, else the row says nothing useful.
			if ( null === $near || abs( log( max( $near['income'], 1e-9 ) / $v ) ) > log( 1.25 ) ) {
				continue;
			}
			$rows .= '<tr><th scope="row">' . esc_html( (string) $bio['name'] ) . '</th><td>' . esc_html( $name ) . ' ' . Ui::link( $top['record'] ) . '</td><td class="rbx-num-cell">' . Ui::number( $ds, $v, 'abbr' ) . '</td><td>' . Ui::link( $near['record'] ) . ' (' . Ui::number( $ds, $near['income'], 'abbr' ) . ')</td></tr>';
			if ( '' === $first ) {
				$first = sprintf(
					/* translators: 1: mutation, 2: pet link, 3: income, 4: plain pet link */
					esc_html__( 'For example, a %1$s %2$s earns %3$s per second, about the same as a plain %4$s.', 'rbx-data' ),
					esc_html( $name ),
					Ui::link( $top['record'] ),
					Ui::number( $ds, $v, 'abbr' ),
					Ui::link( $near['record'] )
				);
			}
			++$n;
		}
		if ( $n < 2 ) {
			return '';
		}
		/* translators: %s: mutation */
		$intro = sprintf( esc_html__( 'A mutation can make an early pet worth as much as a plain pet from a later zone. The table takes the best pet of each zone, adds %s, and finds the plain pet that earns closest to it.', 'rbx-data' ), esc_html( $name ) ) . ' ' . $first;
		/* translators: %s: mutation */
		$cap   = sprintf( __( 'Best pet of each zone with %s, and the plain pet it matches', 'rbx-data' ), $name );
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Zone', 'rbx-data' ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: mutation */ __( 'Best pet of the zone with %s', 'rbx-data' ), $name ) ) . '</th><th scope="col">' . esc_html__( 'Cash per second', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Earns about as much as', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: mutation */
		return '<section class="rbx-equiv">' . Ui::h2( sprintf( __( 'What a %s pet is worth in plain pets', 'rbx-data' ), $name ), 'equivalents' ) . '<p>' . $intro . '</p>' . $table . self::source_note() . '</section>';
	}

	/* ---------------------------------------------------------------- crops (Grow a Garden games) */

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
				? sprintf( esc_html__( 'A plain %1$s at its base weight of %4$s kg sells for %2$s %3$s.', 'rbx-data' ), esc_html( $name ), Ui::number( $ds, $base, 'abbr' ), esc_html( $cur ), Ui::number( $ds, (float) $bw, 'decimal(' . self::decimals( (float) $bw ) . ')' ) )
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
		// UO-23: the answer with its unit comes first.
		$intro = sprintf(
			/* translators: 1: crop, 2: game, 3: value, 4: currency */
			esc_html__( 'A %1$s in %2$s sells for %3$s %4$s at its base weight with no mutation.', 'rbx-data' ),
			esc_html( $name ),
			esc_html( $game->name() ),
			Ui::number( $ds, $base, 'abbr' ),
			esc_html( $cur )
		) . ' ' . $intro;
		$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr>' . $head . '<th scope="col">' . esc_html__( 'Multiplier', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Sell value', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		/* translators: %s: crop */
		return '<section class="rbx-income">' . Ui::h2( sprintf( __( 'How much is a %s worth?', 'rbx-data' ), $name ), 'value' ) . '<p>' . $intro . '</p>' . $table . self::source_note() . '</section>';
	}

	/** Decimals needed to show a base weight exactly: 2 to 4 (0.4 → 2, 0.275 → 3). */
	private static function decimals( float $v ): int {
		for ( $dp = 2; $dp < 4; $dp++ ) {
			if ( abs( round( $v, $dp ) - $v ) < 1e-9 ) {
				return $dp;
			}
		}
		return 4;
	}

	/** One sentence instead of a table (UO-69); the calculator takes the player's own weight. */
	private static function plant_weight( Dataset $ds, array $rec ): string {
		$bw   = $rec['data']['base_weight_kg'] ?? null;
		$base = self::crop_value( $ds, (string) $rec['id'] );
		if ( ! is_numeric( $bw ) || (float) $bw <= 0 || null === $base || $base <= 0 ) {
			return '';
		}
		$bw   = (float) $bw;
		$name = (string) $rec['name'];
		// UO-28: print each weight with as many decimals as the base weight has (0.275 → 3) and
		// compute at that printed weight, so the numbers match the calculator at the same input.
		$dp  = self::decimals( $bw );
		$w2  = round( $bw * 2, $dp );
		$w5  = round( $bw * 5, $dp );
		$two = self::crop_value( $ds, (string) $rec['id'], array( 'weight' => $w2 ) );
		$fiv = self::crop_value( $ds, (string) $rec['id'], array( 'weight' => $w5 ) );
		if ( null === $two || null === $fiv ) {
			return '';
		}
		$cur  = self::currency( $ds, $rec );
		$text = sprintf(
			/* translators: 1: crop, 2: base weight, 3: weight x2, 4: value at x2, 5: ratio x2, 6: weight x5, 7: value at x5, 8: currency */
			esc_html__( 'Yes, and value climbs faster than weight. Around its base weight of %2$s kg a plain %1$s sells for the base value above; at %3$s kg it is worth %4$s %8$s (about %5$s times as much), and at %6$s kg %7$s %8$s.', 'rbx-data' ),
			esc_html( $name ),
			Ui::number( $ds, $bw, 'decimal(' . $dp . ')' ),
			Ui::number( $ds, $w2, 'decimal(' . $dp . ')' ),
			Ui::number( $ds, $two, 'abbr' ),
			esc_html( self::ratio( $two / $base ) ),
			Ui::number( $ds, $w5, 'decimal(' . $dp . ')' ),
			Ui::number( $ds, $fiv, 'abbr' ),
			esc_html( $cur )
		);
		$calc = Item::calculator_link( $ds->game(), $rec );
		/* translators: %s: crop */
		return '<section class="rbx-weight">' . Ui::h2( sprintf( __( 'Is a heavier %s worth more?', 'rbx-data' ), $name ), 'weight' ) . '<p>' . $text . '</p>' . ( '' !== $calc ? '<p>' . $calc . '</p>' : '' ) . '</section>';
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
