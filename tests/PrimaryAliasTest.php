<?php
/**
 * Covers the old name for the primary edition, which sites may still be reading.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use WP_Query;
use WP_UnitTestCase;

/**
 * Alias tests.
 *
 * The plugin writes one name now, but a theme out there reads the other. It keeps working.
 */
class PrimaryAliasTest extends WP_UnitTestCase {

	/**
	 * Creates a book that is the primary edition of its title.
	 *
	 * @param int $primary  1 for the primary edition, 0 for one of the others.
	 *
	 * @return int
	 */
	private function book( $primary = 1 ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'boekdb_boek',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, 'boekdb_primair', $primary );

		return $post_id;
	}

	/**
	 * A theme asking for the old name gets the value stored under the new one.
	 */
	public function test_reading_the_old_name_gives_the_stored_value() {
		$post_id = $this->book();

		$this->assertSame( '1', get_post_meta( $post_id, 'boekdb_primary', true ) );
	}

	/**
	 * Including when it asks for every value rather than one.
	 */
	public function test_reading_the_old_name_without_single_gives_the_stored_values() {
		$post_id = $this->book( 0 );

		$this->assertSame( array( '0' ), get_post_meta( $post_id, 'boekdb_primary' ) );
	}

	/**
	 * An archive that selects on the old name keeps finding its books.
	 */
	public function test_a_query_on_the_old_name_finds_the_primary_editions() {
		$primary = $this->book( 1 );
		$this->book( 0 );

		$query = new WP_Query(
			array(
				'post_type'  => 'boekdb_boek',
				'meta_key'   => 'boekdb_primary', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $primary ), $query->posts );
	}

	/**
	 * And so does one that spells the condition out.
	 */
	public function test_a_meta_query_on_the_old_name_finds_the_primary_editions() {
		$primary = $this->book( 1 );
		$this->book( 0 );

		$query = new WP_Query(
			array(
				'post_type'  => 'boekdb_boek',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'boekdb_primary',
						'value' => 1,
					),
				),
				'fields'     => 'ids',
			)
		);

		$this->assertSame( array( $primary ), $query->posts );
	}
}
