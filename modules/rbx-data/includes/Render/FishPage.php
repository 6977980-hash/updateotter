<?php
/**
 * Our own blocks on a fish page (Fisch Phase B2, 2026-11-23). Every number is worked out with the
 * game's live calculator formula from community wiki values (FishValue), so the page carries
 * things the wiki does not: value at the lightest, average and heaviest weight with each strong
 * mutation, the appraisal fee, a catch checklist and the most valuable fish at the same spot.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Data\Dataset;

defined( 'ABSPATH' ) || exit;

final class FishPage {

	/** Strong mutations shown in the value table. */
	public const MUTATIONS = 8;

	/** Fish listed under "Also at this spot". */
	public const SPOT = 5;

	public static function blocks( Dataset $ds, array $rec ): string {
		$game = $ds->game();
		if ( ! FishValue::supports( $game ) ) {
			return '';
		}
		return self::glance( $ds, $rec ) . self::checklist( $ds, $rec ) . self::value_table( $ds, $rec ) . self::spot( $ds, $rec );
	}

	/** Value at a weight (null = average weight) with an optional mutation or attribute. */
	private static function v( Dataset $ds, array $rec, ?float $kg = null, string $mut = '', string $atr = '', string $formula = FishValue::VALUE ): ?float {
		$in = array( 'fish' => (string) $rec['id'] );
		if ( null !== $kg ) {
			$in['weight'] = $kg;
		}
		if ( '' !== $mut ) {
			$in['mutation'] = $mut;
		}
		if ( '' !== $atr ) {
			$in['attribute'] = $atr;
		}
		return FishValue::value( $ds->game(), $in, $formula );
	}

	private static function kg( Dataset $ds, array $rec, string $f ): ?float {
		$x = $rec['data'][ $f ] ?? null;
		return is_numeric( $x ) && (float) $x > 0 ? (float) $x : null;
	}

	private static function weight( Dataset $ds, float $kg ): string {
		// Plain kg with up to 2 decimals ("0.6", "5,000.5", "74,550"): a weight reads better whole.
		return esc_html( rtrim( rtrim( number_format_i18n( $kg, 2 ), '0' ), '.,' ) );
	}

	/** Quick facts: value now, the heaviest one, rank in its rarity, appraisal fee, XP. */
	private static function glance( Dataset $ds, array $rec ): string {
		$name = (string) $rec['name'];
		$avg  = self::v( $ds, $rec );
		if ( null === $avg ) {
			return '';
		}
		$facts = array();
		$akg   = self::kg( $ds, $rec, 'weight_avg_kg' );
		$mkg   = self::kg( $ds, $rec, 'weight_max_kg' );
		$facts[] = sprintf(
			/* translators: 1: fish with a/an, 2: weight in kg, 3: value */
			esc_html__( '%1$s of average weight (%2$s kg) sells for %3$s C$ with no mutation.', 'rbx-data' ),
			esc_html( Insights::a( $name, true ) ),
			null === $akg ? '?' : self::weight( $ds, $akg ),
			Ui::number( $ds, $avg, 'abbr' )
		);
		if ( null !== $mkg && FishValue::weights_ok( $rec ) ) {
			$max = self::v( $ds, $rec, $mkg );
			if ( null !== $max ) {
				/* translators: 1: weight in kg, 2: value, 3: how many times the average */
				$facts[] = sprintf( esc_html__( 'The heaviest one the wiki lists (%1$s kg) sells for %2$s C$, about %3$s times an average one.', 'rbx-data' ), self::weight( $ds, $mkg ), Ui::number( $ds, $max, 'abbr' ), esc_html( number_format_i18n( $max / $avg, $max / $avg >= 10 ? 0 : 1 ) ) );
			}
		}
		$rid = (string) ( $rec['data']['rarity_id'] ?? '' );
		$rar = $ds->get( $rid );
		if ( $rar ) {
			$same = FishValue::valued( $ds->game(), $rid );
			$hi   = 1;
			$tied = 0;
			$in   = false;
			foreach ( $same as $r ) {
				if ( (string) $r['rec']['id'] === (string) $rec['id'] ) {
					$in = true;
				}
				if ( $r['avg'] > $avg * ( 1 + 1e-9 ) ) {
					++$hi;
				} elseif ( abs( $r['avg'] - $avg ) <= $avg * 1e-9 ) {
					++$tied;
				}
			}
			if ( $in && count( $same ) > 1 ) {
				$place = 1 === $hi ? ( $tied > 1 ? __( 'the joint most', 'rbx-data' ) : __( 'the most', 'rbx-data' ) )
					/* translators: %s: ordinal such as 3rd */
					: sprintf( __( 'the %s most', 'rbx-data' ), ( $tied > 1 ? __( 'joint', 'rbx-data' ) . ' ' : '' ) . self::ordinal( $hi ) );
				/* translators: 1: fish, 2: count, 3: rarity, 4: "the 3rd most" */
				$facts[] = sprintf( esc_html__( 'Of the %2$s %3$s fish with wiki numbers we can check, the %1$s is worth %4$s at average weight.', 'rbx-data' ), esc_html( $name ), esc_html( number_format_i18n( count( $same ) ) ), esc_html( (string) $rar['name'] ), esc_html( $place ) );
			}
		}
		$fee = self::v( $ds, $rec, null, '', '', FishValue::FEE );
		if ( null !== $fee ) {
			$odds = FishValue::odds( $ds );
			$tail = '';
			if ( null !== $odds && $odds['strong'] > 0 ) {
				/* translators: 1: one in N, 2: multiplier */
				$tail = ' ' . sprintf( esc_html__( 'About 1 in %1$s tries gives a mutation of ×%2$s or more.', 'rbx-data' ), esc_html( number_format_i18n( (int) round( 1 / $odds['strong'] ) ) ), esc_html( number_format_i18n( FishValue::STRONG ) ) );
			}
			/* translators: %s: fee */
			$facts[] = sprintf( esc_html__( 'Appraising an average one costs %s C$ per try (30%% of its value).', 'rbx-data' ), Ui::number( $ds, $fee, 'abbr' ) ) . $tail;
		}
		$xp = $rec['data']['base_xp'] ?? null;
		if ( is_numeric( $xp ) && (int) $xp > 0 ) {
			/* translators: %s: XP */
			$facts[] = sprintf( esc_html__( 'Each catch gives %s base XP.', 'rbx-data' ), Ui::number( $ds, (int) $xp, 'abbr' ) );
		}
		$li = '';
		foreach ( $facts as $f ) {
			$li .= '<li>' . $f . '</li>';
		}
		/* translators: %s: fish */
		return '<section class="rbx-glance">' . Ui::h2( sprintf( __( '%s at a glance', 'rbx-data' ), $name ), 'at-a-glance' ) . '<ul>' . $li . '</ul></section>';
	}

	/** "Any" = no requirement, empty = the wiki does not say, else the listed values. */
	private static function condition( mixed $v, string $any ): string {
		$list = array_values( array_filter( array_map( 'strval', (array) $v ), static fn( $x ) => '' !== trim( $x ) ) );
		if ( ! $list ) {
			return '<span class="rbx-v rbx-v--unknown">' . esc_html__( 'the community wiki does not say', 'rbx-data' ) . '</span>';
		}
		if ( in_array( 'Any', $list, true ) ) {
			return esc_html( $any );
		}
		return '<strong>' . esc_html( implode( ' ' . __( 'or', 'rbx-data' ) . ' ', $list ) ) . '</strong>';
	}

	/** Tick-off list of what to have before casting (checkboxes are for the reader only, not saved). */
	private static function checklist( Dataset $ds, array $rec ): string {
		$d     = $rec['data'];
		$items = array();
		$where = FishValue::where( $ds, $rec );
		$items[] = esc_html__( 'Go to', 'rbx-data' ) . ': <strong>' . $where . '</strong>';
		$rule = (string) ( $d['bait_rule'] ?? 'unknown' );
		if ( 'none' === $rule ) {
			$items[] = esc_html__( 'Bait: none needed, any bait works', 'rbx-data' );
		} elseif ( 'specific' === $rule ) {
			$names = array();
			foreach ( (array) ( $d['bait_ids'] ?? array() ) as $bid ) {
				$b = $ds->get( (string) $bid );
				if ( $b ) {
					$names[] = (string) $b['name'];
				}
			}
			$txt     = $names ? implode( ' ' . __( 'or', 'rbx-data' ) . ' ', $names ) : trim( (string) ( $d['bait_text__en'] ?? '' ) );
			$items[] = esc_html__( 'Bait', 'rbx-data' ) . ': ' . ( '' !== $txt && mb_strlen( $txt ) <= 60 ? '<strong>' . esc_html( $txt ) . '</strong>' : '<span class="rbx-v rbx-v--unknown">' . esc_html__( 'a preferred bait (see the community wiki)', 'rbx-data' ) . '</span>' );
		} else {
			$items[] = esc_html__( 'Bait', 'rbx-data' ) . ': <span class="rbx-v rbx-v--unknown">' . esc_html__( 'the community wiki does not say', 'rbx-data' ) . '</span>';
		}
		$items[] = esc_html__( 'Time of day', 'rbx-data' ) . ': ' . self::condition( $d['time_of_day'] ?? null, __( 'any time, day or night', 'rbx-data' ) );
		$items[] = esc_html__( 'Weather', 'rbx-data' ) . ': ' . self::condition( $d['weather'] ?? null, __( 'any weather', 'rbx-data' ) );
		$items[] = esc_html__( 'Season', 'rbx-data' ) . ': ' . self::condition( $d['season'] ?? null, __( 'any season', 'rbx-data' ) );
		$li = '';
		foreach ( $items as $i => $it ) {
			$id  = 'rbx-chk-' . $i;
			$li .= '<li><input type="checkbox" id="' . esc_attr( $id ) . '"> <label for="' . esc_attr( $id ) . '">' . $it . '</label></li>';
		}
		$more = '';
		$url  = Breadcrumbs::page_url( $ds->game()->folder() . '/what-can-i-catch' );
		if ( '' !== $url ) {
			/* translators: %s: link to the What can I catch tool */
			$more = '<p>' . sprintf( esc_html__( 'Already at a spot? Our %s lists every fish you can catch there right now, sorted by value.', 'rbx-data' ), '<a href="' . esc_url( $url ) . '">' . esc_html__( 'What can I catch tool', 'rbx-data' ) . '</a>' ) . '</p>';
		}
		/* translators: %s: fish */
		return '<section class="rbx-where">' . Ui::h2( sprintf( __( 'How to catch %s: checklist', 'rbx-data' ), Insights::a( (string) $rec['name'] ) ), 'how-to-catch' ) . '<ul class="rbx-checklist">' . $li . '</ul>' . $more . '</section>';
	}

	/** Value at the lightest, average and heaviest weight: plain, Shiny and with the strongest mutations. */
	private static function value_table( Dataset $ds, array $rec ): string {
		$cols = array();
		if ( FishValue::weights_ok( $rec ) ) {
			foreach ( array( 'weight_min_kg' => __( 'Lightest', 'rbx-data' ), 'weight_avg_kg' => __( 'Average', 'rbx-data' ), 'weight_max_kg' => __( 'Heaviest', 'rbx-data' ) ) as $f => $label ) {
				$kg = self::kg( $ds, $rec, $f );
				if ( null !== $kg ) {
					$cols[] = array( $label, $kg );
				}
			}
		}
		if ( ! $cols ) {
			$akg = self::kg( $ds, $rec, 'weight_avg_kg' );
			if ( null === $akg ) {
				return '';
			}
			$cols[] = array( __( 'Average', 'rbx-data' ), $akg );
		}
		$rows = array( array( 'label' => esc_html__( 'No mutation', 'rbx-data' ), 'mult' => 1.0, 'mut' => '', 'atr' => '' ) );
		foreach ( $ds->all( 'ATR', array( 'live' ) ) as $a ) {
			if ( is_numeric( $a['data']['value_mult'] ?? null ) ) {
				/* translators: %s: attribute such as Shiny */
				$rows[] = array( 'label' => esc_html( sprintf( __( '%s (no mutation)', 'rbx-data' ), (string) $a['name'] ) ), 'mult' => (float) $a['data']['value_mult'], 'mut' => '', 'atr' => (string) $a['id'] );
				break; // Shiny and Sparkling share one multiplier on the wiki; one row is enough.
			}
		}
		foreach ( array_slice( FishValue::mutations( $ds ), 0, self::MUTATIONS ) as $m ) {
			$rows[] = array( 'label' => Ui::link( $m ), 'mult' => (float) $m['data']['value_mult'], 'mut' => (string) $m['id'], 'atr' => '' );
			Sections::remember( (string) $m['id'] );
		}
		$head = '<th scope="col">' . esc_html__( 'Mutation', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Multiplier', 'rbx-data' ) . '</th>';
		foreach ( $cols as $c ) {
			/* translators: 1: Lightest/Average/Heaviest, 2: weight */
			$head .= '<th scope="col">' . sprintf( esc_html__( '%1$s (%2$s kg)', 'rbx-data' ), esc_html( $c[0] ), self::weight( $ds, $c[1] ) ) . '</th>';
		}
		$body = '';
		foreach ( $rows as $r ) {
			$cells = '';
			$any   = false;
			foreach ( $cols as $c ) {
				$v      = self::v( $ds, $rec, $c[1], $r['mut'], $r['atr'] );
				$any    = $any || null !== $v;
				$cells .= '<td data-label="' . esc_attr( $c[0] . ' (' . html_entity_decode( self::weight( $ds, $c[1] ) ) . ' kg)' ) . '" class="rbx-num-cell">' . ( null === $v ? Ui::dash() : Ui::number( $ds, $v, 'abbr' ) ) . '</td>';
			}
			if ( $any ) {
				$body .= '<tr><th scope="row">' . $r['label'] . '</th><td data-label="' . esc_attr__( 'Multiplier', 'rbx-data' ) . '" class="rbx-num-cell">' . Ui::number( $ds, $r['mult'], 'mult' ) . '</td>' . $cells . '</tr>';
			}
		}
		if ( '' === $body ) {
			return '';
		}
		$name = (string) $rec['name'];
		/* translators: %s: fish */
		$cap = sprintf( __( 'Sell value of one %s in C$, by weight and mutation', 'rbx-data' ), $name );
		$calc = FishValue::calc_url( $ds->game(), $rec );
		$note = esc_html__( 'Worked out by us: C$ per kg × weight × multiplier, with the per-kg value, weights and multipliers from the community wiki. Your own catch has its own weight, so the calculator gives its exact value.', 'rbx-data' );
		if ( '' !== $calc ) {
			/* translators: %s: fish */
			$note .= ' <a href="' . esc_url( $calc ) . '">' . esc_html( sprintf( __( 'Work out your own %s', 'rbx-data' ), $name ) ) . '</a>';
		}
		/* translators: %s: fish with a/an */
		return '<section class="rbx-income">' . Ui::h2( sprintf( __( 'How much is %s worth?', 'rbx-data' ), Insights::a( $name ) ), 'value' )
			. '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $cap ) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
			. '<p class="rbx-note">' . $note . '</p></section>';
	}

	/** The most valuable other fish at the same first spot, so one trip can catch more. */
	private static function spot( Dataset $ds, array $rec ): string {
		$locs = array_values( array_filter( array_map( 'strval', (array) ( $rec['data']['location_ids'] ?? array() ) ) ) );
		$loc  = $locs ? $ds->get( $locs[0] ) : null;
		if ( ! $loc ) {
			return '';
		}
		$rows = '';
		$n    = 0;
		foreach ( FishValue::valued( $ds->game(), '', true ) as $r ) {
			$f = $r['rec'];
			if ( (string) $f['id'] === (string) $rec['id'] || ! in_array( (string) $loc['id'], array_map( 'strval', (array) ( $f['data']['location_ids'] ?? array() ) ), true ) ) {
				continue;
			}
			$rar   = $ds->get( is_string( $f['data']['rarity_id'] ?? null ) ? $f['data']['rarity_id'] : null );
			$link  = FishValue::fish_link( $ds->game(), $f );
			$rows .= '<tr><th scope="row">' . $link . '</th><td data-label="' . esc_attr__( 'Rarity', 'rbx-data' ) . '">' . ( $rar ? esc_html( (string) $rar['name'] ) : Ui::dash() ) . '</td><td data-label="' . esc_attr__( 'At average weight', 'rbx-data' ) . '" class="rbx-num-cell">' . Ui::number( $ds, $r['avg'], 'abbr' ) . '</td></tr>';
			Sections::remember( (string) $f['id'] );
			if ( ++$n >= self::SPOT ) {
				break;
			}
		}
		if ( $n < 2 ) {
			return '';
		}
		/* translators: %s: location */
		$cap = sprintf( __( 'Most valuable other fish at %s (C$ at average weight, no mutation)', 'rbx-data' ), (string) $loc['name'] );
		/* translators: %s: location */
		return '<section class="rbx-compare">' . Ui::h2( sprintf( __( 'Also worth catching at %s', 'rbx-data' ), (string) $loc['name'] ), 'same-spot' )
			. '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Fish', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'Rarity', 'rbx-data' ) . '</th><th scope="col">' . esc_html__( 'At average weight', 'rbx-data' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
	}

	private static function ordinal( int $n ): string {
		$v = $n % 100;
		return $n . ( $v >= 11 && $v <= 13 ? 'th' : ( array( 1 => 'st', 2 => 'nd', 3 => 'rd' )[ $n % 10 ] ?? 'th' ) );
	}
}
