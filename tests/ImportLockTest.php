<?php
/**
 * Covers the lock an import holds on an etalage while it processes a batch.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Lock tests.
 *
 * An import marks its etalage as running while it works through a batch. If the process
 * dies in the middle, nothing clears that mark: the import finds no work, the Run button
 * reports an import is already running, and the site stops updating until someone edits the
 * database by hand. These tests pin down that a lock nobody holds any more is taken back.
 */
class ImportLockTest extends WP_UnitTestCase {

	/**
	 * The etalage row under test.
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * Creates an etalage that is marked as being processed.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'name'        => 'Stuck Etalage',
				'api_key'     => 'test-key',
				'prefix'      => null,
				'importing'   => 0,
				'offset'      => 200,
				'isbns'       => 0,
				'last_import' => null,
				'filter_hash' => null,
				'running'     => 1,
			)
		);

		$this->etalage_id = (int) $wpdb->insert_id;
	}

	/**
	 * Reads the running state straight from the database.
	 *
	 * @return int
	 */
	private function running() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT running FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * A batch that died leaves its etalage marked as running. Nothing picks it up again,
	 * which is what left the Omniboek site standing still for three weeks.
	 */
	public function test_a_lock_without_a_holder_is_taken_back() {
		BoekDB_Import::import();

		$this->assertSame( 2, $this->running(), 'An import should take back a lock that nobody holds.' );
	}

	/**
	 * A lock that is still young belongs to a run that is probably still going.
	 */
	public function test_a_fresh_lock_is_left_alone() {
		$this->write_lock( 'held-by-a-running-import', time() );

		BoekDB_Import::import();

		$this->assertSame( 1, $this->running(), 'A lock that was just taken has to be left alone.' );
	}

	/**
	 * Ten minutes without a sign of life is long enough: a batch runs for seconds, and a
	 * run that is still alive refreshes its lock.
	 */
	public function test_an_expired_lock_is_taken_back() {
		$this->write_lock( 'held-by-an-import-that-went-quiet', time() - 601 );

		BoekDB_Import::import();

		$this->assertSame( 2, $this->running(), 'A lock older than the timeout should be taken back.' );
	}

	/**
	 * Writes the lock of another import on the etalage.
	 *
	 * @param string $token  Identifies the run holding it.
	 * @param int    $time   When it was last refreshed.
	 *
	 * @return void
	 */
	private function write_lock( $token, $time ) {
		update_option(
			'boekdb_import_lock_' . $this->etalage_id,
			array(
				'token' => $token,
				'time'  => $time,
			),
			false
		);
	}

	/**
	 * Returns the etalage row the way the import passes it around.
	 *
	 * @return object
	 */
	private function etalage() {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Shutdown runs at the end of the request, by which time the next run may already be
	 * working on this etalage. Handing that run's lock back would set a second import loose
	 * on the same batch.
	 */
	public function test_shutdown_leaves_a_lock_held_by_another_run_alone() {
		$this->write_lock( 'belongs-to-another-run', time() );

		BoekDB_Import::release_lock_on_shutdown( $this->etalage() );

		$this->assertSame( 1, $this->running(), 'A lock held by another run must be left alone.' );
	}
}
