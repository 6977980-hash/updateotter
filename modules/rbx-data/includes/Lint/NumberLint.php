<?php
/**
 * `wp rbx lint` (DATA-30, CONT-48, REV C-47): hand-typed game numbers and banned words in post
 * content, titles and excerpts. Bound blocks, [rbx_*] shortcodes and HTML comments are skipped.
 * A `<!-- rbx-allow-number: {reason} -->` comment allows numbers in that post.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Lint;

defined( 'ABSPATH' ) || exit;

/**
 * Pure checks plus the publish guard.
 */
final class NumberLint {

	public const UNIT_RE = '/(?:[x×]\s?\d+(?:[.,]\d+)?|\d+(?:[.,]\d+)?\s?(?:x|×|%|percent|sec|secs|seconds|s\b|min|minutes|hours?|robux|chance))/iu';
	public const STAT_RE = '/(?:\d[\d.,]*\s*(?:\w+\s){0,2}?(?:multiplier|income|odds|chance|speed|hatch|weight|value|price)\b|\b(?:multiplier|income|odds|chance|speed|hatch|weight|value|price)\w*\s+(?:\w+\s){0,2}?(?:of\s|is\s|=\s?|:\s?)?\d)/iu';

	public const BANNED = array( 'official', '100% accurate', 'guaranteed', 'always up to date', 'the only', '#1 site', 'ultimate', 'insane', 'op!!', 'secret trick', 'free robux', 'hack', 'leaked', "before it's patched" );

	public static function boot(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'guard' ), 20, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/** Visible prose of a post: bound blocks and data shortcodes removed. */
	public static function prose( string $content ): string {
		$text = '';
		if ( function_exists( 'parse_blocks' ) && str_contains( $content, '<!-- wp:' ) ) {
			$walk = static function ( array $blocks ) use ( &$walk, &$text ) {
				foreach ( $blocks as $b ) {
					if ( ! empty( $b['attrs']['metadata']['bindings'] ) || 'rbx/data-section' === $b['blockName'] || str_starts_with( (string) $b['blockName'], 'rbx/' ) ) {
						continue;
					}
					if ( ! empty( $b['innerBlocks'] ) ) {
						$walk( $b['innerBlocks'] );
						foreach ( $b['innerContent'] as $piece ) {
							if ( is_string( $piece ) ) {
								$text .= ' ' . $piece;
							}
						}
					} else {
						$text .= ' ' . (string) $b['innerHTML'];
					}
				}
			};
			$walk( parse_blocks( $content ) );
		} else {
			$text = $content;
		}
		$text = preg_replace( '/\[rbx_[a-z_]+[^\]]*\]/', ' ', $text ) ?? $text;
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text ) ?? $text;
		$text = preg_replace( '/<span[^>]*class="[^"]*rbx-num[^"]*"[^>]*>.*?<\/span>/s', ' ', $text ) ?? $text;
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES );
	}

	/**
	 * @return array<int, array{rule: string, match: string}>
	 */
	public static function check( string $content, string $title = '', string $excerpt = '' ): array {
		$issues = array();
		$allow  = (bool) preg_match( '/<!--\s*rbx-allow-number:\s*[^>]+-->/', $content );
		$text   = self::prose( $content ) . "\n" . $title . "\n" . $excerpt;
		// Years and full dates are allowed in prose.
		$scan = preg_replace( '/\b(?:19|20)\d{2}\b|\b\d{1,2}\s(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/i', ' ', $text ) ?? $text;
		if ( ! $allow ) {
			foreach ( array( 'unit' => self::UNIT_RE, 'stat-word' => self::STAT_RE ) as $rule => $re ) {
				if ( preg_match_all( $re, $scan, $m ) ) {
					foreach ( array_unique( $m[0] ) as $hit ) {
						$issues[] = array(
							'rule'  => $rule,
							'match' => trim( (string) $hit ),
						);
					}
				}
			}
		}
		// Quoted warnings and label names (CONT-48 exceptions: scam warnings, the DATA-05 labels) sit in an element with class rbx-lint-quote.
		$quoted = preg_replace( '/<(p|ul|figure|table)\b[^>]*\brbx-lint-quote\b[^>]*>.*?<\/\1>/s', ' ', $content ) ?? $content;
		$lower  = mb_strtolower( self::prose( $quoted ) . "\n" . $title . "\n" . $excerpt );
		foreach ( self::BANNED as $w ) {
			if ( preg_match( '/(?<![\w])' . preg_quote( $w, '/' ) . '(?![\w])/u', $lower ) ) {
				$issues[] = array(
					'rule'  => 'banned-word',
					'match' => $w,
				);
			}
		}
		if ( preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $title ) ) {
			$issues[] = array(
				'rule'  => 'emoji-in-title',
				'match' => $title,
			);
		}
		return $issues;
	}

	/** Runs for CPT shells and pages tied to a game; also for policy pages it only checks words. */
	private static function applies( array $data, array $raw ): bool {
		if ( str_starts_with( (string) $data['post_type'], 'rbx_' ) ) {
			return true;
		}
		if ( 'page' !== $data['post_type'] ) {
			return false;
		}
		$id = (int) ( $raw['ID'] ?? 0 );
		if ( ! $id ) {
			return false;
		}
		$post = get_post( $id );
		return $post && null !== \RBX\Data\Render\Breadcrumbs::game_for( $post );
	}

	/**
	 * Keeps the post a draft when publishing would add a hand-typed number or banned word.
	 *
	 * @param array<string, mixed> $data Sanitized post data.
	 * @param array<string, mixed> $raw  Raw post data.
	 * @return array<string, mixed>
	 */
	public static function guard( array $data, array $raw ): array {
		if ( 'publish' !== $data['post_status'] || ! self::applies( $data, $raw ) || defined( 'RBX_IMPORTING' ) ) {
			return $data;
		}
		$issues = self::check( wp_unslash( (string) $data['post_content'] ), wp_unslash( (string) $data['post_title'] ), wp_unslash( (string) $data['post_excerpt'] ) );
		if ( ! $issues ) {
			return $data;
		}
		$id = (int) ( $raw['ID'] ?? 0 );
		$was = $id ? get_post_status( $id ) : '';
		if ( 'publish' !== $was ) {
			$data['post_status'] = 'draft';
		}
		set_transient( 'rbx_lint_' . get_current_user_id(), $issues, 120 );
		return $data;
	}

	public static function notice(): void {
		$issues = get_transient( 'rbx_lint_' . get_current_user_id() );
		if ( ! is_array( $issues ) || ! $issues ) {
			return;
		}
		delete_transient( 'rbx_lint_' . get_current_user_id() );
		$li = '';
		foreach ( array_slice( $issues, 0, 10 ) as $i ) {
			$li .= '<li><code>' . esc_html( $i['match'] ) . '</code> (' . esc_html( $i['rule'] ) . ')</li>';
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Not published: the text has hand-typed game numbers or banned words. Use a data binding or [rbx_value], or add <!-- rbx-allow-number: reason --> for years and counts.', 'rbx-data' ) . '</p><ul>' . $li . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
