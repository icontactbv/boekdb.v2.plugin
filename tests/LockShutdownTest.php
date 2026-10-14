<?php
/**
 * Covers what the lock tells the shutdown handler about the book in hand.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Lock and shutdown tests.
 */
class LockShutdownTest extends WP_UnitTestCase {

	/**
	 * The etalage, as the import reads it.
	 *
	 * @var object
	 */
	private $etalage;

	/**
	 * Creates an etalage and claims a batch of 25 on it.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'name'    => 'Test Etalage',
				'api_key' => 'test-key',
				'running' => 2,
				'offset'  => 50,
			)
		);
		$this->etalage = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$this->call( 'claim_lock', array( $this->etalage, 25 ) );
	}

	/**
	 * Leaves no claim behind for the next test.
	 */
	public function tear_down() {
		foreach ( array( 'lock_token', 'lock_value', 'lock_etalage' ) as $name ) {
			$property = new ReflectionProperty( BoekDB_Import::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, null );
		}

		parent::tear_down();
	}

	/**
	 * Calls a private method of the import.
	 *
	 * @param string $method  The method.
	 * @param array  $args    Its arguments.
	 *
	 * @return mixed
	 */
	private function call( $method, array $args ) {
		$reflected = new ReflectionMethod( BoekDB_Import::class, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflected->setAccessible( true );
		}

		return $reflected->invokeArgs( null, $args );
	}

	/**
	 * A book without an isbn must not leave the isbn of the book before it in the lock, or
	 * that one is blamed when the process dies.
	 */
	public function test_a_book_without_an_isbn_clears_the_one_before() {
		$this->call( 'refresh_lock', array( $this->etalage, '9789000000201' ) );
		$this->call( 'refresh_lock', array( $this->etalage, null ) );

		$lock = $this->call( 'read_lock', array( $this->etalage->id ) );

		$this->assertArrayNotHasKey( 'isbn', $lock );
	}

	/**
	 * Dying on such a book still moves the next batch past it, without blaming another.
	 */
	public function test_dying_on_a_book_without_an_isbn_skips_it_and_blames_nobody() {
		$this->call( 'refresh_lock', array( $this->etalage, '9789000000201' ) );
		$this->call( 'refresh_lock', array( $this->etalage, null ) );

		BoekDB_Import::release_lock_on_shutdown( $this->etalage );

		global $wpdb;
		$offset = (int) $wpdb->get_var( $wpdb->prepare( "SELECT `offset` FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $this->etalage->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 52, $offset, 'The next batch starts after the second book.' );
		$this->assertFalse( get_option( BoekDB_Import::FAILED_OPTION_PREFIX . $this->etalage->id ), 'The first book did nothing wrong.' );
	}
}
