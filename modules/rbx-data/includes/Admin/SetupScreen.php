<?php
/**
 * Setup (manage_options): create the Phase 1 page skeletons as drafts (§2.4), download the
 * Sheet template (DATA-16/17), Roblox IDs override, default byline author.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Admin;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Sheet\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Page skeletons hold data blocks and headings only; Phase 1b writes the copy.
 */
final class SetupScreen {

	public static function boot(): void {
		add_action( 'admin_post_rbx_setup_pages', array( self::class, 'create_pages' ) );
		add_action( 'admin_post_rbx_sheet_template', array( self::class, 'template' ) );
		add_action( 'admin_post_rbx_setup_ids', array( self::class, 'save_ids' ) );
		add_action( 'admin_post_rbx_setup_author', array( self::class, 'save_author' ) );
		add_action( 'admin_post_rbx_setup_lock', array( self::class, 'save_lock' ) );
	}

	private static function block( string $section, string $game = '', string $entity = '', int $limit = 0 ): string {
		$a = array_filter(
			array(
				'game'    => $game,
				'section' => $section,
				'entity'  => $entity,
				'limit'   => $limit ?: null,
			)
		);
		return '<!-- wp:rbx/data-section ' . wp_json_encode( $a ) . ' /-->' . "\n\n";
	}

	private static function h2( string $text, string $anchor ): string {
		return '<!-- wp:heading {"anchor":"' . esc_attr( $anchor ) . '"} -->' . "\n" . '<h2 class="wp-block-heading" id="' . esc_attr( $anchor ) . '">' . esc_html( $text ) . '</h2>' . "\n<!-- /wp:heading -->\n\n";
	}

	private static function todo( string $text ): string {
		return '<!-- wp:paragraph {"className":"rbx-todo"} -->' . "\n" . '<p class="rbx-todo">[' . esc_html( $text ) . ']</p>' . "\n<!-- /wp:paragraph -->\n\n";
	}

	/**
	 * Page tree for one game and the site pages (§2.4).
	 *
	 * @return array<int, array<string, mixed>> path, title, crumb, content, meta
	 */
	public static function pages( Game $game ): array {
		$k   = $game->key();
		$n   = $game->name();
		$f   = $game->folder();
		$out = array();

		$out[] = array(
			'path'    => '',
			'slug'    => 'home',
			'title'   => 'UpdateOtter: Calculators and Wikis for Roblox Games',
			'content' => self::todo( 'Phase 1b: two sentences saying this is an unofficial, fan-made site and what it offers (CONT-19).' ) . self::block( 'home_games' ) . self::h2( 'Latest updates', 'latest-updates' ) . self::block( 'home_latest' ) . self::h2( 'How we check our data', 'how-we-check' ) . self::todo( 'Phase 1b: three short lines and a link to /methodology/.' ),
			'front'   => true,
		);
		$out[] = array(
			'path'    => $f,
			'title'   => $n . ' Wiki',
			'content' => self::todo( 'Phase 1b: answer-first, unofficial fan-made wiki, what the game is, counts via [rbx_count] (CONT-20).' ) . self::block( 'hub_tiles', $k ) . self::h2( 'Game at a glance', 'at-a-glance' ) . self::block( 'hub_glance', $k ) . self::h2( 'Key terms', 'key-terms' ) . self::block( 'glossary', $k ) . self::h2( 'Biomes', 'biomes' ) . self::block( 'biomes', $k ) . self::h2( 'Mounts', 'mounts' ) . self::block( 'mounts', $k ) . self::block( 'latest_update', $k ) . self::h2( 'Best pets right now', 'best-pets' ) . self::block( 'top_tier', $k, '', 5 ) . self::block( 'changes', $k ),
		);
		foreach ( $game->menu_order() as $slug ) {
			$s = $game->section( $slug );
			if ( null === $s ) {
				continue;
			}
			$label   = (string) $s['label__en'];
			$content = '';
			$title   = $n . ' ' . $label;
			switch ( (string) $s['type'] ) {
				case 'tool':
					$title   = (string) ( $game->tool( (string) $s['tool'] )['label__en'] ?? $title );
					$content = self::todo( 'Phase 1b: one sentence on what it computes (CONT-21).' ) . '<!-- wp:rbx/calculator ' . wp_json_encode(
						array(
							'game' => $k,
							'tool' => (string) $s['tool'],
						)
					) . ' /-->' . "\n\n" . self::h2( 'How to use it', 'how-to-use' ) . self::todo( 'Phase 1b: 3 to 5 numbered steps.' ) . self::h2( 'How it is calculated', 'how-it-is-calculated' ) . self::todo( 'Phase 1b: formula in plain words; rbx-tools adds the formula notation below the tool.' ) . self::h2( 'Worked example', 'worked-example' ) . self::todo( 'Phase 1b: example with bound values equal to the default result.' ) . self::h2( 'Limits and known gaps', 'limits' ) . self::todo( 'Phase 1b: list unconfirmed mechanics.' ) . self::block( 'changes', $k );
					break;
				case 'list':
					$content = self::todo( 'Phase 1b: answer-first with the count and the ordering (CONT-22).' ) . self::block( 'count', $k, (string) $s['entity'] ) . self::block( 'list', $k, (string) $s['entity'] ) . self::block( 'changes', $k );
					break;
				case 'mutations':
					$content = self::todo( 'Phase 1b: answer-first (CONT-24).' ) . self::block( 'stacking', $k ) . self::h2( 'All mutations', 'all-mutations' ) . self::block( 'mutations', $k ) . self::h2( 'Sizes', 'size-multipliers' ) . self::block( 'sizes', $k ) . self::h2( 'How mutations and sizes stack', 'stacking' ) . self::todo( 'Phase 1b: formula and a worked example with bound values.' ) . self::block( 'changes', $k );
					$title   = $n . ' Mutations & Weight';
					break;
				case 'codes':
					$title   = $n . ' Codes';
					$content = self::block( 'codes', $k ) . self::h2( 'How to redeem codes', 'how-to-redeem' ) . self::todo( 'Phase 1b: only if _meta.redeem_ui_exists = yes, with own screenshots; otherwise delete this section.' );
					break;
				case 'updates':
					$content = self::block( 'updates', $k ) . self::h2( 'How we track updates', 'how-we-track' ) . self::todo( 'Phase 1b: notifier and the game developer channels, in plain words.' );
					break;
				case 'tier_list':
					$content = self::todo( 'Phase 1b: answer-first with top 3 picks and ranking date (CONT-27).' ) . self::h2( 'How we rank', 'how-we-rank' ) . self::todo( 'Phase 1b: criteria and weighting from dataset fields.' ) . self::block( 'tier_list', $k ) . self::block( 'changes', $k );
					break;
			}
			$out[] = array(
				'path'    => $f . '/' . $slug,
				'title'   => $title,
				'crumb'   => $label,
				'content' => $content,
			);
		}
		$trust = array(
			'about'          => array( 'About UpdateOtter', 'Phase 1b (POL-58): operator Ali Ahmad, Pakistan; why the site exists; unofficial and fan-made; how data is gathered; games covered; last-updated date and changelog list.' ),
			'methodology'    => array( 'Editorial Policy & Methodology', 'Phase 1b (CONT-43, POL-58): how data is collected, what the labels mean, re-verification after updates, how calculators compute, AI-use statement, independence, corrections link, accessibility statement, last reviewed date.' ),
			'authors'        => array( 'Authors', 'Phase 1b (CONT-42): list of authors.' ),
			'corrections'    => array( 'Corrections', 'Phase 1b (CONT-45): how to report, 72 h triage target; the log below comes from the data.' ),
			'contact'        => array( 'Contact', 'Phase 1b (POL-58): mailto:contact@updateotter.com, suggested subjects, response time, child notice.' ),
			'report'         => array( 'Report a wrong value', 'Phase 1b: one line of intro. This page is noindex and has no ads.' ),
			'privacy-policy' => array( 'Privacy Policy', 'Phase 1b (POL-43, POL-33).' ),
			'cookie-policy'  => array( 'Cookie Policy', 'Phase 1b (POL-44).' ),
			'terms'          => array( 'Terms of Use', 'Phase 1b (POL-58).' ),
			'disclaimer'     => array( 'Disclaimer', 'Phase 1b (POL-46 full statement, POL-58).' ),
			'dmca'           => array( 'Copyright & Takedown', 'Phase 1b (POL-55).' ),
		);
		foreach ( $trust as $slug => $t ) {
			$content = self::todo( $t[1] );
			if ( 'corrections' === $slug ) {
				$content .= self::block( 'corrections' );
			}
			if ( 'report' === $slug ) {
				$content .= self::block( 'report_form' );
			}
			$out[] = array(
				'path'    => $slug,
				'title'   => $t[0],
				'content' => $content,
			);
		}
		$out[] = array(
			'path'    => 'authors/ali-ahmad',
			'title'   => 'Ali Ahmad',
			'content' => self::todo( 'Phase 1b (CONT-42): real bio, what Ali plays and tests, how to contact. No invented credentials.' ),
		);
		return $out;
	}

	public static function create_pages(): void {
		Menu::guard( 'manage_options', 'rbx_setup_pages' );
		$created = self::create();
		/* translators: %d: count */
		Menu::back( admin_url( 'admin.php?page=rbx-setup' ), sprintf( __( '%d draft pages created. Existing pages were left alone.', 'rbx-data' ), $created ) );
	}

	/** Creates missing skeleton pages as drafts; returns how many were created. */
	public static function create(): int {
		$created = 0;
		foreach ( Loader::games() as $game ) {
			foreach ( self::pages( $game ) as $p ) {
				$path = '' === $p['path'] ? (string) ( $p['slug'] ?? 'home' ) : (string) $p['path'];
				if ( get_page_by_path( $path, OBJECT, 'page' ) ) {
					continue;
				}
				$parts  = explode( '/', $path );
				$slug   = (string) array_pop( $parts );
				$parent = $parts ? get_page_by_path( implode( '/', $parts ) ) : null;
				if ( $parts && ! $parent ) {
					continue;
				}
				$id = wp_insert_post(
					array(
						'post_type'      => 'page',
						'post_status'    => 'draft',
						'post_author'    => (int) get_option( 'rbx_default_author', 0 ) ?: get_current_user_id(),
						'post_title'     => (string) $p['title'],
						'post_name'      => $slug,
						'post_parent'    => $parent ? $parent->ID : 0,
						'post_content'   => (string) $p['content'],
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
						'menu_order'     => $created,
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					continue;
				}
				++$created;
				if ( ! empty( $p['crumb'] ) ) {
					update_post_meta( (int) $id, '_rbx_crumb', (string) $p['crumb'] );
				}
				if ( str_starts_with( $path, $game->folder() ) ) {
					update_post_meta( (int) $id, '_rbx_game', $game->key() );
				}
				if ( ! empty( $p['front'] ) && ! get_option( 'page_on_front' ) ) {
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', (int) $id );
				}
			}
		}
		return $created;
	}

	public static function template(): void {
		Menu::guard( 'rbx_import', 'rbx_sheet_template' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$game = Loader::game( sanitize_key( (string) wp_unslash( $_POST['game'] ?? '' ) ) );
		if ( ! $game ) {
			wp_die( esc_html__( 'Unknown game.', 'rbx-data' ) );
		}
		$tmp = wp_tempnam( 'rbx-template.xlsx' );
		Template::write( $game, $tmp );
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $game->folder() . '-data-template.xlsx"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $tmp );
		exit;
	}

	public static function save_ids(): void {
		Menu::guard( 'manage_options', 'rbx_setup_ids' );
		$all  = (array) get_option( 'rbx_game_overrides', array() );
		$same = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( Loader::games() as $g ) {
			$u = preg_replace( '/\D/', '', (string) wp_unslash( $_POST['universe'][ $g->key() ] ?? '' ) );
			$p = preg_replace( '/\D/', '', (string) wp_unslash( $_POST['place'][ $g->key() ] ?? '' ) );
			if ( '' !== $u && $u === $p ) {
				$u      = ''; // A universe ID is never the place ID; keep it empty until looked up.
				$same[] = $g->name();
			}
			$all[ $g->key() ] = array(
				'roblox_universe_id' => '' === $u ? null : $u,
				'roblox_place_id'    => '' === $p ? null : $p,
			);
		}
		// phpcs:enable
		update_option( 'rbx_game_overrides', $all, false );
		Loader::reset();
		if ( $same ) {
			/* translators: %s: game names */
			Menu::back( admin_url( 'admin.php?page=rbx-setup' ), sprintf( __( 'Place ID saved. The universe ID for %s was the same as the place ID, so it was not saved: get it with RBX Data, Notifier, "Look up universe".', 'rbx-data' ), implode( ', ', $same ) ), true );
		}
		Menu::back( admin_url( 'admin.php?page=rbx-setup' ), __( 'Roblox IDs saved. Put the same IDs in the Sheet _meta tab.', 'rbx-data' ) );
	}

	public static function save_author(): void {
		Menu::guard( 'manage_options', 'rbx_setup_author' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = absint( $_POST['author'] ?? 0 );
		if ( $id && get_userdata( $id ) ) {
			update_option( 'rbx_default_author', $id, false );
		}
		Menu::back( admin_url( 'admin.php?page=rbx-setup' ), __( 'Default author saved.', 'rbx-data' ) );
	}

	public static function save_lock(): void {
		Menu::guard( 'manage_options', 'rbx_setup_lock' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$on = ! empty( $_POST['lock'] );
		update_option( 'rbx_prelaunch_lock', $on ? 1 : 0, true );
		do_action( 'litespeed_purge_all' );
		Menu::back( admin_url( 'admin.php?page=rbx-setup' ), $on ? __( 'Pre-launch lock is ON. Logged-out visitors see the holding page.', 'rbx-data' ) : __( 'Pre-launch lock is OFF. The site is public now.', 'rbx-data' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'rbx-data' ) );
		}
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div class="wrap rbx-admin"><h1>' . esc_html__( 'RBX Data setup', 'rbx-data' ) . '</h1>';
		Menu::notice_from_query();

		echo '<h2>' . esc_html__( '1. Page skeletons', 'rbx-data' ) . '</h2><p>' . esc_html__( 'Creates the launch pages as drafts with their data blocks and headings (home, game hub, calculator, lists, codes, updates, tier list, trust pages, report). Pages that already exist are not touched.', 'rbx-data' ) . '</p>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="rbx_setup_pages">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_setup_pages' );
		submit_button( __( 'Create draft pages', 'rbx-data' ), 'secondary' );
		echo '</form>';

		echo '<h2>' . esc_html__( '2. Sheet template', 'rbx-data' ) . '</h2><p>' . esc_html__( 'Download, then in Google Drive: New, File upload, open with Google Sheets. Tabs, headers and dropdowns come from the site config.', 'rbx-data' ) . '</p>';
		foreach ( Loader::games() as $g ) {
			echo '<form method="post" action="' . $post . '" class="rbx-inline"><input type="hidden" name="action" value="rbx_sheet_template"><input type="hidden" name="game" value="' . esc_attr( $g->key() ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_nonce_field( 'rbx_sheet_template' );
			/* translators: %s: game */
			submit_button( sprintf( __( 'Download %s template (.xlsx)', 'rbx-data' ), $g->name() ), 'secondary', 'submit', false );
			echo '</form> ';
		}

		echo '<h2>' . esc_html__( '3. Roblox IDs', 'rbx-data' ) . '</h2><p>' . esc_html__( 'Used by the update notifier and the hub "Play on Roblox" link. The place ID is the number in the game URL (roblox.com/games/NUMBER/...).', 'rbx-data' ) . '</p><form method="post" action="' . $post . '"><input type="hidden" name="action" value="rbx_setup_ids">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_setup_ids' );
		echo '<table class="form-table">';
		foreach ( Loader::games() as $g ) {
			echo '<tr><th>' . esc_html( $g->name() ) . '</th><td><label>' . esc_html__( 'Place ID', 'rbx-data' ) . ' <input name="place[' . esc_attr( $g->key() ) . ']" value="' . esc_attr( (string) $g->place_id() ) . '" inputmode="numeric"></label> <label>' . esc_html__( 'Universe ID', 'rbx-data' ) . ' <input name="universe[' . esc_attr( $g->key() ) . ']" value="' . esc_attr( (string) $g->universe_id() ) . '" inputmode="numeric"></label></td></tr>';
		}
		echo '</table>';
		submit_button( __( 'Save IDs', 'rbx-data' ), 'secondary' );
		echo '</form>';

		$locked = (bool) get_option( 'rbx_prelaunch_lock', 1 );
		echo '<h2>' . esc_html__( '4. Pre-launch lock', 'rbx-data' ) . '</h2><p>' . esc_html__( 'While ON, logged-out visitors (and search engines) see a short noindex holding page; logged-in users see the real site. Turn it OFF only on launch day (Phase 1c).', 'rbx-data' ) . '</p>';
		echo '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="rbx_setup_lock"><input type="hidden" name="lock" value="' . ( $locked ? '' : '1' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_setup_lock' );
		echo '<p><strong>' . ( $locked ? esc_html__( 'Status: ON', 'rbx-data' ) : esc_html__( 'Status: OFF (public)', 'rbx-data' ) ) . '</strong></p>';
		submit_button( $locked ? __( 'Turn the lock OFF (go public)', 'rbx-data' ) : __( 'Turn the lock ON', 'rbx-data' ), $locked ? 'secondary' : 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( '5. Default byline', 'rbx-data' ) . '</h2><form method="post" action="' . $post . '"><input type="hidden" name="action" value="rbx_setup_author">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'rbx_setup_author' );
		wp_dropdown_users(
			array(
				'name'     => 'author',
				'selected' => (int) get_option( 'rbx_default_author', 0 ),
				'role__in' => array( 'administrator', 'rbx_data_editor', 'author' ),
			)
		);
		submit_button( __( 'Save author', 'rbx-data' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
}
