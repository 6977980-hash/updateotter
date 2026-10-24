<?php
/**
 * Speed calculator (GR-25): which biomes a Speed reaches, how much more the next one needs, and
 * the reachable eggs with the biggest Speed reward, with how many of them close the gap. All from
 * the biome `unlock_speed_required` and egg `reward_speed` fields, so it follows every import.
 * The server renders the result for the example Speed; speed.js recomputes it as the player types.
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
 * Shortcode [rbx_speed_calculator game="sae" speed="10000"].
 */
final class SpeedCalculator {

	private const TOP = 5;

	/** @param array<string, string>|string $atts Attributes. */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'game'  => '',
				'speed' => '10000',
			),
			(array) $atts
		);
		return self::render( (string) $a['game'], is_numeric( $a['speed'] ) ? (float) $a['speed'] : 0.0 );
	}

	public static function render( string $game_key, float $speed ): string {
		$game = Api::game( $game_key );
		if ( ! $game || null === $game->field( 'BIO', 'unlock_speed_required' ) || null === $game->field( 'EGG', 'reward_speed' ) ) {
			return '';
		}
		$ds   = Api::dataset( $game );
		$data = self::data( $ds );
		if ( ! $data['zones'] ) {
			return '';
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$ladder = $ds->meta( 'suffix_ladder' );
		$ladder = is_array( $ladder ) ? $ladder : null;
		$loc    = Lang::num();
		$id     = 'rbx-speed-' . wp_unique_id();
		$r      = self::compute( $data, $speed );
		$fmt    = static fn( float $v ): string => Number::format( $v, 'abbr', $loc, $ladder );

		$rows = '';
		foreach ( $data['zones'] as $i => $z ) {
			$ok    = $z['speed'] <= $speed;
			$rows .= '<tr data-rbx-zone="' . (int) $i . '"' . ( $ok ? ' class="is-reached"' : '' ) . '><th scope="row">' . esc_html( $z['name'] ) . '</th><td data-label="' . esc_attr__( 'Speed needed', 'rbx-tools' ) . '">' . esc_html( $fmt( $z['speed'] ) ) . '</td><td data-label="' . esc_attr__( 'Can you reach it?', 'rbx-tools' ) . '" data-rbx-reach>' . esc_html( $ok ? __( 'Yes', 'rbx-tools' ) : __( 'Not yet', 'rbx-tools' ) ) . '</td></tr>';
		}

		$config = array(
			'locale' => $loc,
			'ladder' => $ladder,
			'zones'  => $data['zones'],
			'eggs'   => $data['eggs'],
			'top'    => self::TOP,
			'i18n'   => self::strings(),
		);
		$out  = '<section class="rbx-calc rbx-speed" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Speed calculator', 'rbx-tools' ) . '" data-rbx-speed>';
		$out .= '<form class="rbx-calc__form" novalidate><div class="rbx-calc__field"><label for="' . esc_attr( $id ) . '-s">' . esc_html__( 'Your Speed', 'rbx-tools' ) . '</label><span class="rbx-calc__num"><input type="text" id="' . esc_attr( $id ) . '-s" value="' . esc_attr( Number::format( $speed, 'int', $loc ) ) . '" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="24" enterkeyhint="done" data-rbx-speed-input aria-describedby="' . esc_attr( $id ) . '-e"></span><p class="rbx-calc__err" id="' . esc_attr( $id ) . '-e" hidden></p></div></form>';
		$out .= '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to change the Speed; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>';
		$out .= '<div class="rbx-calc__result"><dl class="rbx-calc__outs">'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Farthest biome you can reach', 'rbx-tools' ) . '</dt><dd data-rbx-out="far">' . esc_html( $r['far'] ) . '</dd></div>'
			. '<div class="rbx-calc__out"><dt>' . esc_html__( 'Next biome', 'rbx-tools' ) . '</dt><dd data-rbx-out="next">' . esc_html( self::next_text( $r, $fmt ) ) . '</dd></div>'
			. '</dl></div>';
		$out .= '<h3>' . esc_html__( 'Eggs with the biggest Speed reward you can reach', 'rbx-tools' ) . '</h3>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><thead><tr><th scope="col">' . esc_html__( 'Egg', 'rbx-tools' ) . '</th><th scope="col">' . esc_html__( 'Biome', 'rbx-tools' ) . '</th><th scope="col">' . esc_html__( 'Speed reward', 'rbx-tools' ) . '</th><th scope="col">' . esc_html__( 'Eggs to reach the next biome', 'rbx-tools' ) . '</th></tr></thead><tbody data-rbx-eggs>' . self::egg_rows( $r, $fmt ) . '</tbody></table></div>';
		$out .= '<h3>' . esc_html__( 'Speed needed for each biome', 'rbx-tools' ) . '</h3>';
		$out .= '<div class="rbx-table-wrap"><table class="rbx-table"><thead><tr><th scope="col">' . esc_html__( 'Biome', 'rbx-tools' ) . '</th><th scope="col">' . esc_html__( 'Speed needed', 'rbx-tools' ) . '</th><th scope="col">' . esc_html__( 'Can you reach it?', 'rbx-tools' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
		$out .= '<p class="rbx-calc__meta">' . esc_html__( 'Speed needed and egg Speed rewards come from the community wiki. We have not tested them in the game ourselves.', 'rbx-tools' ) . '</p>';
		$out .= '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		$out .= '<script type="application/json" class="rbx-speed-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-speed' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * Live biomes with a Speed requirement, in Speed order, and the live biome eggs with a Speed reward.
	 *
	 * @return array{zones: array<int, array{name: string, speed: float}>, eggs: array<int, array{name: string, zone: int, reward: float}>}
	 */
	public static function data( Dataset $ds ): array {
		$zones = array();
		$index = array();
		foreach ( $ds->all( 'BIO', array( 'live' ) ) as $id => $b ) {
			$s = $b['data']['unlock_speed_required'] ?? null;
			if ( is_numeric( $s ) ) {
				$zones[] = array(
					'id'    => (string) $id,
					'name'  => (string) $b['name'],
					'speed' => (float) $s,
				);
			}
		}
		usort( $zones, static fn( $a, $b ) => $a['speed'] <=> $b['speed'] );
		foreach ( $zones as $i => $z ) {
			$index[ $z['id'] ] = $i;
		}
		$eggs = array();
		foreach ( $ds->all( 'EGG', array( 'live' ) ) as $e ) {
			$rw = $e['data']['reward_speed'] ?? null;
			if ( ! is_numeric( $rw ) || (float) $rw <= 0 || 'biome_spawn' !== ( $e['data']['obtain_method'] ?? '' ) ) {
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
			if ( null !== $zone ) {
				$eggs[] = array(
					'name'   => (string) $e['name'],
					'zone'   => $zone,
					'reward' => (float) $rw,
				);
			}
		}
		usort( $eggs, static fn( $a, $b ) => $b['reward'] <=> $a['reward'] ?: strcmp( $a['name'], $b['name'] ) );
		return array(
			'zones' => array_map( static fn( $z ) => array( 'name' => $z['name'], 'speed' => $z['speed'] ), $zones ),
			'eggs'  => $eggs,
		);
	}

	/**
	 * Twin of compute() in speed.js.
	 *
	 * @param array{zones: array<int, array{name: string, speed: float}>, eggs: array<int, array{name: string, zone: int, reward: float}>} $data Data.
	 * @return array{far: string, next: ?array{name: string, speed: float}, gap: float, eggs: array<int, array{name: string, zone: string, reward: float, need: ?int}>}
	 */
	public static function compute( array $data, float $speed ): array {
		$far  = 0;
		$next = null;
		foreach ( $data['zones'] as $i => $z ) {
			if ( $z['speed'] <= $speed ) {
				$far = $i;
			} elseif ( null === $next ) {
				$next = $z;
			}
		}
		$gap  = null === $next ? 0.0 : $next['speed'] - $speed;
		$eggs = array();
		foreach ( $data['eggs'] as $e ) {
			if ( $data['zones'][ $e['zone'] ]['speed'] > $speed ) {
				continue;
			}
			$eggs[] = array(
				'name'   => $e['name'],
				'zone'   => $data['zones'][ $e['zone'] ]['name'],
				'reward' => $e['reward'],
				'need'   => null === $next ? null : (int) ceil( $gap / $e['reward'] ),
			);
			if ( count( $eggs ) >= self::TOP ) {
				break;
			}
		}
		return array(
			'far'  => $data['zones'][ $far ]['name'],
			'next' => $next,
			'gap'  => $gap,
			'eggs' => $eggs,
		);
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function next_text( array $r, callable $fmt ): string {
		if ( null === $r['next'] ) {
			return __( 'You can reach every biome.', 'rbx-tools' );
		}
		/* translators: 1: biome name, 2: Speed it needs, 3: Speed still missing */
		return sprintf( __( '%1$s needs %2$s Speed, %3$s more than you have.', 'rbx-tools' ), $r['next']['name'], $fmt( $r['next']['speed'] ), $fmt( $r['gap'] ) );
	}

	/** @param array<string, mixed> $r compute() result. */
	private static function egg_rows( array $r, callable $fmt ): string {
		$out = '';
		foreach ( $r['eggs'] as $e ) {
			$out .= '<tr><th scope="row">' . esc_html( $e['name'] ) . '</th><td data-label="' . esc_attr__( 'Biome', 'rbx-tools' ) . '">' . esc_html( $e['zone'] ) . '</td><td data-label="' . esc_attr__( 'Speed reward', 'rbx-tools' ) . '">' . esc_html( $fmt( $e['reward'] ) ) . '</td><td data-label="' . esc_attr__( 'Eggs to reach the next biome', 'rbx-tools' ) . '">' . esc_html( null === $e['need'] ? '—' : Number::format( (float) $e['need'], 'int', Lang::num() ) ) . '</td></tr>';
		}
		return $out;
	}

	/** @return array<string, string> */
	public static function strings(): array {
		return array(
			'all'       => __( 'You can reach every biome.', 'rbx-tools' ),
			/* translators: 1: biome name, 2: Speed it needs, 3: Speed still missing */
			'next'      => __( '%1$s needs %2$s Speed, %3$s more than you have.', 'rbx-tools' ),
			'yes'       => __( 'Yes', 'rbx-tools' ),
			'no'        => __( 'Not yet', 'rbx-tools' ),
			'biome'     => __( 'Biome', 'rbx-tools' ),
			'reward'    => __( 'Speed reward', 'rbx-tools' ),
			'need'      => __( 'Eggs to reach the next biome', 'rbx-tools' ),
			/* translators: 1: biome name, 2: next-biome sentence */
			'live'      => __( 'Farthest biome: %1$s. %2$s', 'rbx-tools' ),
			'empty'     => __( 'Enter a number.', 'rbx-tools' ),
			'invalid'   => __( 'Use digits, like 1500 or 1.5K.', 'rbx-tools' ),
			'negative'  => __( 'The number cannot be negative.', 'rbx-tools' ),
			'too_big'   => __( 'That number is too big.', 'rbx-tools' ),
			'ambiguous' => __( 'Use a dot for decimals, like 1.5.', 'rbx-tools' ),
		);
	}
}
