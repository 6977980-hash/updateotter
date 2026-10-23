<?php
/**
 * Bundled modules: RBX Data, RBX Tools and RBX Notifier live in modules/ and deploy with the
 * theme through Hostinger Git (owner decision 2026-10-23, spec §13.3 v1.3). No separate plugin
 * install. If a module is also active as a plugin, the plugin wins and the theme copy is skipped.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** Bump to re-run the install steps (tables, roles, rewrite rules, cron) after a deploy. */
const UPDATEOTTER_MODULES_VERSION = '1.0.0';

/** @return array<string, array{const: string, file: string}> */
function updateotter_modules(): array {
	return array(
		'rbx-data'     => array(
			'const' => 'RBX_DATA',
			'file'  => 'rbx-data.php',
		),
		'rbx-tools'    => array(
			'const' => 'RBX_TOOLS',
			'file'  => 'rbx-tools.php',
		),
		'rbx-notifier' => array(
			'const' => 'RBX_NOTIFIER',
			'file'  => 'rbx-notifier.php',
		),
	);
}

$updateotter_skipped = array();
foreach ( updateotter_modules() as $updateotter_slug => $updateotter_m ) {
	if ( defined( $updateotter_m['const'] . '_VERSION' ) ) {
		$updateotter_skipped[] = $updateotter_slug; // Active as a plugin.
		continue;
	}
	define( $updateotter_m['const'] . '_URL', get_template_directory_uri() . '/modules/' . $updateotter_slug . '/' );
	require get_template_directory() . '/modules/' . $updateotter_slug . '/' . $updateotter_m['file'];
}

if ( $updateotter_skipped ) {
	add_action(
		'admin_notices',
		static function () use ( $updateotter_skipped ): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html(
					sprintf(
						/* translators: %s: plugin names */
						__( 'The UpdateOtter theme already includes %s. Deactivate and delete the separate plugin copy so updates come from Git.', 'updateotter' ),
						implode( ', ', $updateotter_skipped )
					)
				) . '</p></div>';
			}
		}
	);
}
unset( $updateotter_skipped, $updateotter_slug, $updateotter_m );

/**
 * Install steps that a plugin would run on activation. Runs once per UPDATEOTTER_MODULES_VERSION,
 * after post types are registered.
 */
add_action(
	'init',
	static function (): void {
		if ( UPDATEOTTER_MODULES_VERSION === get_option( 'updateotter_modules_installed' ) ) {
			return;
		}
		if ( class_exists( '\RBX\Data\Plugin' ) ) {
			\RBX\Data\Plugin::activate();
		}
		if ( class_exists( '\RBX\Notifier\Plugin' ) ) {
			\RBX\Notifier\Plugin::activate();
		}
		update_option( 'updateotter_modules_installed', UPDATEOTTER_MODULES_VERSION, true );
	},
	99
);

// Switching away from the theme stops the module cron jobs; switching back re-runs the install.
add_action(
	'switch_theme',
	static function (): void {
		if ( class_exists( '\RBX\Notifier\Plugin' ) ) {
			\RBX\Notifier\Plugin::deactivate();
		}
		if ( class_exists( '\RBX\Data\Plugin' ) ) {
			\RBX\Data\Plugin::deactivate();
		}
		delete_option( 'updateotter_modules_installed' );
	}
);
