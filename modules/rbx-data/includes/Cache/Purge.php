<?php
/**
 * LiteSpeed Cache tags and purges (ENG-29). Every call is guarded so the site works without LSCWP.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Cache;

use RBX\Data\Config\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Tags: rbx-{game} on list, calculator and hub responses; rbx-rec-{id} on item responses.
 */
final class Purge {

	public const PURGE_ALL_OVER = 50;

	/** Cache tag on the sitemap responses (GR-32). */
	public const SITEMAP_TAG = 'rbx-sitemap';

	/** @var array<int, string> Tags collected during this request. */
	private static array $tags = array();

	private static function active(): bool {
		return defined( 'LSCWP_V' ) || has_action( 'litespeed_purge' );
	}

	public static function tag( string $tag ): void {
		$tag = strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', $tag ) ?? '' );
		if ( '' === $tag || in_array( $tag, self::$tags, true ) ) {
			return;
		}
		self::$tags[] = $tag;
		if ( self::active() ) {
			do_action( 'litespeed_tag_add', $tag );
		}
	}

	public static function tag_game( Game $game ): void {
		self::tag( 'rbx-' . $game->key() );
	}

	public static function tag_record( string $id ): void {
		self::tag( 'rbx-rec-' . $id );
	}

	/** @return array<int, string> */
	public static function tags(): array {
		return self::$tags;
	}

	/**
	 * @param array<int, string> $record_ids Changed records.
	 */
	public static function after_run( Game $game, array $record_ids ): void {
		if ( ! self::active() ) {
			return;
		}
		if ( count( $record_ids ) > self::PURGE_ALL_OVER ) {
			self::pages();
			return;
		}
		do_action( 'litespeed_purge', 'rbx-' . $game->key() );
		do_action( 'litespeed_purge', self::SITEMAP_TAG );
		foreach ( $record_ids as $id ) {
			do_action( 'litespeed_purge', 'rbx-rec-' . strtolower( $id ) );
		}
	}

	/**
	 * Purges every cached page, and only the pages (GR-02, deploy CPU spikes 2026-10-24).
	 *
	 * `litespeed_purge_all` also empties the object cache and resets OPcache, so every PHP file
	 * is compiled again and every cached query re-run at the moment crawlers arrive. This purges
	 * the page cache alone, marked stale: LiteSpeed keeps serving the old copy of a page while
	 * one request builds the new one, instead of every visitor rendering it at once. A gentle
	 * warm of the pages in search follows (Warm).
	 */
	public static function pages( bool $warm = true ): void {
		if ( self::active() ) {
			if ( class_exists( '\LiteSpeed\Control' ) && method_exists( '\LiteSpeed\Control', 'cls' ) ) {
				\LiteSpeed\Control::cls()->set_stale();
			}
			do_action( 'litespeed_purge', '*' );
		}
		if ( $warm ) {
			Warm::schedule();
		}
	}

	/** Purge everything for one game (settings changes, page setup). */
	public static function game( Game $game ): void {
		if ( self::active() ) {
			do_action( 'litespeed_purge', 'rbx-' . $game->key() );
		}
	}
}
