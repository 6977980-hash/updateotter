<?php
/**
 * Time-to-goal calculator (SAE): how long the player's income takes to reach a Cash goal, and how
 * much sooner they get there by swapping their weakest pet for an egg they can reach. The swap
 * counts the hatch time (the old pet keeps earning while the egg hatches) but not the trips to
 * find the egg, which the egg's spawn chance hints at. Eggs come from Advisor::data(), so both
 * tools always agree. The server renders the example; goal.js recomputes (twin of compute()).
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

use RBX\Data\Api;
use RBX\Data\Content\Lang;
use RBX\Data\Format\Number;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [rbx_goal game="sae" goal="1000000000" income="1000000" speed="2500000"].
 */
final class Goal {

	/** Rows in the swap table. */
	public const TOP = 8;

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a   = shortcode_atts(
			array(
				'game'   => '',
				'goal'   => '1000000000',
				'income' => '1000000',
				'speed'  => '2500000',
			),
			(array) $atts
		);
		$num = static fn( $v ): float => is_numeric( $v ) ? max( 0.0, (float) $v ) : 0.0;
		return self::render( (string) $a['game'], $num( $a['goal'] ), $num( $a['income'] ), $num( $a['speed'] ) );
	}

	public static function render( string $game_key, float $goal, float $income, float $speed ): string {
		$game = Api::game( $game_key );
		if ( ! $game || null === $game->field( 'BIO', 'unlock_speed_required' ) || null === $game->field( 'EGG', 'spawn_chance_prob' ) || null === $game->field( 'PET', 'base_income_per_s' ) ) {
			return '';
		}
		$ds   = Api::dataset( $game );
		$data = Advisor::data( $ds );
		if ( ! $data['zones'] || ! $data['eggs'] ) {
			return '';
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$ladder = $ds->meta( 'suffix_ladder' );
		$ladder = is_array( $ladder ) ? $ladder : null;
		$loc    = Lang::num();
		$id     = 'rbx-goal-' . wp_unique_id();
		$t      = self::strings();
		$r      = self::compute( $data, 0.0, $goal, $income, $speed, null, false );
		$x      = self::texts( $r, $t, $loc, $ladder );

		$field = static function ( string $key, string $label, string $hint, string $value ) use ( $id ): string {
			$fid = $id . '-' . $key;
			return '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label>'
				. ( '' !== $hint ? '<p class="rbx-advisor__hint" id="' . esc_attr( $fid ) . '-h">' . esc_html( $hint ) . '</p>' : '' )
				. '<span class="rbx-calc__num"><input type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="24" enterkeyhint="done" data-rbx-goal="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( ( '' !== $hint ? $fid . '-h ' : '' ) . $fid . '-e' ) . '"></span>'
				. '<p class="rbx-calc__err" id="' . esc_attr( $fid ) . '-e" hidden></p></div>';
		};
		$abbr = static fn( float $v ): string => Number::format( $v, 'abbr', $loc, $ladder );

		$out  = '<section class="rbx-calc rbx-advisor rbx-goal" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Time to goal calculator', 'rbx-tools' ) . '" data-rbx-goal-tool>';
		$out .= '<form class="rbx-calc__form" novalidate>';
		$out .= $field( 'goal', __( 'Your Cash goal', 'rbx-tools' ), '', $abbr( $goal ) );
		$out .= $field( 'cash', __( 'Cash you have now (optional)', 'rbx-tools' ), '', '' );
		$out .= $field( 'income', __( 'Your total income per second', 'rbx-tools' ), __( 'All your pets together, as the game shows it.', 'rbx-tools' ), $abbr( $income ) );
		$out .= $field( 'speed', __( 'Your Speed (for the swap table)', 'rbx-tools' ), '', $abbr( $speed ) );
		$out .= $field( 'weakest', __( 'Income of your weakest pet, per second (optional)', 'rbx-tools' ), __( 'Leave it empty if you have a free pen slot: the new pet is then added, not swapped.', 'rbx-tools' ), '' );
		$out .= '<div class="rbx-calc__field rbx-calc__field--check"><input type="checkbox" id="' . esc_attr( $id ) . '-pass" name="pass" value="1" data-rbx-goal="pass"> <label for="' . esc_attr( $id ) . '-pass">' . esc_html__( 'I have the X2 Growth Speed pass (eggs hatch twice as fast)', 'rbx-tools' ) . '</label></div>';
		$out .= '</form>';
		$out .= '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to change the numbers; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>';
		$out .= '<div class="rbx-calc__result"><dl class="rbx-calc__outs">'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Time to your goal now', 'rbx-tools' ) . '</dt><dd data-rbx-out="now">' . esc_html( $x['now'] ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Fastest with one new pet', 'rbx-tools' ) . '</dt><dd data-rbx-out="best">' . esc_html( $x['best'] ) . '</dd></div>'
			. '</dl></div>';
		$out .= '<h3>' . esc_html__( 'How much sooner each egg gets you there', 'rbx-tools' ) . '</h3>';
		$out .= '<p class="rbx-advisor__empty" data-rbx-empty' . ( $x['rows'] ? ' hidden' : '' ) . '>' . esc_html( $x['empty'] ) . '</p>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><thead><tr>'
			. '<th scope="col">' . esc_html( $t['col_egg'] ) . '</th>'
			. '<th scope="col">' . esc_html( $t['col_income'] ) . '</th>'
			. '<th scope="col">' . esc_html( $t['col_odds'] ) . '</th>'
			. '<th scope="col">' . esc_html( $t['col_hatch'] ) . '</th>'
			. '<th scope="col">' . esc_html( $t['col_time'] ) . '</th>'
			. '<th scope="col">' . esc_html( $t['col_saved'] ) . '</th>'
			. '</tr></thead><tbody data-rbx-rows>' . self::rows_html( $x['rows'], $t ) . '</tbody></table></div>';
		$out .= '<p class="rbx-calc__meta">' . esc_html__( 'Speed needed, spawn chances, hatch times and incomes come from the community wiki; we have not tested them in the game ourselves. New pets count at base income, before mutation and weight. The time to find an egg is not included: a "1 in 50" egg can take many trips.', 'rbx-tools' ) . '</p>';
		$out .= '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		$config = array(
			'locale' => $loc,
			'ladder' => $ladder,
			'zones'  => $data['zones'],
			'eggs'   => $data['eggs'],
			'top'    => self::TOP,
			'common' => Advisor::COMMON,
			'i18n'   => $t,
		);
		$out .= '<script type="application/json" class="rbx-goal-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-goal' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * Seconds to reach `need` Cash at `income`, when the income becomes `next` after `wait` seconds
	 * (the hatch). Null when it never gets there. Twin of after() in goal.js.
	 */
	public static function after( float $need, float $income, float $wait, float $next ): ?float {
		if ( $need <= 0 ) {
			return 0.0;
		}
		if ( $income > 0 && $income * $wait >= $need ) {
			return $need / $income;
		}
		if ( $next <= 0 ) {
			return null;
		}
		return $wait + ( $need - max( 0.0, $income ) * $wait ) / $next;
	}

	/**
	 * Twin of compute() in goal.js. `eggs` in Advisor::order() order.
	 *
	 * @param array<string, mixed> $data Advisor::data().
	 * @return array<string, mixed>
	 */
	public static function compute( array $data, float $cash, float $goal, float $income, float $speed, ?float $weakest, bool $pass ): array {
		$need = max( 0.0, $goal - $cash );
		$now  = $need <= 0 ? 0.0 : ( $income > 0 ? $need / $income : null );
		$rows = array();
		foreach ( $data['eggs'] as $e ) {
			if ( $data['zones'][ $e['zone'] ]['speed'] > $speed || ( null !== $weakest && $e['income'] <= $weakest ) ) {
				continue;
			}
			$hatch = null === $e['hatch'] ? 0.0 : ( $pass ? $e['hatch'] / 2 : $e['hatch'] );
			$next  = $income - ( $weakest ?? 0.0 ) + $e['income'];
			$time  = self::after( $need, $income, $hatch, $next );
			if ( null === $time ) {
				continue;
			}
			$rows[] = array(
				'egg'    => $e['egg'],
				'pet'    => $e['pet'],
				'url'    => $e['url'],
				'zone'   => $data['zones'][ $e['zone'] ]['name'],
				'income' => $e['income'],
				'chance' => $e['chance'],
				'hatch'  => null === $e['hatch'] ? null : $hatch,
				'time'   => $time,
				'saved'  => null === $now ? null : $now - $time,
			);
		}
		// Soonest first; then the egg that spawns more often, then the name.
		usort( $rows, static fn( $a, $b ) => ( $a['time'] <=> $b['time'] ) ?: ( ( $b['chance'] ?? 0.0 ) <=> ( $a['chance'] ?? 0.0 ) ) ?: strcmp( $a['egg'], $b['egg'] ) );
		$best = null;
		foreach ( $rows as $row ) {
			if ( null !== $row['chance'] && $row['chance'] >= Advisor::COMMON && ( null === $now || $row['time'] < $now ) ) {
				$best = $row;
				break;
			}
		}
		return array(
			'need' => $need,
			'now'  => $now,
			'best' => $best,
			'rows' => array_slice( $rows, 0, self::TOP ),
		);
	}

	/** Readable span: "45s", "12m 05s", "3h 20m", "5d 4h", "about 3 years". Twin of span() in goal.js. */
	public static function span( float $s, array $t, string $loc, ?array $ladder ): string {
		$s = max( 0.0, $s );
		if ( $s >= 365 * 86400 ) {
			$y = $s / ( 365 * 86400 );
			return self::fill( $t['years'], $y >= 1000 ? Number::format( $y, 'abbr', $loc, $ladder ) : Number::format( round( $y ), 'int', $loc ) );
		}
		$n = (int) floor( $s + 0.5 );
		if ( $n < 60 ) {
			return $n . 's';
		}
		if ( $n < 3600 ) {
			return intdiv( $n, 60 ) . 'm ' . str_pad( (string) ( $n % 60 ), 2, '0', STR_PAD_LEFT ) . 's';
		}
		if ( $n < 86400 ) {
			return intdiv( $n, 3600 ) . 'h ' . str_pad( (string) intdiv( $n % 3600, 60 ), 2, '0', STR_PAD_LEFT ) . 'm';
		}
		return intdiv( $n, 86400 ) . 'd ' . intdiv( $n % 86400, 3600 ) . 'h';
	}

	/**
	 * Display strings for compute(). Twin of texts() in goal.js.
	 *
	 * @param array<string, mixed> $r compute() result.
	 * @return array{now: string, best: string, empty: string, rows: array<int, array<string, string>>}
	 */
	public static function texts( array $r, array $t, string $loc, ?array $ladder ): array {
		$span = static fn( float $s ): string => self::span( $s, $t, $loc, $ladder );
		$fmt  = static fn( float $v ): string => Number::format( $v, 'abbr', $loc, $ladder );
		if ( $r['need'] <= 0 ) {
			$now = $t['done'];
		} elseif ( null === $r['now'] ) {
			$now = $t['no_income'];
		} else {
			$now = $span( $r['now'] );
		}
		$best = $t['no_best'];
		if ( $r['need'] <= 0 ) {
			$best = $t['done'];
		} elseif ( null !== $r['best'] ) {
			$b    = $r['best'];
			$best = null === $b['saved']
				? self::fill( $t['best_plain'], $b['egg'], $b['zone'], $span( $b['time'] ) )
				: self::fill( $t['best'], $b['egg'], $b['zone'], $span( $b['time'] ), $span( $b['saved'] ) );
		}
		$rows = array();
		foreach ( $r['rows'] as $e ) {
			$rows[] = array(
				'egg'   => $e['egg'],
				'pet'   => $e['pet'],
				'url'   => $e['url'],
				'zone'  => $e['zone'],
				'income' => self::fill( $t['per_s'], $fmt( $e['income'] ) ),
				'odds'  => null === $e['chance'] ? $t['unknown'] : self::fill( $t['one_in'], Number::format( max( 1.0, floor( 1 / $e['chance'] + 0.5 ) ), 'int', $loc ) ),
				'hatch' => null === $e['hatch'] ? $t['unknown'] : $span( $e['hatch'] ),
				'time'  => $span( $e['time'] ),
				'saved' => null === $e['saved'] ? '—' : ( $e['saved'] > 0 ? $span( $e['saved'] ) : $t['not_sooner'] ),
			);
		}
		return array(
			'now'   => $now,
			'best'  => $best,
			'empty' => $r['need'] <= 0 ? $t['done'] : $t['none'],
			'rows'  => $r['need'] <= 0 ? array() : $rows,
		);
	}

	/** @param array<int, array<string, string>> $rows texts() rows. */
	private static function rows_html( array $rows, array $t ): string {
		$out = '';
		foreach ( $rows as $e ) {
			$pet  = '' !== $e['url'] ? '<a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['pet'] ) . '</a>' : esc_html( $e['pet'] );
			$out .= '<tr><th scope="row" class="rbx-cell-name">' . esc_html( $e['egg'] ) . '<span class="rbx-advisor__pet">' . esc_html( $t['hatches'] ) . ' ' . $pet . ' · ' . esc_html( $e['zone'] ) . '</span></th>'
				. '<td data-label="' . esc_attr( $t['col_income'] ) . '">' . esc_html( $e['income'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_odds'] ) . '">' . esc_html( $e['odds'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_hatch'] ) . '">' . esc_html( $e['hatch'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_time'] ) . '">' . esc_html( $e['time'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_saved'] ) . '">' . esc_html( $e['saved'] ) . '</td></tr>';
		}
		return $out;
	}

	private static function fill( string $s, string ...$args ): string {
		if ( 1 === count( $args ) ) {
			$s = str_replace( '%s', $args[0], $s );
		}
		foreach ( $args as $i => $a ) {
			$s = str_replace( '%' . ( $i + 1 ) . '$s', $a, $s );
		}
		return $s;
	}

	/** @return array<string, string> */
	public static function strings(): array {
		return array(
			'done'        => __( 'You already have enough Cash for this goal.', 'rbx-tools' ),
			'no_income'   => __( 'Type your income per second to see the time.', 'rbx-tools' ),
			/* translators: 1: egg, 2: biome, 3: time, 4: time saved */
			'best'        => __( '%1$s in %2$s: you reach the goal in %3$s, %4$s sooner, counting its hatch time.', 'rbx-tools' ),
			/* translators: 1: egg, 2: biome, 3: time */
			'best_plain'  => __( '%1$s in %2$s: you reach the goal in %3$s, counting its hatch time.', 'rbx-tools' ),
			'no_best'     => __( 'No egg you can reach that spawns often enough (about 1 in 25 or more) gets you there sooner.', 'rbx-tools' ),
			'none'        => __( 'No egg you can reach earns more than your weakest pet. More Speed opens better eggs.', 'rbx-tools' ),
			'not_sooner'  => __( 'Not sooner', 'rbx-tools' ),
			/* translators: %s: number of years */
			'years'       => __( 'about %s years', 'rbx-tools' ),
			'hatches'     => __( 'Hatches:', 'rbx-tools' ),
			/* translators: %s: income */
			'per_s'       => __( '%s/s', 'rbx-tools' ),
			/* translators: %s: number of eggs, as in "1 in 25" */
			'one_in'      => __( '1 in %s', 'rbx-tools' ),
			'unknown'     => __( 'Unknown', 'rbx-tools' ),
			'col_egg'     => __( 'Egg', 'rbx-tools' ),
			'col_income'  => __( 'Base income', 'rbx-tools' ),
			'col_odds'    => __( 'How often it spawns', 'rbx-tools' ),
			'col_hatch'   => __( 'Hatch time', 'rbx-tools' ),
			'col_time'    => __( 'Time to goal with it', 'rbx-tools' ),
			'col_saved'   => __( 'Sooner by', 'rbx-tools' ),
			'empty'       => __( 'Enter a number.', 'rbx-tools' ),
			'invalid'     => __( 'Use digits, like 1500 or 1.5K.', 'rbx-tools' ),
			'negative'    => __( 'The number cannot be negative.', 'rbx-tools' ),
			'too_big'     => __( 'That number is too big.', 'rbx-tools' ),
			'ambiguous'   => __( 'Use a dot for decimals, like 1.5.', 'rbx-tools' ),
		);
	}
}
