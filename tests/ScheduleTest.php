<?php
/**
 * Covers the hooks and schedules the plugin sets up when it loads.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Cleanup;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Schedule tests.
 *
 * A hook without a listener fails quietly: the admin reports that the job was started and
 * nothing happens, now or ever.
 */
class ScheduleTest extends WP_UnitTestCase {

	/**
	 * The import has to listen to its own hooks.
	 */
	public function test_the_import_hooks_have_a_listener() {
		$this->assertNotFalse( has_action( BoekDB_Import::START_IMPORT_HOOK ), 'Nothing would start an import.' );
		$this->assertNotFalse( has_action( BoekDB_Import::IMPORT_HOOK ), 'Nothing would run a batch.' );
	}

	/**
	 * And so does the cleanup, which removes books that left the etalage and the posts of
	 * etalages that were deleted.
	 */
	public function test_the_cleanup_hook_has_a_listener() {
		$this->assertNotFalse( has_action( BoekDB_Cleanup::CLEANUP_HOOK ), 'The Opruimen button fires this hook.' );
	}

	/**
	 * The cleanup also has to run on its own, daily.
	 */
	public function test_the_cleanup_is_scheduled() {
		$this->assertNotFalse( wp_next_scheduled( BoekDB_Cleanup::CLEANUP_HOOK ), 'Nothing would clean up by itself.' );
	}
}
