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
	 * Whether the first edition is served as no longer for sale, which hands the primary
	 * place to the next one.
	 *
	 * @var bool
	 */
	private $demote_first = false;

	/**
	 * Whether the books are served without an nstc, as a title with a single edition is.
	 *
	 * @var bool
	 */
	private $drop_nstc = false;

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
				'nstc'              => $this->drop_nstc ? null : '5016648',
				'verschijningsvorm' => $vormen[ $i - 1 ],
				'status'            => ( 1 === $i && $this->demote_first ) ? '40' : '10',
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

	/**
	 * The primary edition carries the title's own url, the others a url of their own. A
	 * book that becomes the primary one keeps its old url for as long as the cached
	 * permalink lives, which is half a day.
	 */
	public function test_a_book_that_becomes_primary_loses_its_cached_permalink() {
		BoekDB_Import::import();

		global $wpdb;
		$second_edition = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000012' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( '0', get_post_meta( $second_edition, 'boekdb_primair', true ), 'The second edition starts out as a secondary one.' );

		set_transient( 'boekdb_permalink_' . $second_edition, 'https://example.org/boek/oude-url/', DAY_IN_SECONDS );

		// The paperback is no longer for sale, so the next edition takes its place.
		$this->demote_first = true;
		$this->queue_another_run();

		BoekDB_Import::import();

		$this->assertSame( '1', get_post_meta( $second_edition, 'boekdb_primair', true ), 'The second edition should have taken over.' );
		$this->assertFalse( get_transient( 'boekdb_permalink_' . $second_edition ), 'Its cached permalink should be gone.' );
	}

	/**
	 * Same thing when the book already carries the slug it will keep as the primary one.
	 * Nothing about the post changes then, only the flag that decides which url it gets.
	 */
	public function test_a_book_that_only_gains_the_primary_flag_loses_its_cached_permalink() {
		BoekDB_Import::import();

		global $wpdb;
		$second_edition = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000012' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// It already carries the slug of the title itself.
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'de-amerikaanse-burgeroorlog' ), array( 'ID' => $second_edition ) );
		clean_post_cache( $second_edition );

		set_transient( 'boekdb_permalink_' . $second_edition, 'https://example.org/boek/oude-url/', DAY_IN_SECONDS );

		$this->demote_first = true;
		$this->queue_another_run();

		BoekDB_Import::import();

		$this->assertSame( '1', get_post_meta( $second_edition, 'boekdb_primair', true ), 'The second edition should have taken over.' );
		$this->assertFalse( get_transient( 'boekdb_permalink_' . $second_edition ), 'Its cached permalink should be gone.' );
	}

	/**
	 * A book with no nstc has no other editions, so it is the primary one. It has to say so
	 * under the name every overview reads.
	 */
	public function test_a_book_without_an_nstc_is_marked_as_primary() {
		$this->drop_nstc = true;

		BoekDB_Import::import();

		global $wpdb;
		$boek_post_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000011' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( '1', get_post_meta( $boek_post_id, 'boekdb_primair', true ), 'A book on its own is the primary edition.' );
	}
}
