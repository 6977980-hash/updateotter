<?php
/**
 * Pet compare (SAE): two or three pets side by side, with our own numbers the wiki does not have
 * (income ratio, how many plain copies match one stronger pet, hunt value, which one is easier to
 * reach). One tool page, no "X vs Y" pages: the chosen pets live in the ?pets= query only, which
 * the page's canonical drops. The server renders the default pets; compare.js recomputes as the
 * player picks (twin of compute() and lines()).
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

use RBX\Data\Api;
use RBX\Data\Content\Lang;
use RBX\Data\Data\Dataset;
use RBX\Data\Format\Number;
use RBX\Data\Render\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [rbx_pet_compare game="sae" pets="slug,slug"].
 */
final class PetCompare {

	/** From this many plain copies the "it takes N to match" clause is left out. */
	public const SLOTS = 100;

	/** Most pets compared at once. */
	public const MAX = 3;

	/** Rows of the table: key in the pet data, label key in strings(), which value is best. */
	public const ROWS = array(
		array( 'rarity', 'row_rarity', '' ),
		array( 'tier', 'row_tier', '' ),
		array( 'it', 'row_income', 'max:i' ),
		array( 'hr', 'row_hour', '' ),
		array( 'how', 'row_how', '' ),
		array( 'spt', 'row_speed', 'min:sp' ),
		array( 'ct', 'row_chance', 'max:c' ),
		array( 'ht', 'row_hatch', 'min:h' ),
		array( 'hvt', 'row_hunt', 'max:hv' ),
	);

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'game' => '',
				'pets' => '',
			),
			(array) $atts
		);
		return self::render( (string) $a['game'], array_filter( array_map( 'trim', explode( ',', (string) $a['pets'] ) ) ) );
	}

	/** @param array<int, string> $slugs Default pets. */
	public static function render( string $game_key, array $slugs ): string {
		$game = Api::game( $game_key );
		if ( ! $game || null === $game->field( 'PET', 'base_income_per_s' ) ) {
			return '';
		}
		$ds   = Api::dataset( $game );
		$pets = self::data( $ds );
		if ( count( $pets ) < 2 ) {
			return '';
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$slugs = array_values( array_filter( $slugs, static fn( $s ) => isset( $pets[ $s ] ) ) );
		if ( count( $slugs ) < 2 ) {
			$slugs = array_slice( array_keys( $pets ), 0, 2 );
		}
		$slugs = array_slice( $slugs, 0, self::MAX );
		$t     = self::strings();
		$loc   = Lang::num();
		$id    = 'rbx-cmp-' . wp_unique_id();
		$r     = self::compute( $pets, $slugs );

		$out  = '<section class="rbx-calc rbx-compare" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Pet compare', 'rbx-tools' ) . '" data-rbx-compare>';
		$out .= '<form class="rbx-calc__form" novalidate>';
		for ( $i = 0; $i < self::MAX; $i++ ) {
			$fid   = $id . '-p' . $i;
			$val   = isset( $slugs[ $i ] ) ? $pets[ $slugs[ $i ] ]['n'] : '';
			$label = 2 === $i ? __( 'Third pet (optional)', 'rbx-tools' ) : sprintf( /* translators: %s: 1 or 2 */ __( 'Pet %s', 'rbx-tools' ), (string) ( $i + 1 ) );
			$out  .= '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label>'
				. '<input type="text" id="' . esc_attr( $fid ) . '" value="' . esc_attr( $val ) . '" list="' . esc_attr( $id ) . '-list" autocomplete="off" spellcheck="false" maxlength="60" enterkeyhint="done" data-rbx-cmp="' . $i . '" aria-describedby="' . esc_attr( $fid ) . '-e">'
				. '<p class="rbx-calc__err" id="' . esc_attr( $fid ) . '-e" hidden></p></div>';
		}
		$out .= '<datalist id="' . esc_attr( $id ) . '-list">';
		foreach ( $pets as $p ) {
			$out .= '<option value="' . esc_attr( $p['n'] ) . '">';
		}
		$out .= '</datalist></form>';
		$out .= '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to pick other pets; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>';
		$out .= '<ul class="rbx-compare__lines" data-rbx-lines>' . self::lines_html( self::lines( $r, $t, $loc, is_array( $ds->meta( 'suffix_ladder' ) ) ? $ds->meta( 'suffix_ladder' ) : null ) ) . '</ul>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table rbx-compare__table" data-rbx-table>' . self::table_html( $r, $t ) . '</table></div>';
		$out .= '<p class="rbx-calc__meta">' . esc_html__( 'Incomes, eggs, Speed, spawn chances and hatch times come from the community wiki; we have not tested them in the game ourselves. Incomes are base incomes, before mutation and weight. The ratios, hunt value and the best mark in each row are worked out by us.', 'rbx-tools' ) . '</p>';
		$out .= '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		$config = array(
			'locale' => $loc,
			'ladder' => $ds->meta( 'suffix_ladder' ),
			'pets'   => $pets,
			'rows'   => self::ROWS,
			'max'    => self::MAX,
			'slots'  => self::SLOTS,
			'i18n'   => $t,
		);
		$out .= '<script type="application/json" class="rbx-compare-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-compare' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * Live and limited pets with a base income, strongest first, keyed by slug. Display strings are
	 * made here once, so compare.js only picks and compares.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function data( Dataset $ds ): array {
		$loc    = Lang::num();
		$ladder = $ds->meta( 'suffix_ladder' );
		$ladder = is_array( $ladder ) ? $ladder : null;
		$fmt    = static fn( float $v ): string => Number::format( $v, 'abbr', $loc, $ladder );
		$t      = self::strings();
		$out    = array();
		foreach ( Insights::ranked_pets( $ds, null ) as $pid => $p ) {
			$rec  = $p['record'];
			$rar  = $ds->get( is_string( $rec['data']['rarity_id'] ?? null ) ? $rec['data']['rarity_id'] : null );
			$zone = Insights::zone_of( $ds, (string) $pid );
			$sp   = null;
			$c    = null;
			$h    = null;
			$how  = $t['how_other'];
			if ( null !== $zone ) {
				[ $bio, $egg ] = $zone;
				$sp  = is_numeric( $bio['data']['unlock_speed_required'] ?? null ) ? (float) $bio['data']['unlock_speed_required'] : null;
				$h   = is_numeric( $egg['data']['hatch_time_s'] ?? null ) && (float) $egg['data']['hatch_time_s'] > 0 ? (float) $egg['data']['hatch_time_s'] : null;
				$how = self::fill( 'event' === ( $egg['data']['obtain_method'] ?? '' ) ? $t['how_event'] : $t['how_egg'], (string) $egg['name'], (string) $bio['name'] );
			} else {
				foreach ( $ds->children( 'SRC', 'pet_id', (string) $pid ) as $s ) {
					$kind = (string) ( $s['data']['source_kind'] ?? '' );
					$ref  = $ds->get( (string) ( $s['data']['source_ref_id'] ?? '' ) );
					$key  = 'how_src_' . $kind;
					if ( isset( $t[ $key ] ) ) {
						$how = str_contains( $t[ $key ], '%s' ) ? ( $ref ? self::fill( $t[ $key ], (string) $ref['name'] ) : $t[ 'how_src_' . $kind . '_plain' ] ?? $how ) : $t[ $key ];
					}
					break;
				}
				if ( $t['how_other'] === $how && $ds->children( 'FUS', 'output_pet_id', (string) $pid ) ) {
					$how = $t['how_src_fusion'];
				}
			}
			$hunt = Insights::hunt( $ds, (string) $pid );
			if ( null !== $hunt ) {
				$c = $hunt['chance'];
			}
			$hv            = null === $hunt ? null : $hunt['value'];
			$slug          = (string) $rec['slug'];
			$out[ $slug ]  = array(
				'n'      => (string) $rec['name'],
				'u'      => self::url( $rec ),
				'rarity' => $rar ? (string) $rar['name'] : '—',
				'tier'   => '' !== (string) ( $rec['data']['tier_list_rank'] ?? '' ) ? (string) $rec['data']['tier_list_rank'] : ( 'limited' === $rec['status'] ? $t['limited'] : '—' ),
				'i'      => $p['income'],
				'it'     => self::fill( $t['per_s'], $fmt( $p['income'] ) ),
				'hr'     => $fmt( $p['income'] * 3600 ),
				'how'    => trim( $how ),
				'sp'     => $sp,
				'spt'    => null === $sp ? '—' : ( $sp > 0 ? $fmt( $sp ) : $t['open'] ),
				'c'      => $c,
				'ct'     => null === $c ? '—' : self::fill( $t['one_in'], Number::format( max( 1.0, floor( 1 / $c + 0.5 ) ), 'int', $loc ) ),
				'h'      => $h,
				'ht'     => null === $h ? '—' : Number::format( $h, 'duration', $loc ),
				'hv'     => $hv,
				'hvt'    => null === $hv ? '—' : self::fill( $t['per_s'], $fmt( $hv >= 100 ? round( $hv ) : round( $hv, 2 ) ) ),
			);
		}
		return $out;
	}

	/** Link only to a pet page that is in search. */
	private static function url( array $pet ): string {
		$pid = (int) ( $pet['post_id'] ?? 0 );
		if ( $pid && 'publish' === get_post_status( $pid ) && \RBX\Data\Render\Ui::indexable( $pid ) ) {
			return (string) get_permalink( $pid );
		}
		return '';
	}

	/**
	 * Twin of compute() in compare.js: the chosen pets, and for each row the columns holding the best value.
	 *
	 * @param array<string, array<string, mixed>> $pets  data().
	 * @param array<int, string>                  $slugs Chosen slugs (2-3, known).
	 * @return array{pets: array<int, array<string, mixed>>, best: array<string, array<int, int>>}
	 */
	public static function compute( array $pets, array $slugs ): array {
		$list = array_map( static fn( $s ) => $pets[ $s ], $slugs );
		$best = array();
		foreach ( self::ROWS as $row ) {
			if ( '' === $row[2] ) {
				continue;
			}
			[ $dir, $key ] = explode( ':', $row[2] );
			$vals          = array();
			foreach ( $list as $i => $p ) {
				if ( null !== $p[ $key ] ) {
					$vals[ $i ] = (float) $p[ $key ];
				}
			}
			// A best mark needs two known values that differ.
			if ( count( $vals ) < 2 || max( $vals ) === min( $vals ) ) {
				continue;
			}
			$target           = 'max' === $dir ? max( $vals ) : min( $vals );
			$best[ $row[0] ] = array_keys( array_filter( $vals, static fn( $v ) => $v === $target ) );
		}
		return array(
			'pets' => $list,
			'best' => $best,
		);
	}

	/** "about 3.5" / "about 12" times. Twin of ratio() in compare.js. */
	public static function ratio( float $r, string $loc, ?array $ladder = null ): string {
		if ( $r >= 1000 ) {
			return Number::format( $r, 'abbr', $loc, $ladder );
		}
		if ( $r >= 10 ) {
			return Number::format( round( $r ), 'int', $loc );
		}
		$v = round( $r, 1 );
		return floor( $v ) === $v ? Number::format( $v, 'int', $loc ) : Number::format( $v, 'decimal(1)', $loc );
	}

	/**
	 * Summary sentences. Twin of lines() in compare.js.
	 *
	 * @param array<string, mixed>  $r compute() result.
	 * @param array<string, string> $t strings().
	 * @return array<int, string>
	 */
	public static function lines( array $r, array $t, string $loc, ?array $ladder = null ): array {
		$list = $r['pets'];
		$top  = 0;
		foreach ( $list as $i => $p ) {
			if ( $p['i'] > $list[ $top ]['i'] ) {
				$top = $i;
			}
		}
		$out = array();
		$s   = $list[ $top ];
		foreach ( $list as $i => $p ) {
			if ( $i === $top ) {
				continue;
			}
			$x = $s['i'] / $p['i'];
			if ( abs( $x - 1 ) < 0.05 ) {
				$out[] = self::fill( $t['same'], $s['n'], $p['n'] );
			} elseif ( $x >= self::SLOTS ) {
				// More copies than any pen holds: the slot count would be silly, the ratio says it all.
				$out[] = self::fill( $t['times_only'], $s['n'], self::ratio( $x, $loc, $ladder ), $p['n'] );
			} else {
				$out[] = self::fill( $t['times'], $s['n'], self::ratio( $x, $loc, $ladder ), $p['n'], Number::format( ceil( $x - 1e-9 ), 'int', $loc ) );
			}
		}
		if ( isset( $r['best']['hvt'] ) && 1 === count( $r['best']['hvt'] ) ) {
			$out[] = self::fill( $t['best_hunt'], $list[ $r['best']['hvt'][0] ]['n'] );
		}
		if ( isset( $r['best']['spt'] ) && 1 === count( $r['best']['spt'] ) ) {
			$e     = $list[ $r['best']['spt'][0] ];
			$out[] = self::fill( $t['easiest'], $e['n'], $e['spt'] );
		}
		return $out;
	}

	/** @param array<int, string> $lines Sentences. */
	private static function lines_html( array $lines ): string {
		$out = '';
		foreach ( $lines as $l ) {
			$out .= '<li>' . esc_html( $l ) . '</li>';
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>  $r compute() result.
	 * @param array<string, string> $t strings().
	 */
	private static function table_html( array $r, array $t ): string {
		$head = '<thead><tr><td></td>';
		foreach ( $r['pets'] as $p ) {
			$head .= '<th scope="col">' . ( '' !== $p['u'] ? '<a href="' . esc_url( $p['u'] ) . '">' . esc_html( $p['n'] ) . '</a>' : esc_html( $p['n'] ) ) . '</th>';
		}
		$body = '<tbody>';
		foreach ( self::ROWS as $row ) {
			$body .= '<tr><th scope="row">' . esc_html( $t[ $row[1] ] ) . '</th>';
			foreach ( $r['pets'] as $i => $p ) {
				$is    = in_array( $i, $r['best'][ $row[0] ] ?? array(), true );
				$body .= '<td' . ( $is ? ' class="rbx-compare__best"' : '' ) . '>' . esc_html( (string) $p[ $row[0] ] ) . ( $is ? ' <span class="rbx-compare__mark">' . esc_html( $t['best'] ) . '</span>' : '' ) . '</td>';
			}
			$body .= '</tr>';
		}
		return $head . '</tr></thead>' . $body . '</tbody>';
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
			'row_rarity'   => __( 'Rarity', 'rbx-tools' ),
			'row_tier'     => __( 'Tier (our list)', 'rbx-tools' ),
			'row_income'   => __( 'Base income', 'rbx-tools' ),
			'row_hour'     => __( 'Cash per hour', 'rbx-tools' ),
			'row_how'      => __( 'How you get it', 'rbx-tools' ),
			'row_speed'    => __( 'Speed needed', 'rbx-tools' ),
			'row_chance'   => __( 'How often its egg spawns', 'rbx-tools' ),
			'row_hatch'    => __( 'Hatch time', 'rbx-tools' ),
			'row_hunt'     => __( 'Hunt value', 'rbx-tools' ),
			'best'         => __( 'best', 'rbx-tools' ),
			'limited'      => __( 'Limited', 'rbx-tools' ),
			'open'         => __( 'Open from the start', 'rbx-tools' ),
			/* translators: 1: egg, 2: biome */
			'how_egg'      => __( '%1$s in %2$s', 'rbx-tools' ),
			/* translators: 1: egg, 2: event zone */
			'how_event'    => __( 'Event: %1$s in %2$s', 'rbx-tools' ),
			/* translators: %s: egg */
			'how_src_egg'          => __( 'Egg: %s', 'rbx-tools' ),
			/* translators: %s: event or egg */
			'how_src_event'        => __( 'Event: %s', 'rbx-tools' ),
			'how_src_event_plain'  => __( 'Event', 'rbx-tools' ),
			/* translators: %s: egg */
			'how_src_lab'          => __( 'Lab: %s', 'rbx-tools' ),
			'how_src_lab_plain'    => __( 'Lab', 'rbx-tools' ),
			'how_src_fusion'       => __( 'Fusion', 'rbx-tools' ),
			'how_src_ritual'       => __( 'Ritual', 'rbx-tools' ),
			/* translators: %s: shop item */
			'how_src_shop'         => __( 'Shop: %s', 'rbx-tools' ),
			'how_src_shop_plain'   => __( 'Shop', 'rbx-tools' ),
			/* translators: %s: game pass */
			'how_src_game_pass'    => __( 'Game pass: %s', 'rbx-tools' ),
			'how_src_game_pass_plain' => __( 'Game pass', 'rbx-tools' ),
			'how_src_code'         => __( 'Code reward', 'rbx-tools' ),
			'how_other'    => __( 'Other (see its page)', 'rbx-tools' ),
			/* translators: %s: number of eggs, as in "1 in 25" */
			'one_in'       => __( '1 in %s', 'rbx-tools' ),
			/* translators: %s: income */
			'per_s'        => __( '%s/s', 'rbx-tools' ),
			/* translators: 1: pet, 2: pet */
			'same'         => __( '%1$s and %2$s earn about the same base income.', 'rbx-tools' ),
			/* translators: 1: stronger pet, 2: ratio, 3: weaker pet, 4: whole number */
			'times'        => __( '%1$s earns about %2$s times as much as %3$s: it takes %4$s plain %3$s to match one %1$s.', 'rbx-tools' ),
			/* translators: 1: stronger pet, 2: ratio, 3: weaker pet */
			'times_only'   => __( '%1$s earns about %2$s times as much as %3$s.', 'rbx-tools' ),
			/* translators: %s: pet */
			'best_hunt'    => __( 'Best hunt value: %s. Its egg is worth the most per trip on average.', 'rbx-tools' ),
			/* translators: 1: pet, 2: Speed */
			'easiest'      => __( 'Easiest to reach: %1$s (Speed needed: %2$s).', 'rbx-tools' ),
			'unknown_pet'  => __( 'Pick a pet from the list.', 'rbx-tools' ),
			'twice'        => __( 'This pet is already picked.', 'rbx-tools' ),
			'need_two'     => __( 'Pick at least two pets.', 'rbx-tools' ),
		);
	}
}
