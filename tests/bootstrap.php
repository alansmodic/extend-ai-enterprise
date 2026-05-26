<?php
/**
 * PHPUnit bootstrap for the extend-ai-enterprise contract suite.
 *
 * Loads the WordPress test scaffolding, then activates the WP AI plugin and
 * ours. Contract tests assert that the integration points we depend on still
 * exist with the expected signatures.
 *
 * Plugin paths can be overridden via env vars:
 *
 *   WP_AI_PLUGIN_FILE  Absolute path to WordPress/ai's ai.php.
 *                      Defaults to a sibling plugin install.
 *   WP_TESTS_DIR       WordPress test library location.
 *                      Defaults to /tmp/wordpress-tests-lib.
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found at {$_tests_dir}. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

$_ai_plugin = getenv( 'WP_AI_PLUGIN_FILE' ) ?: null;

if ( ! $_ai_plugin ) {
	// Try common locations relative to this checkout.
	$candidates = [
		dirname( __DIR__, 2 ) . '/ai/ai.php',     // sibling plugin directory
		dirname( __DIR__, 2 ) . '/wp-ai/ai.php',  // CI layout (separate checkout)
		'/tmp/wordpress/wp-content/plugins/ai/ai.php',
	];
	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			$_ai_plugin = $candidate;
			break;
		}
	}
}

if ( ! $_ai_plugin || ! file_exists( $_ai_plugin ) ) {
	fwrite( STDERR, "WordPress AI plugin not found. Set WP_AI_PLUGIN_FILE to its ai.php path.\n" );
	exit( 1 );
}

$_self_plugin = dirname( __DIR__ ) . '/extend-ai-enterprise.php';

tests_add_filter( 'muplugins_loaded', static function () use ( $_ai_plugin, $_self_plugin ): void {
	require_once $_ai_plugin;
	require_once $_self_plugin;
} );

require $_tests_dir . '/includes/bootstrap.php';
