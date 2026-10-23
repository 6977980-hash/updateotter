<?php
/**
 * Shared render helpers: values with field states (DATA-13, UX-42), dates (DATA-29), trust strip
 * (UX-41), change box (CONT-39, UX-44), report button (UX-43), media (UX-46..51).
 * Every helper returns escaped HTML.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Game;
use RBX\Data\Data\Dataset;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Changelog;
use RBX\Data\Format\Number;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
final class Ui {

	/** Public date: `<time datetime="Y-m-d">22 Oct 2026</time>`. */
	public static function date( ?string $ymd ): string {
		if ( null === $ymd || '' === $ymd ) {
			return '';
		}
		$ymd = substr( $ymd, 0, 10 );
		$ts  = strtotime( $ymd . ' 12:00:00 UTC' );
		if ( false === $ts ) {
			return '';
		}
		return '<time datetime="' . esc_attr( $ymd ) . '">' . esc_html( wp_date( 'j M Y', $ts, new \DateTimeZone( 'UTC' ) ) ) . '</time>';
	}

	/** Plain number in the field's display format, with exact value for abbreviations. */
	public static function number( Dataset $ds, mixed $value, string $format ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return '';
		}
		$ladder = $ds->meta( 'suffix_ladder' );
		$text   = Number::format( $value, $format, 'en', is_array( $ladder ) ? $ladder : null );
		$exact  = Number::exact( $value, 'en' );
		if ( 'abbr' === $format && $text !== $exact ) {
			return '<span class="rbx-num" data-exact="' . esc_attr( $exact ) . '" title="' . esc_attr( $exact ) . '">' . esc_html( $text ) . '</span><span class="screen-reader-text"> (' . esc_html( $exact ) . ')</span>';
		}
		return '<span class="rbx-num">' . esc_html( $text ) . '</span>';
	}

	/** Display format for a field definition. */
	public static function format_of( array $def ): string {
		if ( isset( $def['display_format'] ) ) {
			return (string) $def['display_format'];
		}
		switch ( (string) ( $def['type'] ?? '' ) ) {
			case 'int':
				return 'int';
			case 'mult':
				return 'mult';
			case 'prob':
				return 'prob';
			case 'duration_s':
				return 'duration';
			default:
				return 'abbr';
		}
	}

	/** Human label for an enum value: "biome_spawn" → "Biome spawn". */
	public static function enum_label( string $v ): string {
		return ucfirst( str_replace( '_', ' ', $v ) );
	}

	/**
	 * Link to a record's page when it is generated, plain text otherwise (DATA-23b).
	 *
	 * @param array<string, mixed>|null $rec Record.
	 */
	public static function link( ?array $rec, string $class = '' ): string {
		if ( null === $rec ) {
			return '';
		}
		$name = 'UPD' === $rec['entity'] ? (string) ( $rec['data']['title__en'] ?? $rec['name'] ) : (string) $rec['name'];
		$pid  = (int) ( $rec['post_id'] ?? 0 );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return '<a href="' . esc_url( (string) get_permalink( $pid ) ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $name ) . '</a>';
		}
		return '<span' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $name ) . '</span>';
	}

	/**
	 * Raw value (no state UI) for one field: numbers formatted, refs as names, enums labelled.
	 *
	 * @param array<string, mixed> $def Field definition.
	 */
	public static function raw( Dataset $ds, array $def, mixed $value, bool $links = true ): string {
		if ( null === $value || ( is_array( $value ) && ! $value ) ) {
			return '';
		}
		switch ( (string) ( $def['type'] ?? 'string' ) ) {
			case 'ref':
				$r = $ds->get( (string) $value );
				return $links ? self::link( $r ) : esc_html( (string) ( $r['name'] ?? '' ) );
			case 'ref_list':
				$parts = array();
				foreach ( (array) $value as $id ) {
					$r = $ds->get( (string) $id );
					if ( $r ) {
						$parts[] = $links ? self::link( $r ) : esc_html( (string) $r['name'] );
					}
				}
				return implode( ', ', $parts );
			case 'enum':
				return esc_html( self::enum_label( (string) $value ) );
			case 'bool':
				return $value ? esc_html__( 'Yes', 'rbx-data' ) : esc_html__( 'No', 'rbx-data' );
			case 'text':
				return self::text( (string) $value );
			case 'date':
			case 'datetime':
				return self::date( (string) $value );
			case 'url':
				return '<a href="' . esc_url( (string) $value ) . '" rel="noopener">' . esc_html( (string) wp_parse_url( (string) $value, PHP_URL_HOST ) ) . '</a>';
			case 'string_list':
				return esc_html( implode( ', ', (array) $value ) );
			case 'json':
				return '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return self::number( $ds, $value, self::format_of( $def ) );
		}
		return esc_html( (string) $value );
	}

	/**
	 * Value with its field-state UI (UX-42). Unknown never renders as 0 or "-".
	 *
	 * @param array<string, mixed> $rec Record.
	 */
	public static function value( Dataset $ds, array $rec, string $field, bool $chip = true ): string {
		$game = $ds->game();
		$def  = $game->field( (string) $rec['entity'], $field ) ?? array( 'type' => 'string' );
		$st   = $ds->field_state( $rec, $field );
		if ( 'disputed' === $st['state'] ) {
			$items = '';
			foreach ( $st['candidates'] as $c ) {
				$src    = self::source_link( $c['source_type'] ?? null, $c['source_url'] ?? null, null );
				$items .= '<li>' . self::raw( $ds, $def, $c['value'] ) . ( '' !== $src ? ' <span class="rbx-src">' . $src . '</span>' : '' ) . '</li>';
			}
			return '<details class="rbx-dispute"><summary class="rbx-chip rbx-chip--danger">' . esc_html__( 'Conflicting reports', 'rbx-data' ) . '</summary><ul>' . $items . '</ul></details>';
		}
		if ( 'unknown' === $st['state'] || null === $st['value'] ) {
			return '<span class="rbx-v rbx-v--unknown">' . esc_html__( 'Not confirmed yet', 'rbx-data' ) . '</span>';
		}
		$html = self::raw( $ds, $def, $st['value'] );
		if ( ! empty( $def['currency_field'] ) ) {
			$cur = $ds->get( (string) ( $rec['data'][ (string) $def['currency_field'] ] ?? '' ) );
			if ( $cur ) {
				$html .= ' <span class="rbx-unit">' . esc_html( (string) $cur['name'] ) . '</span>';
			}
		}
		if ( ! $chip ) {
			return $html;
		}
		return $html . ' ' . self::chip( $st, $ds );
	}

	/**
	 * State chip plus source chip.
	 *
	 * @param array<string, mixed> $st Field state.
	 */
	public static function chip( array $st, ?Dataset $ds = null ): string {
		$tone = 'verified' === $st['state'] && $st['mechanic_ok'] ? 'success' : 'warning';
		$out  = '<span class="rbx-chip rbx-chip--' . esc_attr( $tone ) . '">' . esc_html( (string) $st['label'] ) . '</span>';
		$src  = self::source_link( $st['source_type'] ?? null, $st['source_url'] ?? null, $st['evidence_media_id'] ?? null, $ds );
		return '' === $src ? $out : $out . ' ' . $src;
	}

	/** Source chip: in_game opens the evidence screenshot, official/community link out (UX-42). */
	public static function source_link( ?string $type, ?string $url, ?string $media_id, ?Dataset $ds = null ): string {
		$label = Dataset::source_label( $type );
		if ( '' === $label ) {
			return '';
		}
		if ( 'in_game' === $type && $media_id && $ds ) {
			$m   = $ds->get( $media_id );
			$src = $m ? Media::url( (string) $m['data']['file_name'] ) : '';
			if ( '' !== $src ) {
				wp_enqueue_script_module( 'rbx-data-lightbox', RBX_DATA_URL . 'assets/front/lightbox.js', array(), RBX_DATA_VERSION );
				return '<a class="rbx-chip rbx-chip--source" href="' . esc_url( $src ) . '" data-rbx-lightbox data-credit="' . esc_attr( (string) $m['data']['image_credit'] ) . '" data-alt="' . esc_attr( (string) $m['data']['alt__en'] ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		if ( $url && 'in_game' !== $type ) {
			$tone = 'community' === $type ? ' rbx-chip--warning' : '';
			return '<a class="rbx-chip rbx-chip--source' . $tone . '" href="' . esc_url( $url ) . '" rel="noopener nofollow" target="_blank">' . esc_html( $label ) . '</a>';
		}
		return '<span class="rbx-chip rbx-chip--source">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Trust strip (UX-41): Updated · Verified · How we verify data.
	 */
	public static function trust_strip( Game $game, string $last_changed, ?string $page_last_verified ): string {
		$parts = array();
		if ( '' !== $last_changed ) {
			/* translators: %s: date */
			$parts[] = sprintf( esc_html__( 'Updated %s', 'rbx-data' ), self::date( $last_changed ) );
		}
		$stale = false;
		if ( null !== $page_last_verified && '' !== $page_last_verified ) {
			$cut   = gmdate( 'Y-m-d', (int) strtotime( current_time( 'Y-m-d' ) . ' -' . $game->freshness_days() . ' days' ) );
			$stale = $page_last_verified < $cut;
			$parts[] = $stale
				/* translators: %s: date */
				? sprintf( esc_html__( 'Last verified %s, may be out of date', 'rbx-data' ), self::date( $page_last_verified ) )
				/* translators: %s: date */
				: sprintf( esc_html__( 'Verified %s', 'rbx-data' ), self::date( $page_last_verified ) );
		}
		$parts[] = '<a href="' . esc_url( home_url( '/methodology/' ) ) . '">' . esc_html__( 'How we verify data', 'rbx-data' ) . '</a>';
		return '<p class="rbx-trust' . ( $stale ? ' rbx-trust--stale' : '' ) . '"><svg class="rbx-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4"/></svg> ' . implode( ' <span aria-hidden="true">·</span> ', $parts ) . '</p>';
	}

	/**
	 * "What changed" box from changelog rows only (CONT-39, UX-44).
	 *
	 * @param array<int, string> $ids Record IDs shown on the page.
	 */
	public static function changes( Dataset $ds, array $ids, int $limit = 5, string $heading = '' ): string {
		if ( ! $ids ) {
			return '';
		}
		$rows = array_filter(
			Changelog::for_records( $ids, $limit * 4 ),
			static fn( $r ) => ! in_array( $r['field'], Changelog::NOT_SUBSTANTIVE, true ) && ! str_ends_with( (string) $r['field'], '__en' ) && ! str_starts_with( (string) $r['field'], 'notes_' )
		);
		$rows = array_slice( array_values( $rows ), 0, $limit );
		if ( ! $rows ) {
			return '';
		}
		$heading = '' === $heading ? __( 'What changed', 'rbx-data' ) : $heading;
		$out     = '<section class="rbx-changes" aria-labelledby="what-changed"><h2 id="what-changed">' . esc_html( $heading ) . '</h2><ul>';
		foreach ( $rows as $r ) {
			$out .= '<li>' . self::change_line( $ds, $r ) . '</li>';
		}
		return $out . '</ul><p><a href="' . esc_url( home_url( '/' . $ds->game()->folder() . '/updates/' ) ) . '">' . esc_html__( 'All updates and changes', 'rbx-data' ) . '</a></p></section>';
	}

	/** @param array<string, mixed> $r Changelog row. */
	public static function change_line( Dataset $ds, array $r ): string {
		$rec   = $ds->get( (string) $r['record_id'] );
		$name  = $rec ? self::link( $rec ) : esc_html( (string) $r['record_id'] );
		$date  = self::date( substr( (string) $r['changed_at'], 0, 10 ) );
		$why   = ! empty( $r['is_correction'] ) ? __( 'Correction', 'rbx-data' ) : ( ! empty( $r['update_id'] ) ? __( 'Game update', 'rbx-data' ) : ( 'rollback' === $r['change_kind'] ? __( 'Reverted', 'rbx-data' ) : __( 'New test', 'rbx-data' ) ) );
		if ( 'create' === $r['change_kind'] ) {
			/* translators: %s: item name */
			return $date . ': ' . sprintf( esc_html__( '%s added', 'rbx-data' ), $name );
		}
		$game = $ds->game();
		$def  = $rec ? ( $game->field( (string) $rec['entity'], (string) $r['field'] ) ?? array() ) : array();
		$old  = self::changelog_value( $ds, $def, $r['old_value'] );
		$new  = self::changelog_value( $ds, $def, $r['new_value'] );
		$lbl  = $rec ? $game->label( (string) $rec['entity'], (string) $r['field'] ) : (string) $r['field'];
		return $date . ': ' . $name . ', ' . esc_html( $lbl ) . ' ' . ( '' === $old ? '' : $old . ' → ' ) . ( '' === $new ? esc_html__( 'removed', 'rbx-data' ) : $new ) . ' <span class="rbx-chip">' . esc_html( $why ) . '</span>';
	}

	/** @param array<string, mixed> $def Field def. */
	private static function changelog_value( Dataset $ds, array $def, mixed $stored ): string {
		if ( null === $stored || '' === $stored ) {
			return '';
		}
		if ( 'TRUE' === $stored || 'FALSE' === $stored ) {
			return self::raw( $ds, $def, 'TRUE' === $stored, false );
		}
		$v = json_decode( (string) $stored, true );
		if ( null === $v && 'null' !== $stored ) {
			$v = (string) $stored;
		}
		return self::raw( $ds, $def, $v, false );
	}

	/**
	 * "Report a wrong value" button (UX-43): dialog with JS, /report/ link without.
	 *
	 * @param array<string, string> $ctx record_id, field, value, page.
	 */
	public static function report_button( Game $game, array $ctx = array() ): string {
		$q = array_filter(
			array(
				'game'   => $game->key(),
				'record' => $ctx['record_id'] ?? '',
				'field'  => $ctx['field'] ?? '',
			)
		);
		$url = add_query_arg( $q, home_url( '/report/' ) );
		return '<a class="rbx-report-btn button-secondary" href="' . esc_url( $url ) . '" data-rbx-report data-game="' . esc_attr( $game->key() ) . '" data-record="' . esc_attr( $ctx['record_id'] ?? '' ) . '" data-field="' . esc_attr( $ctx['field'] ?? '' ) . '" data-value="' . esc_attr( $ctx['value'] ?? '' ) . '" data-version="' . esc_attr( Repository::version( $game ) ) . '" rel="nofollow"><svg class="rbx-icon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/></svg>' . esc_html__( 'Report a wrong value', 'rbx-data' ) . '</a>';
	}

	/** Byline: "By {Author}" plus " · Checked by {Reviewer}" when a different person (CONT-41, REV C-38). */
	/**
	 * Public author of a post: name and author page URL. The WordPress account name is used only
	 * when it has its own author page (user meta rbx_author_page); otherwise the site's author from
	 * the theme's content/author.json, so a login name like "admin" never shows.
	 *
	 * @return array{name: string, url: string}|null
	 */
	public static function author( int $post_id ): ?array {
		$post = get_post( $post_id );
		$user = $post ? get_userdata( (int) $post->post_author ) : false;
		if ( $user ) {
			$url = (string) get_user_meta( $user->ID, 'rbx_author_page', true );
			if ( '' !== $url ) {
				return array( 'name' => (string) $user->display_name, 'url' => $url );
			}
		}
		$file = get_template_directory() . '/content/author.json';
		$site = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_array( $site ) && ! empty( $site['name'] ) && ! empty( $site['url'] ) ) {
			return array( 'name' => (string) $site['name'], 'url' => home_url( (string) $site['url'] ) );
		}
		return $user ? array( 'name' => (string) $user->display_name, 'url' => home_url( '/authors/' . $user->user_nicename . '/' ) ) : null;
	}

	public static function byline( int $post_id, string $reviewer = '' ): string {
		$author = self::author( $post_id );
		if ( ! $author ) {
			return '';
		}
		/* translators: %s: author name with link */
		$html = sprintf( esc_html__( 'By %s', 'rbx-data' ), '<a href="' . esc_url( $author['url'] ) . '" rel="author">' . esc_html( $author['name'] ) . '</a>' );
		// AI drafting is explained on the methodology page; "claude" is not a reviewer name.
		if ( '' !== $reviewer && 0 !== strcasecmp( trim( $reviewer ), $author['name'] ) && 0 !== strcasecmp( trim( $reviewer ), 'claude' ) ) {
			/* translators: %s: reviewer name */
			$html .= ' <span aria-hidden="true">·</span> ' . sprintf( esc_html__( 'Checked by %s', 'rbx-data' ), esc_html( $reviewer ) );
		}
		return '<p class="rbx-byline">' . $html . '</p>';
	}

	/**
	 * Placeholder icon (UX-48): rarity bar and first letter, no fake image.
	 */
	public static function placeholder( string $name, string $color = '', int $size = 96 ): string {
		$letter = mb_strtoupper( mb_substr( $name, 0, 1 ) );
		$bar    = preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ? $color : 'currentColor';
		return '<svg class="rbx-ph" width="' . $size . '" height="' . $size . '" viewBox="0 0 96 96" aria-hidden="true" focusable="false"><rect width="96" height="96" rx="12" class="rbx-ph__bg"/><rect y="88" width="96" height="8" fill="' . esc_attr( $bar ) . '"/><text x="48" y="62" text-anchor="middle" font-size="44" font-weight="700" class="rbx-ph__t">' . esc_html( $letter ) . '</text></svg>';
	}

	/** Item icon: approved media image or the placeholder. */
	public static function icon( Dataset $ds, array $rec, int $size = 96 ): string {
		$media = $ds->media_for( (string) $rec['id'] );
		$rar   = $ds->get( (string) ( $rec['data']['rarity_id'] ?? '' ) );
		$color = (string) ( $rar['data']['ui_color_hex'] ?? '' );
		foreach ( $media as $m ) {
			$img = Media::img( (string) $m['data']['file_name'], '', $size, 'rbx-icon-img' );
			if ( '' !== $img ) {
				return $img;
			}
		}
		return self::placeholder( (string) $rec['name'], $color, $size );
	}

	/**
	 * Sheet text through the allowlist (ENG-51), with link rel per CONT-35: own site and
	 * official domains followed, others nofollow.
	 */
	public static function text( string $html ): string {
		$html  = wp_kses( $html, \RBX\Data\Import\Normalizer::KSES );
		$own   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$allow = (array) apply_filters( 'rbx_data_followed_domains', array( $own, 'roblox.com', 'www.roblox.com', 'devforum.roblox.com' ) );
		return (string) preg_replace_callback(
			'/<a\s([^>]*?)href="([^"]*)"([^>]*)>/i',
			static function ( $m ) use ( $allow ) {
				$host = strtolower( (string) wp_parse_url( html_entity_decode( $m[2] ), PHP_URL_HOST ) );
				$rest = preg_replace( '/\srel="[^"]*"/i', '', $m[1] . $m[3] ) ?? '';
				$rel  = '' === $host || in_array( $host, $allow, true ) ? 'noopener' : 'nofollow noopener';
				return '<a ' . trim( $rest ) . ( '' !== trim( $rest ) ? ' ' : '' ) . 'href="' . $m[2] . '" rel="' . $rel . '">';
			},
			$html
		);
	}

	/** H2 with stable id (CONT-10). */
	public static function h2( string $text, string $id ): string {
		return '<h2 id="' . esc_attr( $id ) . '">' . esc_html( $text ) . '</h2>';
	}
}
