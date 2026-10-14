<?php
/**
 * The plugin installs itself on the first `init` via BoekDB_Install::check_version().
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use WP_UnitTestCase;

/**
 * Self-install on first load.
 */
class InstallTest extends WP_UnitTestCase {

	/**
	 * The install has created the etalage, link and isbn tables through dbDelta().
	 *
	 * @test
	 */
	public function install_creates_the_three_plugin_tables() {
		global $wpdb;

		foreach ( array( 'boekdb_etalages', 'boekdb_etalage_boeken', 'boekdb_isbns' ) as $name ) {
			$table = $wpdb->prefix . $name;

			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"$table should exist after install"
			);
		}
	}

	/**
	 * The installed version is persisted so check_version() does not reinstall on every request.
	 *
	 * @test
	 */
	public function install_records_the_plugin_version() {
		$this->assertSame( BOEKDB_VERSION, get_option( 'boekdb_version' ) );
	}
}
