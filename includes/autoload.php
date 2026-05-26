<?php
/**
 * PSR-4-ish autoloader for the ExtendAI\Enterprise namespace.
 *
 * @package ExtendAI\Enterprise
 */

declare( strict_types=1 );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'ExtendAI\\Enterprise\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$path     = EXTEND_AI_ENTERPRISE_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );
