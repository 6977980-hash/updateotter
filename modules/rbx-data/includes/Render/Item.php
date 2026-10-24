<?php
/**
 * Item page (UX-07, CONT-23, DATA-22b): every block renders from data on the server (DATA-28);
 * a block with no data is omitted entirely.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Game;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Changelog;
use RBX\Data\Gate\PageState;

defined( 'ABSPATH' ) || exit;

/**
 * Item and update shells.
 */
final class Item {

	/** H1 that names the game too (UO-19): "Chicken (Steal an Egg)", "Golden mutation in Steal an Egg". */
	public static function heading( Game $game, array $rec ): string {
		$name = (string) $rec['name'];
		if ( 'MUT' === $rec['entity'] ) {
			/* translators: 1: mutation, 2: game */
			return sprintf( __( '%1$s mutation in %2$s', 'rbx-data' ), (string) preg_replace( '/\s+mutation$/i', '', $name ), $game->name() );
		}
		/* translators: 1: item, 2: game */
		return sprintf( __( '%1$s (%2$s)', 'rbx-data' ), $name, $game->name() );
	}


	/**
	 * @return array{game: Game, ds: Dataset, rec: array<string, mixed>, post_id: int}|null
	 */
	public static function context( int $post_id ): ?array {
		$found = Repository::for_post( $post_id );
		if ( null === $found ) {
			return null;
		}
		[ $game, $rec ] = $found;
		return array(
			'game'    => $game,
			'ds'      => Repository::dataset( $game ),
			'rec'     => $rec,
			'post_id' => $post_id,
		);
	}

	/** Required fields shown on the page, for the trust strip. */
	public static function page_last_verified( int $post_id ): ?string {
		$v = (string) get_post_meta( $post_id, '_rbx_page_last_verified', true );
		return '' === $v ? null : $v;
	}

	/**
	 * Full item page body (inside <main>).
	 *
	 * @param array{game: Game, ds: Dataset, rec: array<string, mixed>, post_id: int} $c Context.
	 */
	public static function render( array $c, string $editorial ): string {
		$game = $c['game'];
		$ds   = $c['ds'];
		$rec  = $c['rec'];
		$pid  = $c['post_id'];
		\RBX\Data\Cache\Purge::tag_game( $game );
		\RBX\Data\Cache\Purge::tag_record( (string) $rec['id'] );

		$out  = '<article class="rbx-item rbx-item--' . esc_attr( strtolower( (string) $rec['entity'] ) ) . '">';
		$out .= '<header class="rbx-item__head"><h1>' . esc_html( self::heading( $game, $rec ) ) . '</h1>';
		$out .= Ui::byline( $pid, (string) ( $rec['data']['notes_reviewed_by'] ?? '' ) ) . '</header>';
		$out .= self::removed_banner( $ds, $rec );
		$out .= self::summary( $ds, $rec, $pid );
		$out .= self::card( $c );
		$out .= (string) apply_filters( 'rbx_data_item_after_card', '', $game, $rec, $ds );
		$out .= self::screenshots( $ds, $rec );
		$out .= self::where_to_get( $ds, $rec );
		$ins  = Insights::render( $ds, $rec );
		$out .= $ins;
		$out .= self::notes( $ds, $rec, $editorial );
		$out .= self::how_made( $ds, $rec, $pid, '' !== $ins );
		$out .= self::comparison( $ds, $rec );
		$out .= self::aliases( $rec );
		ob_start();
		do_action( 'updateotter_ad_slot', 'mid-content' );
		$out .= (string) ob_get_clean();
		$out .= self::history( $ds, $rec );
		$out .= self::related( $ds, $rec );
		return $out . '</article>';
	}

	/** DATA-32 / UX-45. */
	private static function removed_banner( Dataset $ds, array $rec ): string {
		if ( 'removed_from_game' !== $rec['status'] ) {
			return '';
		}
		$upd = $ds->get( (string) ( $rec['data']['removed_in_update'] ?? '' ) );
		$txt = $upd
			/* translators: %s: update name with link */
			? sprintf( esc_html__( 'Removed in %s. The data below is kept for reference.', 'rbx-data' ), Ui::link( $upd ) )
			: esc_html__( 'Removed from the game. The data below is kept for reference.', 'rbx-data' );
		return '<p class="rbx-banner rbx-banner--removed" role="note">' . $txt . '</p>';
	}

	/**
	 * Answer-first summary (CONT-11): the editor's excerpt, else labelled data only (DATA-25b).
	 */
	private static function summary( Dataset $ds, array $rec, int $pid ): string {
		$ex = (string) get_post_field( 'post_excerpt', $pid );
		if ( '' !== trim( $ex ) ) {
			return '<p class="rbx-summary">' . esc_html( $ex ) . '</p>';
		}
		$game  = $ds->game();
		$parts = array();
		$lead  = self::lead( $pid );
		$rar   = $ds->get( (string) ( $rec['data']['rarity_id'] ?? '' ) );
		if ( $rar ) {
			$parts[] = esc_html( $game->label( (string) $rec['entity'], 'rarity_id' ) ) . ': ' . esc_html( (string) $rar['name'] );
		}
		if ( 'PET' === $rec['entity'] ) {
			$eggs = array();
			foreach ( $ds->children( 'DRP', 'pet_id', (string) $rec['id'] ) as $d ) {
				$egg = $ds->get( (string) $d['data']['egg_id'] );
				if ( $egg ) {
					$eggs[ $egg['id'] ] = Ui::link( $egg );
				}
			}
			if ( $eggs ) {
				$parts[] = esc_html__( 'Hatches from', 'rbx-data' ) . ': ' . implode( ', ', $eggs );
			}
		}
		if ( 'EGG' === $rec['entity'] && ! empty( $rec['data']['biome_ids'] ) ) {
			$parts[] = esc_html( $game->label( 'EGG', 'biome_ids' ) ) . ': ' . Ui::raw( $ds, array( 'type' => 'ref_list' ), $rec['data']['biome_ids'] );
		}
		if ( 'MUT' === $rec['entity'] ) {
			$st = $ds->field_state( $rec, 'income_mult' );
			if ( 'verified' === $st['state'] && null !== $st['value'] ) {
				$parts[] = esc_html( $game->label( 'MUT', 'income_mult' ) ) . ': ' . Ui::raw( $ds, array( 'type' => 'mult' ), $st['value'] );
			}
		}
		return ( '' !== $lead ? '<p class="rbx-lead">' . esc_html( $lead ) . '</p>' : '' ) . ( $parts ? '<p class="rbx-summary">' . implode( ' <span aria-hidden="true">·</span> ', $parts ) . '</p>' : '' );
	}

	/** One plain sentence with the main number under the H1 (UO-23), the first sentence of the meta description. */
	private static function lead( int $pid ): string {
		$d = Seo::item_description( $pid );
		return preg_match( '/^(.+?\.)(?:\s|$)/u', $d, $m ) && ! str_starts_with( $m[1], 'Stats, sources' ) ? $m[1] : '';
	}

	/** Data card: icon, chips, stats with states, trust strip, calculator link, report (UX-07). */
	private static function card( array $c ): string {
		$game = $c['game'];
		$ds   = $c['ds'];
		$rec  = $c['rec'];
		$cols = $game->columns( (string) $rec['entity'] );
		$stats = array();
		foreach ( (array) ( $game->entity( (string) $rec['entity'] )['fields'] ?? array() ) as $f => $def ) {
			if ( ! empty( $def['internal'] ) || ! empty( $def['editorial'] ) || ! empty( $def['opinion'] ) || 'aliases' === $f || 'text' === ( $def['type'] ?? '' ) && 'obtain_method_text__en' !== $f
				|| ! empty( $cols[ $f ]['currency_of'] ) || str_ends_with( $f, 'currency_id' ) || 'visual_color_hex' === $f || 'json' === ( $def['type'] ?? '' ) ) {
				continue;
			}
			$st = $ds->field_state( $rec, $f );
			// GR-11: an empty stat is shown only when it matters for this item; one no item has is hidden.
			if ( 'unknown' === $st['state'] && ( ( empty( $def['required_for_index'] ) && empty( $def['compare'] ) ) || ! $ds->field_has_values( (string) $rec['entity'], $f ) ) ) {
				continue;
			}
			$known   = 'unknown' !== $st['state'] && 'disputed' !== $st['state'] && null !== $st['value'];
			$stats[] = array(
				'label' => (string) ( $def['label__en'] ?? $f ),
				'value' => Ui::value( $ds, $rec, $f, false ),
				'full'  => Ui::value( $ds, $rec, $f ),
				'chip'  => $known ? Ui::chip( $st, $ds ) : null,
				'lv'    => $st['last_verified'] ? '<span class="rbx-lv">' . esc_html( Ui::as_of_label( $st ) ) . ' ' . Ui::date( (string) $st['last_verified'] ) . '</span>' : '',
			);
		}
		// UO-69: when every known stat has the same source and date, say it once under the stats
		// instead of repeating the same chips on every row. Mixed sources keep their own chips.
		$marks  = array_unique( array_map( static fn( $x ) => $x['chip'] . '|' . $x['lv'], array_filter( $stats, static fn( $x ) => null !== $x['chip'] ) ) );
		$shared = count( array_filter( $stats, static fn( $x ) => null !== $x['chip'] ) ) >= 2 && 1 === count( $marks );
		$rows   = '';
		$common = '';
		foreach ( $stats as $x ) {
			if ( $shared && null !== $x['chip'] ) {
				$rows  .= '<div class="rbx-stat"><dt>' . esc_html( $x['label'] ) . '</dt><dd>' . $x['value'] . '</dd></div>';
				$common = $x['chip'] . $x['lv'];
			} else {
				$rows .= '<div class="rbx-stat"><dt>' . esc_html( $x['label'] ) . '</dt><dd>' . $x['full'] . ( null !== $x['chip'] ? $x['lv'] : '' ) . '</dd></div>';
			}
		}
		$chips = '';
		$rar   = $ds->get( (string) ( $rec['data']['rarity_id'] ?? '' ) );
		if ( $rar ) {
			$col    = (string) ( $rar['data']['ui_color_hex'] ?? '' );
			$chips .= '<span class="rbx-chip rbx-chip--rarity"' . ( preg_match( '/^#[0-9A-Fa-f]{6}$/', $col ) ? ' style="--chip:' . esc_attr( $col ) . '"' : '' ) . '>' . esc_html( (string) $rar['name'] ) . '</span>';
		}
		if ( ! empty( $rec['data']['tier_list_rank'] ) ) {
			/* translators: %s: tier letter */
			$chips .= ' <a class="rbx-chip rbx-chip--tier" href="' . esc_url( home_url( '/' . $game->folder() . '/tier-list/' ) ) . '">' . esc_html( sprintf( __( '%s tier', 'rbx-data' ), (string) $rec['data']['tier_list_rank'] ) ) . '</a>';
		}
		if ( ! empty( $rec['data']['is_secret'] ) ) {
			$chips .= ' <span class="rbx-chip">' . esc_html__( 'Secret', 'rbx-data' ) . '</span>';
		}
		$out  = '<section class="rbx-card" aria-label="' . esc_attr__( 'Stats', 'rbx-data' ) . '">';
		$out .= '<div class="rbx-card__top"><div class="rbx-card__icon">' . Ui::icon( $ds, $rec, 128 ) . '</div><div class="rbx-card__chips">' . $chips . '</div></div>';
		if ( '' !== $rows ) {
			$out .= '<h2 id="stats" class="rbx-card__title">' . esc_html__( 'Stats', 'rbx-data' ) . '</h2><dl class="rbx-stats">' . $rows . '</dl>';
			if ( '' !== $common ) {
				$out .= '<p class="rbx-stats__src">' . esc_html__( 'All stats above:', 'rbx-data' ) . ' ' . $common . '</p>';
			}
		}
		$out .= Ui::trust_strip( $game, (string) get_post_meta( $c['post_id'], '_rbx_last_changed', true ), self::page_last_verified( $c['post_id'] ) );
		$out .= Ui::license_line( $game );
		$calc = self::calculator_link( $game, $rec );
		if ( '' !== $rows ) {
			$out .= Ui::vote_box( $game, $rec );
		}
		$out .= '<div class="rbx-card__actions">' . $calc . Ui::report_button( $game, array( 'record_id' => (string) $rec['id'] ) ) . '</div>';
		return $out . '</section>';
	}

	/** "Use in calculator" deep link with fragment state (ENG-33). */
	public static function calculator_link( Game $game, array $rec ): string {
		foreach ( $game->tools() as $tool ) {
			foreach ( (array) ( $tool['inputs'] ?? array() ) as $in ) {
				if ( 'ref' === ( $in['type'] ?? '' ) && ( $in['target'] ?? '' ) === $rec['entity'] && ! empty( $rec['slug'] ) ) {
					$url = home_url( '/' . $game->folder() . '/' . ( $tool['path'] ?? $tool['key'] ) . '/' ) . '#v=1&' . rawurlencode( (string) $in['key'] ) . '=' . rawurlencode( (string) $rec['slug'] );
					/* translators: %s: item name */
					return '<a class="button rbx-calc-link" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Open full calculator with %s', 'rbx-data' ), (string) $rec['name'] ) ) . '</a>';
				}
			}
		}
		return '';
	}

	/** Verified screenshots with caption and credit (DATA-22b block 5, POL-48e). */
	private static function screenshots( Dataset $ds, array $rec ): string {
		$items = '';
		$own   = true;
		foreach ( array_slice( $ds->media_for( (string) $rec['id'] ), 0, 4 ) as $m ) {
			$d   = $m['data'];
			$img = Media::img( (string) $d['file_name'], (string) $d['alt__en'], 800, 'rbx-shot', true );
			if ( '' === $img ) {
				continue;
			}
			$cap = '' !== (string) ( $d['caption__en'] ?? '' ) ? esc_html( (string) $d['caption__en'] ) . '. ' : '';
			if ( 'wiki_cc' === $d['image_source'] ) {
				$own   = false;
				$cap  .= self::wiki_credit( $d );
			} else {
				$upd   = $ds->get( (string) ( $d['captured_in_update_id'] ?? '' ) );
				$cap   = rtrim( $cap, '. ' ) . ( '' !== $cap ? ' ' : '' );
				$cap  .= 'own_screenshot' === $d['image_source'] ? esc_html__( '(in-game screenshot)', 'rbx-data' ) . ' ' : '';
				/* translators: 1: credit, 2: date */
				$cap  .= sprintf( esc_html__( 'Credit: %1$s, captured %2$s', 'rbx-data' ), esc_html( (string) $d['image_credit'] ), Ui::date( (string) $d['captured_on'] ) );
				$cap  .= $upd ? ' (' . Ui::link( $upd ) . ')' : '';
			}
			$items .= '<figure class="rbx-figure">' . $img . '<figcaption>' . $cap . '</figcaption></figure>';
		}
		if ( '' === $items ) {
			return '';
		}
		return '<section class="rbx-shots">' . Ui::h2( $own ? __( 'Screenshots', 'rbx-data' ) : __( 'Images', 'rbx-data' ), 'screenshots' ) . $items . '</section>';
	}

	/**
	 * Credit line for a wiki image (Phase E, owner decision 2026-10-24): uploader, licence and a
	 * link to the wiki file page, as CC BY-SA asks. Also used by the image credits page.
	 *
	 * @param array<string, mixed> $d Media row.
	 */
	public static function wiki_credit( array $d ): string {
		$lic = (string) ( $d['license_note'] ?? '' );
		$lic = str_starts_with( $lic, 'CC BY-SA 3.0' )
			? '<a href="https://creativecommons.org/licenses/by-sa/3.0/" rel="license nofollow">CC BY-SA 3.0</a>' . esc_html( substr( $lic, strlen( 'CC BY-SA 3.0' ) ) )
			: esc_html( $lic );
		/* translators: 1: uploader and wiki, 2: licence, 3: link to the wiki file page, 4: upload date */
		return sprintf(
			esc_html__( 'Image: %1$s, %2$s, %3$s, uploaded %4$s. Not our screenshot.', 'rbx-data' ),
			esc_html( (string) $d['image_credit'] ),
			$lic,
			'<a href="' . esc_url( (string) $d['source_url'] ) . '" rel="nofollow">' . esc_html__( 'wiki file page', 'rbx-data' ) . '</a>',
			Ui::date( (string) $d['captured_on'] )
		);
	}

	/** Where to get it (DATA-22b block 4); odds only from official/observed sources (CONT-22). */
	private static function where_to_get( Dataset $ds, array $rec ): string {
		$game = $ds->game();
		$li   = '';
		if ( 'PET' === $rec['entity'] ) {
			foreach ( $ds->children( 'SRC', 'pet_id', (string) $rec['id'] ) as $s ) {
				$ref  = $ds->get( (string) ( $s['data']['source_ref_id'] ?? '' ) );
				$line = '<strong>' . esc_html( Ui::enum_label( (string) $s['data']['source_kind'] ) ) . '</strong>' . ( $ref ? ': ' . Ui::link( $ref ) : '' );
				if ( $ref && 'EGG' === $ref['entity'] ) {
					foreach ( $ds->children( 'DRP', 'egg_id', (string) $ref['id'] ) as $d ) {
						if ( $d['data']['pet_id'] === $rec['id'] && self::odds_known( $ds ) ) {
							$line .= ', ' . self::odds( $ds, $d );
						}
					}
				}
				if ( ! empty( $s['data']['note__en'] ) ) {
					$line .= '. ' . Ui::text( (string) $s['data']['note__en'] );
				}
				$li .= '<li>' . $line . '</li>';
			}
		} elseif ( 'EGG' === $rec['entity'] ) {
			$drops = $ds->children( 'DRP', 'egg_id', (string) $rec['id'] );
			if ( $drops ) {
				// GR-11: no odds column while no egg has odds, and no condition column while no drop has one.
				$odds = self::odds_known( $ds );
				$cond = (bool) array_filter( $drops, static fn( $d ) => '' !== trim( (string) ( $d['data']['condition_text__en'] ?? '' ) ) );
				$rows = '';
				foreach ( $drops as $d ) {
					$pet   = $ds->get( (string) $d['data']['pet_id'] );
					$rows .= '<tr><th scope="row">' . Ui::link( $pet ) . '</th>' . ( $odds ? '<td>' . self::odds( $ds, $d ) . '</td>' : '' ) . ( $cond ? '<td>' . ( '' !== trim( (string) ( $d['data']['condition_text__en'] ?? '' ) ) ? Ui::text( (string) $d['data']['condition_text__en'] ) : Ui::dash() ) . '</td>' : '' ) . '</tr>';
				}
				$table = '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html__( 'Pets that can hatch from this egg', 'rbx-data' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Pet', 'rbx-data' ) . '</th>' . ( $odds ? '<th scope="col">' . esc_html__( 'Chance', 'rbx-data' ) . '</th>' : '' ) . ( $cond ? '<th scope="col">' . esc_html__( 'Condition', 'rbx-data' ) . '</th>' : '' ) . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
				$out   = '<section class="rbx-where">' . Ui::h2( __( 'What hatches from it', 'rbx-data' ), 'what-hatches' ) . $table;
				if ( ! $odds ) {
					$out .= '<p class="rbx-note">' . esc_html__( 'The hatch chances for these pets are not published yet.', 'rbx-data' ) . '</p>';
				}
				return $out . '</section>';
			}
		} elseif ( 'MUT' === $rec['entity'] && ! empty( $rec['data']['obtain_method_text__en'] ) ) {
			$li .= '<li>' . Ui::text( (string) $rec['data']['obtain_method_text__en'] ) . '</li>';
		}
		if ( '' === $li ) {
			return '';
		}
		return '<section class="rbx-where">' . Ui::h2( __( 'How to get it', 'rbx-data' ), 'how-to-get' ) . '<ul>' . $li . '</ul></section>';
	}

	/** Whether any egg drop of the game has official or observed odds (GR-11). */
	private static function odds_known( Dataset $ds ): bool {
		static $known = array();
		$k = spl_object_id( $ds );
		if ( ! isset( $known[ $k ] ) ) {
			$known[ $k ] = false;
			foreach ( $ds->all( 'DRP' ) as $d ) {
				$st = $ds->field_state( $d, 'chance_prob' );
				if ( ( 'verified' === $st['state'] && 'official' === $st['source_type'] && is_numeric( $st['value'] ) ) || ( is_numeric( $d['data']['observed_rate'] ?? null ) && (int) ( $d['data']['sample_size'] ?? 0 ) > 0 ) ) {
					$known[ $k ] = true;
					break;
				}
			}
		}
		return $known[ $k ];
	}

	/**
	 * Chance text: verified official odds, or "Observed in our {n} hatches", else "Odds not published".
	 *
	 * @param array<string, mixed> $d DRP record.
	 */
	public static function odds( Dataset $ds, array $d ): string {
		$st = $ds->field_state( $d, 'chance_prob' );
		if ( 'verified' === $st['state'] && 'official' === $st['source_type'] && is_numeric( $st['value'] ) ) {
			return Ui::number( $ds, $st['value'], 'prob' ) . ' ' . Ui::chip( $st, $ds );
		}
		if ( isset( $d['data']['observed_rate'], $d['data']['sample_size'] ) && is_numeric( $d['data']['observed_rate'] ) && (int) $d['data']['sample_size'] > 0 ) {
			/* translators: 1: rate, 2: number of hatches */
			return sprintf( esc_html__( '%1$s observed in our %2$s hatches', 'rbx-data' ), Ui::number( $ds, $d['data']['observed_rate'], 'prob' ), esc_html( number_format_i18n( (int) $d['data']['sample_size'] ) ) );
		}
		return '<span class="rbx-v rbx-v--unknown">' . esc_html__( 'Odds not published', 'rbx-data' ) . '</span>';
	}

	/** Human-written block (DATA-22b block 7) plus any editorial blocks in the shell. */
	private static function notes( Dataset $ds, array $rec, string $editorial ): string {
		$notes = (string) ( $rec['data']['notes__en'] ?? '' );
		if ( '' === trim( $notes ) && '' === trim( wp_strip_all_tags( $editorial ) ) ) {
			return '';
		}
		$out = '<section class="rbx-notes">' . Ui::h2( __( 'Our notes', 'rbx-data' ), 'notes' );
		if ( '' !== trim( $notes ) ) {
			$out .= wpautop( Ui::text( $notes ) );
		}
		return $out . $editorial . '</section>';
	}

	/**
	 * "How this page was made" (GR-16): where the numbers come from, and whether the notes were
	 * drafted with AI help and reviewed, in the words the methodology page uses.
	 */
	private static function how_made( Dataset $ds, array $rec, int $pid, bool $worked ): string {
		// GR-01: one short line; the long version is on /methodology/#ai.
		$parts = array(
			$worked
				? esc_html__( 'stats from the community wiki, tables worked out with our calculator formula', 'rbx-data' )
				: esc_html__( 'stats from the community wiki', 'rbx-data' ),
		);
		if ( array_filter( $ds->media_for( (string) $rec['id'] ), static fn( $m ) => 'own_screenshot' === ( $m['data']['image_source'] ?? '' ) ) ) {
			$parts[] = esc_html__( 'our own in-game screenshot', 'rbx-data' );
		}
		$notes = '';
		if ( '' !== trim( (string) ( $rec['data']['notes__en'] ?? '' ) ) ) {
			$by     = strtolower( trim( (string) ( $rec['data']['notes_reviewed_by'] ?? '' ) ) );
			$on     = (string) ( $rec['data']['notes_reviewed_on'] ?? '' );
			$author = Ui::author( $pid );
			if ( '' !== $by && in_array( $by, Ui::reviewer_ids(), true ) && $author ) {
				$notes = '' !== $on
					/* translators: 1: reviewer name, 2: date */
					? sprintf( esc_html__( 'notes drafted with AI help, reviewed by %1$s on %2$s', 'rbx-data' ), esc_html( $author['name'] ), Ui::date( $on ) )
					/* translators: %s: reviewer name */
					: sprintf( esc_html__( 'notes drafted with AI help, reviewed by %s', 'rbx-data' ), esc_html( $author['name'] ) );
			} else {
				$notes = esc_html__( 'notes drafted with AI help, not reviewed yet', 'rbx-data' );
			}
		}
		$text = implode( ', ', $parts ) . ( '' !== $notes ? '; ' . $notes : '' ) . '.';
		return '<p class="rbx-how-made"><strong>' . esc_html__( 'How this page was made:', 'rbx-data' ) . '</strong> ' . $text . ' <a href="' . esc_url( home_url( '/methodology/#ai' ) ) . '">' . esc_html__( 'How we work', 'rbx-data' ) . '</a></p>';
	}


	/** Sibling comparison (DATA-22b block 3): deterministic nearest by stat; hidden below 2 siblings. */
	private static function comparison( Dataset $ds, array $rec ): string {
		$game    = $ds->game();
		$def     = $game->entity( (string) $rec['entity'] ) ?? array();
		$compare = array();
		foreach ( (array) ( $def['fields'] ?? array() ) as $f => $fd ) {
			if ( ! empty( $fd['compare'] ) ) {
				$compare[] = $f;
			}
		}
		$sib = PageState::find_siblings( $ds, $rec, $compare, (array) ( $def['siblings_by'] ?? array() ), 5 );
		if ( count( $sib ) < 2 ) {
			return '';
		}
		$field = (string) reset( $sib )['field'];
		$fdef  = $game->field( (string) $rec['entity'], $field ) ?? array();
		$all   = array( (string) $rec['id'] => $rec );
		foreach ( $sib as $id => $s ) {
			$all[ $id ] = $s['record'];
		}
		uasort( $all, static fn( $a, $b ) => ( (float) $ds->field_state( $b, $field )['value'] <=> (float) $ds->field_state( $a, $field )['value'] ) ?: strcmp( (string) $a['id'], (string) $b['id'] ) );
		$rows = '';
		foreach ( $all as $id => $r ) {
			$me    = $id === $rec['id'];
			$rows .= '<tr' . ( $me ? ' class="rbx-row--current" aria-current="true"' : '' ) . '><th scope="row">' . ( $me ? esc_html( (string) $r['name'] ) : Ui::link( $r ) ) . '</th><td class="rbx-num-cell">' . Ui::raw( $ds, $fdef, $ds->field_state( $r, $field )['value'] ) . '</td></tr>';
		}
		$by    = (array) ( $def['siblings_by'] ?? array() );
		$group = $by ? $ds->get( is_string( $rec['data'][ (string) $by[0] ] ?? null ) ? (string) $rec['data'][ (string) $by[0] ] : '' ) : null;
		$cap   = sprintf(
			/* translators: 1: stat label, 2: group name such as "Legendary" (may be empty), 3: plural entity label */
			__( '%1$s compared with other %2$s%3$s', 'rbx-data' ),
			(string) ( $fdef['label__en'] ?? $field ),
			$group ? $group['name'] . ' ' : '',
			strtolower( $game->entity_label( (string) $rec['entity'], true ) )
		);
		return '<section class="rbx-compare">' . Ui::h2( __( 'Compared with similar items', 'rbx-data' ), 'compare' ) . '<div class="rbx-table-wrap"><table class="rbx-table"><caption>' . esc_html( $cap ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Name', 'rbx-data' ) . '</th><th scope="col">' . esc_html( (string) ( $fdef['label__en'] ?? $field ) ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
	}

	private static function aliases( array $rec ): string {
		$a = trim( (string) ( $rec['data']['aliases'] ?? '' ) );
		if ( '' === $a ) {
			return '';
		}
		return '<section class="rbx-aliases">' . Ui::h2( __( 'Also called', 'rbx-data' ), 'also-called' ) . '<p>' . esc_html( $a ) . '</p></section>';
	}

	/** Public change history for this record, newest first, corrections labelled (DATA-22b block 6). */
	private static function history( Dataset $ds, array $rec ): string {
		$ids = array( (string) $rec['id'] );
		foreach ( array( array( 'DRP', 'egg_id' ), array( 'DRP', 'pet_id' ), array( 'SRC', 'pet_id' ) ) as $rel ) {
			if ( $ds->game()->entity( $rel[0] ) ) {
				$ids = array_merge( $ids, array_keys( $ds->children( $rel[0], $rel[1], (string) $rec['id'] ) ) );
			}
		}
		$rows = array_filter( Changelog::for_records( $ids, 60 ), static fn( $r ) => Ui::public_change( $ds, $r ) );
		if ( ! $rows ) {
			return '';
		}
		// UO-69: the drop and source rows of a new item were logged as their own "added" lines
		// ("Chicken added / added / added"); the item's own line is enough, and repeats go.
		$own  = array_filter( $rows, static fn( $r ) => 'create' === $r['change_kind'] && $r['record_id'] === $rec['id'] );
		$li   = '';
		$seen = array();
		foreach ( $rows as $r ) {
			if ( 'create' === $r['change_kind'] && $r['record_id'] !== $rec['id'] && $own ) {
				continue;
			}
			$line = Ui::change_line( $ds, $r );
			if ( isset( $seen[ $line ] ) ) {
				continue;
			}
			$seen[ $line ] = true;
			$li           .= '<li>' . $line . '</li>';
			if ( count( $seen ) >= 20 ) {
				break;
			}
		}
		// GR-01: "X added to UpdateOtter" alone is the same line on every page; the history shows
		// once something about the item has changed since it was added.
		if ( 1 === count( $seen ) && $own && 'create' === reset( $own )['change_kind'] && Ui::change_line( $ds, reset( $own ) ) === array_key_first( $seen ) ) {
			return '';
		}
		return '<section class="rbx-changes">' . Ui::h2( __( 'Change history', 'rbx-data' ), 'change-history' ) . '<ul>' . $li . '</ul></section>';
	}

	/** 3–6 related items picked automatically: same egg, then same rarity (CONT-32). */
	private static function related( Dataset $ds, array $rec ): string {
		$picked = array();
		if ( 'PET' === $rec['entity'] ) {
			foreach ( $ds->children( 'DRP', 'pet_id', (string) $rec['id'] ) as $d ) {
				foreach ( $ds->children( 'DRP', 'egg_id', (string) $d['data']['egg_id'] ) as $d2 ) {
					$pid = (string) $d2['data']['pet_id'];
					if ( $pid !== $rec['id'] ) {
						$picked[ $pid ] = true;
					}
				}
			}
		}
		$group = (array) ( $ds->game()->entity( (string) $rec['entity'] )['siblings_by'] ?? array() );
		foreach ( $ds->all( (string) $rec['entity'], array( 'live', 'limited' ) ) as $id => $r ) {
			if ( count( $picked ) >= 6 ) {
				break;
			}
			if ( $id === $rec['id'] ) {
				continue;
			}
			$same = true;
			foreach ( $group as $g ) {
				$same = $same && ( $r['data'][ $g ] ?? null ) === ( $rec['data'][ $g ] ?? null );
			}
			if ( $same ) {
				$picked[ $id ] = true;
			}
		}
		// Pages that can rank come first (UO-25): indexable picks, then other indexable items of the
		// same kind nearest in list order, then the remaining picks.
		$indexable = static fn( array $r ): bool => ! empty( $r['post_id'] ) && PageState::INDEX === get_post_meta( (int) $r['post_id'], '_rbx_page_state', true );
		$first     = array();
		$rest      = array();
		foreach ( array_keys( $picked ) as $id ) {
			$r = $ds->get( (string) $id );
			if ( $r && $indexable( $r ) ) {
				$first[ $id ] = true;
			} else {
				$rest[ $id ] = true;
			}
		}
		if ( count( $first ) < 6 ) {
			$ids  = array_map( 'strval', array_keys( $ds->all( (string) $rec['entity'], array( 'live', 'limited' ) ) ) );
			$pos  = array_flip( $ids );
			$here = (int) ( $pos[ (string) $rec['id'] ] ?? 0 );
			usort( $ids, static fn( $a, $b ) => abs( $pos[ $a ] - $here ) <=> abs( $pos[ $b ] - $here ) );
			foreach ( $ids as $id ) {
				if ( count( $first ) >= 6 ) {
					break;
				}
				$r = $ds->get( $id );
				if ( $id !== (string) $rec['id'] && $r && $indexable( $r ) ) {
					$first[ $id ] = true;
				}
			}
		}
		$picked = $first + $rest;
		$links  = array();
		foreach ( array_slice( array_keys( $picked ), 0, 6 ) as $id ) {
			$r = $ds->get( (string) $id );
			if ( $r && ! empty( $r['post_id'] ) && 'publish' === get_post_status( (int) $r['post_id'] ) ) {
				$links[] = '<li><a class="rbx-related__item" href="' . esc_url( (string) get_permalink( (int) $r['post_id'] ) ) . '">' . Ui::icon( $ds, $r, 48 ) . '<span>' . esc_html( (string) $r['name'] ) . '</span></a></li>';
			}
		}
		if ( count( $links ) < 3 ) {
			return '';
		}
		return '<section class="rbx-related">' . Ui::h2( __( 'Related', 'rbx-data' ), 'related' ) . '<ul class="rbx-grid">' . implode( '', $links ) . '</ul></section>';
	}
}
