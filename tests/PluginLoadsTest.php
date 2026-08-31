<?php
/**
 * Proves the harness boots WordPress with the plugin in it.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Plugin load and scheduling smoke tests.
 */
class PluginLoadsTest extends WP_UnitTestCase {

	/**
	 * The main class is available and the version constant matches the instance.
	 *
	 * @test
	 */
	public function plugin_is_loaded_with_its_version() {
		$this->assertTrue( class_exists( 'BoekDB' ) );
		$this->assertSame( BoekDB()->version, BOEKDB_VERSION );
	}

	/**
	 * The hourly start_import event is registered while the plugin loads.
	 *
	 * @test
	 */
	public function hourly_start_import_is_scheduled_on_load() {
		$this->assertNotFalse( wp_next_scheduled( BoekDB_Import::START_IMPORT_HOOK ) );
	}

	/**
	 * Pins current behaviour: init() schedules 'minutely' before boekdb.php registers that
	 * interval, so the recurring event is refused and batches chain through single events.
	 * Changing the order alone would cap throughput at one batch a minute.
	 *
	 * @test
	 */
	public function minutely_import_schedule_is_registered_too_late_to_be_used() {
		$this->assertArrayHasKey( 'minutely', wp_get_schedules(), 'the schedule does exist once the plugin has fully loaded' );
		$this->assertFalse( wp_next_scheduled( BoekDB_Import::IMPORT_HOOK ), 'but the recurring event was refused at init() time' );
	}

	/**
	 * The bootstrap short-circuits every outbound request into a WP_Error.
	 *
	 * @test
	 */
	public function no_request_reaches_boekdb_during_tests() {
		$response = wp_remote_get( 'https://boekdbv2.nl/api/json/v1/test' );

		$this->assertWPError( $response );
		$this->assertSame( 'boekdb_tests_http_blocked', $response->get_error_code() );
	}
}
