<?php
/**
 * Covers what a batch does when one of its products cannot be processed.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Import;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Resilience tests.
 *
 * One unusable product used to take the whole import with it: the batch stopped where it
 * stood, the offset never moved and every following book in that batch was skipped.
 */
class ImportResilienceTest extends WP_UnitTestCase {

	/**
	 * The etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * Creates a ready-to-run etalage and starts intercepting outbound HTTP requests.
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
				'offset'      => 0,
				'isbns'       => 0,
				'last_import' => null,
				'filter_hash' => null,
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
	 * Serves two books on the products endpoint and leaves everything else to the blocker
	 * in the bootstrap.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
			return $preempt;
		}

		$products = array(
			array(
				'isbn'        => '9789000000011',
				'titel'       => 'Onverwerkbaar Boek',
				'betrokkenen' => array(),
				'onderwerpen' => array(),
			),
			array(
				'isbn'        => '9789000000012',
				'titel'       => 'Gewoon Boek',
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
	 * Makes the first book of the batch blow up halfway through its import.
	 */
	private function break_the_first_book() {
		add_filter(
			'wp_insert_post_data',
			function ( $data ) {
				if ( 'Onverwerkbaar Boek' === $data['post_title'] ) {
					throw new RuntimeException( 'Cannot use object of type WP_Error as array' );
				}

				return $data;
			}
		);
	}

	/**
	 * The books behind the broken one still have to be imported.
	 */
	public function test_a_broken_book_does_not_take_the_rest_of_the_batch_with_it() {
		$this->break_the_first_book();

		BoekDB_Import::import();

		global $wpdb;
		$imported = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000012' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertNotSame( 0, $imported, 'The book after the broken one should still have been imported.' );
	}

	/**
	 * And the batch has to finish, or the import starts over on the same books every run.
	 */
	public function test_a_broken_book_does_not_block_the_next_batch() {
		$this->break_the_first_book();

		BoekDB_Import::import();

		$this->assertSame( Boekdb_Api_Service::LIMIT, $this->etalage_column( 'offset' ), 'The offset should have moved on.' );
		$this->assertSame( 2, $this->etalage_column( 'running' ), 'The etalage should be queued for its next batch.' );
	}

	/**
	 * Runs a callback while the first book of the batch is being imported.
	 *
	 * @param callable $callback  What to do halfway through the batch.
	 *
	 * @return void
	 */
	private function during_the_first_book( callable $callback ) {
		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( $callback ) {
				if ( 'Onverwerkbaar Boek' === $data['post_title'] ) {
					$callback();
				}

				return $data;
			}
		);
	}

	/**
	 * A batch whose lock was handed to another run has to stop. Carrying on would mean two
	 * imports writing the same books at the same time.
	 */
	public function test_a_batch_stops_once_its_lock_is_taken_over() {
		$this->during_the_first_book(
			function () {
				update_option(
					'boekdb_import_lock_' . $this->etalage_id,
					array(
						'token' => 'taken-over-by-another-run',
						'time'  => time(),
					),
					false
				);
			}
		);

		BoekDB_Import::import();

		global $wpdb;
		$second = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000012' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 0, $second, 'A batch that lost its lock should not keep writing books.' );
	}

	/**
	 * A batch that takes longer than the lock timeout has to keep its lock alive, or the
	 * next run takes the etalage over while this one is still working.
	 */
	public function test_a_running_batch_keeps_its_lock_fresh() {
		$this->during_the_first_book(
			function () {
				$lock         = get_option( 'boekdb_import_lock_' . $this->etalage_id );
				$lock['time'] = time() - 5000;
				update_option( 'boekdb_import_lock_' . $this->etalage_id, $lock, false );
			}
		);

		$seen = null;
		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( &$seen ) {
				if ( 'Gewoon Boek' === $data['post_title'] ) {
					$lock = get_option( 'boekdb_import_lock_' . $this->etalage_id );
					$seen = is_array( $lock ) ? $lock['time'] : null;
				}

				return $data;
			}
		);

		BoekDB_Import::import();

		$this->assertNotNull( $seen, 'The second book should have been imported.' );
		$this->assertGreaterThan( time() - 60, $seen, 'The lock should have been refreshed while the batch ran.' );
	}

	/**
	 * A process that ends mid-batch still holds its lock. Shutdown is the last moment to
	 * hand it back, and the only one a fatal error leaves.
	 */
	public function test_shutdown_hands_back_the_lock_of_the_batch_it_belongs_to() {
		$this->during_the_first_book(
			function () {
				global $wpdb;

				$etalage = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);

				BoekDB_Import::release_lock_on_shutdown( $etalage );
			}
		);

		BoekDB_Import::import();

		$this->assertSame( 2, $this->etalage_column( 'running' ), 'The etalage should be waiting for its next run.' );
		$this->assertFalse( get_option( 'boekdb_import_lock_' . $this->etalage_id ), 'The lock should be gone.' );
	}

	/**
	 * The Stop button clears the running state. Shutdown must not put the etalage back to
	 * work behind the user's back.
	 */
	public function test_shutdown_leaves_a_stopped_etalage_alone() {
		$this->during_the_first_book(
			function () {
				global $wpdb;

				$wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'running' => 0 ), array( 'id' => $this->etalage_id ) );

				$etalage = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);

				BoekDB_Import::release_lock_on_shutdown( $etalage );
			}
		);

		BoekDB_Import::import();

		$this->assertSame( 0, $this->etalage_column( 'running' ), 'A stopped etalage has to stay stopped.' );
	}
}
