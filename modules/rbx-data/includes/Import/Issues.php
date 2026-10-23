<?php
/**
 * Collects validation errors and warnings for a dry run (DATA-19, DATA-35).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Issue list with severity and warning class (each warning class needs one acknowledgment).
 */
final class Issues {

	/** @var array<int, array<string, mixed>> */
	private array $items = array();

	public function error( string $class, string $msg, string $tab = '', int $row = 0, string $id = '', string $field = '' ): void {
		$this->add( 'error', $class, $msg, $tab, $row, $id, $field );
	}

	public function warn( string $class, string $msg, string $tab = '', int $row = 0, string $id = '', string $field = '' ): void {
		$this->add( 'warn', $class, $msg, $tab, $row, $id, $field );
	}

	private function add( string $sev, string $class, string $msg, string $tab, int $row, string $id, string $field ): void {
		$this->items[] = array(
			'sev'   => $sev,
			'class' => $class,
			'msg'   => $msg,
			'tab'   => $tab,
			'row'   => $row,
			'id'    => $id,
			'field' => $field,
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function all(): array {
		return $this->items;
	}

	public function error_count(): int {
		return count( array_filter( $this->items, static fn( $i ) => 'error' === $i['sev'] ) );
	}

	/** @return array<int, string> Distinct warning classes. */
	public function warning_classes(): array {
		$c = array();
		foreach ( $this->items as $i ) {
			if ( 'warn' === $i['sev'] ) {
				$c[ $i['class'] ] = true;
			}
		}
		return array_keys( $c );
	}

	/** @return array<int, string> IDs touched by an error. */
	public function error_ids(): array {
		$ids = array();
		foreach ( $this->items as $i ) {
			if ( 'error' === $i['sev'] && '' !== $i['id'] ) {
				$ids[ $i['id'] ] = true;
			}
		}
		return array_keys( $ids );
	}
}
