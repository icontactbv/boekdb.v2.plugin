<?php
/**
 * Covers moving the series image to the key it is read from.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Install;
use WP_UnitTestCase;

/**
 * Series image migration tests.
 *
 * Versions 1.0.7 to 1.1.1 stored the image of a series under 'seriebeeld_id', while
 * boekdb_serie_data() and the cleanup read 'boekdb_seriebeeld_id', as 1.0.5 and 1.0.6
 * wrote it. Where a series has both, the bare key is the later write.
 */
class SerieImageMetaTest extends WP_UnitTestCase {

	/**
	 * A series image under the old key is moved to the one it is read from.
	 */
	public function test_an_image_under_the_old_key_is_moved() {
		$serie = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		add_term_meta( $serie, 'seriebeeld_id', '42' );

		BoekDB_Install::migrate_serie_image_meta();

		$this->assertSame( '42', get_term_meta( $serie, 'boekdb_seriebeeld_id', true ) );
		$this->assertSame( '', get_term_meta( $serie, 'seriebeeld_id', true ) );
		$this->assertSame( '42', boekdb_serie_data( $serie )['serie_beeld_id'] );
	}

	/**
	 * Where both keys are there, the old one was written last and wins.
	 */
	public function test_the_old_key_wins_where_both_are_there() {
		$serie = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		add_term_meta( $serie, 'boekdb_seriebeeld_id', '7' );
		add_term_meta( $serie, 'seriebeeld_id', '42' );

		BoekDB_Install::migrate_serie_image_meta();

		$this->assertSame( array( '42' ), get_term_meta( $serie, 'boekdb_seriebeeld_id', false ) );
	}

	/**
	 * A term of another taxonomy that happens to use the key is not the plugin's.
	 */
	public function test_terms_of_other_taxonomies_are_left_alone() {
		$tag = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		add_term_meta( $tag, 'seriebeeld_id', '42' );

		BoekDB_Install::migrate_serie_image_meta();

		$this->assertSame( '42', get_term_meta( $tag, 'seriebeeld_id', true ) );
	}
}
