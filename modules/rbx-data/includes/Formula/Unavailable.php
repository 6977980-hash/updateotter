<?php
/**
 * A value needed by a formula is unknown, disputed, unconfirmed or missing (DATA-12, DATA-13).
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the reason so the UI can say which value is missing.
 */
final class Unavailable extends \RuntimeException {

	public function __construct( public readonly string $reason, public readonly string $what = '' ) {
		parent::__construct( $reason . ( '' !== $what ? ': ' . $what : '' ) );
	}
}
