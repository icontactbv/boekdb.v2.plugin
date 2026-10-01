<?php
/**
 * Covers the work an import does on books that did not change.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Performance tests.
 *
 * Sites report being slow while an import runs. Part of that is the import rewriting books
 * that nothing happened to: every book in a title group saved every other book in that
 * group, on every run.
 */
class ImportPerformanceTest extends WP_UnitTestCase {

	/**
	 * Books in the batch, all editions of the same title.
	 */
	const BOOKS = 6;

	/**
	 * The etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

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
	 * Serves a batch of editions that share one nstc, the way a title with a paperback, a
	 * hardback, an ebook and an audio edition arrives.
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

		// One title, several editions: that is what an nstc group is.
		$vormen   = array( 'Paperback', 'Hardback', 'Ebook', 'Luisterboek', 'Grootletterboek', 'Dwarsligger' );
		$products = array();
		for ( $i = 1; $i <= self::BOOKS; $i++ ) {
			$products[] = array(
				'isbn'              => '978900000001' . $i,
				'titel'             => 'De Amerikaanse Burgeroorlog',
				'nstc'              => '5016648',
				'verschijningsvorm' => $vormen[ $i - 1 ],
				'status'            => '10',
				'betrokkenen'       => array(),
				'onderwerpen'       => array(),
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
	 * Queues the etalage for another batch from the start.
	 *
	 * @return void
	 */
	private function queue_another_run() {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'offset'  => 0,
				'running' => 2,
			),
			array( 'id' => $this->etalage_id )
		);
	}

	/**
	 * Importing a batch that changed nothing should touch each book once, not once for
	 * every other edition of the same title.
	 */
	public function test_an_unchanged_batch_does_not_rewrite_the_other_editions() {
		BoekDB_Import::import();
		$this->queue_another_run();

		$saved = 0;
		add_action(
			'save_post',
			function () use ( &$saved ) {
				++$saved;
			}
		);

		BoekDB_Import::import();

		$this->assertLessThanOrEqual(
			self::BOOKS,
			$saved,
			sprintf( 'An unchanged batch of %d books saved %d posts.', self::BOOKS, $saved )
		);
	}

	/**
	 * And it should not take hundreds of queries to work out that nothing changed.
	 */
	public function test_an_unchanged_batch_stays_well_under_a_hundred_queries_per_book() {
		global $wpdb;

		BoekDB_Import::import();
		$this->queue_another_run();

		$before = $wpdb->num_queries;

		BoekDB_Import::import();

		$queries = $wpdb->num_queries - $before;

		$this->assertLessThan(
			300,
			$queries,
			sprintf( 'An unchanged batch of %d books ran %d queries.', self::BOOKS, $queries )
		);
	}
}
