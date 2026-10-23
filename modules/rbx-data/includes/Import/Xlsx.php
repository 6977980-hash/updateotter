<?php
/**
 * Minimal XLSX reader: sheet name => rows of strings. Used for the published-workbook
 * and uploaded-workbook import paths (DATA-18 fallback). No external libraries.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads cell values as text; numeric cells keep their raw value (date serials are converted later by type).
 */
final class Xlsx {

	/**
	 * @return array<string, array<int, array<int, string>>>
	 * @throws \RuntimeException On unreadable files.
	 */
	public static function read( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'The PHP zip extension is missing.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( 'Could not open the workbook.' );
		}
		$shared = array();
		$ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$xml = self::xml( $ss );
			foreach ( $xml->si as $si ) {
				$shared[] = self::si_text( $si );
			}
		}
		$wb   = self::xml( (string) $zip->getFromName( 'xl/workbook.xml' ) );
		$rels = self::xml( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
		$map  = array();
		foreach ( $rels->Relationship as $r ) {
			$map[ (string) $r['Id'] ] = (string) $r['Target'];
		}
		$out = array();
		foreach ( $wb->sheets->sheet as $sheet ) {
			$name   = (string) $sheet['name'];
			$rid    = (string) $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )['id'];
			$target = $map[ $rid ] ?? '';
			$target = ltrim( str_starts_with( $target, '/' ) ? substr( $target, 1 ) : 'xl/' . $target, '/' );
			$data   = $zip->getFromName( $target );
			if ( false === $data ) {
				continue;
			}
			$out[ $name ] = self::sheet_rows( self::xml( $data ), $shared );
		}
		$zip->close();
		return $out;
	}

	private static function xml( string $s ): \SimpleXMLElement {
		$prev = libxml_use_internal_errors( true );
		$x    = simplexml_load_string( $s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_use_internal_errors( $prev );
		if ( false === $x ) {
			throw new \RuntimeException( 'Workbook XML could not be read.' );
		}
		return $x;
	}

	private static function si_text( \SimpleXMLElement $si ): string {
		if ( isset( $si->t ) ) {
			return (string) $si->t;
		}
		$t = '';
		foreach ( $si->r as $r ) {
			$t .= (string) $r->t;
		}
		return $t;
	}

	/**
	 * @param array<int, string> $shared Shared strings.
	 * @return array<int, array<int, string>>
	 */
	private static function sheet_rows( \SimpleXMLElement $x, array $shared ): array {
		$rows = array();
		if ( ! isset( $x->sheetData ) ) {
			return $rows;
		}
		foreach ( $x->sheetData->row as $row ) {
			$r   = (int) $row['r'] - 1;
			$out = array();
			foreach ( $row->c as $c ) {
				$ref = (string) $c['r'];
				$col = self::col_index( preg_replace( '/\d+/', '', $ref ) ?? '' );
				$t   = (string) $c['t'];
				if ( 's' === $t ) {
					$v = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $t ) {
					$v = self::si_text( $c->is );
				} elseif ( 'b' === $t ) {
					$v = '1' === (string) $c->v ? 'TRUE' : 'FALSE';
				} else {
					$v = (string) $c->v;
				}
				$out[ $col ] = $v;
			}
			if ( $out ) {
				$max  = max( array_keys( $out ) );
				$full = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$full[] = $out[ $i ] ?? '';
				}
				$rows[ $r ] = $full;
			}
		}
		ksort( $rows );
		$dense = array();
		$last  = $rows ? max( array_keys( $rows ) ) : -1;
		for ( $i = 0; $i <= $last; $i++ ) {
			$dense[] = $rows[ $i ] ?? array();
		}
		return $dense;
	}

	private static function col_index( string $letters ): int {
		$n = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}
}
