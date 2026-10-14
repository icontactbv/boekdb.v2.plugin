<?php
/**
 * Covers the hourly start, for an etalage whose key BoekDB refuses.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Cleanup;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Start tests.
 */
class StartImportTest extends WP_UnitTestCase {

	/**
	 * The etalage.
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * The urls requested.
	 *
	 * @var array
	 */
	private $requested = array();

	/**
	 * Creates an etalage and answers BoekDB as it does for a key it does not know.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'name'    => 'Test Etalage',
				'api_key' => 'onbekende-sleutel',
				'running' => 0,
			)
		);
		$this->etalage_id = (int) $wpdb->insert_id;

		delete_option( BoekDB_Import::STOPPED_OPTION );
		add_filter( 'pre_http_request', array( $this, 'refuse_the_key' ), 9, 3 );
	}

	/**
	 * Stops answering.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'refuse_the_key' ), 9 );

		parent::tear_down();
	}

	/**
	 * Answers the connection test, and 401 to everything that needs the key.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return array
	 */
	public function refuse_the_key( $preempt, $args, $url ) {
		$this->requested[] = $url;
		$test              = false !== strpos( $url, '/test' );

		return array(
			'response' => array(
				'code'    => $test ? 200 : 401,
				'message' => $test ? 'OK' : 'Unauthorized',
			),
			'body'     => $test ? wp_json_encode(
				array(
					'hello',
					'plugin_version' => '1.2.0',
				)
			) : '',
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * An etalage removed for its key is gone: nothing more is asked or written for it.
	 */
	public function test_an_etalage_removed_for_its_key_is_not_started() {
		BoekDB_Import::start_import();

		$isbns = array_filter(
			$this->requested,
			function ( $url ) {
				return false !== strpos( $url, '/isbns' );
			}
		);

		$this->assertSame( array(), $isbns, 'Its list of isbns should not be asked for.' );
		$this->assertFalse( get_option( BoekDB_Import::START_OPTION_PREFIX . $this->etalage_id ), 'Nor a run start written.' );
	}

	/**
	 * What the import kept for an etalage goes with it.
	 */
	public function test_deleting_an_etalage_removes_what_the_import_kept_for_it() {
		update_option( BoekDB_Import::FAILED_OPTION_PREFIX . $this->etalage_id, array( '9789000000001' => 1 ), false );
		update_option( BoekDB_Import::FINISHED_OPTION_PREFIX . $this->etalage_id, time(), false );
		update_option( BoekDB_Import::START_OPTION_PREFIX . $this->etalage_id, '2026-10-01 10:00:00', false );

		BoekDB_Cleanup::delete_etalage( $this->etalage_id );

		$this->assertFalse( get_option( BoekDB_Import::FAILED_OPTION_PREFIX . $this->etalage_id ) );
		$this->assertFalse( get_option( BoekDB_Import::FINISHED_OPTION_PREFIX . $this->etalage_id ) );
		$this->assertFalse( get_option( BoekDB_Import::START_OPTION_PREFIX . $this->etalage_id ) );
	}
}
