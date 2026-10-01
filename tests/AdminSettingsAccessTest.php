<?php
/**
 * Covers who is allowed to act on the settings screen.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Admin_Settings;
use WP_UnitTestCase;

/**
 * Access tests.
 *
 * The settings handler runs on wp_loaded, which fires on every request and well before
 * wp-admin asks anyone to log in. Whatever it does, it has to check first who is asking.
 */
class AdminSettingsAccessTest extends WP_UnitTestCase {

	/**
	 * The etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * Creates an etalage and loads the admin classes.
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

		include_once BOEKDB_ABSPATH . 'includes/admin/class-boekdb-admin-settings.php';
	}

	/**
	 * Leaves no request state behind.
	 */
	public function tear_down() {
		$_POST = array();
		$_GET  = array();

		parent::tear_down();
	}

	/**
	 * Whether the etalage is still there.
	 *
	 * @return bool
	 */
	private function etalage_exists() {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Deleting an etalage deletes the books that came with it. A visitor must not be able
	 * to do that by posting to the settings page.
	 */
	public function test_a_visitor_cannot_delete_an_etalage() {
		wp_set_current_user( 0 );
		$_POST['delete'] = (string) $this->etalage_id;

		BoekDB_Admin_Settings::save();

		$this->assertTrue( $this->etalage_exists(), 'A visitor deleted an etalage.' );
	}

	/**
	 * Neither may a logged-in user without rights to the plugin.
	 */
	public function test_a_subscriber_cannot_delete_an_etalage() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['delete']   = (string) $this->etalage_id;
		$_POST['_wpnonce'] = wp_create_nonce( 'boekdb-settings' );

		BoekDB_Admin_Settings::save();

		$this->assertTrue( $this->etalage_exists(), 'A subscriber deleted an etalage.' );
	}

	/**
	 * A request without the form's nonce is not a request from the form.
	 */
	public function test_an_administrator_needs_the_form_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['delete'] = (string) $this->etalage_id;

		BoekDB_Admin_Settings::save();

		$this->assertTrue( $this->etalage_exists(), 'A request without a nonce went through.' );
	}

	/**
	 * And the screen itself has to keep working.
	 */
	public function test_an_administrator_can_delete_an_etalage_from_the_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['delete']   = (string) $this->etalage_id;
		$_POST['_wpnonce'] = wp_create_nonce( 'boekdb-settings' );

		BoekDB_Admin_Settings::save();

		$this->assertFalse( $this->etalage_exists(), 'An administrator should be able to delete an etalage.' );
	}
}
