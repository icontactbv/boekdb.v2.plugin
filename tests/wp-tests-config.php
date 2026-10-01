<?php
/**
 * WordPress test-suite configuration.
 *
 * Database settings come from the environment so the same file serves a local
 * run (Herd MySQL, root without password) and CI (see .github/workflows/tests.yml).
 *
 * @package BoekDB
 */

/**
 * Reads a test setting from the environment, falling back to the local default.
 *
 * @param string $name    Environment variable.
 * @param string $fallback Value used when the variable is unset or empty.
 *
 * @return string
 */
function boekdb_tests_env( $name, $fallback ) {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $fallback : $value;
}

// The WordPress core checkout that Composer installs (roots/wordpress-no-content).
define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );

define( 'DB_NAME', boekdb_tests_env( 'WP_TESTS_DB_NAME', 'boekdb_plugin_test' ) );
define( 'DB_USER', boekdb_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', boekdb_tests_env( 'WP_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', boekdb_tests_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Every table in the test database is dropped and recreated on each run.
$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'boekdb.test' );
define( 'WP_TESTS_EMAIL', 'admin@boekdb.test' );
define( 'WP_TESTS_TITLE', 'BoekDB plugin tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Surface every notice, warning and deprecation: phpunit.xml.dist turns them into failures.
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
