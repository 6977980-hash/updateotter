<?php
/**
 * Server render for rbx/mini-calculator.
 *
 * @package RBX\Tools
 * @var array<string, mixed> $attributes
 */

defined( 'ABSPATH' ) || exit;

$rbx_game = \RBX\Data\Api::game( (string) ( $attributes['game'] ?? '' ) );
$rbx_rec  = \RBX\Data\Api::record( (string) ( $attributes['record'] ?? '' ) );
if ( $rbx_game && $rbx_rec ) {
	echo \RBX\Tools\Calculator::mini_for_item( '', $rbx_game, $rbx_rec, \RBX\Data\Api::dataset( $rbx_game ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
}
