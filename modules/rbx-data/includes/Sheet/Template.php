<?php
/**
 * Template workbook from config (DATA-16, DATA-17): `wp rbx sheet-template {game}` and the Setup
 * screen download. Plain SpreadsheetML written with ZipArchive; Google Sheets keeps the header
 * row, frozen panes and data validation when the file is imported.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Sheet;

use RBX\Data\Config\Game;
use RBX\Data\Config\Tabs;
use RBX\Data\Import\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Validation formulas use functions that exist in both Excel and Google Sheets.
 */
final class Template {

	private const ROWS = 2000;

	/**
	 * Sheets in DATA-16 order: name => [ header[], rows[][], validations[] ].
	 *
	 * @return array<string, array{0: array<int, string>, 1: array<int, array<int, string>>, 2: array<int, array<string, string>>}>
	 */
	public static function sheets( Game $game ): array {
		$out   = array();
		$tabs  = $game->tabs();
		$enums = array();

		$readme = array();
		foreach ( $tabs as $tab => $code ) {
			foreach ( $game->columns( $code ) as $field => $def ) {
				$readme[] = array(
					$tab,
					$field,
					(string) ( $def['type'] ?? '' ),
					(string) ( $def['display_format'] ?? '' ),
					empty( $def['required'] ) ? '' : 'yes',
					empty( $def['required_for_index'] ) ? '' : 'yes',
					implode( ', ', (array) ( $def['requires_mechanics'] ?? array() ) ),
					isset( $def['target'] ) ? implode( '|', (array) $def['target'] ) : ( isset( $def['enum'] ) ? implode( '|', (array) $def['enum'] ) : '' ),
					(string) ( $def['label__en'] ?? '' ),
				);
			}
		}
		$out['_README'] = array( array( 'tab', 'field', 'type', 'display_format', 'required', 'required_for_index', 'mechanics', 'allowed values / target', 'label' ), $readme, array() );

		$meta = array();
		foreach ( array_keys( Normalizer::META_TYPES ) as $k ) {
			$v = '';
			if ( 'game_code' === $k ) {
				$v = $game->code();
			} elseif ( 'schema_version' === $k ) {
				$v = $game->schema_version();
			} elseif ( 'game_name' === $k ) {
				$v = $game->name();
			} elseif ( 'roblox_universe_id' === $k ) {
				$v = (string) $game->universe_id();
			} elseif ( 'roblox_place_id' === $k ) {
				$v = (string) $game->place_id();
			}
			$meta[] = array( $k, $v, '', '', '', '' );
		}
		$out['_meta'] = array(
			array( 'key', 'value', 'source_type', 'source_url', 'last_verified', 'verified_by' ),
			$meta,
			array(
				self::list_rule( 'C2:C200', Tabs::SOURCE_TYPES ),
				self::date_rule( 'E2:E200' ),
			),
		);

		foreach ( $tabs as $tab => $code ) {
			foreach ( $game->columns( $code ) as $field => $def ) {
				if ( 'enum' === ( $def['type'] ?? '' ) && ! isset( $enums[ $field ] ) ) {
					$enums[ $field ] = array_values( (array) $def['enum'] );
				}
			}
		}
		$enum_rows = array();
		$max       = $enums ? max( array_map( 'count', $enums ) ) : 0;
		for ( $i = 0; $i < $max; $i++ ) {
			$row = array();
			foreach ( $enums as $vals ) {
				$row[] = (string) ( $vals[ $i ] ?? '' );
			}
			$enum_rows[] = $row;
		}
		$out['_enums'] = array( array_keys( $enums ), $enum_rows, array() );

		$order   = array_merge( array_keys( $game->entities() ) );
		$special = array();
		foreach ( Tabs::special() as $stab => $sdef ) {
			$special[ $stab ] = $sdef['code'];
		}
		foreach ( $order as $code ) {
			$tab = (string) $game->tab_for( $code );
			$out[ $tab ] = self::entity_sheet( $game, $code, $tabs );
		}
		foreach ( $special as $stab => $code ) {
			$out[ $stab ] = self::entity_sheet( $game, $code, $tabs );
		}
		$out['_internal'] = array( array( 'note', 'record_id', 'lead_url', 'added_by', 'added_on' ), array(), array() );
		return $out;
	}

	/**
	 * @param array<string, string> $tabs Tab => code.
	 * @return array{0: array<int, string>, 1: array<int, array<int, string>>, 2: array<int, array<string, string>>}
	 */
	private static function entity_sheet( Game $game, string $code, array $tabs ): array {
		$cols   = $game->columns( $code );
		$header = array_keys( $cols );
		$rules  = array();
		$i      = 0;
		foreach ( $cols as $field => $def ) {
			$col   = self::col( $i++ );
			$range = $col . '2:' . $col . self::ROWS;
			$type  = (string) ( $def['type'] ?? '' );
			if ( 'id' === $type ) {
				$prefix  = $game->code() . '-' . $code . '-';
				$rules[] = array(
					'type'    => 'custom',
					'sqref'   => $range,
					'formula' => 'AND(LEFT(' . $col . '2,' . strlen( $prefix ) . ')="' . $prefix . '",LEN(' . $col . '2)>=' . ( strlen( $prefix ) + 4 ) . ',LEN(' . $col . '2)<=' . ( strlen( $prefix ) + 6 ) . ',ISNUMBER(VALUE(MID(' . $col . '2,' . ( strlen( $prefix ) + 1 ) . ',6))),COUNTIF(' . $col . ':' . $col . ',' . $col . '2)=1)',
					'error'   => 'ID must look like ' . $prefix . '0001 and be unique.',
				);
			} elseif ( 'slug' === $type ) {
				$rules[] = array(
					'type'    => 'custom',
					'sqref'   => $range,
					'formula' => 'AND(EXACT(' . $col . '2,LOWER(' . $col . '2)),ISERROR(SEARCH(" ",' . $col . '2)),LEFT(' . $col . '2,1)<>"-",RIGHT(' . $col . '2,1)<>"-",ISERROR(SEARCH("--",' . $col . '2)),COUNTIF(' . $col . ':' . $col . ',' . $col . '2)=1)',
					'error'   => 'Slug: lowercase letters, digits and single hyphens; unique.',
				);
			} elseif ( 'enum' === $type ) {
				$rules[] = self::list_rule( $range, (array) $def['enum'] );
			} elseif ( 'bool' === $type ) {
				$rules[] = self::list_rule( $range, array( 'TRUE', 'FALSE' ) );
			} elseif ( 'prob' === $type ) {
				$rules[] = array(
					'type'     => 'decimal',
					'operator' => 'between',
					'sqref'    => $range,
					'formula'  => '0',
					'formula2' => '1',
					'error'    => 'A chance is a number from 0 to 1 (0.25 = 25%).',
				);
			} elseif ( 'mult' === $type ) {
				$rules[] = array(
					'type'     => 'decimal',
					'operator' => 'greaterThan',
					'sqref'    => $range,
					'formula'  => '0',
					'error'    => 'A multiplier is a number above 0 (2.5 = ×2.5).',
				);
			} elseif ( 'date' === $type ) {
				$rules[] = self::date_rule( $range );
			} elseif ( 'ref' === $type ) {
				$targets = (array) ( $def['target'] ?? array() );
				if ( 1 === count( $targets ) && '*' !== $targets[0] ) {
					$ttab = $game->tab_for( (string) $targets[0] );
					if ( null !== $ttab ) {
						$rules[] = array(
							'type'    => 'list',
							'sqref'   => $range,
							'formula' => "'" . $ttab . "'!\$A\$2:\$A\$" . self::ROWS,
							'error'   => 'Pick an ID from the ' . $ttab . ' tab.',
						);
					}
				}
			}
		}
		return array( $header, array(), $rules );
	}

	/** @param array<int, string> $vals Values. */
	private static function list_rule( string $range, array $vals ): array {
		return array(
			'type'    => 'list',
			'sqref'   => $range,
			'formula' => '"' . implode( ',', $vals ) . '"',
			'error'   => 'Allowed: ' . implode( ', ', $vals ),
		);
	}

	private static function date_rule( string $range ): array {
		return array(
			'type'     => 'date',
			'operator' => 'greaterThan',
			'sqref'    => $range,
			'formula'  => '36526',
			'error'    => 'Use a date like 2026-10-22.',
		);
	}

	private static function col( int $i ): string {
		$s = '';
		for ( $n = $i + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$s = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $s;
		}
		return $s;
	}

	private static function x( string $s ): string {
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/** Writes the workbook and returns its path (caller deletes it). */
	public static function write( Game $game, string $path ): string {
		$sheets = self::sheets( $game );
		$zip    = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Cannot create workbook.' );
		}
		$names = array_keys( $sheets );
		$ct    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$wb    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		$rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( $names as $i => $name ) {
			$n     = $i + 1;
			$ct   .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb   .= '<sheet name="' . self::x( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', self::sheet_xml( $sheets[ $name ] ) );
		}
		$rels .= '<Relationship Id="rId' . ( count( $names ) + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$zip->addFromString( '[Content_Types].xml', $ct . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', $wb . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels );
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>' );
		$zip->close();
		return $path;
	}

	/** @param array{0: array<int, string>, 1: array<int, array<int, string>>, 2: array<int, array<string, string>>} $s Sheet. */
	private static function sheet_xml( array $s ): string {
		[ $header, $rows, $rules ] = $s;
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
		$all  = array_merge( array( $header ), $rows );
		foreach ( $all as $r => $row ) {
			$xml .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( $row ) as $c => $v ) {
				if ( '' === (string) $v ) {
					continue;
				}
				$xml .= '<c r="' . self::col( $c ) . ( $r + 1 ) . '" t="inlineStr"' . ( 0 === $r ? ' s="1"' : '' ) . '><is><t xml:space="preserve">' . self::x( (string) $v ) . '</t></is></c>';
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData>';
		if ( $rules ) {
			$xml .= '<dataValidations count="' . count( $rules ) . '">';
			foreach ( $rules as $v ) {
				$xml .= '<dataValidation type="' . self::x( $v['type'] ) . '"' . ( isset( $v['operator'] ) ? ' operator="' . self::x( $v['operator'] ) . '"' : '' ) . ' allowBlank="1" showErrorMessage="1" errorStyle="stop" errorTitle="Not allowed" error="' . self::x( $v['error'] ) . '" sqref="' . self::x( $v['sqref'] ) . '"><formula1>' . self::x( $v['formula'] ) . '</formula1>' . ( isset( $v['formula2'] ) ? '<formula2>' . self::x( $v['formula2'] ) . '</formula2>' : '' ) . '</dataValidation>';
			}
			$xml .= '</dataValidations>';
		}
		return $xml . '</worksheet>';
	}
}
