<?php
/**
 * One game's configuration (DATA-40). Structure only; game facts live in `_meta`.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only accessor around a validated game config array.
 */
final class Game {

	/** Post type key per page-bearing entity when config does not set one. */
	private const DEFAULT_POST_TYPES = array(
		'PET' => 'rbx_pet',
		'EGG' => 'rbx_egg',
		'MUT' => 'rbx_mutation',
		'PLT' => 'rbx_plant',
		'GER' => 'rbx_gear',
		'BIO' => 'rbx_biome',
		'MNT' => 'rbx_mount',
		'UPD' => 'rbx_update',
	);

	/**
	 * @param array<string, mixed> $config Validated config.
	 */
	public function __construct( private array $config ) {}

	/** @return array<string, mixed> */
	public function raw(): array {
		return $this->config;
	}

	public function code(): string {
		return (string) $this->config['game_code'];
	}

	/** Lower-case code used in URLs, file paths and REST routes. */
	public function key(): string {
		return strtolower( $this->code() );
	}

	public function name(): string {
		return (string) $this->config['name'];
	}

	public function short_name(): string {
		return (string) ( $this->config['short_name'] ?? $this->config['name'] );
	}

	public function folder(): string {
		return (string) $this->config['folder'];
	}

	public function freshness_days(): int {
		return (int) ( $this->config['freshness_days'] ?? 30 );
	}

	/** Live tests a live formula needs (DATA-34 default 3; a game config may lower it by owner decision). */
	public function min_formula_tests(): int {
		return max( 1, (int) ( $this->config['min_formula_tests'] ?? 3 ) );
	}

	/** Owner decision: one community source is enough for calculator use (shown as "Source: community wiki"; DATA-13 default needs 2). */
	public function community_single_source(): bool {
		return ! empty( $this->config['community_single_source_calc'] );
	}

	/** Owner decision: community values may count for indexing (I-1, I-6) and no screenshot is required (I-5); I-3 notes still gate. */
	public function index_community(): bool {
		return ! empty( $this->config['index_community_data'] );
	}

	public function schema_version(): string {
		return (string) ( $this->config['schema_version'] ?? '1.0' );
	}

	public function universe_id(): ?string {
		$v = $this->config['roblox_universe_id'] ?? null;
		return null === $v || '' === $v ? null : (string) $v;
	}

	public function place_id(): ?string {
		$v = $this->config['roblox_place_id'] ?? null;
		return null === $v || '' === $v ? null : (string) $v;
	}

	public function accent(): string {
		return (string) ( $this->config['game_accent'] ?? '#5032C8' );
	}

	/** @return array<int, string> */
	public function menu_order(): array {
		return array_values( (array) ( $this->config['menu_order'] ?? array() ) );
	}

	/** @return array<string, array<string, mixed>> */
	public function sections(): array {
		return (array) ( $this->config['sections'] ?? array() );
	}

	/** @return array<string, mixed>|null */
	public function section( string $slug ): ?array {
		return $this->config['sections'][ $slug ] ?? null;
	}

	/** @return array<int, array<string, mixed>> */
	public function update_windows(): array {
		return (array) ( $this->config['notifier']['update_windows'] ?? array() );
	}

	/** @return array<string, array<string, mixed>> */
	public function entities(): array {
		return (array) $this->config['entities'];
	}

	public function has_entity( string $code ): bool {
		return isset( $this->config['entities'][ $code ] ) || in_array( $code, self::special_codes(), true );
	}

	/** @return array<string, mixed>|null */
	public function entity( string $code ): ?array {
		return $this->config['entities'][ $code ] ?? null;
	}

	/** @return array<int, string> */
	public static function special_codes(): array {
		return array_map( static fn( $t ) => $t['code'], Tabs::special() );
	}

	/** Entity codes that generate item pages. @return array<int, string> */
	public function page_entities(): array {
		$out = array();
		foreach ( $this->entities() as $code => $def ) {
			if ( ! empty( $def['page'] ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	public function post_type( string $code ): ?string {
		$def = $this->entity( $code );
		if ( null === $def ) {
			return null;
		}
		return $def['post_type'] ?? ( self::DEFAULT_POST_TYPES[ $code ] ?? null );
	}

	public function path( string $code ): ?string {
		$def = $this->entity( $code );
		return $def['path'] ?? null;
	}

	public function is_named( string $code ): bool {
		return ! empty( $this->entity( $code )['named'] );
	}

	public function is_slugged( string $code ): bool {
		$def = $this->entity( $code );
		return ! empty( $def['named'] ) || ! empty( $def['slugged'] );
	}

	/**
	 * Tab name => entity code for every importable tab (entity + special).
	 *
	 * @return array<string, string>
	 */
	public function tabs(): array {
		$out = array();
		foreach ( $this->entities() as $code => $def ) {
			$out[ (string) $def['tab'] ] = $code;
		}
		foreach ( Tabs::special() as $tab => $def ) {
			$out[ $tab ] = $def['code'];
		}
		return $out;
	}

	public function tab_for( string $code ): ?string {
		$tab = array_search( $code, $this->tabs(), true );
		return false === $tab ? null : (string) $tab;
	}

	/**
	 * Ordered column definitions for a tab, including `id`, `slug`, `name` and the common tail.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function columns( string $code ): array {
		foreach ( Tabs::special() as $def ) {
			if ( $def['code'] === $code ) {
				return array_merge( array( 'id' => array( 'type' => 'id', 'required' => true ) ), $def['fields'] );
			}
		}
		$def = $this->entity( $code );
		if ( null === $def ) {
			return array();
		}
		$cols = array( 'id' => array( 'type' => 'id', 'required' => true ) );
		if ( $this->is_slugged( $code ) ) {
			$cols['slug'] = array( 'type' => 'slug', 'required' => true );
		}
		if ( $this->is_named( $code ) ) {
			$cols['name'] = array( 'type' => 'string', 'required' => true, 'label__en' => 'Name' );
		}
		foreach ( (array) $def['fields'] as $field => $fdef ) {
			$cols[ $field ] = $fdef;
		}
		return array_merge( $cols, Tabs::common_tail( $this->is_slugged( $code ) ) );
	}

	/** @return array<string, mixed>|null */
	public function field( string $code, string $field ): ?array {
		return $this->columns( $code )[ $field ] ?? null;
	}

	public function label( string $code, string $field ): string {
		$f = $this->field( $code, $field );
		if ( null !== $f && isset( $f['label__en'] ) ) {
			return (string) $f['label__en'];
		}
		return ucfirst( str_replace( '_', ' ', preg_replace( '/__[a-z]{2}$/', '', $field ) ?? $field ) );
	}

	public function entity_label( string $code, bool $plural = false ): string {
		$def = $this->entity( $code );
		if ( null === $def ) {
			return $code;
		}
		return (string) ( $plural ? ( $def['plural__en'] ?? $def['label__en'] ?? $code ) : ( $def['label__en'] ?? $code ) );
	}

	/** @return array<int, array<string, mixed>> */
	public function tools(): array {
		return (array) ( $this->config['tools'] ?? array() );
	}

	/** @return array<string, mixed>|null */
	public function tool( string $key ): ?array {
		foreach ( $this->tools() as $tool ) {
			if ( ( $tool['key'] ?? '' ) === $key ) {
				return $tool;
			}
		}
		return null;
	}

	/** Item permalink path (relative, with slashes) for a record slug (DATA-41). */
	public function item_path( string $code, string $slug ): string {
		return '/' . $this->folder() . '/' . $this->path( $code ) . '/' . $slug . '/';
	}

	/** Reserved slugs for an entity path (DATA-03). @return array<int, string> */
	public function reserved_slugs( string $code ): array {
		$reserved = array( 'rarity', 'biome', 'compare', 'all', 'new', 'page', 'feed', 'calculator', 'list', 'tier-list', 'values', 'amp' );
		return array_merge( $reserved, array_keys( $this->sections() ) );
	}
}
