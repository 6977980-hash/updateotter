<?php
/**
 * "What to get next" advisor (SAE). Eggs in Steal an Egg are stolen, not bought, so the next step
 * is always "which egg should I go for": from the player's Speed it finds the eggs they can reach,
 * names the best one that spawns often enough to hunt every trip, the rare jackpot to grab when it
 * shows up, how much a swap adds over the player's weakest pet (and to their total income), and
 * what the next biome would add. Everything comes from the dataset: biome `unlock_speed_required`,
 * egg `spawn_chance_prob`, `hatch_time_s`, the egg's drop pet and its `base_income_per_s`.
 * The server renders the example; advisor.js recomputes as the player types (twin of compute()).
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

use RBX\Data\Api;
use RBX\Data\Content\Lang;
use RBX\Data\Data\Dataset;
use RBX\Data\Format\Number;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [rbx_advisor game="sae" speed="10000"].
 */
final class Advisor {

	/** Rows in the upgrade table. */
	public const TOP = 10;

	/**
	 * An egg counts as "common enough to hunt" from this spawn chance (about 1 in 25 eggs). It is the
	 * same line our "best egg in every biome" guide draws between the egg to hunt and the jackpot.
	 */
	public const COMMON = 0.04;

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'game'  => '',
				'speed' => '10000',
			),
			(array) $atts
		);
		return self::render( (string) $a['game'], is_numeric( $a['speed'] ) ? max( 0.0, (float) $a['speed'] ) : 0.0 );
	}

	public static function render( string $game_key, float $speed ): string {
		$game = Api::game( $game_key );
		if ( ! $game || null === $game->field( 'BIO', 'unlock_speed_required' ) || null === $game->field( 'EGG', 'spawn_chance_prob' ) || null === $game->field( 'PET', 'base_income_per_s' ) ) {
			return '';
		}
		$ds   = Api::dataset( $game );
		$data = self::data( $ds );
		if ( ! $data['zones'] || ! $data['eggs'] ) {
			return '';
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$ladder = $ds->meta( 'suffix_ladder' );
		$ladder = is_array( $ladder ) ? $ladder : null;
		$loc    = Lang::num();
		$id     = 'rbx-adv-' . wp_unique_id();
		$r      = self::compute( $data, $speed, null, null, false );
		$t      = self::strings();
		$fmt    = static fn( float $v ): string => Number::format( $v, 'abbr', $loc, $ladder );

		$config = array(
			'locale' => $loc,
			'ladder' => $ladder,
			'zones'  => $data['zones'],
			'eggs'   => $data['eggs'],
			'top'    => self::TOP,
			'common' => self::COMMON,
			'i18n'   => $t,
		);

		$field = static function ( string $key, string $label, string $hint, string $value ) use ( $id ): string {
			$fid = $id . '-' . $key;
			return '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label>'
				. ( '' !== $hint ? '<p class="rbx-advisor__hint" id="' . esc_attr( $fid ) . '-h">' . esc_html( $hint ) . '</p>' : '' )
				. '<span class="rbx-calc__num"><input type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="24" enterkeyhint="done" data-rbx-adv="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( ( '' !== $hint ? $fid . '-h ' : '' ) . $fid . '-e' ) . '"></span>'
				. '<p class="rbx-calc__err" id="' . esc_attr( $fid ) . '-e" hidden></p></div>';
		};

		$out  = '<section class="rbx-calc rbx-advisor" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'What to get next advisor', 'rbx-tools' ) . '" data-rbx-advisor>';
		$out .= '<form class="rbx-calc__form" novalidate>';
		$out .= $field( 'speed', __( 'Your Speed', 'rbx-tools' ), '', Number::format( $speed, 'int', $loc ) );
		$out .= $field( 'weakest', __( 'Income of your weakest pet, per second (optional)', 'rbx-tools' ), __( 'The Cash per second the game shows for your lowest earner, mutation and weight included.', 'rbx-tools' ), '' );
		$out .= $field( 'total', __( 'Your total income per second (optional)', 'rbx-tools' ), __( 'All your pets together, as the game shows it.', 'rbx-tools' ), '' );
		$out .= '<div class="rbx-calc__field rbx-calc__field--check"><input type="checkbox" id="' . esc_attr( $id ) . '-pass" name="pass" value="1" data-rbx-adv="pass"> <label for="' . esc_attr( $id ) . '-pass">' . esc_html__( 'I have the X2 Growth Speed pass (eggs hatch twice as fast)', 'rbx-tools' ) . '</label></div>';
		$out .= '</form>';
		$out .= '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to change the numbers; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>';
		$out .= '<div class="rbx-calc__result"><dl class="rbx-calc__outs">'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Best egg to hunt now', 'rbx-tools' ) . '</dt><dd data-rbx-out="hunt">' . esc_html( self::hunt_text( $r, $t, $fmt ) ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Jackpot to grab if you see it', 'rbx-tools' ) . '</dt><dd data-rbx-out="jackpot">' . esc_html( self::jackpot_text( $r, $t, $fmt ) ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'What a swap adds', 'rbx-tools' ) . '</dt><dd data-rbx-out="gain">' . esc_html( self::gain_text( $r, $t, $fmt ) ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Next biome', 'rbx-tools' ) . '</dt><dd data-rbx-out="next">' . esc_html( self::next_text( $r, $t, $fmt ) ) . '</dd></div>'
			. '</dl></div>';
		$out .= '<h3>' . esc_html__( 'Upgrades you can reach, best first', 'rbx-tools' ) . '</h3>';
		$out .= '<p class="rbx-advisor__empty" data-rbx-empty' . ( $r['rows'] ? ' hidden' : '' ) . '>' . esc_html( null === $r['next'] ? $t['none_all'] : $t['none'] ) . '</p>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table rbx-table--list"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Egg', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Biome', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Base income', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'How often it spawns', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Hatch time', 'rbx-tools' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'More than your weakest pet', 'rbx-tools' ) . '</th>'
			. '</tr></thead><tbody data-rbx-rows>' . self::rows_html( $r, $t, $fmt, $loc ) . '</tbody></table></div>';
		$out .= '<p class="rbx-calc__meta">' . esc_html( $data['community'] ? __( 'Speed needed, spawn chances, hatch times and incomes come from the community wiki. We have not tested them in the game ourselves. Incomes are base incomes, before mutation and weight.', 'rbx-tools' ) : __( 'Incomes are base incomes, before mutation and weight. Each value shows its source on its egg and pet page.', 'rbx-tools' ) ) . '</p>';
		$out .= '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		$out .= '<script type="application/json" class="rbx-advisor-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-advisor' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * Live biomes with a Speed requirement (in Speed order) and the live biome eggs that hatch one
	 * live pet with a known base income. Event zones have no Speed requirement and drop out.
	 *
	 * @return array{zones: array<int, array{name: string, speed: float}>, eggs: array<int, array<string, mixed>>, community: bool}
	 */
	public static function data( Dataset $ds ): array {
		$zones = array();
		foreach ( $ds->all( 'BIO', array( 'live' ) ) as $bid => $b ) {
			$s = $b['data']['unlock_speed_required'] ?? null;
			if ( is_numeric( $s ) ) {
				$zones[] = array(
					'id'    => (string) $bid,
					'name'  => (string) $b['name'],
					'speed' => (float) $s,
				);
			}
		}
		usort( $zones, static fn( $a, $b ) => $a['speed'] <=> $b['speed'] );
		$index = array();
		foreach ( $zones as $i => $z ) {
			$index[ $z['id'] ] = $i;
		}
		$community = false;
		$eggs      = array();
		foreach ( $ds->all( 'EGG', array( 'live' ) ) as $eid => $e ) {
			if ( 'biome_spawn' !== ( $e['data']['obtain_method'] ?? '' ) ) {
				continue;
			}
			$zone = null;
			$bids = $e['data']['biome_ids'] ?? array();
			foreach ( is_array( $bids ) ? $bids : explode( '|', (string) $bids ) as $bid ) {
				if ( isset( $index[ (string) $bid ] ) ) {
					$zone = $index[ (string) $bid ];
					break;
				}
			}
			if ( null === $zone ) {
				continue;
			}
			// One live pet per egg: an egg with several possible pets has no single income to rank.
			$pets = array();
			foreach ( $ds->children( 'DRP', 'egg_id', (string) $eid ) as $d ) {
				$p = $ds->get( (string) ( $d['data']['pet_id'] ?? '' ) );
				if ( $p && 'live' === $p['status'] ) {
					$pets[ $p['id'] ?? (string) $d['data']['pet_id'] ] = $p;
				}
			}
			if ( 1 !== count( $pets ) ) {
				continue;
			}
			$pet    = reset( $pets );
			$income = $pet['data']['base_income_per_s'] ?? null;
			if ( ! is_numeric( $income ) || (float) $income <= 0 ) {
				continue;
			}
			$chance = $e['data']['spawn_chance_prob'] ?? null;
			$hatch  = $e['data']['hatch_time_s'] ?? null;
			$rar    = $ds->get( is_string( $pet['data']['rarity_id'] ?? null ) ? $pet['data']['rarity_id'] : null );
			if ( 'in_game' !== ( $pet['source_type'] ?? $pet['data']['source_type'] ?? '' ) || 'in_game' !== ( $e['source_type'] ?? $e['data']['source_type'] ?? '' ) ) {
				$community = true;
			}
			$eggs[] = array(
				'egg'    => (string) $e['name'],
				'pet'    => (string) $pet['name'],
				'url'    => self::url( $pet ),
				'rarity' => $rar ? (string) $rar['name'] : '',
				'zone'   => $zone,
				'income' => (float) $income,
				'chance' => is_numeric( $chance ) && (float) $chance > 0 ? (float) $chance : null,
				'hatch'  => is_numeric( $hatch ) && (float) $hatch > 0 ? (float) $hatch : null,
			);
		}
		// Best first: income, then the egg that spawns more often, then name (stable on both sides).
		usort( $eggs, array( self::class, 'order' ) );
		return array(
			'zones'     => array_map( static fn( $z ) => array( 'name' => $z['name'], 'speed' => $z['speed'] ), $zones ),
			'eggs'      => $eggs,
			'community' => $community,
		);
	}

	/**
	 * Sort order of eggs. Twin of order() in advisor.js.
	 *
	 * @param array<string, mixed> $a Egg.
	 * @param array<string, mixed> $b Egg.
	 */
	public static function order( array $a, array $b ): int {
		return ( $b['income'] <=> $a['income'] ) ?: ( ( $b['chance'] ?? 0.0 ) <=> ( $a['chance'] ?? 0.0 ) ) ?: strcmp( $a['egg'], $b['egg'] );
	}

	/** Link only to a pet page that is in search, so the tool sends visitors and crawlers to good pages. */
	private static function url( array $pet ): string {
		$pid = (int) ( $pet['post_id'] ?? 0 );
		if ( $pid && 'publish' === get_post_status( $pid ) && \RBX\Data\Render\Ui::indexable( $pid ) ) {
			return (string) get_permalink( $pid );
		}
		return '';
	}

	/**
	 * Twin of compute() in advisor.js. `eggs` must be in order() order.
	 *
	 * @param array<string, mixed> $data    data() result (zones, eggs).
	 * @param float                $speed   Player's Speed.
	 * @param float|null           $weakest Income per second of the player's weakest pet.
	 * @param float|null           $total   Player's total income per second.
	 * @param bool                 $pass    X2 Growth Speed pass (halves hatch times).
	 * @return array<string, mixed>
	 */
	public static function compute( array $data, float $speed, ?float $weakest, ?float $total, bool $pass ): array {
		$zones = $data['zones'];
		$far   = null;
		$next  = null;
		foreach ( $zones as $i => $z ) {
			if ( $z['speed'] <= $speed ) {
				$far = $i;
			} elseif ( null === $next ) {
				$next = $i;
			}
		}
		$hunt    = null;
		$jackpot = null;
		$nhunt   = null;
		$rows    = array();
		$beats   = 0;
		foreach ( $data['eggs'] as $e ) {
			$reach  = $zones[ $e['zone'] ]['speed'] <= $speed;
			$common = null !== $e['chance'] && $e['chance'] >= self::COMMON;
			if ( ! $reach ) {
				if ( null === $nhunt && $common && $e['zone'] === $next ) {
					$nhunt = $e;
				}
				continue;
			}
			if ( null === $jackpot ) {
				$jackpot = $e;
			}
			if ( null === $hunt && $common ) {
				$hunt = $e;
			}
			if ( null !== $weakest && $e['income'] <= $weakest ) {
				continue;
			}
			++$beats;
			if ( count( $rows ) < self::TOP ) {
				$rows[] = self::row( $e, $zones, $weakest, $pass );
			}
		}
		// The jackpot is only worth naming when it earns more than the egg to hunt.
		if ( null !== $jackpot && null !== $hunt && $jackpot['income'] <= $hunt['income'] ) {
			$jackpot = null;
		}
		$gain = null;
		if ( null !== $hunt && null !== $weakest ) {
			$g    = $hunt['income'] - $weakest;
			$gain = array(
				'gain'  => $g,
				'times' => $weakest > 0 ? $hunt['income'] / $weakest : null,
				'share' => null !== $total && $total > 0 && $g > 0 ? $g / $total : null,
			);
		}
		return array(
			'far'     => null === $far ? '' : $zones[ $far ]['name'],
			'hunt'    => null === $hunt ? null : self::row( $hunt, $zones, $weakest, $pass ),
			'jackpot' => null === $jackpot ? null : self::row( $jackpot, $zones, $weakest, $pass ),
			'gain'    => $gain,
			'weakest' => $weakest,
			'next'    => null === $next ? null : array(
				'name'  => $zones[ $next ]['name'],
				'speed' => $zones[ $next ]['speed'],
				'gap'   => $zones[ $next ]['speed'] - $speed,
				'hunt'  => null === $nhunt ? null : self::row( $nhunt, $zones, $weakest, $pass ),
				'times' => null !== $nhunt && null !== $hunt ? $nhunt['income'] / $hunt['income'] : null,
			),
			'beats'   => $beats,
			'rows'    => $rows,
		);
	}

	/**
	 * @param array<string, mixed>             $e     Egg.
	 * @param array<int, array<string, mixed>> $zones Zones.
	 * @return array<string, mixed>
	 */
	private static function row( array $e, array $zones, ?float $weakest, bool $pass ): array {
		return array(
			'egg'    => $e['egg'],
			'pet'    => $e['pet'],
			'url'    => $e['url'],
			'rarity' => $e['rarity'],
			'zone'   => $zones[ $e['zone'] ]['name'],
			'income' => $e['income'],
			'chance' => $e['chance'],
			'hatch'  => null === $e['hatch'] ? null : ( $pass ? $e['hatch'] / 2 : $e['hatch'] ),
			'gain'   => null === $weakest ? null : $e['income'] - $weakest,
			'times'  => null !== $weakest && $weakest > 0 ? $e['income'] / $weakest : null,
		);
	}

	private static function fill( string $s, string ...$args ): string {
		// One placeholder may be written %s (translator convention); it is the first argument.
		if ( 1 === count( $args ) ) {
			$s = str_replace( '%s', $args[0], $s );
		}
		foreach ( $args as $i => $a ) {
			$s = str_replace( '%' . ( $i + 1 ) . '$s', $a, $s );
		}
		return $s;
	}

	/** Spawn chance as "about 1 in N eggs", or "spawn chance unknown". */
	private static function odds( ?float $p, array $t ): string {
		return null === $p ? $t['odds_unknown'] : self::fill( $t['odds'], self::one_in( $p ) );
	}

	/** N in "1 in N": the spawn chance as a whole number of eggs, at least 1. Twin of oneIn() in advisor.js. */
	private static function one_in( float $p ): string {
		return Number::format( max( 1.0, floor( 1 / $p + 0.5 ) ), 'int', Lang::num() );
	}

	/** "1.5×" with up to one decimal (two below 10). */
	private static function times( float $x ): string {
		$d = $x < 10 ? 1 : 0;
		return Number::format( $x, 'decimal(' . $d . ')', Lang::num() ) . '×';
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function hunt_text( array $r, array $t, callable $fmt ): string {
		if ( null === $r['hunt'] ) {
			return $t['no_hunt'];
		}
		$h = $r['hunt'];
		return self::fill( $t['hunt'], $h['egg'], $h['zone'], $h['pet'], $fmt( $h['income'] ), self::odds( $h['chance'], $t ) );
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function jackpot_text( array $r, array $t, callable $fmt ): string {
		if ( null === $r['jackpot'] ) {
			return $t['no_jackpot'];
		}
		$j = $r['jackpot'];
		return null === $j['chance'] ? self::fill( $t['jackpot_unknown'], $j['egg'], $j['zone'], $fmt( $j['income'] ) ) : self::fill( $t['jackpot'], $j['egg'], $j['zone'], $fmt( $j['income'] ), self::odds( $j['chance'], $t ) );
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function gain_text( array $r, array $t, callable $fmt ): string {
		if ( null === $r['weakest'] ) {
			return $t['gain_ask'];
		}
		if ( null === $r['hunt'] ) {
			return $t['no_hunt'];
		}
		if ( null === $r['gain'] || $r['gain']['gain'] <= 0 ) {
			return $t['gain_none'] . ' ' . ( $r['beats'] > 0 ? $t['gain_rare'] : ( null !== $r['next'] ? $t['gain_speed'] : $t['gain_top'] ) );
		}
		$g   = $r['gain'];
		$out = null === $g['times'] ? self::fill( $t['gain_plain'], $fmt( $g['gain'] ) ) : self::fill( $t['gain'], $fmt( $g['gain'] ), self::times( $g['times'] ) );
		if ( null !== $g['share'] ) {
			$out .= ' ' . self::fill( $t['share'], self::pct( $g['share'] ) );
		}
		return $out;
	}

	/** Share as a percentage, whole numbers from 10 %, one decimal below. */
	private static function pct( float $share ): string {
		$v = $share * 100;
		$d = $v < 10 ? 1 : 0;
		return Number::format( $v, 'decimal(' . $d . ')', Lang::num() ) . '%';
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function next_text( array $r, array $t, callable $fmt ): string {
		if ( null === $r['next'] ) {
			return $t['all'];
		}
		$n   = $r['next'];
		$out = self::fill( $t['next'], $n['name'], $fmt( $n['speed'] ), $fmt( $n['gap'] ) );
		if ( null !== $n['hunt'] ) {
			$out .= ' ' . ( null !== $n['times'] && $n['times'] > 1
				? self::fill( $t['next_hunt'], $n['hunt']['egg'], $fmt( $n['hunt']['income'] ), self::times( $n['times'] ) )
				: self::fill( $t['next_hunt_plain'], $n['hunt']['egg'], $fmt( $n['hunt']['income'] ) ) );
		}
		return $out;
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function rows_html( array $r, array $t, callable $fmt, string $loc ): string {
		$out = '';
		foreach ( $r['rows'] as $e ) {
			$pet  = '' !== $e['url'] ? '<a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['pet'] ) . '</a>' : esc_html( $e['pet'] );
			$more = null === $e['gain'] ? '—' : esc_html( '+' . $fmt( $e['gain'] ) . ( null !== $e['times'] ? ' (' . self::times( $e['times'] ) . ')' : '' ) );
			$out .= '<tr><th scope="row" class="rbx-cell-name">' . esc_html( $e['egg'] ) . '<span class="rbx-advisor__pet">' . esc_html( $t['hatches'] ) . ' ' . $pet . ( '' !== $e['rarity'] ? ' · ' . esc_html( $e['rarity'] ) : '' ) . '</span></th>'
				. '<td data-label="' . esc_attr( $t['col_biome'] ) . '">' . esc_html( $e['zone'] ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_income'] ) . '">' . esc_html( self::fill( $t['per_s'], $fmt( $e['income'] ) ) ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_odds'] ) . '">' . esc_html( null === $e['chance'] ? $t['unknown'] : self::fill( $t['one_in'], self::one_in( $e['chance'] ) ) ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_hatch'] ) . '">' . esc_html( null === $e['hatch'] ? $t['unknown'] : Number::format( $e['hatch'], 'duration', $loc ) ) . '</td>'
				. '<td data-label="' . esc_attr( $t['col_more'] ) . '">' . $more . '</td></tr>';
		}
		return $out;
	}

	/** @return array<string, string> */
	public static function strings(): array {
		return array(
			/* translators: 1: egg, 2: biome, 3: pet, 4: income per second, 5: "about 1 in N eggs" */
			'hunt'            => __( '%1$s in %2$s (%5$s): %3$s earns %4$s per second.', 'rbx-tools' ),
			'no_hunt'         => __( 'None of the eggs you can reach spawns often enough to hunt yet.', 'rbx-tools' ),
			/* translators: 1: egg, 2: biome, 3: income per second, 4: "about 1 in N eggs" */
			'jackpot'         => __( '%1$s in %2$s: %3$s per second, but rare (%4$s).', 'rbx-tools' ),
			/* translators: 1: egg, 2: biome, 3: income per second */
			'jackpot_unknown' => __( '%1$s in %2$s: %3$s per second (spawn chance not known).', 'rbx-tools' ),
			'no_jackpot'      => __( 'The egg to hunt is already the best earner you can reach.', 'rbx-tools' ),
			/* translators: %s: number of eggs, as in "about 1 in 25 eggs" */
			'odds'            => __( 'about 1 in %s eggs', 'rbx-tools' ),
			/* translators: %s: number of eggs, as in "1 in 25" */
			'one_in'          => __( '1 in %s', 'rbx-tools' ),
			'odds_unknown'    => __( 'spawn chance not known', 'rbx-tools' ),
			'gain_ask'        => __( 'Type your weakest pet’s income to see how much the egg to hunt adds.', 'rbx-tools' ),
			'gain_none'       => __( 'The egg to hunt does not beat your weakest pet.', 'rbx-tools' ),
			'gain_rare'       => __( 'Only the rarer eggs in the table below do.', 'rbx-tools' ),
			'gain_speed'      => __( 'More Speed for the next biome opens better eggs.', 'rbx-tools' ),
			'gain_top'        => __( 'No biome egg earns more than it.', 'rbx-tools' ),
			/* translators: 1: extra income per second, 2: multiple like "3.5×" */
			'gain'            => __( 'Swapping your weakest pet for it adds %1$s per second, %2$s what that pet earns.', 'rbx-tools' ),
			/* translators: %s: extra income per second */
			'gain_plain'      => __( 'Swapping your weakest pet for it adds %s per second.', 'rbx-tools' ),
			/* translators: %s: percentage */
			'share'           => __( 'That is %s more total income.', 'rbx-tools' ),
			'all'             => __( 'You can reach every biome.', 'rbx-tools' ),
			/* translators: 1: biome name, 2: Speed it needs, 3: Speed still missing */
			'next'            => __( '%1$s needs %2$s Speed, %3$s more than you have.', 'rbx-tools' ),
			/* translators: 1: egg, 2: income per second, 3: multiple like "3.5×" */
			'next_hunt'       => __( 'Its egg to hunt is the %1$s: %2$s per second, %3$s your egg to hunt now.', 'rbx-tools' ),
			/* translators: 1: egg, 2: income per second */
			'next_hunt_plain' => __( 'Its egg to hunt is the %1$s: %2$s per second.', 'rbx-tools' ),
			'none'            => __( 'No egg you can reach earns more than your weakest pet. More Speed opens better eggs.', 'rbx-tools' ),
			'none_all'        => __( 'No biome egg earns more than your weakest pet.', 'rbx-tools' ),
			'hatches'         => __( 'Hatches:', 'rbx-tools' ),
			/* translators: %s: income */
			'per_s'           => __( '%s/s', 'rbx-tools' ),
			'unknown'         => __( 'Unknown', 'rbx-tools' ),
			'col_biome'       => __( 'Biome', 'rbx-tools' ),
			'col_income'      => __( 'Base income', 'rbx-tools' ),
			'col_odds'        => __( 'How often it spawns', 'rbx-tools' ),
			'col_hatch'       => __( 'Hatch time', 'rbx-tools' ),
			'col_more'        => __( 'More than your weakest pet', 'rbx-tools' ),
			'empty'           => __( 'Enter a number.', 'rbx-tools' ),
			'invalid'         => __( 'Use digits, like 1500 or 1.5K.', 'rbx-tools' ),
			'negative'        => __( 'The number cannot be negative.', 'rbx-tools' ),
			'too_big'         => __( 'That number is too big.', 'rbx-tools' ),
			'ambiguous'       => __( 'Use a dot for decimals, like 1.5.', 'rbx-tools' ),
		);
	}
}
