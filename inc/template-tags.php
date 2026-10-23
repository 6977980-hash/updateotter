<?php
/**
 * Small template helpers.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** POL-46, exact text. */
function updateotter_disclaimer(): string {
	return 'UpdateOtter is an independent fan site. It is not affiliated with, endorsed, sponsored or approved by Roblox Corporation or the developers of the games covered. Roblox and the game names are trademarks of their respective owners and are used here only to identify the games.';
}

/** CC BY-SA credit for the wiki data (UO-04). Our own text and code are not under it. */
function updateotter_license_line(): string {
	return sprintf(
		/* translators: 1: links to the Fandom wikis, 2: link to the CC BY-SA licence */
		esc_html__( 'Game data is adapted from the %1$s wikis on Fandom, licensed %2$s; our changes to that data use the same licence.', 'updateotter' ),
		'<a href="https://stealanegg.fandom.com/" rel="noopener">Steal an Egg</a>, <a href="https://growagarden.fandom.com/" rel="noopener">Grow a Garden</a>, <a href="https://growagarden2.fandom.com/" rel="noopener">Grow a Garden 2</a> ' . esc_html__( 'and', 'updateotter' ) . ' <a href="https://fish-it.fandom.com/" rel="noopener">Fish It!</a>',
		'<a href="https://creativecommons.org/licenses/by-sa/3.0/" rel="license noopener">CC BY-SA</a>'
	);
}

/** Inline SVG lockup (UX-53): colours follow the tokens, so it switches with dark mode. */
function updateotter_logo(): void {
	static $svg = null;
	$svg ??= (string) file_get_contents( UPDATEOTTER_DIR . '/parts/logo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static theme file.
}

function updateotter_icon( string $name, string $class = '' ): string {
	return '<svg class="icon' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" aria-hidden="true" focusable="false"><use href="#i-' . esc_attr( $name ) . '"/></svg>';
}

/** Game of the current request, from RBX Data (null on site pages or when the plugin is off). */
function updateotter_game(): ?\RBX\Data\Config\Game {
	static $done = false, $game = null;
	if ( $done ) {
		return $game;
	}
	$done = true;
	if ( ! class_exists( '\RBX\Data\Render\Breadcrumbs' ) ) {
		return null;
	}
	$obj  = get_queried_object();
	$game = $obj instanceof WP_Post ? \RBX\Data\Render\Breadcrumbs::game_for( $obj ) : null;
	return $game;
}

/** Pages that never show ads or bylines: trust, policy, report, contact (REV C-32). */
function updateotter_is_site_page(): bool {
	if ( ! is_page() ) {
		return false;
	}
	$p = get_queried_object();
	if ( ! $p instanceof WP_Post ) {
		return false;
	}
	$anc  = get_post_ancestors( $p );
	$root = $anc ? get_post( (int) end( $anc ) ) : $p;
	return $root && in_array( $root->post_name, array( 'about', 'methodology', 'authors', 'corrections', 'contact', 'report', 'privacy-policy', 'cookie-policy', 'terms', 'disclaimer', 'dmca' ), true );
}

/** H1, byline and trust strip for WP Pages (CONT shared parts; game pages only get byline + trust). */
function updateotter_page_head(): void {
	$id   = get_the_ID();
	$game = updateotter_game();
	echo '<header class="page-head">';
	the_title( '<h1>', '</h1>' );
	if ( $game && class_exists( '\RBX\Data\Render\Ui' ) && ! is_front_page() ) {
		echo \RBX\Data\Render\Ui::byline( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
		echo \RBX\Data\Api::trust_strip( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Ui.
	} elseif ( updateotter_is_site_page() ) {
		printf(
			'<p class="rbx-meta">%s <time datetime="%s">%s</time></p>',
			esc_html__( 'Last updated', 'updateotter' ),
			esc_attr( get_the_modified_date( 'Y-m-d' ) ),
			esc_html( get_the_modified_date( 'j M Y' ) )
		);
	}
	echo '</header>';
}

/** Result label on the search page, the same words as the game's own menus ("Crop", not "Plant"; UO-20). */
function updateotter_search_label( WP_Post $p ): string {
	if ( class_exists( '\RBX\Data\Data\Repository' ) && in_array( $p->post_type, \RBX\Data\Plugin::post_types(), true ) ) {
		$found = \RBX\Data\Data\Repository::for_post( $p->ID );
		if ( $found ) {
			return $found[0]->entity_label( (string) $found[1]['entity'] );
		}
	}
	return 'page' === $p->post_type ? __( 'Page', 'updateotter' ) : (string) get_post_type_object( $p->post_type )->labels->singular_name;
}
