<?php
/**
 * Bootstrap: CPTs, meta, roles, migrations, cron, and wiring of every subsystem.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data;

use RBX\Data\Config\Game;
use RBX\Data\Config\Loader;
use RBX\Data\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton plugin controller.
 */
final class Plugin {

	public const CAPS = array( 'rbx_import', 'rbx_view_health', 'rbx_notifier_view', 'rbx_notifier_ack', 'rbx_reports_manage', 'rbx_codes_log' );

	/** Mapped primitives for capability_type rbx_record/rbx_records. */
	public const CPT_CAPS = array( 'edit_rbx_record', 'read_rbx_record', 'delete_rbx_record', 'edit_rbx_records', 'edit_others_rbx_records', 'publish_rbx_records', 'read_private_rbx_records', 'delete_rbx_records', 'delete_private_rbx_records', 'delete_published_rbx_records', 'delete_others_rbx_records', 'edit_private_rbx_records', 'edit_published_rbx_records', 'create_rbx_records' );

	/** Plural labels per post type. */
	private const PT_LABELS = array(
		'rbx_pet'      => array( 'Pets', 'Pet' ),
		'rbx_egg'      => array( 'Eggs', 'Egg' ),
		'rbx_mutation' => array( 'Mutations', 'Mutation' ),
		'rbx_plant'    => array( 'Plants', 'Plant' ),
		'rbx_gear'     => array( 'Gear', 'Gear item' ),
		'rbx_biome'    => array( 'Biomes', 'Biome' ),
		'rbx_mount'    => array( 'Mounts', 'Mount' ),
		'rbx_update'   => array( 'Updates', 'Update' ),
	);

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'init' ), 5 );
		add_action( 'init', array( Render\Router::class, 'register' ), 6 );
		add_action( did_action( 'plugins_loaded' ) ? 'init' : 'plugins_loaded', array( $this, 'maybe_migrate' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_flush' ) );
		add_filter( 'cron_schedules', array( $this, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'rbx_data_nightly_gate', array( $this, 'nightly' ) );
		add_action( 'rbx_data_prune', array( $this, 'prune' ) );
		add_filter( 'pre_delete_post', array( $this, 'guard_delete' ), 10, 2 );

		Security\Hardening::boot();
		Lint\NumberLint::boot();
		Render\Router::boot();
		Render\Bindings::boot();
		Render\Shortcodes::boot();
		Render\Blocks::boot();
		Render\Seo::boot();
		Render\Media::boot();
		Render\Pages::boot();
		Render\Report::boot();
		Reports\Endpoint::boot();
		Rest\Routes::boot();
		Import\Bundled::boot();
		Content\BundledPages::boot();
		Content\Lang::boot();
		Index\IndexNow::boot();
		Index\Llms::boot();
		if ( is_admin() ) {
			Admin\Menu::boot();
			Admin\Editor::boot();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'rbx', Cli\Command::class );
		}
	}

	public function init(): void {
		load_textdomain( 'rbx-data', RBX_DATA_DIR . '/languages/rbx-data-' . determine_locale() . '.mo' );
		foreach ( self::post_types() as $pt ) {
			$this->register_post_type( $pt );
		}
		$this->register_meta();
	}

	/** @return array<int, string> Post types used by any configured game. */
	public static function post_types(): array {
		$out = array();
		foreach ( Loader::games() as $game ) {
			foreach ( $game->page_entities() as $e ) {
				$pt = (string) $game->post_type( $e );
				if ( '' !== $pt ) {
					$out[ $pt ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	private function register_post_type( string $pt ): void {
		[ $plural, $single ] = self::PT_LABELS[ $pt ] ?? array( ucfirst( substr( $pt, 4 ) ) . 's', ucfirst( substr( $pt, 4 ) ) );
		register_post_type(
			$pt,
			array(
				'labels'              => array(
					// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText
					'name'          => __( $plural, 'rbx-data' ),
					'singular_name' => __( $single, 'rbx-data' ),
					'menu_name'     => __( $plural, 'rbx-data' ),
					'all_items'     => __( $plural, 'rbx-data' ),
					/* translators: %s: item type */
					'edit_item'     => sprintf( __( 'Edit %s', 'rbx-data' ), __( $single, 'rbx-data' ) ),
					// phpcs:enable
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_in_rest'        => true,
				'show_in_menu'        => 'rbx-data',
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'excerpt', 'revisions', 'custom-fields', 'thumbnail', 'author' ),
				'capability_type'     => array( 'rbx_record', 'rbx_records' ),
				'map_meta_cap'        => true,
				'delete_with_user'    => false,
			)
		);
	}

	/** ENG-22. */
	private function register_meta(): void {
		$readonly = array(
			'_rbx_record_id'          => 'string',
			'_rbx_game'               => 'string',
			'_rbx_entity'             => 'string',
			'_rbx_page_state'         => 'string',
			'_rbx_gate_reasons'       => 'string',
			'_rbx_last_changed'       => 'string',
			'_rbx_page_last_verified' => 'string',
		);
		$editable = array(
			'_rbx_index_override'  => array( '', 'noindex', 'index' ),
			'_rbx_edit_kind'       => array( 'minor', 'substantive' ),
			'_rbx_template'        => array( '', 'guide' ),
			'_rbx_manual_index_ok' => null,
			'faq_sources'          => null,
			'ai_assist'            => null,
			'fact_checked_by'      => null,
		);
		foreach ( array_merge( self::post_types(), array( 'page' ) ) as $pt ) {
			foreach ( $readonly as $key => $type ) {
				register_post_meta(
					$pt,
					$key,
					array(
						'type'              => $type,
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => 'sanitize_text_field',
						// Read-only over REST: only server code writes these.
						'auth_callback'     => '__return_false',
					)
				);
			}
			foreach ( $editable as $key => $enum ) {
				register_post_meta(
					$pt,
					$key,
					array(
						'type'              => '_rbx_manual_index_ok' === $key ? 'boolean' : 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => static function ( $v ) use ( $enum, $key ) {
							if ( '_rbx_manual_index_ok' === $key ) {
								return (bool) $v;
							}
							$v = sanitize_text_field( (string) $v );
							return null === $enum || in_array( $v, $enum, true ) ? $v : '';
						},
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							if ( in_array( $meta_key, array( '_rbx_index_override', '_rbx_manual_index_ok' ), true ) ) {
								return current_user_can( 'rbx_import' ) && current_user_can( 'edit_post', $post_id );
							}
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}
	}

	/* ---------------------------------------------------------------- lifecycle */

	public static function activate(): void {
		Schema::migrate();
		self::add_roles();
		self::instance()->init();
		Render\Router::register();
		flush_rewrite_rules( false );
		update_option( 'rbx_config_hash', Loader::hash(), false );
		if ( ! wp_next_scheduled( 'rbx_data_nightly_gate' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:10 UTC' ), 'daily', 'rbx_data_nightly_gate' );
		}
		if ( ! wp_next_scheduled( 'rbx_data_prune' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:40 UTC' ), 'daily', 'rbx_data_prune' );
		}
		// The site stays behind the holding page until Phase 1c turns this off (Setup screen or `wp rbx prelaunch off`).
		add_option( 'rbx_prelaunch_lock', 1, '', true );
		if ( false === get_option( 'rbx_report_salt', false ) ) {
			update_option( 'rbx_report_salt', wp_generate_password( 32, false ), false );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rbx_data_nightly_gate' );
		wp_clear_scheduled_hook( 'rbx_data_prune' );
		flush_rewrite_rules( false );
	}

	/** ENG-24. */
	public static function add_roles(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::CAPS, self::CPT_CAPS ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		$caps = array(
			'read'              => true,
			'edit_posts'        => true,
			'edit_pages'        => true,
			'edit_others_pages' => true,
			'edit_published_pages' => true,
			'publish_pages'     => true,
			'upload_files'      => true,
		);
		foreach ( array_merge( self::CAPS, self::CPT_CAPS ) as $cap ) {
			$caps[ $cap ] = true;
		}
		remove_role( 'rbx_data_editor' );
		add_role( 'rbx_data_editor', __( 'RBX Data Editor', 'rbx-data' ), $caps );
	}

	/**
	 * Runs dbDelta when the DB version changes. A schema change needs the backup box ticked once
	 * (option rbx_backup_confirmed set from Data health) unless the tables do not exist yet.
	 */
	public function maybe_migrate(): void {
		if ( ! Schema::needs_migration() ) {
			return;
		}
		if ( Schema::installed() && ! get_option( 'rbx_backup_confirmed' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'manage_options' ) ) {
						echo '<div class="notice notice-warning"><p>' . esc_html__( 'RBX Data needs a database update. Take a backup in hPanel, then confirm it on RBX Data, Data health.', 'rbx-data' ) . '</p></div>';
					}
				}
			);
			return;
		}
		Schema::migrate();
		delete_option( 'rbx_backup_confirmed' );
	}

	/** Re-registers rules when a games/*.json file changed (ENG-21). */
	public function maybe_flush(): void {
		$hash = Loader::hash();
		if ( get_option( 'rbx_config_hash' ) !== $hash ) {
			flush_rewrite_rules( false );
			update_option( 'rbx_config_hash', $hash, false );
		}
	}

	/** @param array<string, array<string, mixed>> $s Schedules. */
	public function schedules( array $s ): array {
		$s['rbx_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'rbx-data' ),
		);
		return $s;
	}

	/** Nightly gate: freshness decay can change page states without an import (DATA-20 I-3). */
	public function nightly(): void {
		foreach ( Loader::games() as $game ) {
			try {
				$t = Gate\Runner::run( $game );
				$changed = array_keys( array_filter( $t, static fn( $x ) => $x['old'] !== $x['new'] ) );
				if ( $changed ) {
					Cache\Purge::after_run( $game, $changed );
					Export\SearchIndex::write();
				}
			} catch ( \Throwable $e ) {
				Log\ErrorLog::add( 'gate', $e->getMessage() );
			}
		}
	}

	public function prune(): void {
		Reports\Queue::prune();
		foreach ( Loader::games() as $game ) {
			Db\Runs::prune_snapshots( $game->code() );
		}
	}

	/**
	 * Shells of records are never deleted directly (DATA-22): delete the record via Records first.
	 *
	 * @param mixed    $check Short-circuit value.
	 * @param \WP_Post $post  Post.
	 */
	public function guard_delete( mixed $check, \WP_Post $post ): mixed {
		if ( in_array( $post->post_type, self::post_types(), true ) && get_post_meta( $post->ID, '_rbx_record_id', true ) && ! doing_action( 'rbx_hard_delete' ) ) {
			$rec = Db\Records::by_post( $post->ID );
			if ( $rec ) {
				return false;
			}
		}
		return $check;
	}

	public static function game_for_post( int $post_id ): ?Game {
		$key = (string) get_post_meta( $post_id, '_rbx_game', true );
		return '' === $key ? null : Loader::game( $key );
	}
}
