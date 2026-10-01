<?php
/**
 * Covers what the settings screen says about books that could not be imported.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Failed book tests.
 *
 * A book the import gave up on is simply missing from the site. The only trace is a line
 * in a debug log that is switched off almost everywhere, so it has to be visible where
 * someone looks: on the screen that shows the etalages.
 */
class FailedBooksTest extends WP_UnitTestCase {

	/**
	 * The etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * Creates an etalage.
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
				'running'     => 0,
			)
		);

		$this->etalage_id = (int) $wpdb->insert_id;
	}

	/**
	 * Renders the row of the etalage as the settings screen does.
	 *
	 * @return string
	 */
	private function render_row() {
		global $wpdb;

		$etalage = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$etalage->boeken = 0;
		$disabled        = '';

		ob_start();
		include BOEKDB_ABSPATH . 'includes/admin/views/html-admin-etalage-row.php';

		return ob_get_clean();
	}

	/**
	 * The books an etalage gave up on are readable without digging through a log.
	 */
	public function test_the_books_that_failed_are_readable() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array(
				'9789000000011' => 3,
				'9789000000012' => 1,
			),
			false
		);

		$this->assertSame(
			array( '9789000000011', '9789000000012' ),
			BoekDB_Import::failed_products( $this->etalage_id )
		);
	}

	/**
	 * An etalage with nothing wrong reports nothing wrong.
	 */
	public function test_an_etalage_without_failures_reports_none() {
		$this->assertSame( array(), BoekDB_Import::failed_products( $this->etalage_id ) );
	}

	/**
	 * And the screen shows them, with the isbns, so the next question can be asked at
	 * BoekDB rather than in a log file.
	 */
	public function test_the_screen_names_the_books_that_failed() {
		update_option(
			'boekdb_import_failed_' . $this->etalage_id,
			array( '9789000000011' => 3 ),
			false
		);

		$row = $this->render_row();

		$this->assertStringContainsString( '9789000000011', $row, 'The isbn should be on the screen.' );
	}

	/**
	 * A clean etalage shows no alarm.
	 */
	public function test_the_screen_stays_quiet_when_nothing_failed() {
		$row = $this->render_row();

		$this->assertStringNotContainsString( 'niet geïmporteerd', strtolower( $row ), 'Nothing should be reported.' );
	}
}
