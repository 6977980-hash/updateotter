<?php
/**
 * Formula syntax or declaration error.
 *
 * @package RBX\Data
 */

declare( strict_types=1 );

namespace RBX\Data\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the parser.
 */
final class SyntaxError extends \RuntimeException {}
