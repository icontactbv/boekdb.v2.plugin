<?php
/**
 * Covers the data the plugin hands to templates, and the permalink of a book.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use WP_UnitTestCase;

/**
 * Template data tests.
 *
 * Templates call these on every page that shows a book. Whatever a book holds, they must
 * not end that page.
 */
class BoekDataTest extends WP_UnitTestCase {

	/**
	 * The book.
	 *
	 * @var int
	 */
	private $boek_id;

	/**
	 * Creates a book.
	 */
	public function set_up() {
		parent::set_up();

		$this->boek_id = self::factory()->post->create(
			array(
				'post_type'   => 'boekdb_boek',
				'post_name'   => 'het-boek',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Review quotes that are not a list give no quotes, not an error.
	 */
	public function test_review_quotes_that_are_not_a_list_give_no_quotes() {
		update_post_meta( $this->boek_id, 'boekdb_recensiequotes', 'abc' );

		$data = boekdb_boek_data( $this->boek_id );

		$this->assertSame( array(), maybe_unserialize( $data['recensiequotes'] ) );
	}

	/**
	 * Only the quotes the site shows are handed on.
	 */
	public function test_only_the_quotes_that_are_shown_are_handed_on() {
		update_post_meta(
			$this->boek_id,
			'boekdb_recensiequotes',
			array(
				'a' => array(
					'tekst' => 'Getoond',
					'tonen' => true,
				),
				'b' => array(
					'tekst' => 'Verborgen',
					'tonen' => false,
				),
			)
		);

		$data = boekdb_boek_data( $this->boek_id );

		$this->assertSame( array( array( 'tekst' => 'Getoond' ) ), maybe_unserialize( $data['recensiequotes'] ) );
	}

	/**
	 * The authors of a book come with their own data.
	 */
	public function test_the_authors_of_a_book_come_with_their_data() {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'boekdb_auteur_tax',
				'name'     => 'Een Auteur',
			)
		);
		update_term_meta( $term->term_id, 'voornaam', 'Een' );
		wp_set_object_terms( $this->boek_id, array( $term->term_id ), 'boekdb_auteur_tax' );

		$data = boekdb_betrokkenen_data( $this->boek_id );

		$this->assertSame( 'auteur', $data['auteurs'][0]['rol'] );
		$this->assertSame( 'Een', $data['auteurs'][0]['voornaam'] );
	}

	/**
	 * An alternate url stored before saving checked it, pointing off the site, is not used
	 * as the permalink.
	 */
	public function test_an_alternate_url_off_the_site_is_not_the_permalink() {
		update_post_meta( $this->boek_id, 'selected_alternate_url', 'https://example.com/elders/' );
		delete_transient( 'boekdb_permalink_' . $this->boek_id );

		$this->assertStringStartsWith( home_url( '/' ), get_permalink( $this->boek_id ) );
	}

	/**
	 * One on the site is.
	 */
	public function test_an_alternate_url_on_the_site_is_the_permalink() {
		update_post_meta( $this->boek_id, 'selected_alternate_url', home_url( '/boek/ls/het-boek/' ) );
		delete_transient( 'boekdb_permalink_' . $this->boek_id );

		$this->assertSame( home_url( '/boek/ls/het-boek/' ), get_permalink( $this->boek_id ) );
	}
}
