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
		if ( $mini ) {
			// Inputs marked "mini": false (e.g. extra mutation slots) stay on the full calculator only.
			$decls = array_values( array_filter( $decls, static fn( $d ) => false !== ( $d['mini'] ?? true ) ) );
		}
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
			$results .= '<div class="rbx-calc__out" data-rbx-output="' . esc_attr( (string) $fkey ) . '"><dt>' . esc_html( \RBX\Data\Content\Lang::t( (string) ( $d['label__en'] ?? $fkey ) ) ) . '</dt><dd>' . self::value_html( $res, $format, $ladder, \RBX\Data\Content\Lang::t( (string) ( $d['output_unit__en'] ?? '' ) ) ) . '</dd></div>';
			$item     = array(
				'key'    => (string) $fkey,
				'label'  => \RBX\Data\Content\Lang::t( (string) ( $d['label__en'] ?? $fkey ) ),
				'unit'   => \RBX\Data\Content\Lang::t( (string) ( $d['output_unit__en'] ?? '' ) ),
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
			'locale'   => \RBX\Data\Content\Lang::num(),
			'ladder'   => $ladder,
			'slices'   => $slices,
			'beacon'   => rest_url( 'rbx/v1/beacon' ),
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

		// GR-25: the date of the data the tool uses (the oldest wiki copy date of its records), not a page field.
		// The version carries the date our file was rebuilt, which can be later; say so, so the two dates don't look contradictory.
		$asof  = self::data_date( $ds, $decls );
		$built = preg_match( '/-(\d{4})(\d{2})(\d{2})-/', (string) $manifest['version'], $bm ) ? $bm[1] . '-' . $bm[2] . '-' . $bm[3] : '';
		$meta  = '' !== $asof && '' !== $built
			/* translators: 1: date, 2: dataset version, 3: date */
			? sprintf( esc_html__( 'Wiki data as of %1$s. Our data file: version %2$s, rebuilt %3$s.', 'rbx-tools' ), self::time_tag( $asof ), esc_html( $manifest['version'] ), self::time_tag( $built ) )
			: ( '' !== $asof
				/* translators: 1: date, 2: dataset version */
				? sprintf( esc_html__( 'Wiki data as of %1$s (data version %2$s)', 'rbx-tools' ), self::time_tag( $asof ), esc_html( $manifest['version'] ) )
				/* translators: %s: dataset version */
				: sprintf( esc_html__( 'Data version %s', 'rbx-tools' ), esc_html( $manifest['version'] ) ) );
		// Date of our latest in-game formula check (a live test row with a screenshot), when there is one.
		// CR-20: this line goes after the "Source: community wiki" note, so the wiki label sits with
		// the wiki data line and the in-game check is not read as wiki-sourced.
		$checked = self::checked_date( $ds );
		$tested  = '' !== $checked && ! $mini
			/* translators: %s: date */
			? '<p class="rbx-calc__meta">' . sprintf( esc_html__( 'Formula last checked in game: %s', 'rbx-tools' ), self::time_tag( $checked ) ) . '</p>'
			: '';

		// UO-41: the full calculator sits under the page H1 with the same words, so only the mini
		// calculator on item pages gets its own heading.
		$out = '<section class="rbx-calc' . ( $mini ? ' rbx-calc--mini' : '' ) . '" id="' . esc_attr( $id ) . '"'
			. ( $mini ? ' aria-labelledby="' . esc_attr( $id ) . '-t"' : ' aria-label="' . esc_attr( \RBX\Data\Content\Lang::t( (string) ( $tool['label__en'] ?? '' ) ) ) . '"' ) . ' data-rbx-calculator>'
			. ( $mini ? '<h2 class="rbx-calc__title" id="' . esc_attr( $id ) . '-t">' . esc_html__( 'Quick calculator', 'rbx-tools' ) . '</h2>' : '' )
			. '<form class="rbx-calc__form" novalidate>' . $fields . '</form>'
			. '<noscript><p class="rbx-note">' . esc_html__( 'Turn on JavaScript to change inputs; the example below is still accurate.', 'rbx-tools' ) . '</p></noscript>'
			// UO-29: only the result itself is sticky on phones; the notes below it scroll with the page.
			. '<div class="rbx-calc__result"><p class="rbx-calc__fix" hidden data-rbx-fix>' . esc_html__( 'Fix the highlighted field to update', 'rbx-tools' ) . '</p><dl class="rbx-calc__outs">' . $results . '</dl></div>'
			. '<div class="rbx-calc__info"><p class="rbx-calc__meta">' . $meta . '</p>'
			// GR-01: the mini calculator on item pages leaves this out; the page's stats card and
			// "How this page was made" line already say where the values come from.
			. ( ! $mini && $game->community_single_source() ? '<p class="rbx-calc__meta rbx-calc__unconfirmed"><strong>' . esc_html__( 'Source: community wiki.', 'rbx-tools' ) . '</strong> ' . ( $game->wiki_formula_ok()
				? esc_html__( 'The values and the formula come from the community wiki. We have not tested them in the game ourselves yet.', 'rbx-tools' )
				: esc_html__( 'Pet values come from the community wiki. The formula matched our one in-game test so far (Golden Chicken); more checks are planned.', 'rbx-tools' ) ) . '</p>' : '' )
			. $tested
			. '<p class="rbx-calc__notice" role="alert" hidden data-rbx-notice></p>'
			// The load-error box is built by calculator.js only when loading fails, so text-only readers never see it.
			. '</div>'
			. '<p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-rbx-live></p>';
		if ( ! $mini ) {
			$out .= '<div class="rbx-calc__actions" hidden data-rbx-actions><button type="button" class="button" data-rbx-copy>' . esc_html__( 'Copy link', 'rbx-tools' ) . '</button> <button type="button" class="button button-secondary" data-rbx-reset>' . esc_html__( 'Reset', 'rbx-tools' ) . '</button> <span class="rbx-live" role="status" data-rbx-copy-status></span></div>';
			$out .= self::formula_notes( $tool );
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
			} elseif ( ! empty( $d['optional'] ) && ! isset( $meta[ $k ] ) && ! isset( $d['default'] ) ) {
				$out[ $k ] = null; // Optional number: left empty.
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
					// A mutation with no known multiplier (disputed) is left out (GR-05).
					if ( 'MUT' === $r['entity'] && null !== $ds->game()->field( 'MUT', 'income_mult' ) && ! is_numeric( $r['data']['income_mult'] ?? null ) ) {
						continue;
					}
					$g              = ! empty( $d['group_by'] ) ? (string) ( $r['data'][ $d['group_by'] ] ?? '' ) : '';
					$groups[ $g ][] = $r;
					if ( $rid === $value ) {
						$seed[ $target ][ $rid ] = $ds->calc_record( $r );
					}
				}
				$opts  = ! empty( $d['optional'] ) ? '<option value="">' . esc_html( \RBX\Data\Content\Lang::t( (string) ( $d['none_label__en'] ?? __( 'None', 'rbx-tools' ) ) ) ) . '</option>' : '';
				$count = array_sum( array_map( 'count', $groups ) );
				$popular = '';
				foreach ( $groups as $g => $recs ) {
					$o = '';
					foreach ( $recs as $r ) {
						$text = self::option_text( $r );
						$o   .= '<option value="' . esc_attr( (string) $r['slug'] ) . '" data-id="' . esc_attr( (string) $r['id'] ) . '"' . selected( $r['id'], $value, false ) . '>' . esc_html( $text ) . '</option>';
						// UO-32: long lists start with the items that have a full guide page.
						$pid = (int) ( $r['post_id'] ?? 0 );
						if ( $count > 30 && $pid && \RBX\Data\Gate\PageState::INDEX === get_post_meta( $pid, '_rbx_page_state', true ) ) {
							$popular .= '<option value="' . esc_attr( (string) $r['slug'] ) . '" data-id="' . esc_attr( (string) $r['id'] ) . '">' . esc_html( $text ) . '</option>';
						}
					}
					$gr    = '' !== $g ? $ds->get( $g ) : null;
					// CR-15: records without a group (e.g. lab pets with no rarity) get an "Other" group when the list is grouped.
					$label = $gr ? (string) $gr['name'] : ( count( $groups ) > 1 ? __( 'Other', 'rbx-tools' ) : '' );
					$opts .= '' !== $label ? '<optgroup label="' . esc_attr( $label ) . '">' . $o . '</optgroup>' : $o;
				}
				if ( '' !== $popular ) {
					$opts = '<optgroup label="' . esc_attr__( 'Popular', 'rbx-tools' ) . '">' . $popular . '</optgroup>' . $opts;
				}
				return '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . $label . '</label><select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" data-rbx-input="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $fid ) . '-e">' . $opts . '</select>' . $err . '</div>';
			case 'bool':
				return '<div class="rbx-calc__field rbx-calc__field--check"><input type="checkbox" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" value="1" data-rbx-input="' . esc_attr( $key ) . '"' . checked( (bool) $value, true, false ) . '> <label for="' . esc_attr( $fid ) . '">' . $label . '</label></div>';
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
				return '<div class="rbx-calc__field"><label for="' . esc_attr( $fid ) . '">' . $label . '</label><span class="rbx-calc__num"><input type="text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $shown ) . '" inputmode="' . ( ! empty( $d['integer'] ) ? 'numeric' : 'decimal' ) . '" autocomplete="off" spellcheck="false" maxlength="24" enterkeyhint="done" data-rbx-input="' . esc_attr( $key ) . '" aria-describedby="' . esc_attr( $fid ) . '-e">' . $suffix . '</span>' . $err . '</div>';
		}
	}

	/**
	 * Option text: the name, plus the multiplier for mutations and variants ("Shocked ×100", UO-32).
	 *
	 * @param array<string, mixed> $r Record.
	 */
	private static function option_text( array $r ): string {
		foreach ( array( 'income_mult', 'value_mult' ) as $f ) {
			$m = $r['data'][ $f ] ?? null;
			if ( is_int( $m ) || is_float( $m ) ) {
				return (string) $r['name'] . ' ' . \RBX\Data\Format\Number::format( $m, 'mult', \RBX\Data\Content\Lang::num() );
			}
		}
		return (string) $r['name'];
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
		$short = \RBX\Data\Format\Number::format( $v, $format, \RBX\Data\Content\Lang::num(), $ladder );
		$exact = \RBX\Data\Format\Number::exact( $v, \RBX\Data\Content\Lang::num(), $ladder );
		$html  = $short === $exact ? '<span class="rbx-calc__v" data-exact="' . esc_attr( $exact ) . '">' . esc_html( $short ) . '</span>' : '<span class="rbx-calc__v" data-exact="' . esc_attr( $exact ) . '"><span aria-hidden="true">' . esc_html( $short ) . '</span><span class="screen-reader-text">' . esc_html( $exact ) . '</span></span>';
		return $html . ( '' !== $unit ? ' <span class="rbx-calc__unit">' . esc_html( $unit ) . '</span>' : '' );
	}

	/**
	 * Oldest `last_verified` of the live records the tool's pick lists use, or ''.
	 *
	 * @param array<int, array<string, mixed>> $decls Inputs.
	 */
	private static function data_date( Dataset $ds, array $decls ): string {
		$dates = array();
		foreach ( $decls as $d ) {
			if ( 'ref' !== ( $d['type'] ?? '' ) ) {
				continue;
			}
			foreach ( $ds->all( (string) $d['target'] ) as $r ) {
				$lv = (string) ( $r['data']['last_verified'] ?? $r['last_verified'] ?? '' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $lv ) ) {
					$dates[] = substr( $lv, 0, 10 );
				}
			}
		}
		return $dates ? (string) min( $dates ) : '';
	}

	/** The newest capture date of a live formula test that has an in-game screenshot, or ''. */
	private static function checked_date( Dataset $ds ): string {
		$dates = array();
		foreach ( $ds->all( 'TST', null ) as $t ) {
			$mid = (string) ( $t['data']['evidence_media_id'] ?? '' );
			if ( 'live' !== ( $t['data']['status'] ?? '' ) || '' === $mid ) {
				continue;
			}
			$m  = $ds->get( $mid );
			$on = (string) ( $m['data']['captured_on'] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $on ) ) {
				$dates[] = substr( $on, 0, 10 );
			}
		}
		return $dates ? (string) max( $dates ) : '';
	}

	private static function time_tag( string $ymd ): string {
		return '<time datetime="' . esc_attr( $ymd ) . '">' . esc_html( wp_date( 'j M Y', strtotime( $ymd . ' 12:00 UTC' ) ) ) . '</time>';
	}

	/**
	 * "How the result is worked out" (GR-25): the plain-maths lines from the tool config
	 * (`formula_plain__en`), so players never see the formula language.
	 *
	 * @param array<string, mixed> $tool Tool config.
	 */
	private static function formula_notes( array $tool ): string {
		$li = '';
		foreach ( (array) ( $tool['formula_plain__en'] ?? array() ) as $line ) {
			$li .= '<li>' . esc_html( \RBX\Data\Content\Lang::t( (string) $line ) ) . '</li>';
		}
		return '' === $li ? '' : '<details class="rbx-calc__formula"><summary>' . esc_html__( 'How the result is worked out', 'rbx-tools' ) . '</summary><ul>' . $li . '</ul></details>';
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
			'duplicate'   => __( 'Each mutation counts once. Pick a different one.', 'rbx-tools' ),
			'badNumber'   => __( 'A number in this link could not be read, so the default is shown.', 'rbx-tools' ),
			'noWeight'    => __( 'The weight effect is not known for this pick, so the result uses the base weight.', 'rbx-tools' ),
			'find'        => __( 'Type to find', 'rbx-tools' ),
			/* translators: %s: what the visitor typed */
			'noMatch'     => __( 'Nothing called "%s" in this list. Check the spelling or pick from the list below.', 'rbx-tools' ),
			'loadError'   => __( 'The live calculator could not load. The example result shown is correct for the default inputs.', 'rbx-tools' ),
			'retry'       => __( 'Try again', 'rbx-tools' ),
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
