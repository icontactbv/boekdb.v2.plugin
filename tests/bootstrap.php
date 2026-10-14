<?php
/**
 * PHPUnit bootstrap: boots WordPress from the wp-phpunit test library and loads the
 * plugin into it as a must-use plugin.
 *
 * @package BoekDB
 */

$boekdb_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__DIR=' . $boekdb_tests_dir ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

require_once $boekdb_tests_dir . '/includes/functions.php';

/**
 * Block every outbound HTTP request.
 *
 * The plugin calls boekdbv2.nl on load, on install and on every import run. A test run
 * must never reach production, so every request short-circuits into a WP_Error — which
 * is also exactly the failure path the plugin has to handle.
 */
tests_add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		// A test that hooked in earlier and supplied a canned response keeps it.
		if ( false !== $preempt ) {
			return $preempt;
		}

		return new WP_Error( 'boekdb_tests_http_blocked', 'Outbound HTTP is blocked during tests: ' . $url );
	},
	10,
	3
);

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/boekdb.php';
	}
);

require $boekdb_tests_dir . '/includes/bootstrap.php';
