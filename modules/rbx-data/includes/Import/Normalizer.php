<?php
/**
 * Turns raw tab rows into typed, canonical records (DATA-04, DATA-16, DATA-21).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

use RBX\Data\Config\Game;
use RBX\Data\Config\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * Header checks, per-cell typing and canonical serialisation.
 */
final class Normalizer {

	public const ID_RE   = '/^[A-Z][A-Z0-9]{1,4}-[A-Z]{3}-\d{4,6}$/';
	public const SLUG_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/** Meta keys and their types. */
	public const META_TYPES = array(
		'game_code'            => 'string',
		'schema_version'       => 'string',
		'roblox_universe_id'   => 'digits',
		'roblox_place_id'      => 'digits',
		'timezone_note'        => 'text',
		'game_name'            => 'string',
		'developer_name'       => 'string',
		'developer_roblox_url' => 'url',
		'official_channels'    => 'url_list',
		'release_date'         => 'date',
		'genre'                => 'string',
		'update_cadence_text'  => 'text',
		'redeem_ui_exists'     => 'yesno',
		'suffix_ladder'        => 'ladder',
		'calc_defaults'        => 'json',
	);

	/** Allowed tags in text fields (ENG-51). */
	public const KSES = array(
		'a'      => array( 'href' => true ),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
	);

	private string $today;

	public function __construct( private Game $game, private Issues $issues, ?string $today = null ) {
		$this->today = $today ?? gmdate( 'Y-m-d' );
	}

	/**
	 * @param array<string, array<int, array<int, string>>> $tabs Raw tabs.
	 * @return array{records: array<string, array<string, mixed>>, meta: array<string, array<string, mixed>>, enums: array<string, array<int, string>>}
	 */
	public function run( array $tabs ): array {
		$records = array();
		$meta    = array();
		$enums   = array();
		$known   = $this->game->tabs();
		foreach ( $tabs as $tab => $rows ) {
			if ( Tabs::META_TAB === $tab ) {
				$meta = $this->meta( $rows );
				continue;
			}
			if ( Tabs::ENUMS_TAB === $tab ) {
				$enums = $this->enums( $rows );
				continue;
			}
			if ( ! isset( $known[ $tab ] ) ) {
				/* translators: %s: tab name */
				$this->issues->error( 'unknown_tab', sprintf( __( 'Unknown tab "%s". Rename it or remove it from the published set.', 'rbx-data' ), $tab ), $tab );
				continue;
			}
			foreach ( $this->tab( $tab, $known[ $tab ], $rows ) as $id => $rec ) {
				if ( isset( $records[ $id ] ) ) {
					/* translators: %s: record ID */
					$this->issues->error( 'duplicate_id', sprintf( __( 'ID %s appears more than once.', 'rbx-data' ), $id ), $tab, (int) $rec['_row'], $id, 'id' );
					continue;
				}
				$records[ $id ] = $rec;
			}
		}
		foreach ( $known as $tab => $code ) {
			if ( ! isset( $tabs[ $tab ] ) ) {
				/* translators: %s: tab name */
				$this->issues->warn( 'missing_tab', sprintf( __( 'Tab "%s" was not found. Its records are treated as missing (nothing is deleted).', 'rbx-data' ), $tab ), $tab );
			}
		}
		if ( ! isset( $tabs[ Tabs::META_TAB ] ) ) {
			$this->issues->error( 'meta_missing', __( 'The _meta tab is missing.', 'rbx-data' ), Tabs::META_TAB );
		}
		return array(
			'records' => $records,
			'meta'    => $meta,
			'enums'   => $enums,
		);
	}

	/**
	 * @param array<int, array<int, string>> $rows Rows incl. header.
	 * @return array<string, array<string, mixed>>
	 */
	private function tab( string $tab, string $code, array $rows ): array {
		$cols   = $this->game->columns( $code );
		$header = array_map( 'trim', $rows[0] ?? array() );
		while ( $header && '' === end( $header ) ) {
			array_pop( $header );
		}
		$expect = array_keys( $cols );
		foreach ( $header as $h ) {
			if ( in_array( $h, Tabs::DERIVED, true ) ) {
				/* translators: %s: column name */
				$this->issues->error( 'derived_column', sprintf( __( 'Column "%s" is computed by the site and must not be in the Sheet (DATA-36).', 'rbx-data' ), $h ), $tab );
			}
		}
		if ( $header !== $expect ) {
			$unknown = array_diff( $header, $expect );
			$missing = array_diff( $expect, $header );
			$msg     = __( 'Header row does not match the config.', 'rbx-data' );
			if ( $unknown ) {
				$msg .= ' ' . __( 'Unknown:', 'rbx-data' ) . ' ' . implode( ', ', $unknown ) . '.';
			}
			if ( $missing ) {
				$msg .= ' ' . __( 'Missing:', 'rbx-data' ) . ' ' . implode( ', ', $missing ) . '.';
			}
			if ( ! $unknown && ! $missing ) {
				$msg .= ' ' . __( 'The columns are in the wrong order. Expected:', 'rbx-data' ) . ' ' . implode( ', ', $expect ) . '.';
			}
			$this->issues->error( 'header', $msg, $tab, 1 );
			return array();
		}
		$out       = array();
		$saw_blank = false;
		$count     = count( $rows );
		for ( $i = 1; $i < $count; $i++ ) {
			$raw = $rows[ $i ];
			if ( '' === trim( implode( '', $raw ) ) ) {
				$saw_blank = true;
				continue;
			}
			if ( $saw_blank ) {
				$this->issues->warn( 'blank_rows', __( 'Blank rows inside the data. Rows after a blank row were still read.', 'rbx-data' ), $tab, $i + 1 );
				$saw_blank = false;
			}
			$rec = $this->row( $tab, $code, $cols, $raw, $i + 1 );
			if ( null !== $rec ) {
				$out[ $rec['id'] ] = $rec;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $cols Columns.
	 * @param array<int, string>                  $raw  Cells.
	 * @return array<string, mixed>|null
	 */
	private function row( string $tab, string $code, array $cols, array $raw, int $line ): ?array {
		$data = array();
		$i    = 0;
		$id   = trim( (string) ( $raw[0] ?? '' ) );
		foreach ( $cols as $field => $def ) {
			$cell = (string) ( $raw[ $i++ ] ?? '' );
			$res  = $this->cell( $def, $cell, $field );
			if ( null !== $res['error'] ) {
				$this->issues->error( 'type', $res['error'], $tab, $line, $id, $field );
				$data[ $field ] = null;
				continue;
			}
			$data[ $field ] = $res['value'];
			if ( null === $res['value'] && ! empty( $def['required'] ) ) {
				/* translators: %s: column name */
				$this->issues->error( 'required', sprintf( __( '"%s" is required.', 'rbx-data' ), $field ), $tab, $line, $id, $field );
			}
		}
		if ( ! is_string( $data['id'] ?? null ) ) {
			return null;
		}
		$prefix = $this->game->code() . '-' . $code . '-';
		if ( ! str_starts_with( $data['id'], $prefix ) ) {
			/* translators: 1: ID, 2: expected prefix */
			$this->issues->error( 'id_prefix', sprintf( __( 'ID %1$s must start with %2$s.', 'rbx-data' ), $data['id'], $prefix ), $tab, $line, $data['id'], 'id' );
			return null;
		}
		$slug_re = $this->game->entity( $code )['slug_pattern'] ?? null;
		if ( $slug_re && is_string( $data['slug'] ?? null ) && ! preg_match( '/' . $slug_re . '/', $data['slug'] ) ) {
			/* translators: %s: slug */
			$this->issues->error( 'slug_pattern', sprintf( __( 'Slug "%s" does not follow this tab\'s pattern (updates use YYYY-MM-DD-short-name).', 'rbx-data' ), $data['slug'] ), $tab, $line, $data['id'], 'slug' );
		}
		return array(
			'id'     => $data['id'],
			'game'   => $this->game->code(),
			'entity' => $code,
			'slug'   => $data['slug'] ?? null,
			'name'   => (string) ( $data['name'] ?? ( $data['title__en'] ?? ( $data['code'] ?? ( $data['mechanic_key'] ?? ( $data['key'] ?? ( $data['file_name'] ?? '' ) ) ) ) ) ),
			'status' => (string) ( $data['status'] ?? 'draft' ),
			'data'   => $data,
			'_tab'   => $tab,
			'_row'   => $line,
		);
	}

	/**
	 * Types one cell. Empty cells are null.
	 *
	 * @param array<string, mixed> $def Field definition.
	 * @return array{value: mixed, error: string|null}
	 */
	public function cell( array $def, string $cell, string $field = '' ): array {
		$v    = trim( str_replace( "\u{00A0}", ' ', $cell ) );
		$type = (string) $def['type'];
		if ( '' === $v ) {
			return self::ok( null );
		}
		$bad = static fn( string $why ) => array(
			'value' => null,
			/* translators: 1: value, 2: reason */
			'error' => sprintf( __( 'Value "%1$s" is not valid: %2$s', 'rbx-data' ), mb_substr( $v, 0, 60 ), $why ),
		);
		switch ( $type ) {
			case 'id':
			case 'ref':
				return preg_match( self::ID_RE, $v ) ? self::ok( $v ) : $bad( __( 'expected an ID like SAE-PET-0001.', 'rbx-data' ) );
			case 'ref_list':
				$ids = array_values( array_unique( array_filter( array_map( 'trim', explode( '|', $v ) ), 'strlen' ) ) );
				foreach ( $ids as $x ) {
					if ( ! preg_match( self::ID_RE, $x ) ) {
						return $bad( __( 'expected IDs separated by |.', 'rbx-data' ) );
					}
				}
				sort( $ids );
				return self::ok( $ids );
			case 'string_list':
				$l = array_values( array_unique( array_filter( array_map( 'trim', explode( '|', $v ) ), 'strlen' ) ) );
				sort( $l );
				return self::ok( $l );
			case 'slug':
				return ( preg_match( self::SLUG_RE, $v ) && strlen( $v ) <= 60 ) ? self::ok( $v ) : $bad( __( 'lowercase letters, digits and single hyphens, at most 60 characters.', 'rbx-data' ) );
			case 'string':
				$s = sanitize_text_field( $v );
				if ( isset( $def['pattern'] ) && ! preg_match( '/' . $def['pattern'] . '/', $s ) ) {
					return $bad( __( 'wrong format.', 'rbx-data' ) );
				}
				return self::ok( $s );
			case 'text':
				return self::ok( trim( wp_kses( $v, self::KSES ) ) );
			case 'enum':
				return in_array( $v, (array) $def['enum'], true ) ? self::ok( $v ) : $bad( __( 'allowed values are', 'rbx-data' ) . ' ' . implode( ', ', (array) $def['enum'] ) . '.' );
			case 'bool':
				$u = strtoupper( $v );
				if ( in_array( $u, array( 'TRUE', '1' ), true ) ) {
					return self::ok( true );
				}
				if ( in_array( $u, array( 'FALSE', '0' ), true ) ) {
					return self::ok( false );
				}
				return $bad( __( 'use TRUE or FALSE.', 'rbx-data' ) );
			case 'int':
			case 'robux':
				if ( ! preg_match( '/^-?\d{1,15}$/', $v ) ) {
					return $bad( __( 'whole number expected.', 'rbx-data' ) );
				}
				if ( 'robux' === $type && (int) $v < 0 ) {
					return $bad( __( 'must not be negative.', 'rbx-data' ) );
				}
				return self::ok( (int) $v );
			case 'decimal':
			case 'prob':
			case 'mult':
			case 'duration_s':
				if ( ! preg_match( '/^-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?$/', $v ) ) {
					return $bad( __( 'plain number expected (use . for decimals, no thousands separators, no % or x).', 'rbx-data' ) );
				}
				$n = (float) $v;
				if ( is_nan( $n ) || is_infinite( $n ) ) {
					return $bad( __( 'not a finite number.', 'rbx-data' ) );
				}
				if ( 'prob' === $type && ( $n < 0 || $n > 1 ) ) {
					return $bad( __( 'probabilities are fractions between 0 and 1 (write 0.25, not 25).', 'rbx-data' ) );
				}
				if ( 'mult' === $type && $n <= 0 ) {
					return $bad( __( 'multipliers must be greater than 0.', 'rbx-data' ) );
				}
				if ( 'duration_s' === $type && $n < 0 ) {
					return $bad( __( 'durations cannot be negative.', 'rbx-data' ) );
				}
				return self::ok( self::canon_number( $n ) );
			case 'date':
				$d = self::date( $v );
				if ( null === $d ) {
					return $bad( __( 'use YYYY-MM-DD.', 'rbx-data' ) );
				}
				if ( $d > $this->today && empty( $def['future_ok'] ) ) {
					return $bad( __( 'date is in the future.', 'rbx-data' ) );
				}
				return self::ok( $d );
			case 'datetime':
				$dt = self::datetime( $v );
				if ( null === $dt ) {
					return $bad( __( 'use ISO 8601 UTC, for example 2026-10-23T14:00:00Z.', 'rbx-data' ) );
				}
				if ( substr( $dt, 0, 10 ) > $this->today && empty( $def['future_ok'] ) ) {
					return $bad( __( 'date is in the future.', 'rbx-data' ) );
				}
				return self::ok( $dt );
			case 'url':
				$u = esc_url_raw( $v, array( 'http', 'https' ) );
				return ( '' !== $u && preg_match( '#^https?://#i', $u ) ) ? self::ok( $u ) : $bad( __( 'full http(s) URL expected.', 'rbx-data' ) );
			case 'json':
				$j = json_decode( $v, true );
				if ( null === $j && 'null' !== strtolower( $v ) ) {
					return $bad( __( 'invalid JSON.', 'rbx-data' ) );
				}
				$shape = self::json_shape( (string) ( $def['json_shape'] ?? '' ), $j );
				if ( null !== $shape ) {
					return $bad( $shape );
				}
				return self::ok( self::sort_keys( $j ) );
		}
		return $bad( __( 'unknown type.', 'rbx-data' ) );
	}

	/** @return array{value: mixed, error: null} */
	private static function ok( mixed $v ): array {
		return array(
			'value' => $v,
			'error' => null,
		);
	}

	/** Canonical number: integers stay int, others float. */
	public static function canon_number( float $n ): int|float {
		if ( floor( $n ) === $n && abs( $n ) < 9007199254740992 ) {
			return (int) $n;
		}
		return $n;
	}

	public static function date( string $v ): ?string {
		if ( preg_match( '/^\d{4,6}(?:\.\d+)?$/', $v ) && (float) $v > 20000 && (float) $v < 80000 ) {
			// Spreadsheet date serial (days since 1899-12-30).
			return gmdate( 'Y-m-d', (int) round( ( (float) $v - 25569 ) * 86400 ) );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $v;
	}

	public static function datetime( string $v ): ?string {
		if ( preg_match( '/^\d{4,6}\.\d+$/', $v ) && (float) $v > 20000 && (float) $v < 80000 ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', (int) round( ( (float) $v - 25569 ) * 86400 ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $v ) ) {
			return null;
		}
		try {
			$tz = preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $v ) ? null : new \DateTimeZone( 'UTC' );
			$d  = new \DateTimeImmutable( $v, $tz );
		} catch ( \Exception $e ) {
			return null;
		}
		return $d->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/** Recursively sorts object keys so the JSON form is canonical. */
	public static function sort_keys( mixed $j ): mixed {
		if ( ! is_array( $j ) ) {
			return $j;
		}
		if ( array_is_list( $j ) ) {
			return array_map( array( self::class, 'sort_keys' ), $j );
		}
		ksort( $j );
		foreach ( $j as $k => $x ) {
			$j[ $k ] = self::sort_keys( $x );
		}
		return $j;
	}

	private static function json_shape( string $shape, mixed $j ): ?string {
		switch ( $shape ) {
			case 'recipe_inputs':
				if ( ! is_array( $j ) || ! array_is_list( $j ) || ! $j ) {
					return __( 'expected a list like [{"pet_id":"SAE-PET-0001","qty":2}].', 'rbx-data' );
				}
				foreach ( $j as $x ) {
					if ( ! is_array( $x ) || ! is_string( $x['pet_id'] ?? null ) || ! is_int( $x['qty'] ?? null ) || $x['qty'] < 1 ) {
						return __( 'each ingredient needs pet_id and a whole-number qty.', 'rbx-data' );
					}
				}
				return null;
			case 'outcome_table':
				if ( ! is_array( $j ) || ! array_is_list( $j ) ) {
					return __( 'expected a list like [{"pet_id":"…","chance_prob":0.5}].', 'rbx-data' );
				}
				foreach ( $j as $x ) {
					$p = $x['chance_prob'] ?? null;
					if ( ! is_array( $x ) || ! is_string( $x['pet_id'] ?? null ) || ( null !== $p && ( ! is_numeric( $p ) || $p < 0 || $p > 1 ) ) ) {
						return __( 'each outcome needs pet_id and chance_prob between 0 and 1 (or null).', 'rbx-data' );
					}
				}
				return null;
			case 'reward_items':
				if ( ! is_array( $j ) || ! array_is_list( $j ) ) {
					return __( 'expected a list like [{"record_id":"…","qty":1}].', 'rbx-data' );
				}
				return null;
		}
		return null;
	}

	/**
	 * @param array<int, array<int, string>> $rows Rows.
	 * @return array<string, array<string, mixed>>
	 */
	private function meta( array $rows ): array {
		$header = array_map( 'trim', $rows[0] ?? array() );
		$want   = array( 'key', 'value', 'source_type', 'source_url', 'last_verified', 'verified_by' );
		if ( array_slice( $header, 0, 6 ) !== $want ) {
			$this->issues->error( 'header', __( '_meta header must be: key, value, source_type, source_url, last_verified, verified_by.', 'rbx-data' ), Tabs::META_TAB, 1 );
			return array();
		}
		$out = array();
		$n   = count( $rows );
		for ( $i = 1; $i < $n; $i++ ) {
			$r   = array_pad( $rows[ $i ], 6, '' );
			$key = trim( $r[0] );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( self::META_TYPES[ $key ] ) ) {
				/* translators: %s: key */
				$this->issues->error( 'meta_key', sprintf( __( 'Unknown _meta key "%s".', 'rbx-data' ), $key ), Tabs::META_TAB, $i + 1, '', $key );
				continue;
			}
			$val = $this->meta_value( self::META_TYPES[ $key ], trim( $r[1] ), $key, $i + 1 );
			$st  = trim( $r[2] );
			if ( '' !== $st && ! in_array( $st, Tabs::SOURCE_TYPES, true ) ) {
				$this->issues->error( 'type', __( 'source_type must be in_game, official or community.', 'rbx-data' ), Tabs::META_TAB, $i + 1, '', $key );
			}
			$lv = self::date( trim( $r[4] ) );
			if ( '' !== trim( $r[4] ) && null === $lv ) {
				$this->issues->error( 'type', __( 'last_verified must be YYYY-MM-DD.', 'rbx-data' ), Tabs::META_TAB, $i + 1, '', $key );
			}
			$out[ $key ] = array(
				'value'         => $val,
				'source_type'   => '' === $st ? null : $st,
				'source_url'    => '' === trim( $r[3] ) ? null : esc_url_raw( trim( $r[3] ) ),
				'last_verified' => $lv,
				'verified_by'   => sanitize_text_field( $r[5] ),
			);
		}
		return $out;
	}

	private function meta_value( string $type, string $v, string $key, int $line ): mixed {
		if ( '' === $v ) {
			return null;
		}
		$err = function ( string $why ) use ( $key, $line ) {
			/* translators: 1: key, 2: reason */
			$this->issues->error( 'type', sprintf( __( '_meta %1$s: %2$s', 'rbx-data' ), $key, $why ), Tabs::META_TAB, $line, '', $key );
			return null;
		};
		switch ( $type ) {
			case 'digits':
				return preg_match( '/^\d{1,20}$/', $v ) ? $v : $err( __( 'digits only.', 'rbx-data' ) );
			case 'url':
				return preg_match( '#^https?://#', $v ) ? esc_url_raw( $v ) : $err( __( 'full URL expected.', 'rbx-data' ) );
			case 'url_list':
				$l = array_values( array_filter( array_map( 'trim', explode( '|', $v ) ), 'strlen' ) );
				foreach ( $l as $u ) {
					if ( ! preg_match( '#^https?://#', $u ) ) {
						return $err( __( 'URLs separated by | expected.', 'rbx-data' ) );
					}
				}
				return array_map( 'esc_url_raw', $l );
			case 'date':
				return self::date( $v ) ?? $err( __( 'use YYYY-MM-DD.', 'rbx-data' ) );
			case 'yesno':
				return in_array( $v, array( 'yes', 'no', 'unknown' ), true ) ? $v : $err( __( 'use yes, no or unknown.', 'rbx-data' ) );
			case 'ladder':
				$j = json_decode( $v, true );
				if ( ! is_array( $j ) || ! array_is_list( $j ) ) {
					return $err( __( 'JSON list like [{"s":"K","e":3}] expected.', 'rbx-data' ) );
				}
				$last = 0;
				foreach ( $j as $s ) {
					if ( ! is_array( $s ) || ! is_string( $s['s'] ?? null ) || ! preg_match( '/^[A-Za-z]{1,4}$/', $s['s'] ) || ! is_int( $s['e'] ?? null ) || $s['e'] <= $last || 0 !== $s['e'] % 3 ) {
						return $err( __( 'each step needs a letter suffix "s" and an increasing exponent "e" that is a multiple of 3.', 'rbx-data' ) );
					}
					$last = $s['e'];
				}
				return $j;
			case 'json':
				$j = json_decode( $v, true );
				return is_array( $j ) ? self::sort_keys( $j ) : $err( __( 'JSON object expected.', 'rbx-data' ) );
			case 'text':
				return trim( wp_kses( $v, self::KSES ) );
		}
		return sanitize_text_field( $v );
	}

	/**
	 * @param array<int, array<int, string>> $rows Rows.
	 * @return array<string, array<int, string>>
	 */
	private function enums( array $rows ): array {
		$header = $rows[0] ?? array();
		$out    = array();
		foreach ( $header as $c => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$vals = array();
			$n    = count( $rows );
			for ( $i = 1; $i < $n; $i++ ) {
				$v = trim( (string) ( $rows[ $i ][ $c ] ?? '' ) );
				if ( '' !== $v ) {
					$vals[] = $v;
				}
			}
			$out[ $name ] = $vals;
		}
		return $out;
	}

	/**
	 * Fields excluded from the content hash: they are bookkeeping, not data (DATA-21).
	 *
	 * @return array<int, string>
	 */
	public static function unhashed(): array {
		return array( 'change_update_id', 'slug_history' );
	}

	/** @param array<string, mixed> $rec Record. */
	public static function hash( array $rec ): string {
		$d = $rec['data'];
		foreach ( self::unhashed() as $f ) {
			unset( $d[ $f ] );
		}
		$d = self::sort_keys( $d );
		return hash( 'sha256', (string) wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
