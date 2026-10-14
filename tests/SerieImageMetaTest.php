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
		$image = (string) $this->image();
		add_term_meta( $serie, 'seriebeeld_id', $image );

		BoekDB_Install::migrate_serie_image_meta();

		$this->assertSame( $image, get_term_meta( $serie, 'boekdb_seriebeeld_id', true ) );
		$this->assertSame( '', get_term_meta( $serie, 'seriebeeld_id', true ) );
		$this->assertSame( $image, boekdb_serie_data( $serie )['serie_beeld_id'] );
	}

	/**
	 * An image attachment.
	 *
	 * @return int
	 */
	private function image() {
		return self::factory()->attachment->create_object(
			'serie.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
	}

	/**
	 * Where both keys are there, the old one was written last and wins.
	 */
	public function test_the_old_key_wins_where_both_are_there() {
		$serie = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		$older = (string) $this->image();
		$later = (string) $this->image();
		add_term_meta( $serie, 'boekdb_seriebeeld_id', $older );
		add_term_meta( $serie, 'seriebeeld_id', $later );

		BoekDB_Install::migrate_serie_image_meta();

		$this->assertSame( array( $later ), get_term_meta( $serie, 'boekdb_seriebeeld_id', false ) );
	}

	/**
	 * Unless the old key points at an image that is gone: the series keeps the one it has.
	 */
	public function test_an_old_key_pointing_at_nothing_does_not_replace_a_good_image() {
		$serie = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		$good  = (string) $this->image();
		add_term_meta( $serie, 'boekdb_seriebeeld_id', $good );
		add_term_meta( $serie, 'seriebeeld_id', '999999' );

		BoekDB_Install::migrate_serie_image_meta();

		$this->assertSame( $good, get_term_meta( $serie, 'boekdb_seriebeeld_id', true ) );
		$this->assertSame( '', get_term_meta( $serie, 'seriebeeld_id', true ), 'The old key is cleared all the same.' );
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
