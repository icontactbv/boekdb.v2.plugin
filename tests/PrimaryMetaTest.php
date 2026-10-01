<?php
/**
 * Covers the meta key that says which edition of a title is the primary one.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Install;
use WP_UnitTestCase;

/**
 * Primary meta tests.
 *
 * Books without an nstc were marked under an English key, every reader uses the Dutch one.
 * Those books fall out of every overview that filters on it, and have done since the key
 * was introduced.
 */
class PrimaryMetaTest extends WP_UnitTestCase {

	/**
	 * Counts the rows stored under the old name for a book. Reading it through the meta
	 * functions would be answered by the alias that keeps old themes working.
	 *
	 * @param int $post_id  The book.
	 *
	 * @return int
	 */
	private function stored_under_the_old_name( $post_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'boekdb_primary'", $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Creates a book.
	 *
	 * @return int
	 */
	private function book() {
		return self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
	}

	/**
	 * A book carrying only the old key ends up carrying the new one.
	 */
	public function test_the_old_key_is_carried_over() {
		$post_id = $this->book();
		update_post_meta( $post_id, 'boekdb_primary', 1 );

		BoekDB_Install::migrate_primary_meta();

		$this->assertSame( '1', get_post_meta( $post_id, 'boekdb_primair', true ), 'The book should be marked as primary.' );
		$this->assertSame( 0, $this->stored_under_the_old_name( $post_id ), 'The old key should be gone.' );
	}

	/**
	 * Where both exist the one the site reads wins: it was written by a later import and
	 * says which edition is actually the primary one.
	 */
	public function test_an_existing_value_is_left_alone() {
		$post_id = $this->book();
		update_post_meta( $post_id, 'boekdb_primair', 0 );
		update_post_meta( $post_id, 'boekdb_primary', 1 );

		BoekDB_Install::migrate_primary_meta();

		$this->assertSame( '0', get_post_meta( $post_id, 'boekdb_primair', true ), 'The value the site reads stays.' );
		$this->assertSame( 0, $this->stored_under_the_old_name( $post_id ), 'The old key should be gone.' );
	}

	/**
	 * Running it twice changes nothing more.
	 */
	public function test_running_it_again_changes_nothing() {
		$post_id = $this->book();
		update_post_meta( $post_id, 'boekdb_primary', 1 );

		BoekDB_Install::migrate_primary_meta();
		BoekDB_Install::migrate_primary_meta();

		$this->assertSame( array( '1' ), get_post_meta( $post_id, 'boekdb_primair' ), 'There should be exactly one value.' );
	}

	/**
	 * Whatever already read the book before the migration must not keep seeing the old
	 * answer afterwards.
	 */
	public function test_the_new_value_is_readable_straight_after_the_migration() {
		$post_id = $this->book();
		update_post_meta( $post_id, 'boekdb_primary', 1 );

		// Something on the page asked for it first, so the empty answer is cached.
		$this->assertSame( '', get_post_meta( $post_id, 'boekdb_primair', true ) );

		BoekDB_Install::migrate_primary_meta();

		$this->assertSame( '1', get_post_meta( $post_id, 'boekdb_primair', true ), 'The migrated value should be readable at once.' );
	}

	/**
	 * The migration has to run on the oldest WordPress the plugin says it supports, which
	 * does not have every cache function the newest one has.
	 */
	public function test_the_migration_uses_nothing_newer_than_the_supported_wordpress() {
		$source = file_get_contents( BOEKDB_ABSPATH . 'includes/class-boekdb-install.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringNotContainsString(
			'wp_cache_flush_group(',
			$source,
			'wp_cache_flush_group() exists from WordPress 6.1, the plugin supports 5.5.'
		);
	}
}
