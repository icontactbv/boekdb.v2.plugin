<?php
/**
 * Covers the state an import run leaves behind: stopping, resuming and starting over.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Run state tests.
 *
 * Stopping used to clear the running state and throw the offset away, while the hourly
 * start put everything back to work within the hour. Whoever pressed Stop had an import
 * that started over from the beginning, not one that was off.
 */
class ImportRunStateTest extends WP_UnitTestCase {

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

	/**
	 * Answers the products endpoint with two books.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function answer_with_two_products( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
			return $preempt;
		}

		$products = array(
			array(
				'isbn'        => '9789000000021',
				'titel'       => 'Eerste Boek',
				'betrokkenen' => array(),
				'onderwerpen' => array(),
			),
			array(
				'isbn'        => '9789000000022',
				'titel'       => 'Laatste Boek',
				'betrokkenen' => array(),
				'onderwerpen' => array(),
			),
		);

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $products ),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Stop can be pressed while the batch is still being fetched. Claiming the etalage
	 * straight afterwards puts it back to work regardless.
	 */
	public function test_stopping_while_the_batch_is_being_fetched_still_stops_it() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
					BoekDB_Import::stop_import();
				}

				return $preempt;
			},
			8,
			3
		);
		add_filter( 'pre_http_request', array( $this, 'answer_with_two_products' ), 9, 3 );

		BoekDB_Import::import();

		$this->assertSame( 0, $this->etalage_column( 'running' ), 'A stopped import should not be queued again.' );
	}

	/**
	 * And it can be pressed while the last book of a batch is being written, after which
	 * finishing the batch queued the etalage for the next one.
	 */
	public function test_stopping_during_the_last_book_still_stops_it() {
		add_filter( 'pre_http_request', array( $this, 'answer_with_two_products' ), 9, 3 );
		add_filter(
			'wp_insert_post_data',
			function ( $data ) {
				if ( 'Laatste Boek' === $data['post_title'] ) {
					BoekDB_Import::stop_import();
				}

				return $data;
			}
		);

		BoekDB_Import::import();

		$this->assertSame( 0, $this->etalage_column( 'running' ), 'A stopped import should not be queued again.' );
	}

	/**
	 * Resuming carries on where the import was, so it also has to carry on from the moment
	 * that run started. Taking the moment of resuming would skip everything that changed
	 * while the import was off.
	 */
	public function test_resuming_keeps_the_moment_the_run_started() {
		$started = '2026-09-14 08:00:00';
		update_option( 'boekdb_import_start_' . $this->etalage_id, $started, false );

		BoekDB_Import::stop_import();
		BoekDB_Import::resume_import();

		$this->assertSame( $started, get_option( 'boekdb_import_start_' . $this->etalage_id ) );
	}

	/**
	 * Changed filters mean a different set of books, so the import starts over. Keeping the
	 * old offset starts it in the middle of that set and the books before it never arrive.
	 */
	public function test_changed_filters_start_the_import_over() {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'running' => 0 ), array( 'id' => $this->etalage_id ) );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( 0 !== strpos( $url, Boekdb_Api_Service::BASE_URL . 'isbns' ) ) {
					return $preempt;
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'filters' => 'these-are-different-filters',
							'isbns'   => array( '9789000000001' ),
						)
					),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			// After the class filter, which answers the same endpoint.
			10,
			3
		);

		BoekDB_Import::start_import();

		$this->assertSame( 0, $this->etalage_column( 'offset' ), 'A new selection is imported from the start.' );
	}

	/**
	 * A run that finds nothing new still writes down where it got to. It must not do that
	 * when another run took the etalage over while it was asking.
	 */
	public function test_a_run_that_finds_nothing_leaves_another_owner_alone() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
					global $wpdb;

					$wpdb->query(
						$wpdb->prepare(
							"REPLACE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							'boekdb_import_lock_' . $this->etalage_id,
							maybe_serialize(
								array(
									'token' => 'the-run-that-owns-this-etalage',
									'time'  => time(),
								)
							)
						)
					);
				}

				return $preempt;
			},
			8,
			3
		);
		add_filter( 'pre_http_request', array( $this, 'answer_with_no_products' ), 11, 3 );

		BoekDB_Import::import();

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT `offset`, last_import FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( '300', $row->offset, 'The progress belongs to whoever holds the lock.' );
		$this->assertSame( '2026-09-01 10:00:00', $row->last_import, 'And so does the import date.' );
	}

	/**
	 * A book can leave an etalage without its filters changing: it goes out of print, and
	 * the isbn list simply gets shorter. The cleanup removes the book, and the retries, which
	 * fetch by isbn and know no filters, must not bring it back.
	 */
	public function test_a_book_that_left_the_selection_is_dropped_from_the_retries() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array(
				'9789000000001' => 1,
				'9789000000077' => 1,
			),
			false
		);

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'running' => 0 ), array( 'id' => $this->etalage_id ) );

		BoekDB_Import::start_import();

		$this->assertSame(
			array( '9789000000001' => 1 ),
			get_option( 'boekdb_import_failed_' . $this->etalage_id ),
			'Only books the etalage still carries are worth retrying.'
		);
	}
}
