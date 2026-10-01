<?php
/**
 * Covers what the Stop button does to a running import.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Stop tests.
 *
 * Stopping used to clear the running state and throw the offset away, while the hourly
 * start put everything back to work within the hour. Whoever pressed Stop had an import
 * that started over from the beginning, not one that was off.
 */
class ImportStopTest extends WP_UnitTestCase {

	/**
	 * The etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * Creates an etalage halfway through an import and intercepts outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'name'        => 'Test Etalage',
				'api_key'     => 'test-key',
				'prefix'      => null,
				'importing'   => 0,
				'offset'      => 300,
				'isbns'       => 1,
				'last_import' => '2026-09-01 10:00:00',
				'filter_hash' => 'abc',
				'running'     => 2,
			)
		);
		$this->etalage_id = (int) $wpdb->insert_id;

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 9, 3 );
	}

	/**
	 * Stops intercepting outbound HTTP requests.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 9 );

		parent::tear_down();
	}

	/**
	 * Answers the endpoints start_import() calls on its way, and leaves the rest to the
	 * blocker in the bootstrap.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $args, $url ) {
		$bodies = array(
			'test'     => array(
				'0'              => 'hello',
				'plugin_version' => '1.1.1',
			),
			'validate' => array( 'valid' => true ),
			'isbns'    => array(
				'filters' => 'abc',
				'isbns'   => array( '9789000000001' ),
			),
		);

		foreach ( $bodies as $endpoint => $body ) {
			if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . $endpoint ) ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $body ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}

		return $preempt;
	}

	/**
	 * Reads a column of the etalage row.
	 *
	 * @param string $column  Column name.
	 *
	 * @return int
	 */
	private function etalage_column( $column ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT `{$column}` FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * A run can take hours. Recording the moment it ended means every change BoekDB made
	 * while it was running falls in the gap and is never fetched.
	 */
	public function test_a_finished_import_records_when_it_started() {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'offset'  => 0,
				'running' => 2,
			),
			array( 'id' => $this->etalage_id )
		);

		BoekDB_Import::start_import();

		// Stand in for a run that began hours ago and is only now finishing.
		$started = '2026-09-14 08:00:00';
		update_option( 'boekdb_import_start_' . $this->etalage_id, $started, false );

		add_filter( 'pre_http_request', array( $this, 'answer_with_no_products' ), 8, 3 );
		BoekDB_Import::import();
		remove_filter( 'pre_http_request', array( $this, 'answer_with_no_products' ), 8 );

		$last_import = $wpdb->get_var(
			$wpdb->prepare( "SELECT last_import FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( $started, $last_import, 'The import should carry on from where this run began.' );
	}

	/**
	 * Answers the products endpoint with an empty batch, which is how the import knows it
	 * is done.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function answer_with_no_products( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
			return $preempt;
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( array() ),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Throwing the offset away means the next import starts from the first book again,
	 * downloading everything a second time.
	 */
	public function test_stopping_keeps_the_place_the_import_had_reached() {
		BoekDB_Import::stop_import();

		$this->assertSame( 300, $this->etalage_column( 'offset' ), 'Stopping should keep the offset.' );
		$this->assertSame( 0, $this->etalage_column( 'running' ), 'And leave nothing running.' );
	}

	/**
	 * Stop has to mean stopped, not stopped until the next hour.
	 */
	public function test_an_import_that_was_stopped_does_not_start_again_by_itself() {
		BoekDB_Import::stop_import();

		BoekDB_Import::start_import();

		$this->assertSame( 0, $this->etalage_column( 'running' ), 'The hourly start must leave a stopped import alone.' );
	}

	/**
	 * And Run import has to undo it, or there is no way back from the admin screen.
	 */
	public function test_starting_by_hand_undoes_the_stop() {
		BoekDB_Import::stop_import();

		BoekDB_Import::resume_import();

		$this->assertSame( 2, $this->etalage_column( 'running' ), 'Run import should put the etalage back to work.' );
	}
}
