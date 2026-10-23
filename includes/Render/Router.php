<?php
/**
 * Item permalinks from config (DATA-41, ENG-21): rewrite rules, resolver, 301 slug history (DATA-03),
 * post_type_link, and the template loader.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Render;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Data\Repository;
use RBX\Data\Db\Records;
use RBX\Data\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Only single-entity item routes are registered (DATA-24b). Lists and tools are WP Pages.
 */
final class Router {

	private static string $redirect = '';

	public static function boot(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'request', array( self::class, 'resolve' ) );
		add_action( 'template_redirect', array( self::class, 'redirect' ), 1 );
		add_filter( 'post_type_link', array( self::class, 'permalink' ), 10, 2 );
		add_filter( 'template_include', array( self::class, 'template' ), 20 );
		add_filter( 'redirect_canonical', array( self::class, 'no_guess_redirect' ), 10, 2 );
		add_action( 'wp', array( self::class, 'cache_tags' ) );
	}

	/** LiteSpeed tags before any output (ENG-29). */
	public static function cache_tags(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$game = Breadcrumbs::game_for( $post );
		if ( null === $game ) {
			return;
		}
		\RBX\Data\Cache\Purge::tag_game( $game );
		$rid = (string) get_post_meta( $post->ID, '_rbx_record_id', true );
		if ( '' !== $rid ) {
			\RBX\Data\Cache\Purge::tag_record( $rid );
		}
	}

	public static function register(): void {
		foreach ( Loader::games() as $game ) {
			foreach ( $game->page_entities() as $code ) {
				$path = $game->path( $code );
				if ( null === $path ) {
					continue;
				}
				add_rewrite_rule(
					'^' . preg_quote( $game->folder(), '#' ) . '/' . preg_quote( $path, '#' ) . '/([a-z0-9-]+)/?$',
					'index.php?rbx_game=' . $game->key() . '&rbx_entity=' . $code . '&rbx_slug=$matches[1]',
					'top'
				);
			}
		}
	}

	/** @param array<int, string> $vars Public query vars. */
	public static function query_vars( array $vars ): array {
		return array_merge( $vars, array( 'rbx_game', 'rbx_entity', 'rbx_slug' ) );
	}

	/**
	 * Resolves by (game, entity, slug) in rbx_records, then queries the shell by ID (ENG-81).
	 *
	 * @param array<string, mixed> $qv Query vars.
	 * @return array<string, mixed>
	 */
	public static function resolve( array $qv ): array {
		if ( empty( $qv['rbx_slug'] ) || empty( $qv['rbx_game'] ) || empty( $qv['rbx_entity'] ) ) {
			return $qv;
		}
		$game   = Loader::game( (string) $qv['rbx_game'] );
		$entity = (string) $qv['rbx_entity'];
		$slug   = (string) $qv['rbx_slug'];
		if ( null === $game || null === $game->post_type( $entity ) ) {
			return array( 'error' => '404' );
		}
		$rec = Records::by_slug( $game->code(), $entity, $slug );
		if ( null === $rec ) {
			$new = self::history( $game, $entity, $slug );
			if ( null !== $new ) {
				self::$redirect = home_url( $game->item_path( $entity, $new ) );
				return array( 'error' => '404' );
			}
			return array( 'error' => '404' );
		}
		$post_id = (int) $rec['post_id'];
		if ( ! $post_id || ( 'publish' !== get_post_status( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) ) {
			return array( 'error' => '404' );
		}
		return array(
			'p'         => $post_id,
			'post_type' => (string) $game->post_type( $entity ),
			'rbx_game'  => $game->key(),
		);
	}

	/** Old slug → current slug (DATA-03). */
	private static function history( Game $game, string $entity, string $slug ): ?string {
		foreach ( Repository::dataset( $game )->all( $entity, null ) as $rec ) {
			if ( in_array( $slug, (array) ( $rec['data']['slug_history'] ?? array() ), true ) && ! empty( $rec['slug'] ) ) {
				return (string) $rec['slug'];
			}
		}
		return null;
	}

	public static function redirect(): void {
		if ( '' !== self::$redirect ) {
			wp_safe_redirect( self::$redirect, 301, 'UpdateOtter' );
			exit;
		}
	}

	/**
	 * Config permalink; post_name is "{game}-{slug}" internally (DATA-21).
	 */
	public static function permalink( string $link, \WP_Post $post ): string {
		if ( ! str_starts_with( $post->post_type, 'rbx_' ) ) {
			return $link;
		}
		$game   = Plugin::game_for_post( $post->ID );
		$entity = (string) get_post_meta( $post->ID, '_rbx_entity', true );
		if ( null === $game || '' === $entity || ! str_starts_with( $post->post_name, $game->key() . '-' ) ) {
			return $link;
		}
		$slug = substr( $post->post_name, strlen( $game->key() ) + 1 );
		return home_url( $game->item_path( $entity, $slug ) );
	}

	/** Stops WordPress guessing URLs for unknown item slugs (a 404 stays a 404). */
	public static function no_guess_redirect( mixed $redirect, string $requested ): mixed {
		return get_query_var( 'rbx_slug' ) ? false : $redirect;
	}

	/**
	 * Item and update shells use the plugin templates unless the theme provides `rbx/{name}.php`.
	 */
	public static function template( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Plugin::post_types(), true ) ) {
			return $template;
		}
		$name  = 'rbx_update' === $post->post_type ? 'update' : 'item';
		$theme = locate_template( array( 'rbx/' . $name . '.php' ) );
		if ( '' !== $theme ) {
			return $theme;
		}
		return RBX_DATA_DIR . '/templates/' . $name . '.php';
	}
}
