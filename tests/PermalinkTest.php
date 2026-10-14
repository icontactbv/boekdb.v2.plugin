<?php
/**
 * Covers the cached permalink of a book.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Permalink tests.
 *
 * The url of a book is built from the prefix of the etalage it belongs to and kept for
 * half a day. Anything that changes which url a book should have therefore has to throw
 * that cached url away.
 */
class PermalinkTest extends WP_UnitTestCase {

	/**
	 * Creates an etalage.
	 *
	 * @param string $prefix  The url prefix of the etalage.
	 *
	 * @return int
	 */
	private function etalage( $prefix ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'name'        => 'Etalage ' . $prefix,
				'api_key'     => 'key-' . $prefix,
				'prefix'      => $prefix,
				'importing'   => 0,
				'offset'      => 0,
				'isbns'       => 0,
				'last_import' => null,
				'filter_hash' => null,
				'running'     => 0,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Calls one of the import's protected methods.
	 *
	 * @param string $method  Method name on BoekDB_Import.
	 * @param array  $args    Arguments to pass.
	 *
	 * @return void
	 */
	private function invoke_import( $method, array $args ) {
		$reflected = new ReflectionMethod( BoekDB_Import::class, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			// Pointless from 8.1 on, and deprecated in 8.5.
			$reflected->setAccessible( true );
		}
		$reflected->invokeArgs( null, $args );
	}

	/**
	 * A book that joins another etalage gets that etalage's prefix in its url. Keeping the
	 * cached one serves the old url for up to half a day.
	 */
	public function test_a_book_that_joins_an_etalage_loses_its_cached_permalink() {
		$first_etalage  = $this->etalage( 'eerste' );
		$second_etalage = $this->etalage( 'tweede' );

		$boek_post_id = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
		$this->invoke_import( 'link_product', array( $boek_post_id, '9789000000031', $first_etalage ) );

		set_transient( 'boekdb_permalink_' . $boek_post_id, 'https://example.org/boek/eerste/een-boek/', DAY_IN_SECONDS );

		$this->invoke_import( 'link_product', array( $boek_post_id, '9789000000031', $second_etalage ) );

		$this->assertFalse( get_transient( 'boekdb_permalink_' . $boek_post_id ), 'The cached url should be gone.' );
	}

	/**
	 * Importing the same book into the same etalage again changes no url, and should not
	 * throw the cache away for every book of every batch.
	 */
	public function test_a_book_that_was_already_in_the_etalage_keeps_its_cached_permalink() {
		$etalage      = $this->etalage( 'eerste' );
		$boek_post_id = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );

		$this->invoke_import( 'link_product', array( $boek_post_id, '9789000000032', $etalage ) );

		set_transient( 'boekdb_permalink_' . $boek_post_id, 'https://example.org/boek/eerste/een-boek/', DAY_IN_SECONDS );

		$this->invoke_import( 'link_product', array( $boek_post_id, '9789000000032', $etalage ) );

		$this->assertSame( 'https://example.org/boek/eerste/een-boek/', get_transient( 'boekdb_permalink_' . $boek_post_id ) );
	}

	/**
	 * Editions written in the same second have the same post_date. The old post query
	 * handed those back oldest id first, which made the lowest id the primary edition with
	 * the bare slug. The first import after an upgrade must not swap that around.
	 */
	public function test_editions_written_in_the_same_second_keep_their_primary() {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => 'boekdb_boek',
					'post_status' => 'publish',
					'post_date'   => '2026-10-01 10:00:00',
					'meta_input'  => array(
						'boekdb_nstc'              => 'nstc-1',
						'boekdb_status'            => '10',
						'boekdb_verschijningsvorm' => 'Paperback / softback',
					),
				)
			);
		}

		$this->invoke_import( 'check_primary_title', array( $ids[2], 'nstc-1', 'het-boek' ) );

		$this->assertSame( '1', get_post_meta( $ids[0], 'boekdb_primair', true ), 'The oldest id stays primary.' );
		$this->assertSame( '0', get_post_meta( $ids[2], 'boekdb_primair', true ) );
	}
}
