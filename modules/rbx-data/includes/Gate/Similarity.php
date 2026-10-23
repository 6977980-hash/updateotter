<?php
/**
 * 5-word-shingle Jaccard similarity (DATA-20 I-3, I-4; internal heuristic).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers.
 */
final class Similarity {

	/** @return array<string, true> */
	public static function shingles( string $text, int $k = 5 ): array {
		$text  = strtolower( wp_strip_all_tags( $text ) );
		$words = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words = false === $words ? array() : $words;
		$out   = array();
		$n     = count( $words );
		if ( 0 === $n ) {
			return $out;
		}
		if ( $n < $k ) {
			$out[ implode( ' ', $words ) ] = true;
			return $out;
		}
		for ( $i = 0; $i <= $n - $k; $i++ ) {
			$out[ implode( ' ', array_slice( $words, $i, $k ) ) ] = true;
		}
		return $out;
	}

	/**
	 * @param array<string, true> $a Shingles.
	 * @param array<string, true> $b Shingles.
	 */
	public static function jaccard( array $a, array $b ): float {
		if ( ! $a && ! $b ) {
			return 1.0;
		}
		$inter = count( array_intersect_key( $a, $b ) );
		$union = count( $a ) + count( $b ) - $inter;
		return $union > 0 ? $inter / $union : 0.0;
	}
}
