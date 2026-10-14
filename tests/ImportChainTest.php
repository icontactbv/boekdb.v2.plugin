<?php
/**
 * Covers an import that needs more than one batch, the way every real etalage does.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Chain tests.
 *
 * An etalage of a few hundred books is imported a hundred at a time. Each batch has to
 * leave the offset where the next one should pick up, hand the etalage back, and see to it
 * that there is a next run at all.
 */
class ImportChainTest extends WP_UnitTestCase {

	/**
	 * Books the API serves in total, which is more than one batch.
	 */
	const BOOKS = 150;

	/**
	 * The etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * The number of books each request asked for.
	 *
	 * @var array
	 */
	private $requested_limits = array();

	/**
	 * Creates a ready-to-run etalage and intercepts outbound HTTP.
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
	 * Serves books a hundred at a time, as the API does.
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

		$offset   = isset( $args['headers']['x-offset'] ) ? (int) $args['headers']['x-offset'] : 0;
		$limit    = isset( $args['headers']['x-limit'] ) ? (int) $args['headers']['x-limit'] : 0;
		$products = array();

		$this->requested_limits[] = $limit;

		$end = min( $offset + $limit, self::BOOKS );

		for ( $i = $offset; $i < $end; $i++ ) {
			$products[] = array(
				'isbn'        => sprintf( '978900000%04d', $i ),
				'titel'       => 'Boek ' . $i,
				'betrokkenen' => array(),
				'onderwerpen' => array(),
			);
		}

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
	 * After a batch the etalage says where the next one starts, and is waiting for it.
	 */
	public function test_a_batch_leaves_the_offset_for_the_next_one() {
		BoekDB_Import::import();

		$this->assertSame( Boekdb_Api_Service::LIMIT, $this->etalage_column( 'offset' ), 'The offset should have moved on.' );
		$this->assertSame( 2, $this->etalage_column( 'running' ), 'And the etalage should be waiting for its next batch.' );
	}

	/**
	 * And there has to be a next run, or the import stops after one batch.
	 */
	public function test_a_batch_sees_to_it_that_there_is_a_next_run() {
		BoekDB_Import::import();

		$this->assertNotFalse( wp_next_scheduled( BoekDB_Import::IMPORT_HOOK ), 'Nothing would pick the etalage back up.' );
	}

	/**
	 * A batch has to stay well within the time a server gives a process. Books come with
	 * files of tens of megabytes, so a hundred at a time was too many.
	 */
	public function test_a_batch_asks_for_twenty_five_books() {
		BoekDB_Import::import();

		$this->assertSame( array( 25 ), $this->requested_limits );
	}

	/**
	 * A run per batch, and the empty one that finishes it.
	 */
	public function test_the_whole_etalage_is_imported_in_batches() {
		$batches = (int) ceil( self::BOOKS / Boekdb_Api_Service::LIMIT );

		for ( $run = 0; $run <= $batches; $run++ ) {
			BoekDB_Import::import();
		}

		global $wpdb;
		$imported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}boekdb_isbns" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$this->assertSame( self::BOOKS, $imported, 'Every book should have been imported.' );
		$this->assertSame( 0, $this->etalage_column( 'running' ), 'And the etalage should be done.' );
	}
}
