<?php
/**
 * Covers what the hourly start does with the list of isbns an etalage holds.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Isbn list tests.
 *
 * Books missing from the list are removed from the site with their files, and only come
 * back when BoekDB reports them changed or someone presses Reset. A list that suddenly
 * shrinks while the selection stayed the same is held for a round before it is believed.
 */
class IsbnListTest extends WP_UnitTestCase {

	/**
	 * The etalage.
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * The books of the etalage, by isbn.
	 *
	 * @var array
	 */
	private $books = array();

	/**
	 * What the API answers for the list of isbns.
	 *
	 * @var array
	 */
	private $answer;

	/**
	 * Creates an etalage of four books, and answers the list request.
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
				'isbns'       => 4,
				'last_import' => '2026-10-01 10:00:00',
				'filter_hash' => 'zelfde',
				'running'     => 0,
			)
		);
		$this->etalage_id = (int) $wpdb->insert_id;

		foreach ( array( '9789000000101', '9789000000102', '9789000000103', '9789000000104' ) as $isbn ) {
			$post_id              = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
			$this->books[ $isbn ] = $post_id;
			$wpdb->insert(
				$wpdb->prefix . 'boekdb_etalage_boeken',
				array(
					'etalage_id' => $this->etalage_id,
					'boek_id'    => $post_id,
				)
			);
			$wpdb->insert(
				$wpdb->prefix . 'boekdb_isbns',
				array(
					'boek_id' => $post_id,
					'isbn'    => $isbn,
				)
			);
		}

		add_filter( 'pre_http_request', array( $this, 'answer_isbns' ), 9, 3 );
	}

	/**
	 * Stops answering.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer_isbns' ), 9 );

		parent::tear_down();
	}

	/**
	 * Answers the list request with $this->answer.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function answer_isbns( $preempt, $args, $url ) {
		if ( false === strpos( $url, '/isbns' ) ) {
			return $preempt;
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $this->answer ),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Runs the check the hourly start runs for the etalage.
	 *
	 * @param array  $isbns    The list the API answers.
	 * @param string $filters  The filter hash it answers.
	 *
	 * @return void
	 */
	private function hourly_check( $isbns, $filters = 'zelfde' ) {
		global $wpdb;

		$this->answer = array(
			'filters' => $filters,
			'isbns'   => $isbns,
		);

		$etalage = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$check   = new ReflectionMethod( BoekDB_Import::class, 'check_available_isbns' );
		if ( PHP_VERSION_ID < 80100 ) {
			// Private methods are reachable through reflection without it from PHP 8.1 on.
			$check->setAccessible( true );
		}
		$check->invoke( null, $etalage );
	}

	/**
	 * How many of the books are still on the site.
	 *
	 * @return int
	 */
	private function books_left() {
		return count( array_filter( array_map( 'get_post', $this->books ) ) );
	}

	/**
	 * An empty list for the same selection is held for a round.
	 */
	public function test_an_empty_list_for_the_same_selection_removes_nothing_at_first() {
		$this->hourly_check( array() );

		$this->assertSame( 4, $this->books_left() );
	}

	/**
	 * The next round, still empty, it is believed.
	 */
	public function test_an_empty_list_the_next_round_too_removes_the_books() {
		$this->hourly_check( array() );
		$this->hourly_check( array() );

		$this->assertSame( 0, $this->books_left() );
	}

	/**
	 * A list that lost more than half its books is held too.
	 */
	public function test_a_list_that_lost_more_than_half_is_held_for_a_round() {
		$this->hourly_check( array( '9789000000101' ) );

		$this->assertSame( 4, $this->books_left() );
	}

	/**
	 * A normal list after a held one starts over: one odd answer is forgotten.
	 */
	public function test_a_normal_list_in_between_forgets_the_odd_one() {
		$this->hourly_check( array() );
		$this->hourly_check( array( '9789000000101', '9789000000102', '9789000000103', '9789000000104' ) );
		$this->hourly_check( array() );

		$this->assertSame( 4, $this->books_left() );
	}

	/**
	 * A book or two leaving the etalage goes at once.
	 */
	public function test_a_book_leaving_goes_at_once() {
		$this->hourly_check( array( '9789000000101', '9789000000102', '9789000000103' ) );

		$this->assertSame( 3, $this->books_left() );
	}

	/**
	 * Other filters mean another selection, so a shrinking list is expected and goes at once.
	 */
	public function test_a_shrinking_list_after_a_filter_change_goes_at_once() {
		$this->hourly_check( array(), 'ander' );

		$this->assertSame( 0, $this->books_left() );
	}
}
