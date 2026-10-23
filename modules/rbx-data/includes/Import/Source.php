<?php
/**
 * Fetches the raw tabs for an import (DATA-18): published Sheet (per-tab CSV URLs or one
 * published .xlsx URL), or an uploaded .zip of CSVs / .xlsx workbook.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

use RBX\Data\Config\Game;
use RBX\Data\Config\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * Result: [ 'tabs' => [ tab => rows[][] ], 'warnings' => string[] ].
 */
final class Source {

	public const MAX_BYTES = 10485760;

	/** @return array<string, mixed> Saved source settings for a game. */
	public static function settings( Game $game ): array {
		$all = get_option( 'rbx_sheet_urls', array() );
		$s   = is_array( $all ) && isset( $all[ $game->key() ] ) && is_array( $all[ $game->key() ] ) ? $all[ $game->key() ] : array();
		return array(
			'workbook_url' => (string) ( $s['workbook_url'] ?? '' ),
			'tabs'         => is_array( $s['tabs'] ?? null ) ? $s['tabs'] : array(),
		);
	}

	/** @param array<string, mixed> $settings Settings. */
	public static function save_settings( Game $game, array $settings ): void {
		$all = get_option( 'rbx_sheet_urls', array() );
		$all = is_array( $all ) ? $all : array();
		$tabs = array();
		foreach ( (array) ( $settings['tabs'] ?? array() ) as $tab => $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( '' !== $url && ( isset( $game->tabs()[ $tab ] ) || in_array( $tab, array( Tabs::META_TAB, Tabs::ENUMS_TAB ), true ) ) ) {
				$tabs[ $tab ] = $url;
			}
		}
		$all[ $game->key() ] = array(
			'workbook_url' => esc_url_raw( trim( (string) ( $settings['workbook_url'] ?? '' ) ) ),
			'tabs'         => $tabs,
		);
		update_option( 'rbx_sheet_urls', $all, false );
	}

	public static function is_google_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return 'docs.google.com' === $host;
	}

	/**
	 * @return array{tabs: array<string, array<int, array<int, string>>>, warnings: array<int, string>}
	 * @throws \RuntimeException On fetch errors.
	 */
	public static function from_sheet( Game $game ): array {
		$s = self::settings( $game );
		if ( '' !== $s['workbook_url'] ) {
			$tmp = self::download( $s['workbook_url'] );
			try {
				return self::filter( Xlsx::read( $tmp ) );
			} finally {
				wp_delete_file( $tmp );
			}
		}
		if ( ! $s['tabs'] ) {
			throw new \RuntimeException( __( 'No Sheet URLs are saved for this game yet.', 'rbx-data' ) );
		}
		$tabs = array();
		foreach ( $s['tabs'] as $tab => $url ) {
			$tmp = self::download( (string) $url );
			try {
				$tabs[ (string) $tab ] = self::csv( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			} finally {
				wp_delete_file( $tmp );
			}
		}
		return self::filter( $tabs );
	}

	/**
	 * Uploaded .zip of `{tab}.csv` files, or an .xlsx workbook.
	 *
	 * @return array{tabs: array<string, array<int, array<int, string>>>, warnings: array<int, string>}
	 * @throws \RuntimeException On bad files.
	 */
	public static function from_file( string $path, string $original_name ): array {
		if ( filesize( $path ) > self::MAX_BYTES ) {
			throw new \RuntimeException( __( 'The file is larger than 10 MB.', 'rbx-data' ) );
		}
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' === $ext ) {
			return self::filter( Xlsx::read( $path ) );
		}
		if ( 'zip' !== $ext ) {
			throw new \RuntimeException( __( 'Upload a .zip of CSV files or an .xlsx workbook.', 'rbx-data' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( __( 'The ZIP file could not be opened.', 'rbx-data' ) );
		}
		$tabs = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( str_ends_with( $name, '/' ) ) {
				continue;
			}
			// Entries must be plain "{tab}.csv" names: no folders, no traversal (ENG-51).
			if ( ! preg_match( '/^(_?[a-z][a-z0-9_]*)\.csv$/', $name, $m ) ) {
				$zip->close();
				throw new \RuntimeException( sprintf( /* translators: %s: file name inside the ZIP */ __( 'Unexpected file in the ZIP: %s. Only {tab}.csv files at the top level are allowed.', 'rbx-data' ), $name ) );
			}
			$stat = $zip->statIndex( $i );
			if ( ! $stat || $stat['size'] > self::MAX_BYTES ) {
				$zip->close();
				throw new \RuntimeException( __( 'A file in the ZIP is too large.', 'rbx-data' ) );
			}
			$tabs[ $m[1] ] = self::csv( (string) $zip->getFromIndex( $i ) );
		}
		$zip->close();
		return self::filter( $tabs );
	}

	/**
	 * Bundled CSVs in a folder of the theme repo (`data/{game}/{tab}.csv`).
	 *
	 * @return array{tabs: array<string, array<int, array<int, string>>>, warnings: array<int, string>}
	 * @throws \RuntimeException When the folder has no CSV files.
	 */
	public static function from_dir( string $dir ): array {
		$tabs = array();
		foreach ( (array) glob( untrailingslashit( $dir ) . '/*.csv' ) as $path ) {
			if ( ! preg_match( '/^(_?[a-z][a-z0-9_]*)\.csv$/', basename( (string) $path ), $m ) ) {
				continue;
			}
			if ( filesize( (string) $path ) > self::MAX_BYTES ) {
				throw new \RuntimeException( sprintf( /* translators: %s: file name */ __( '%s is larger than 10 MB.', 'rbx-data' ), basename( (string) $path ) ) );
			}
			$tabs[ $m[1] ] = self::csv( (string) file_get_contents( (string) $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( ! $tabs ) {
			throw new \RuntimeException( __( 'The bundled data folder has no CSV files.', 'rbx-data' ) );
		}
		return self::filter( $tabs );
	}

	/**
	 * Drops tabs that must never be imported (ED-10) with a warning.
	 *
	 * @param array<string, array<int, array<int, string>>> $tabs Raw tabs.
	 * @return array{tabs: array<string, array<int, array<int, string>>>, warnings: array<int, string>}
	 */
	public static function filter( array $tabs ): array {
		$warnings = array();
		$out      = array();
		foreach ( $tabs as $name => $rows ) {
			$name = trim( (string) $name );
			if ( str_starts_with( $name, '_' ) && ! in_array( $name, array( Tabs::META_TAB, Tabs::ENUMS_TAB ), true ) ) {
				/* translators: %s: tab name */
				$warnings[] = sprintf( __( 'The tab "%s" is published or included but must stay private. It was ignored. Unpublish it in Google Sheets (File, Share, Publish to web).', 'rbx-data' ), $name );
				continue;
			}
			$out[ $name ] = $rows;
		}
		return array(
			'tabs'     => $out,
			'warnings' => $warnings,
		);
	}

	/** @return array<int, array<int, string>> */
	public static function csv( string $text ): array {
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text ) ?? $text;
		$rows = array();
		$fh   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return $rows;
		}
		fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$rows[] = array_map( static fn( $c ) => (string) $c, $r );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/** @throws \RuntimeException On HTTP errors. */
	private static function download( string $url ): string {
		if ( ! self::is_google_url( $url ) ) {
			throw new \RuntimeException( __( 'Only docs.google.com "Publish to web" links are accepted.', 'rbx-data' ) );
		}
		$tmp = wp_tempnam( 'rbx-import' );
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_BYTES,
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			/* translators: %d: HTTP status code */
			throw new \RuntimeException( sprintf( __( 'Google Sheets answered with HTTP %d. Check that the tab is published to the web.', 'rbx-data' ), $code ) );
		}
		return $tmp;
	}
}
