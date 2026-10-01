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
	 *
	 * @param bool $once  Whether it only fails the first time.
	 *
	 * @return void
	 */
	private function break_the_first_book( $once = false ) {
		$broken = false;

		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( $once, &$broken ) {
				if ( 'Onverwerkbaar Boek' === $data['post_title'] && ! ( $once && $broken ) ) {
					$broken = true;

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

	/**
	 * Writes a lock straight into the options table, the way another process would: through
	 * the database, without touching this request's option cache.
	 *
	 * @param string $token  Identifies the run holding it.
	 *
	 * @return void
	 */
	private function another_process_takes_the_lock( $token ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->options,
			array(
				'option_value' => serialize(
					array(
						'token' => $token,
						'time'  => time(),
					)
				),
			), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			array( 'option_name' => 'boekdb_import_lock_' . $this->etalage_id )
		);
	}

	/**
	 * Reads the lock the way another process would see it: from the database, past this
	 * request's option cache.
	 *
	 * @return array|false
	 */
	private function lock_in_the_database() {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'boekdb_import_lock_' . $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return is_null( $value ) ? false : maybe_unserialize( $value );
	}

	/**
	 * Another process takes the lock over through the database. Reading the cached option
	 * would show this run its own token and let it carry on.
	 */
	public function test_a_batch_notices_a_takeover_it_did_not_see_in_its_own_cache() {
		$this->during_the_first_book(
			function () {
				$this->another_process_takes_the_lock( 'taken-over-through-the-database' );
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
	 * And it must leave that lock alone when it finishes, instead of clearing the claim of
	 * the run that is now working.
	 */
	public function test_a_batch_does_not_clear_a_lock_that_was_taken_from_it() {
		$this->during_the_first_book(
			function () {
				$this->another_process_takes_the_lock( 'now-held-by-someone-else' );
			}
		);

		BoekDB_Import::import();

		$lock = $this->lock_in_the_database();

		$this->assertIsArray( $lock, 'The other run should still hold its lock.' );
		$this->assertSame( 'now-held-by-someone-else', $lock['token'] );
	}

	/**
	 * Two runs can both come back from the API with the same batch. The second must not
	 * claim an etalage the first is already working on.
	 */
	public function test_a_second_run_does_not_claim_a_batch_that_is_already_being_worked_on() {
		update_option(
			'boekdb_import_lock_' . $this->etalage_id,
			array(
				'token' => 'held-by-the-first-run',
				'time'  => time(),
			),
			false
		);

		BoekDB_Import::import();

		$lock = $this->lock_in_the_database();

		$this->assertSame( 'held-by-the-first-run', $lock['token'], 'The first run still holds the etalage.' );
	}

	/**
	 * Two runs reaching for the same etalage: whoever writes the lock first keeps it. Here
	 * the other worker writes its lock in the moment between this run looking and claiming.
	 */
	public function test_a_claim_does_not_overwrite_a_lock_written_in_the_meantime() {
		$written = false;

		add_filter(
			'query',
			function ( $query ) use ( &$written ) {
				if ( $written || false === strpos( $query, 'boekdb_import_lock_' . $this->etalage_id ) || 0 !== stripos( ltrim( $query ), 'INSERT' ) ) {
					return $query;
				}

				// The other worker gets its lock in first, in the moment between this run
				// looking and writing.
				$written = true;
				global $wpdb;
				$wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => 'boekdb_import_lock_' . $this->etalage_id,
						'option_value' => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
							array(
								'token' => 'written-by-the-other-worker',
								'time'  => time(),
							)
						),
						'autoload'     => 'off',
					)
				);

				return $query;
			}
		);

		BoekDB_Import::import();

		$lock = $this->lock_in_the_database();

		$this->assertIsArray( $lock, 'There should still be a lock.' );
		$this->assertSame( 'written-by-the-other-worker', $lock['token'], 'The first writer keeps the lock.' );
	}

	/**
	 * Reads a column of the etalage row.
	 *
	 * @param string $column  Column name.
	 *
	 * @return int
	 */
	private function etalage_value( $column ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT `{$column}` FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Stop is pressed in the admin while a batch is running here. That is another request,
	 * so this one has to look in the database rather than at what it cached.
	 */
	public function test_a_stop_from_another_request_during_the_last_book_is_seen() {
		$this->during_the_last_book(
			function () {
				global $wpdb;

				$wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => 'boekdb_import_stopped',
						'option_value' => '1',
						'autoload'     => 'off',
					)
				);
			}
		);

		BoekDB_Import::import();

		$this->assertSame( 0, $this->etalage_value( 'running' ), 'A stopped import should not be queued again.' );
	}

	/**
	 * A run whose lock was taken over during its last book must not write its offset over
	 * the progress of the run that holds the etalage now.
	 */
	public function test_a_run_that_lost_its_lock_does_not_write_its_offset() {
		$this->during_the_last_book(
			function () {
				$this->another_process_takes_the_lock( 'now-held-by-someone-else' );
			}
		);

		BoekDB_Import::import();

		$this->assertSame( 0, $this->etalage_value( 'offset' ), 'The offset belongs to whoever holds the lock.' );
	}

	/**
	 * Runs a callback while the last book of the batch is being imported.
	 *
	 * @param callable $callback  What to do at the end of the batch.
	 *
	 * @return void
	 */
	private function during_the_last_book( callable $callback ) {
		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( $callback ) {
				if ( 'Gewoon Boek' === $data['post_title'] ) {
					$callback();
				}

				return $data;
			}
		);
	}

	/**
	 * A quote that an editor switched off has to stay off. The import used to write the new
	 * quotes first and then read 'the current ones' back, which handed it its own fresh
	 * values and switched hidden quotes back on.
	 */
	public function test_a_hidden_review_quote_stays_hidden() {
		$quote = (object) array(
			'tekst'  => 'Een prachtig boek.',
			'auteur' => 'De Recensent',
			'bron'   => 'Het Blad',
			'datum'  => '2026-01-01',
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $quote ) {
				if ( 0 !== strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
					return $preempt;
				}

				$products = array(
					array(
						'isbn'           => '9789000000051',
						'titel'          => 'Boek met quote',
						'betrokkenen'    => array(),
						'onderwerpen'    => array(),
						'recensiequotes' => array( $quote ),
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
			},
			10,
			3
		);

		BoekDB_Import::import();

		global $wpdb;
		$boek_post_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000051' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// The editor switches the quote off.
		$hash                     = md5( $quote->tekst );
		$quotes                   = get_post_meta( $boek_post_id, 'boekdb_recensiequotes', true );
		$quotes[ $hash ]['tonen'] = false;
		update_post_meta( $boek_post_id, 'boekdb_recensiequotes', $quotes );

		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'offset'  => 0,
				'running' => 2,
			),
			array( 'id' => $this->etalage_id )
		);

		BoekDB_Import::import();

		$after = get_post_meta( $boek_post_id, 'boekdb_recensiequotes', true );

		$this->assertFalse( $after[ $hash ]['tonen'], 'A quote that was switched off should stay off.' );
	}

	/**
	 * Answers the single product endpoint for the book that failed.
	 *
	 * @param int $calls  Counts how often it was asked for.
	 *
	 * @return void
	 */
	private function serve_the_broken_book_on_its_own( &$calls ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				if ( Boekdb_Api_Service::BASE_URL . 'products/9789000000011' !== $url ) {
					return $preempt;
				}

				++$calls;

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'isbn'        => '9789000000011',
							'titel'       => 'Onverwerkbaar Boek',
							'betrokkenen' => array(),
							'onderwerpen' => array(),
						)
					),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Queues the etalage for another run from the start.
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
	 * Skipping a book keeps the batch going, but the book itself is then missing until
	 * BoekDB happens to change it. It is written down instead.
	 */
	public function test_a_book_that_could_not_be_imported_is_written_down() {
		$this->break_the_first_book();

		BoekDB_Import::import();

		$failed = get_option( 'boekdb_import_failed_' . $this->etalage_id );

		$this->assertIsArray( $failed, 'The failed book should have been written down.' );
		$this->assertArrayHasKey( '9789000000011', $failed );
	}

	/**
	 * And fetched again on its own at the end of the next run, without pulling in the whole
	 * batch a second time.
	 */
	public function test_a_book_that_failed_is_fetched_again_on_its_own() {
		// Whatever it was, it is over by the time the book is fetched again.
		$this->break_the_first_book( true );
		BoekDB_Import::import();

		$calls = 0;
		$this->serve_the_broken_book_on_its_own( $calls );

		// A run that finds nothing new, which is how a run ends.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
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
			},
			11,
			3
		);

		$this->queue_another_run();
		BoekDB_Import::import();

		global $wpdb;
		$imported = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000011' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 1, $calls, 'The book should have been fetched on its own.' );
		$this->assertNotSame( 0, $imported, 'And imported.' );
		$this->assertFalse( get_option( 'boekdb_import_failed_' . $this->etalage_id ), 'Nothing left to retry.' );
	}

	/**
	 * A book that keeps failing is left alone after a few tries, instead of being fetched
	 * at the end of every run forever. It stays on the list, so it can be looked into.
	 */
	public function test_a_book_that_keeps_failing_is_left_alone() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array( '9789000000011' => 3 ),
			false
		);

		$calls = 0;
		$this->serve_the_broken_book_on_its_own( $calls );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
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
			},
			11,
			3
		);

		BoekDB_Import::import();

		$this->assertSame( 0, $calls, 'A book that failed three times is not fetched again.' );
		$this->assertArrayHasKey( '9789000000011', get_option( 'boekdb_import_failed_' . $this->etalage_id ), 'It stays on the list.' );
	}

	/**
	 * Writes a foreign lock the first time a query of the given kind touches the lock of
	 * this etalage, standing in for another worker getting there in that same moment.
	 *
	 * @param string $statement  DELETE, INSERT or UPDATE.
	 * @param string $token      The token the other worker writes.
	 *
	 * @return void
	 */
	private function another_worker_writes_during( $statement, $token ) {
		$written = false;

		add_filter(
			'query',
			function ( $query ) use ( $statement, $token, &$written ) {
				if ( $written || false === strpos( $query, 'boekdb_import_lock_' . $this->etalage_id ) || 0 !== stripos( ltrim( $query ), $statement ) ) {
					return $query;
				}

				$written = true;

				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						"REPLACE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'boekdb_import_lock_' . $this->etalage_id,
						maybe_serialize(
							array(
								'token' => $token,
								'time'  => time(),
							)
						)
					)
				);

				return $query;
			}
		);
	}

	/**
	 * Taking back an expired lock must not throw away the claim another run made in the
	 * same moment: both of them read the same dead lock.
	 */
	public function test_taking_back_an_expired_lock_leaves_a_fresh_claim_alone() {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'running' => 1 ), array( 'id' => $this->etalage_id ) );
		update_option(
			'boekdb_import_lock_' . $this->etalage_id,
			array(
				'token' => 'the-run-that-died',
				'time'  => time() - 601,
			),
			false
		);

		$this->another_worker_writes_during( 'DELETE', 'claimed-by-the-other-run' );

		BoekDB_Import::import();

		$lock = $this->lock_in_the_database();

		$this->assertIsArray( $lock, 'The other run should still hold its lock.' );
		$this->assertSame( 'claimed-by-the-other-run', $lock['token'] );
	}

	/**
	 * Keeping a lock alive must not write over the lock of whoever took it over a moment
	 * earlier.
	 */
	public function test_keeping_a_lock_alive_does_not_write_over_a_takeover() {
		$this->another_worker_writes_during( 'UPDATE', 'taken-over-during-the-heartbeat' );

		BoekDB_Import::import();

		$lock = $this->lock_in_the_database();

		$this->assertIsArray( $lock, 'The other run should still hold its lock.' );
		$this->assertSame( 'taken-over-during-the-heartbeat', $lock['token'] );
	}

	/**
	 * And letting go of a lock must not remove one that belongs to someone else by then.
	 */
	public function test_letting_go_of_a_lock_leaves_a_takeover_alone() {
		$this->another_worker_writes_during( 'DELETE', 'taken-over-at-the-end' );

		BoekDB_Import::import();

		$lock = $this->lock_in_the_database();

		$this->assertIsArray( $lock, 'The other run should still hold its lock.' );
		$this->assertSame( 'taken-over-at-the-end', $lock['token'] );
	}

	/**
	 * Answers the products endpoint with nothing, which is how a run ends.
	 *
	 * @return void
	 */
	private function serve_an_empty_batch() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
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
			},
			11,
			3
		);
	}

	/**
	 * The retries at the end of a run write books like any batch does, so Stop has to reach
	 * them too.
	 */
	public function test_retries_stop_when_the_import_is_stopped() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array( '9789000000011' => 1 ),
			false
		);

		$calls = 0;
		$this->serve_the_broken_book_on_its_own( $calls );
		$this->serve_an_empty_batch();

		// Stop is pressed from the admin while the run is finishing.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( Boekdb_Api_Service::BASE_URL . 'products/9789000000011' === $url ) {
					global $wpdb;
					$wpdb->query(
						$wpdb->prepare(
							"REPLACE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							'boekdb_import_stopped',
							'1'
						)
					);
				}

				return $preempt;
			},
			9,
			3
		);

		BoekDB_Import::import();

		global $wpdb;
		$imported = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s", '9789000000011' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 0, $imported, 'A stopped import should not write books.' );
	}

	/**
	 * A book that failed once and goes in fine the next time has nothing left to retry.
	 */
	public function test_a_book_that_imports_fine_is_taken_off_the_list() {
		$this->break_the_first_book( true );
		BoekDB_Import::import();

		$this->assertArrayHasKey( '9789000000011', get_option( 'boekdb_import_failed_' . $this->etalage_id ) );

		$this->queue_another_run();
		BoekDB_Import::import();

		$this->assertFalse( get_option( 'boekdb_import_failed_' . $this->etalage_id ), 'Nothing left to retry.' );
	}

	/**
	 * Books that fall outside the new filters of an etalage are removed from the site. They
	 * must not come back through the retries, which fetch by isbn and know no filters.
	 */
	public function test_changed_filters_drop_the_books_waiting_for_a_retry() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array( '9789000000011' => 1 ),
			false
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$bodies = array(
					'test'     => array(
						'0'              => 'hello',
						'plugin_version' => '1.1.1',
					),
					'validate' => array( 'valid' => true ),
					'isbns'    => array(
						'filters' => 'these-are-different-filters',
						'isbns'   => array( '9789000000012' ),
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
			},
			11,
			3
		);

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'running' => 0 ), array( 'id' => $this->etalage_id ) );

		BoekDB_Import::start_import();

		$this->assertFalse( get_option( 'boekdb_import_failed_' . $this->etalage_id ), 'The old selection is gone, and so is what was waiting for it.' );
	}

	/**
	 * Letting go of a lock and writing the state of the etalage are two steps. Another run
	 * can claim the etalage in between, and must not find its state overwritten by the one
	 * that was leaving.
	 */
	public function test_a_leaving_run_does_not_overwrite_a_new_owner() {
		$claimed = false;

		add_filter(
			'query',
			function ( $query ) use ( &$claimed ) {
				global $wpdb;

				// The moment the run on its way out writes the etalage back to waiting.
				$writes_waiting = false !== strpos( $query, "= '2'" ) || false !== strpos( $query, 'running = 2' );

				if ( $claimed || false === strpos( $query, $wpdb->prefix . 'boekdb_etalages' ) || ! $writes_waiting ) {
					return $query;
				}

				$claimed = true;

				$wpdb->query(
					$wpdb->prepare(
						"REPLACE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'boekdb_import_lock_' . $this->etalage_id,
						maybe_serialize(
							array(
								'token' => 'the-new-owner',
								'time'  => time(),
							)
						)
					)
				);
				$wpdb->query(
					$wpdb->prepare( "UPDATE {$wpdb->prefix}boekdb_etalages SET running = 1 WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);

				return $query;
			}
		);

		BoekDB_Import::import();

		$this->assertSame( 1, $this->etalage_value( 'running' ), 'The new owner decides the state of the etalage.' );
	}

	/**
	 * Stopping between books has to hand the lock back, or starting again is refused until
	 * the lock times out ten minutes later.
	 */
	public function test_stopping_between_books_hands_the_lock_back() {
		$this->during_the_first_book(
			function () {
				BoekDB_Import::stop_import();
			}
		);

		BoekDB_Import::import();

		$this->assertFalse( $this->lock_in_the_database(), 'A stopped run should not sit on its lock.' );
	}

	/**
	 * Losing the lock halfway through the retries stops the work, and has to stop the
	 * bookkeeping with it: the list belongs to whoever holds the etalage now.
	 */
	public function test_a_run_that_loses_its_lock_during_the_retries_writes_nothing_back() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array(
				'9789000000011' => 1,
				'9789000000013' => 1,
			),
			false
		);

		$calls = 0;
		$this->serve_the_broken_book_on_its_own( $calls );
		$this->serve_an_empty_batch();

		// The first book goes in fine. The etalage then changes hands while the second one
		// is being fetched, and its new owner has its own idea of what still needs retrying.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( Boekdb_Api_Service::BASE_URL . 'products/9789000000013' !== $url ) {
					return $preempt;
				}

				$this->another_process_takes_the_lock( 'the-new-owner' );

				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						"REPLACE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'boekdb_import_failed_' . $this->etalage_id,
						maybe_serialize( array( '9789000000099' => 1 ) )
					)
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'isbn'        => '9789000000013',
							'titel'       => 'Nog Een Boek',
							'betrokkenen' => array(),
							'onderwerpen' => array(),
						)
					),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			9,
			3
		);

		BoekDB_Import::import();

		global $wpdb;
		$failed = maybe_unserialize(
			$wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'boekdb_import_failed_' . $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->assertSame( 1, $calls, 'The first book should have been fetched.' );
		$this->assertSame( array( '9789000000099' => 1 ), $failed, 'The list belongs to the run that owns the etalage.' );
	}

	/**
	 * And neither may it write down where the import got to.
	 */
	public function test_a_run_that_loses_its_lock_during_the_retries_leaves_the_progress_alone() {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'offset'      => 300,
				'last_import' => '2026-09-01 10:00:00',
			),
			array( 'id' => $this->etalage_id )
		);

		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array( '9789000000011' => 1 ),
			false
		);

		$calls = 0;
		$this->serve_the_broken_book_on_its_own( $calls );
		$this->serve_an_empty_batch();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( Boekdb_Api_Service::BASE_URL . 'products/9789000000011' === $url ) {
					$this->another_process_takes_the_lock( 'the-new-owner' );
				}

				return $preempt;
			},
			9,
			3
		);

		BoekDB_Import::import();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT `offset`, last_import FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( '300', $row->offset, 'The progress belongs to the new owner.' );
		$this->assertSame( '2026-09-01 10:00:00', $row->last_import, 'And so does the import date.' );
	}

	/**
	 * A book can take longer to write than the lock lasts. The etalage is then taken over
	 * while this run is still in that book, and what it notes down about failures afterwards
	 * belongs to the run that owns it now.
	 */
	public function test_a_batch_that_lost_its_lock_mid_book_notes_nothing_down() {
		// The etalage changes hands while this run is writing the book, and the book it is
		// writing then fails: the moment it would note that down, it no longer owns anything.
		$this->during_the_first_book(
			function () {
				$this->another_process_takes_the_lock( 'the-new-owner' );

				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						"REPLACE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'boekdb_import_failed_' . $this->etalage_id,
						maybe_serialize( array( '9789000000099' => 1 ) )
					)
				);
			}
		);
		$this->break_the_first_book();

		BoekDB_Import::import();

		global $wpdb;
		$failed = maybe_unserialize(
			$wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'boekdb_import_failed_' . $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);

		$this->assertSame( array( '9789000000099' => 1 ), $failed, 'The list belongs to the run that owns the etalage.' );
	}
}
