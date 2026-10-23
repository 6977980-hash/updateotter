<?php
/**
 * Server render of a calculator (ENG-32): form with defaults preselected, the default result
 * computed by RBX\Data\Api::evaluate(), and the JSON config for the JS module.
 *
 * @package RBX\Tools
 */

declare( strict_types=1 );

namespace RBX\Tools;

use RBX\Data\Api;
use RBX\Data\Config\Game;
use RBX\Data\Data\Dataset;

defined( 'ABSPATH' ) || exit;

/**
 * No GET handling: inputs only change client-side; the URL fragment carries state (ENG-33).
 */
final class Calculator {

	private static int $n = 0;

	/**
	 * @param array<string, string> $fixed Input key => record ID shown as fixed text (mini calculator).
	 */
	public static function render( string $game_key, string $tool_key, array $fixed = array(), bool $mini = false ): string {
		$game = Api::game( $game_key );
		$tool = $game ? $game->tool( $tool_key ) : null;
		if ( ! $game || ! $tool ) {
			return current_user_can( 'edit_posts' ) ? '<p class="rbx-empty">' . esc_html__( 'Calculator: pick a game and tool in the block settings.', 'rbx-tools' ) . '</p>' : '';
		}
		$ds       = Api::dataset( $game );
		$formulas = $ds->formulas( $tool_key );
		if ( $mini ) {
			$formulas = array_slice( array_filter( $formulas, static fn( $f ) => $f['available'] ), 0, 1, true );
			if ( ! $formulas ) {
				return '';
			}
		}
		$decls    = array_values( (array) $tool['inputs'] );
		$values   = self::defaults( $ds, $tool_key, $decls );
		$values   = array_merge( $values, $fixed );
		$id       = 'rbx-calc-' . ( ++self::$n );
		$ladder   = $ds->meta( 'suffix_ladder' );
		$ladder   = is_array( $ladder ) ? $ladder : null;
		$manifest = Api::export_manifest( $game );

		$fields = '';
		$seed   = array();
		foreach ( $decls as $d ) {
			$key = (string) $d['key'];
			if ( isset( $fixed[ $key ] ) ) {
				$rec = $ds->get( $fixed[ $key ] );
				if ( $rec ) {
					$seed[ (string) $d['target'] ][ (string) $rec['id'] ] = $ds->calc_record( $rec );
					$fields .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $rec['slug'] ) . '" data-id="' . esc_attr( (string) $rec['id'] ) . '" data-rbx-input="' . esc_attr( $key ) . '">';
				}
				continue;
			}
			$fields .= self::field( $ds, $d, $values[ $key ] ?? null, $id, $ladder, $seed );
		}

		$results = '';
		$schema  = array();
		foreach ( $formulas as $fkey => $f ) {
			$d        = $f['record']['data'];
			$format   = (string) ( $d['display_format'] ?? 'abbr' );
			$res      = $f['available'] ? Api::evaluate( (string) $fkey, $values, $game ) : array( 'ok' => false, 'reason' => (string) $f['reason'] );
			$results .= '<div class="rbx-calc__out" data-rbx-output="' . esc_attr( (string) $fkey ) . '"><dt>' . esc_html( (string) ( $d['label__en'] ?? $fkey ) ) . '</dt><dd>' . self::value_html( $res, $format, $ladder, (string) ( $d['output_unit__en'] ?? '' ) ) . '</dd></div>';
			$item     = array(
				'key'    => (string) $fkey,
				'label'  => (string) ( $d['label__en'] ?? $fkey ),
				'unit'   => (string) ( $d['output_unit__en'] ?? '' ),
				'format' => $format,
			);
			if ( $f['available'] ) {
				$item['expr']   = (string) $d['expression'];
				$item['inputs'] = (object) $f['inputs'];
			} else {
				$item['na'] = (string) $f['reason'];
			}
			$schema[] = $item;
		}

		$slices = array();
		foreach ( array_unique( array_merge( array( 'core' ), (array) ( $tool['slices'] ?? array() ) ) ) as $s ) {
			if ( isset( $manifest['files'][ $s ] ) ) {
				$slices[ $s ] = $manifest['files'][ $s ];
			}
		}
		$state_defaults = array();
		foreach ( $decls as $d ) {
			$k = (string) $d['key'];
			$v = $values[ $k ] ?? null;
			if ( 'ref' === $d['type'] ) {
				$r                    = is_string( $v ) ? $ds->get( $v ) : null;
				$state_defaults[ $k ] = $r ? (string) $r['slug'] : '';
			} else {
				$state_defaults[ $k ] = $v;
			}
		}
		$config = array(
			'game'     => $game->key(),
			'tool'     => $tool_key,
			'mini'     => $mini,
			'version'  => $manifest['version'],
			'locale'   => 'en',
			'ladder'   => $ladder,
			'slices'   => $slices,
			'inputs'   => $decls,
			'fixed'    => array_keys( $fixed ),
			'formulas' => $schema,
			'defaults' => $state_defaults,
			'seed'     => array(
				'records' => (object) $seed,
				'params'  => (object) $ds->calc_data( array() )['params'],
			),
			'i18n'     => self::strings(),
		);

		$page_lv = (string) get_post_meta( (int) get_the_ID(), '_rbx_page_last_verified', true );
		$meta    = sprintf(
			/* translators: 1: dataset version, 2: "last verified <date>" or "not checked in game yet" */
			esc_html__( 'Data version %1$s, %2$s', 'rbx-tools' ),
			esc_html( $manifest['version'] ),
			'' !== $page_lv
				/* translators: %s: date */
				? sprintf( esc_html__( 'last verified %s', 'rbx-tools' ), '<time datetime="' . esc_attr( $page_lv ) . '">' . esc_html( wp_date( 'j M Y', strtotime( $page_lv . ' 12:00 UTC' ) ) ) . '</time>' )
				: esc_html__( 'not checked in game yet', 'rbx-tools' )
		);

		$title = $mini ? esc_html__( 'Quick calculator', 'rbx-tools' ) : esc_html( (string) ( $tool['label__en'] ?? '' ) );
		$out   = '<section class="rbx-calc' . ( $mini ? ' rbx-calc--mini' : '' ) . '" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-t" data-rbx-calculator>'
			. '<h2 class="rbx-calc__title" id="' . esc_attr( $id ) . '-t">' . $title . '</h2>'
			. '<form class="rbx-calc__form" novalidate>' . $fields . '</form>'
			. '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to change inputs; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>'
			. '<div class="rbx-calc__result"><p class="rbx-calc__fix" hidden data-rbx-fix>' . esc_html__( 'Fix the highlighted field to update', 'rbx-tools' ) . '</p><dl class="rbx-calc__outs">' . $results . '</dl>'
			. '<p class="rbx-calc__meta">' . $meta . '</p>'
			. '<p class="rbx-calc__notice" role="alert" hidden data-rbx-notice></p>'
			. '<div class="rbx-calc__error" hidden data-rbx-load-error><p>' . esc_html__( 'The live calculator could not load. The example result below is correct for the default inputs.', 'rbx-tools' ) . '</p><button type="button" class="button" data-rbx-retry>' . esc_html__( 'Try again', 'rbx-tools' ) . '</button></div>'
			. '</div>'
			. '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		if ( ! $mini ) {
			$out .= '<div class="rbx-calc__actions" hidden data-rbx-actions><button type="button" class="button" data-rbx-copy>' . esc_html__( 'Copy link', 'rbx-tools' ) . '</button> <button type="button" class="button button-secondary" data-rbx-reset>' . esc_html__( 'Reset', 'rbx-tools' ) . '</button> <span class="rbx-live" role="status" data-rbx-copy-status></span></div>';
			$out .= self::formula_notes( $formulas );
		}
		$out .= '<script type="application/json" class="rbx-calc-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></section>';
		wp_enqueue_script_module( 'rbx-calculator' );
		wp_enqueue_style( 'rbx-calculator-style' );
		return $out;
	}

	/**
	 * Defaults: `_meta.calc_defaults[tool]`, then the first live record, then the input default (REV C-40).
	 *
	 * @param array<int, array<string, mixed>> $decls Inputs.
	 * @return array<string, mixed>
	 */
	public static function defaults( Dataset $ds, string $tool_key, array $decls ): array {
		$meta = $ds->meta( 'calc_defaults' );
		$meta = is_array( $meta ) && is_array( $meta[ $tool_key ] ?? null ) ? $meta[ $tool_key ] : array();
		$out  = array();
		foreach ( $decls as $d ) {
			$k = (string) $d['key'];
			if ( 'ref' === $d['type'] ) {
				$v = $meta[ $k ] ?? null;
				if ( is_string( $v ) && $ds->get( $v ) ) {
					$out[ $k ] = $v;
				} elseif ( empty( $d['optional'] ) ) {
					$first     = array_key_first( $ds->all( (string) $d['target'] ) );
					$out[ $k ] = null === $first ? '' : (string) $first;
				} else {
					$out[ $k ] = '';
				}
			} elseif ( 'bool' === $d['type'] ) {
				$out[ $k ] = (bool) ( $meta[ $k ] ?? $d['default'] ?? false );
			} else {
				$v         = $meta[ $k ] ?? $d['default'] ?? ( $d['min'] ?? 0 );
				$out[ $k ] = is_numeric( $v ) ? $v + 0 : 0;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>                $d      Input declaration.
	 * @param array<int, array{s:string,e:int}>|null $ladder Ladder.
	 * @param array<string, array<string, mixed>> $seed   Calc records of the default selection (filled here).
	 */
	private static function field( Dataset $ds, array $d, mixed $value, string $id, ?array $ladder, array &$seed ): string {
		$key   = (string) $d['key'];
		$fid   = $id . '-' . $key;
		$label = esc_html( (string) ( $d['label__en'] ?? $key ) );
		$err   = '<p class="rbx-calc__err" id="' . esc_attr( $fid ) . '-e" hidden></p>';
		switch ( (string) $d['type'] ) {
			case 'ref':
				$target = (string) $d['target'];
				$groups = array();
				foreach ( $ds->all( $target ) as $rid => $r ) {
					$g              = ! empty( $d['group_by'] ) ? (string) ( $r['data'][ $d['group_by'] ] ?? '' ) : '';
					$groups[ $g ][] = $r;
					if ( $rid === $value ) {
						$seed[ $target ][ $rid ] = $ds->calc_record( $r );
					}
				}
				$opts = ! empty( $d['optional'] ) ? '<option value="">' . esc_html( (string) ( $d['none_label__en'] ?? __( 'None', 'rbx-tools' ) ) ) . '</option>' : '';
				foreach ( $groups as $g => $recs ) {
					$o = '';
					foreach ( $recs as $r ) {
						$o .= '<option value="' . esc_attr( (string) $r['slug'] ) . '" data-id="' . esc_attr( (string) $r['id'] ) . '"' . selected( $r['id'], $value, false ) . '>' . esc_html( (string) $r['name'] ) . '</option>';
					}
					$gr    = '' !== $g ? $ds->get( $g ) : null;
					$opts .= $gr ? '<optgroup label="' . esc_attr( (string) $gr['name'] ) . '">' . $o . '</optgroup>' : $o;
				}
				return '<p class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . $label . '</label><select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" data-rbx-input="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $fid ) . '-e">' . $opts . '</select>' . $err . '</p>';
			case 'bool':
				return '<p class="rbx-calc__field rbx-calc__field--check"><input type="checkbox" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" value="1" data-rbx-input="' . esc_attr( $key ) . '"' . checked( (bool) $value, true, false ) . '> <label for="' . esc_attr( $fid ) . '">' . $label . '</label></p>';
			default:
				$suffix = '';
				if ( ! empty( $d['suffix'] ) ) {
					$o = '<option value="">' . esc_html__( 'no suffix', 'rbx-tools' ) . '</option>';
					foreach ( \RBX\Data\Format\Number::ladder( $ladder ) as $s ) {
						$o .= '<option value="' . esc_attr( $s['s'] ) . '">' . esc_html( $s['s'] ) . '</option>';
					}
					$suffix = '<label class="screen-reader-text" for="' . esc_attr( $fid ) . '-s">' . esc_html__( 'Suffix', 'rbx-tools' ) . '</label><select id="' . esc_attr( $fid ) . '-s" class="rbx-calc__suffix" data-rbx-suffix="' . esc_attr( $key ) . '">' . $o . '</select>';
				}
				$shown = is_numeric( $value ) ? \RBX\Data\Format\Number::format( $value + 0, ! empty( $d['integer'] ) ? 'int' : 'plain' ) : '';
				return '<p class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . $label . '</label><span class="rbx-calc__num"><input type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $shown ) . '" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="24" enterkeyhint="done" data-rbx-input="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $fid ) . '-e">' . $suffix . '</span>' . $err . '</p>';
		}
	}

	/**
	 * @param array<string, mixed>                   $res    Api::evaluate result.
	 * @param array<int, array{s:string,e:int}>|null $ladder Ladder.
	 */
	public static function value_html( array $res, string $format, ?array $ladder, string $unit ): string {
		if ( empty( $res['ok'] ) ) {
			$s = self::strings();
			$r = (string) ( $res['reason'] ?? '' );
			$t = 'recheck' === $r ? $s['recheck'] : ( str_starts_with( $r, 'state_' ) && 'state_m' !== $r ? $s['unknown'] : $s['na'] );
			return '<span class="rbx-calc__na">' . esc_html( $t ) . '</span>';
		}
		$v     = (float) $res['value'];
		$short = \RBX\Data\Format\Number::format( $v, $format, 'en', $ladder );
		$exact = \RBX\Data\Format\Number::exact( $v, 'en' );
		$html  = $short === $exact ? '<span class="rbx-calc__v" data-exact="' . esc_attr( $exact ) . '">' . esc_html( $short ) . '</span>' : '<span class="rbx-calc__v" data-exact="' . esc_attr( $exact ) . '"><span aria-hidden="true">' . esc_html( $short ) . '</span><span class="screen-reader-text">' . esc_html( $exact ) . '</span></span>';
		return $html . ( '' !== $unit ? ' <span class="rbx-calc__unit">' . esc_html( $unit ) . '</span>' : '' );
	}

	/** @param array<string, array<string, mixed>> $formulas Formulas. */
	private static function formula_notes( array $formulas ): string {
		$li = '';
		foreach ( $formulas as $f ) {
			if ( $f['available'] ) {
				$li .= '<li><strong>' . esc_html( (string) ( $f['record']['data']['label__en'] ?? $f['key'] ) ) . '</strong>: <code>' . esc_html( (string) $f['record']['data']['expression'] ) . '</code></li>';
			}
		}
		return '' === $li ? '' : '<details class="rbx-calc__formula"><summary>' . esc_html__( 'Formula used', 'rbx-tools' ) . '</summary><ul>' . $li . '</ul></details>';
	}

	/** @return array<string, string> */
	public static function strings(): array {
		return array(
			'na'          => __( 'Not available yet', 'rbx-tools' ),
			'recheck'     => __( 'Being re-verified', 'rbx-tools' ),
			'unknown'     => __( 'Not known yet for this pick', 'rbx-tools' ),
			'result'      => __( 'Result:', 'rbx-tools' ),
			'copied'      => __( 'Link copied', 'rbx-tools' ),
			'copyFail'    => __( 'Copy failed. Select the address bar and copy it.', 'rbx-tools' ),
			'reset'       => __( 'Some items in this link no longer exist and were reset', 'rbx-tools' ),
			'newer'       => __( 'This link was made by a newer version of the calculator, so defaults are shown.', 'rbx-tools' ),
			'clamped'     => __( 'A number in this link was out of range and was adjusted.', 'rbx-tools' ),
			'empty'       => __( 'Enter a number.', 'rbx-tools' ),
			'invalid'     => __( 'Use digits, like 1500 or 1.5K.', 'rbx-tools' ),
			'ambiguous'   => __( 'Use a dot for decimals, like 1.5.', 'rbx-tools' ),
			'negative'    => __( 'The number cannot be negative.', 'rbx-tools' ),
			'not_integer' => __( 'Use a whole number.', 'rbx-tools' ),
			'too_big'     => __( 'That number is too big.', 'rbx-tools' ),
			'too_small'   => __( 'That number is too small.', 'rbx-tools' ),
		);
	}

	/**
	 * Mini calculator after the item data card for records a tool takes as its first ref input.
	 *
	 * @param array<string, mixed> $rec Record.
	 */
	public static function mini_for_item( string $html, Game $game, array $rec, Dataset $ds ): string {
		foreach ( $game->tools() as $t ) {
			foreach ( (array) $t['inputs'] as $in ) {
				if ( 'ref' === ( $in['type'] ?? '' ) ) {
					if ( ( $in['target'] ?? '' ) === $rec['entity'] && in_array( $rec['status'], array( 'live', 'limited' ), true ) ) {
						return $html . self::render( $game->key(), (string) $t['key'], array( (string) $in['key'] => (string) $rec['id'] ), true );
					}
					break;
				}
			}
		}
		return $html;
	}
}
