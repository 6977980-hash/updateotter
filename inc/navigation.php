<?php
/**
 * Header navigation, game switcher (UX-21), per-game menu strip (UX-22), breadcrumbs (UX-23)
 * and footer columns (UX-25). Games come from RBX Data config, so a new game needs no template edit.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** @return array<string, \RBX\Data\Config\Game> */
function updateotter_games(): array {
	return class_exists( '\RBX\Data\Api' ) ? \RBX\Data\Api::games() : array();
}

/** Site URL of a path; on Indonesian pages the Indonesian twin when one is published. */
function updateotter_url( string $path = '' ): string {
	return class_exists( '\RBX\Data\Content\Lang' ) ? \RBX\Data\Content\Lang::url( $path ) : home_url( '/' . ( '' !== trim( $path, '/' ) ? trim( $path, '/' ) . '/' : '' ) );
}

function updateotter_game_url( \RBX\Data\Config\Game $g, string $path = '' ): string {
	return updateotter_url( $g->folder() . ( '' !== $path ? '/' . trim( $path, '/' ) : '' ) );
}

/** Link to the other language of this page (TECH-20 pairs only). */
function updateotter_lang_switch(): void {
	$pair = class_exists( '\RBX\Data\Content\Lang' ) ? \RBX\Data\Content\Lang::pair() : null;
	if ( null === $pair ) {
		return;
	}
	if ( \RBX\Data\Content\Lang::is_id() ) {
		echo '<p class="lang-switch"><a href="' . esc_url( $pair['en'] ) . '" hreflang="en" lang="en">Read this page in English</a></p>';
	} else {
		echo '<p class="lang-switch"><a href="' . esc_url( $pair['id'] ) . '" hreflang="id" lang="id">Baca dalam Bahasa Indonesia</a></p>';
	}
}

/** @return array<int, array{0: string, 1: string}> label, url of each menu entry in config order. */
function updateotter_game_sections( \RBX\Data\Config\Game $g ): array {
	$out = array();
	foreach ( $g->menu_order() as $slug ) {
		$s = $g->section( $slug );
		if ( null !== $s ) {
			$out[] = array( (string) $s['label__en'], updateotter_game_url( $g, $slug ) );
		}
	}
	// Guides are plain Pages, not config sections: listed once the game's guides page is published.
	if ( class_exists( '\RBX\Data\Render\Breadcrumbs' ) && '' !== \RBX\Data\Render\Breadcrumbs::page_url( $g->folder() . '/guides' ) ) {
		$out[] = array( __( 'Guides', 'updateotter' ), updateotter_game_url( $g, 'guides' ) );
	}
	return $out;
}

function updateotter_game_updated( \RBX\Data\Config\Game $g ): string {
	$info = class_exists( '\RBX\Data\Export\Writer' ) ? \RBX\Data\Export\Writer::info( $g ) : array();
	$t    = isset( $info['written'] ) ? strtotime( $info['written'] . ' UTC' ) : false;
	return $t ? wp_date( 'j M Y', $t ) : '';
}

function updateotter_monogram( \RBX\Data\Config\Game $g ): string {
	$words = preg_split( '/\s+/', $g->name() );
	$m     = '';
	foreach ( (array) $words as $w ) {
		if ( ctype_upper( mb_substr( (string) $w, 0, 1 ) ) ) {
			$m .= mb_substr( (string) $w, 0, 1 );
		}
	}
	return mb_substr( '' !== $m ? $m : $g->name(), 0, 3 );
}

/** Per-game section strip under the header. */
function updateotter_game_menu(): void {
	$g = updateotter_game();
	if ( ! $g ) {
		return;
	}
	$here = trailingslashit( (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	echo '<nav class="game-menu" aria-label="' . esc_attr( sprintf( /* translators: %s: game */ __( '%s sections', 'updateotter' ), $g->name() ) ) . '"><div class="container"><ul class="game-menu__list">';
	$hub = updateotter_game_url( $g );
	echo '<li><a href="' . esc_url( $hub ) . '"' . ( wp_make_link_relative( $hub ) === $here ? ' aria-current="page"' : '' ) . '>' . esc_html( $g->short_name() ) . '</a></li>';
	foreach ( updateotter_game_sections( $g ) as [ $label, $url ] ) {
		$rel = wp_make_link_relative( $url );
		$cur = $rel === $here || ( str_starts_with( $here, $rel ) && is_singular() && ! is_page() );
		echo '<li><a href="' . esc_url( $url ) . '"' . ( $cur ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul></div></nav>';
}

/** Breadcrumbs (TECH-18 data from RBX Data; visual per UX-23). Called by plugin templates too. */
function updateotter_breadcrumbs(): void {
	if ( ! is_front_page() && class_exists( '\RBX\Data\Render\Breadcrumbs' ) ) {
		echo \RBX\Data\Render\Breadcrumbs::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Breadcrumbs.
	}
	updateotter_lang_switch();
}
add_action( 'updateotter_breadcrumbs', 'updateotter_breadcrumbs' );

function updateotter_game_switcher_panel(): void {
	$current = updateotter_game();
	echo '<div class="panel" id="game-switcher" hidden><div class="container"><ul class="game-switcher__list">';
	foreach ( updateotter_games() as $g ) {
		$tools = $g->tools();
		$calc  = $tools ? updateotter_game_url( $g, (string) ( $tools[0]['path'] ?? $tools[0]['key'] ) ) : '';
		$upd   = updateotter_game_updated( $g );
		echo '<li class="game-row" style="--game-accent:' . esc_attr( $g->accent() ) . '"><span class="mono" aria-hidden="true">' . esc_html( updateotter_monogram( $g ) ) . '</span><span><a class="game-row__name" href="' . esc_url( updateotter_game_url( $g ) ) . '"' . ( $current && $current->key() === $g->key() ? ' aria-current="true"' : '' ) . '>' . esc_html( $g->name() ) . '</a>' . ( '' !== $upd ? '<br><span class="game-row__meta">' . esc_html( sprintf( /* translators: %s: date */ __( 'Updated %s', 'updateotter' ), $upd ) ) . '</span>' : '' ) . '</span><span class="game-row__links">' . ( '' !== $calc ? '<a href="' . esc_url( $calc ) . '">' . esc_html__( 'Calculator', 'updateotter' ) . '</a>' : '' ) . ( $g->section( 'codes' ) ? '<a href="' . esc_url( updateotter_game_url( $g, 'codes' ) ) . '">' . esc_html__( 'Codes', 'updateotter' ) . '</a>' : '' ) . '</span></li>';
	}
	echo '</ul></div></div>';
}

function updateotter_mobile_menu_panel(): void {
	echo '<div class="panel" id="site-menu" hidden><div class="container"><nav aria-label="' . esc_attr__( 'Site', 'updateotter' ) . '"><ul class="mobile-menu__list">';
	foreach ( updateotter_games() as $g ) {
		echo '<li><a href="' . esc_url( updateotter_game_url( $g ) ) . '">' . esc_html( $g->name() ) . '</a></li>';
	}
	foreach ( updateotter_footer_links()['about'] as [ $label, $url ] ) {
		echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul></nav>';
	updateotter_theme_toggle();
	echo '</div></div>';
}

function updateotter_theme_toggle(): void {
	echo '<div class="theme-toggle" role="group" aria-label="' . esc_attr__( 'Colour theme', 'updateotter' ) . '" hidden data-theme-toggle>';
	foreach ( array(
		'system' => array( __( 'System', 'updateotter' ), 'monitor' ),
		'light'  => array( __( 'Light', 'updateotter' ), 'sun' ),
		'dark'   => array( __( 'Dark', 'updateotter' ), 'moon' ),
	) as $k => $v ) {
		echo '<button type="button" data-theme-set="' . esc_attr( $k ) . '" aria-pressed="false">' . updateotter_icon( $v[1], 'icon--sm' ) . esc_html( $v[0] ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon escaped.
	}
	echo '</div>';
}

/** @return array<string, array<int, array{0: string, 1: string}>> */
function updateotter_footer_links(): array {
	$games = array();
	$tools = array();
	foreach ( updateotter_games() as $g ) {
		$games[] = array( $g->name(), updateotter_game_url( $g ) );
		foreach ( $g->tools() as $t ) {
			$tools[] = array( (string) ( $t['label__en'] ?? $t['key'] ), updateotter_game_url( $g, (string) ( $t['path'] ?? $t['key'] ) ) );
		}
	}
	$p = static fn( string $s ): string => home_url( '/' . $s . '/' );
	return array(
		'games' => $games,
		'tools' => $tools,
		'about' => array(
			array( __( 'About', 'updateotter' ), $p( 'about' ) ),
			array( __( 'How we verify data', 'updateotter' ), $p( 'methodology' ) ),
			array( __( 'Authors', 'updateotter' ), $p( 'authors' ) ),
			array( __( 'Corrections', 'updateotter' ), $p( 'corrections' ) ),
			array( __( 'Report a wrong value', 'updateotter' ), $p( 'report' ) ),
			array( __( 'Contact', 'updateotter' ), $p( 'contact' ) ),
		),
		'legal' => array(
			array( __( 'Privacy Policy', 'updateotter' ), $p( 'privacy-policy' ) ),
			array( __( 'Cookie Policy', 'updateotter' ), $p( 'cookie-policy' ) ),
			array( __( 'Privacy settings', 'updateotter' ), '' ),
			array( __( 'Terms of Use', 'updateotter' ), $p( 'terms' ) ),
			array( __( 'Disclaimer', 'updateotter' ), $p( 'disclaimer' ) ),
			array( __( 'Copyright & Takedown', 'updateotter' ), $p( 'dmca' ) ),
		),
	);
}
