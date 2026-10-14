<?php
/**
 * Covers what the settings screen is told about the import.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Admin_Settings;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Status tests.
 *
 * The screen showed an offset that only moved once a batch was done and a banner that said
 * an import was running whenever anything waited. Nobody could tell what was being worked
 * on, how far it was, or that nothing would pick the rest up.
 */
class ImportStatusTest extends WP_UnitTestCase {

	/**
	 * Books the API serves, which is more than one batch.
	 */
	const BOOKS = 60;

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

		wp_clear_scheduled_hook( BoekDB_Import::IMPORT_HOOK );
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
	 * Serves the books as many at a time as asked for, as the API does.
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

		$offset   = (int) $args['headers']['x-offset'];
		$end      = min( $offset + (int) $args['headers']['x-limit'], self::BOOKS );
		$products = array();

		for ( $i = $offset; $i < $end; $i++ ) {
			$products[] = array(
				'isbn'        => sprintf( '978900001%04d', $i ),
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
	 * Sets the state of the etalage.
	 *
	 * @param int $running  The state.
	 * @param int $offset   Where the next batch starts.
	 *
	 * @return void
	 */
	private function etalage_is( $running, $offset = 0 ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'running' => $running,
				'offset'  => $offset,
			),
			array( 'id' => $this->etalage_id )
		);
	}

	/**
	 * The status of the test etalage.
	 *
	 * @return array
	 */
	private function etalage_status() {
		$status = BoekDB_Import::status();

		return $status['etalages'][ $this->etalage_id ];
	}

	/**
	 * While a batch works, the screen can say which book of how many it is on.
	 */
	public function test_a_running_batch_says_how_far_it_is() {
		$seen = null;
		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( &$seen ) {
				if ( 'Boek 2' === $data['post_title'] ) {
					$seen = $this->etalage_status();
				}

				return $data;
			}
		);

		BoekDB_Import::import();

		$this->assertNotNull( $seen, 'The third book should have been imported.' );
		$this->assertSame( 'busy', $seen['state'] );
		$this->assertSame( 3, $seen['done'], 'It is working on the third book.' );
		$this->assertSame( Boekdb_Api_Service::LIMIT, $seen['size'], 'Of a full batch.' );
		$this->assertSame( 0, $seen['offset'], 'Which starts at the first book.' );
		$this->assertGreaterThan( time() - 60, $seen['heartbeat'] );
	}

	/**
	 * Between batches the etalage waits, and says where it carries on.
	 */
	public function test_a_waiting_etalage_says_where_it_carries_on() {
		BoekDB_Import::import();

		$status = $this->etalage_status();

		$this->assertSame( 'waiting', $status['state'] );
		$this->assertSame( Boekdb_Api_Service::LIMIT, $status['offset'] );
	}

	/**
	 * A batch that stopped reporting in is not busy, whatever the etalage says.
	 */
	public function test_a_batch_that_stopped_reporting_in_is_shown_as_stuck() {
		$this->etalage_is( 1 );
		update_option(
			BoekDB_Import::LOCK_OPTION_PREFIX . $this->etalage_id,
			array(
				'token' => 'gone',
				'time'  => time() - BoekDB_Import::LOCK_TIMEOUT - 60,
			),
			false
		);

		$this->assertSame( 'stuck', $this->etalage_status()['state'] );
	}

	/**
	 * The screen showed when a run started and called it the last import. It now knows when
	 * the etalage was done.
	 */
	public function test_a_finished_etalage_says_when_it_finished() {
		$batches = (int) ceil( self::BOOKS / Boekdb_Api_Service::LIMIT );
		for ( $run = 0; $run <= $batches; $run++ ) {
			BoekDB_Import::import();
		}

		$status = $this->etalage_status();

		$this->assertSame( 'idle', $status['state'] );
		$this->assertGreaterThan( time() - 60, $status['finished'] );
	}

	/**
	 * Etalages waiting with nothing scheduled to pick them up is how an import stood still
	 * for an hour. Opening the screen gets it going again.
	 */
	public function test_waiting_etalages_without_a_next_batch_get_one() {
		$this->assertTrue( BoekDB_Import::ensure_next_batch() );
		$this->assertNotFalse( wp_next_scheduled( BoekDB_Import::IMPORT_HOOK ) );
	}

	/**
	 * So does an etalage whose batch stopped reporting in: the next batch takes it back.
	 */
	public function test_a_stuck_etalage_gets_a_next_batch() {
		$this->test_a_batch_that_stopped_reporting_in_is_shown_as_stuck();

		$this->assertTrue( BoekDB_Import::ensure_next_batch() );
	}

	/**
	 * A busy batch schedules the next one itself when it is done.
	 */
	public function test_nothing_is_scheduled_while_a_batch_is_busy() {
		$this->etalage_is( 1 );
		update_option(
			BoekDB_Import::LOCK_OPTION_PREFIX . $this->etalage_id,
			array(
				'token' => 'working',
				'time'  => time(),
			),
			false
		);

		$this->assertFalse( BoekDB_Import::ensure_next_batch() );
		$this->assertFalse( wp_next_scheduled( BoekDB_Import::IMPORT_HOOK ) );
	}

	/**
	 * Nor when a batch is already on its way.
	 */
	public function test_nothing_is_scheduled_twice() {
		wp_schedule_single_event( time() + 30, BoekDB_Import::IMPORT_HOOK );

		$this->assertFalse( BoekDB_Import::ensure_next_batch() );
	}

	/**
	 * A stopped import stays stopped until someone starts it.
	 */
	public function test_nothing_is_scheduled_for_a_stopped_import() {
		update_option( BoekDB_Import::STOPPED_OPTION, 1, false );

		$this->assertFalse( BoekDB_Import::ensure_next_batch() );
	}

	/**
	 * The banner names the etalage being worked on and how far it is.
	 */
	public function test_the_banner_says_what_is_being_worked_on() {
		$texts = null;
		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( &$texts ) {
				if ( 'Boek 2' === $data['post_title'] ) {
					$texts = wp_list_pluck( BoekDB_Admin_Settings::status_notices( BoekDB_Import::status() ), 'text' );
				}

				return $data;
			}
		);

		BoekDB_Import::import();

		$this->assertStringStartsWith( 'Bezig met Test Etalage: boek 3 van 25 in de batch vanaf boek 1.', $texts[0] );
	}

	/**
	 * And says how many wait, and when the next batch comes.
	 */
	public function test_the_banner_says_what_waits() {
		wp_schedule_single_event( time(), BoekDB_Import::IMPORT_HOOK );

		$texts = wp_list_pluck( BoekDB_Admin_Settings::status_notices( BoekDB_Import::status() ), 'text' );

		$this->assertSame( array( '1 etalage wacht. Volgende batch: binnen een minuut.' ), $texts );
	}

	/**
	 * And warns when it had to schedule that batch itself.
	 */
	public function test_the_banner_warns_when_nothing_was_scheduled() {
		$notices = BoekDB_Admin_Settings::status_notices( BoekDB_Import::status(), true );

		$this->assertSame( 'warning', $notices[0]['type'] );
		$this->assertSame( '1 etalage wacht, maar er stond geen batch ingepland. Die is nu ingepland.', $notices[0]['text'] );
	}

	/**
	 * After Stop every etalage is idle, but the import does not run again until someone
	 * presses Draai import. The banner says so instead of promising the next round.
	 */
	public function test_the_banner_says_the_import_is_stopped() {
		$this->etalage_is( 0 );
		update_option( BoekDB_Import::STOPPED_OPTION, 1, false );

		$notices = BoekDB_Admin_Settings::status_notices( BoekDB_Import::status() );

		$this->assertSame( 'warning', $notices[0]['type'] );
		$this->assertSame( 'De import is gestopt en draait niet tot iemand op Draai import drukt.', $notices[0]['text'] );
	}

	/**
	 * An etalage stopped halfway is not done, and says where it stopped.
	 */
	public function test_an_etalage_stopped_halfway_says_where() {
		$this->etalage_is( 0, 50 );
		update_option( BoekDB_Import::STOPPED_OPTION, 1, false );

		$status = $this->etalage_status();

		$this->assertSame( 'stopped', $status['state'] );
		$this->assertSame( 'Gestopt', BoekDB_Admin_Settings::state_text( $status ) );
		$this->assertSame( 'gestopt bij boek 51', BoekDB_Admin_Settings::progress_text( $status ) );
	}
}
