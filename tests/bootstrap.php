<?php
/**
 * PHPUnit bootstrap for the extend-ai-enterprise contract suite.
 *
 * Loads the WordPress test scaffolding (set up via
 * `bin/install-wp-tests.sh` or wp-env), then activates the WordPress AI
 * plugin and ours. Contract tests assert that the integration points we
 * depend on still exist with the expected signatures.
 *
 * Usage:
 *   composer install
 *   WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=contract
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found at {$_tests_dir}. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function (): void {
	$plugins_dir = dirname( __DIR__, 2 );

	require_once $plugins_dir . '/ai/ai.php';
	require_once dirname( __DIR__ ) . '/extend-ai-enterprise.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
