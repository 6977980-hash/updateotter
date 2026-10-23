<?php
/**
 * Server render for rbx/data-section.
 *
 * @package RBX\Data
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Escaped inside the section renderers.
echo \RBX\Data\Render\Sections::render( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
