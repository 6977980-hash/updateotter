<?php
/**
 * Server render for rbx/calculator (ENG-30, ENG-32).
 *
 * @package RBX\Tools
 * @var array<string, mixed> $attributes
 */

defined( 'ABSPATH' ) || exit;

echo \RBX\Tools\Calculator::render( (string) ( $attributes['game'] ?? '' ), (string) ( $attributes['tool'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
