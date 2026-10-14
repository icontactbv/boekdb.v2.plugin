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

	/**
	 * WordPress slashes the form data. A name with a quote in it is stored as typed.
	 */
	public function test_an_etalage_name_with_a_quote_is_stored_as_typed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'answer_as_boekdb' ), 9, 3 );
		$_POST = wp_slash(
			array(
				'save'            => '1',
				'etalage_api_key' => 'nieuwe-sleutel',
				'etalage_name'    => 'Uitgeverij \'t Hoofd',
				'etalage_prefix'  => '',
				'_wpnonce'        => wp_create_nonce( 'boekdb-settings' ),
			)
		);

		BoekDB_Admin_Settings::save();
		remove_filter( 'pre_http_request', array( $this, 'answer_as_boekdb' ), 9 );

		global $wpdb;
		$this->assertSame(
			'Uitgeverij \'t Hoofd',
			$wpdb->get_var( "SELECT name FROM {$wpdb->prefix}boekdb_etalages WHERE api_key = 'nieuwe-sleutel'" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Posts the form that adds an etalage, as an administrator.
	 *
	 * @param string $name     Name of the etalage.
	 * @param string $api_key  Its api key.
	 *
	 * @return void
	 */
	private function add_etalage( $name, $api_key ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = wp_slash(
			array(
				'save'            => '1',
				'etalage_api_key' => $api_key,
				'etalage_name'    => $name,
				'etalage_prefix'  => '',
				'_wpnonce'        => wp_create_nonce( 'boekdb-settings' ),
			)
		);

		BoekDB_Admin_Settings::save();
	}

	/**
	 * How many etalages have this api key.
	 *
	 * @param string $api_key  The api key.
	 *
	 * @return int
	 */
	private function etalages_with_key( $api_key ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}boekdb_etalages WHERE api_key = %s", $api_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * An etalage without a name is refused, and not stored under the error.
	 */
	public function test_an_etalage_without_a_name_is_not_stored() {
		add_filter( 'pre_http_request', array( $this, 'answer_as_boekdb' ), 9, 3 );

		$this->add_etalage( '', 'naamloze-sleutel' );

		remove_filter( 'pre_http_request', array( $this, 'answer_as_boekdb' ), 9 );
		$this->assertSame( 0, $this->etalages_with_key( 'naamloze-sleutel' ) );
	}

	/**
	 * Nor is one whose key BoekDB does not know.
	 */
	public function test_an_etalage_with_an_unknown_key_is_not_stored() {
		add_filter( 'pre_http_request', array( $this, 'refuse_the_key' ), 9, 3 );

		$this->add_etalage( 'Onbekend', 'onbekende-sleutel' );

		remove_filter( 'pre_http_request', array( $this, 'refuse_the_key' ), 9 );
		$this->assertSame( 0, $this->etalages_with_key( 'onbekende-sleutel' ) );
	}

	/**
	 * With BoekDB out of reach the key cannot be checked. The screen says so instead of
	 * ending in a fatal error, and stores nothing.
	 */
	public function test_an_etalage_is_not_stored_while_boekdb_is_out_of_reach() {
		// Outbound HTTP is blocked in the bootstrap, as if BoekDB were down.
		$this->add_etalage( 'Onbereikbaar', 'sleutel-zonder-verbinding' );

		$this->assertSame( 0, $this->etalages_with_key( 'sleutel-zonder-verbinding' ) );
	}

	/**
	 * Answers the connection test, and refuses the key as BoekDB does for one it does not know.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return array
	 */
	public function refuse_the_key( $preempt, $args, $url ) {
		$response = $this->answer_as_boekdb( $preempt, $args, $url );

		if ( false !== strpos( $url, 'validate' ) ) {
			$response['response'] = array(
				'code'    => 401,
				'message' => 'Unauthorized',
			);
		}

		return $response;
	}

	/**
	 * Answers the connection test and the key check as BoekDB does for a valid key.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return array
	 */
	public function answer_as_boekdb( $preempt, $args, $url ) {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'hello',
					'plugin_version' => '1.2.0',
				)
			),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
