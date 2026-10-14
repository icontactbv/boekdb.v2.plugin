<?php
/**
 * Covers saving and showing the meta boxes of a book.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Admin_Meta_Boxes;
use WP_UnitTestCase;

/**
 * Meta box tests.
 *
 * WordPress fires save_post for every post saved anywhere. What the meta boxes post may only
 * be written for a book, from its edit screen, by someone who may edit it.
 */
class MetaBoxSaveTest extends WP_UnitTestCase {

	/**
	 * The book being edited.
	 *
	 * @var int
	 */
	private $boek_id;

	/**
	 * Creates a book with an annotatie from BoekDB, and an administrator to edit it.
	 */
	public function set_up() {
		parent::set_up();

		$this->boek_id = self::factory()->post->create(
			array(
				'post_type'  => 'boekdb_boek',
				'post_name'  => 'het-boek',
				'post_title' => 'Het boek',
			)
		);
		update_post_meta( $this->boek_id, 'boekdb_annotatie', 'Uitgeverij \'t Hoofd' );
		update_post_meta( $this->boek_id, 'boekdb_annotatie_org', 'Uitgeverij \'t Hoofd' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leaves no request state behind.
	 */
	public function tear_down() {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Posts the meta boxes the way the edit screen does: slashed, with their nonces.
	 *
	 * @param array $fields  The fields of the form.
	 *
	 * @return void
	 */
	private function post_form( $fields ) {
		$_POST = wp_slash(
			array_merge(
				array(
					BoekDB_Admin_Meta_Boxes::FIELDS_NONCE => wp_create_nonce( BoekDB_Admin_Meta_Boxes::FIELDS_NONCE ),
					BoekDB_Admin_Meta_Boxes::URL_NONCE    => wp_create_nonce( BoekDB_Admin_Meta_Boxes::URL_NONCE ),
				),
				$fields
			)
		);
	}

	/**
	 * Saves the alternate url the way save_post does.
	 *
	 * @param int $post_id  The post being saved.
	 *
	 * @return void
	 */
	private function save_url( $post_id ) {
		( new BoekDB_Admin_Meta_Boxes() )->save_etalage_url( $post_id );
	}

	/**
	 * The url offered by the meta box is saved.
	 */
	public function test_an_offered_url_is_saved() {
		$url = home_url( '/boek/ls/het-boek/' );
		$this->post_form( array( 'selected_alternate_url' => $url ) );

		$this->save_url( $this->boek_id );

		$this->assertSame( $url, get_post_meta( $this->boek_id, 'selected_alternate_url', true ) );
	}

	/**
	 * The url becomes the permalink of the book, so one off the site is refused.
	 */
	public function test_a_url_off_the_site_is_refused() {
		$this->post_form( array( 'selected_alternate_url' => 'https://example.com/elders/' ) );

		$this->save_url( $this->boek_id );

		$this->assertSame( '', get_post_meta( $this->boek_id, 'selected_alternate_url', true ) );
	}

	/**
	 * A form posted from elsewhere carries no valid nonce.
	 */
	public function test_nothing_is_saved_without_the_nonce() {
		$this->post_form(
			array(
				BoekDB_Admin_Meta_Boxes::FIELDS_NONCE => 'forged',
				BoekDB_Admin_Meta_Boxes::URL_NONCE    => 'forged',
				'selected_alternate_url'              => home_url( '/boek/ls/het-boek/' ),
				'boekdb_annotatie'                    => 'Overschreven',
			)
		);

		$this->save_url( $this->boek_id );
		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( '', get_post_meta( $this->boek_id, 'selected_alternate_url', true ) );
		$this->assertSame( 'Uitgeverij \'t Hoofd', get_post_meta( $this->boek_id, 'boekdb_annotatie', true ) );
	}

	/**
	 * Nor by someone who may not edit the book.
	 */
	public function test_nothing_is_saved_by_someone_who_may_not_edit_the_book() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->post_form(
			array(
				'selected_alternate_url' => home_url( '/boek/ls/het-boek/' ),
				'boekdb_annotatie'       => 'Overschreven',
			)
		);

		$this->save_url( $this->boek_id );
		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( '', get_post_meta( $this->boek_id, 'selected_alternate_url', true ) );
		$this->assertSame( 'Uitgeverij \'t Hoofd', get_post_meta( $this->boek_id, 'boekdb_annotatie', true ) );
	}

	/**
	 * Nor for a post that is not a book.
	 */
	public function test_nothing_is_saved_for_another_post_type() {
		$post_id = self::factory()->post->create();
		$this->post_form( array( 'selected_alternate_url' => home_url( '/boek/ls/het-boek/' ) ) );

		$this->save_url( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, 'selected_alternate_url', true ) );
	}

	/**
	 * Saving the book without touching its annotatie leaves it as BoekDB's. The form comes
	 * slashed, so a quote in it used to count as a change and freeze the annotatie.
	 */
	public function test_an_untouched_annotatie_with_a_quote_stays_the_one_from_boekdb() {
		$this->post_form( array( 'boekdb_annotatie' => 'Uitgeverij \'t Hoofd' ) );

		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( '', get_post_meta( $this->boek_id, 'boekdb_annotatie_overwritten', true ) );
	}

	/**
	 * A changed annotatie is stored as typed, backslashes and all.
	 */
	public function test_a_changed_annotatie_is_stored_as_typed() {
		$this->post_form( array( 'boekdb_annotatie' => 'Pad C:\\boeken, \'t boek' ) );

		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( 'Pad C:\\boeken, \'t boek', get_post_meta( $this->boek_id, 'boekdb_annotatie', true ) );
		$this->assertSame( '1', get_post_meta( $this->boek_id, 'boekdb_annotatie_overwritten', true ) );
	}

	/**
	 * Someone who may not post unfiltered html gets what is allowed in a post.
	 */
	public function test_script_is_stripped_for_someone_without_unfiltered_html() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post(
			array(
				'ID'          => $this->boek_id,
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );
		$this->post_form( array( 'boekdb_annotatie' => '<p>Tekst</p><script>alert(1)</script>' ) );

		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( '<p>Tekst</p>alert(1)', get_post_meta( $this->boek_id, 'boekdb_annotatie', true ) );
	}

	/**
	 * Without unfiltered html the posted text goes through kses. What BoekDB stored goes
	 * through it too before the two are compared, so an untouched annotatie stays BoekDB's.
	 */
	public function test_an_untouched_annotatie_stays_the_one_from_boekdb_without_unfiltered_html() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post(
			array(
				'ID'          => $this->boek_id,
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );
		update_post_meta( $this->boek_id, 'boekdb_annotatie', 'Regel een<br>regel twee & meer' );
		$this->post_form( array( 'boekdb_annotatie' => 'Regel een<br>regel twee & meer' ) );

		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( '', get_post_meta( $this->boek_id, 'boekdb_annotatie_overwritten', true ) );
	}

	/**
	 * A textarea sends its lines back ending in \r\n. An untouched annotatie of several lines
	 * is not a changed one.
	 */
	public function test_an_untouched_annotatie_of_several_lines_stays_the_one_from_boekdb() {
		update_post_meta( $this->boek_id, 'boekdb_annotatie', "Regel een\nRegel twee" );
		$this->post_form( array( 'boekdb_annotatie' => "Regel een\r\nRegel twee" ) );

		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( '', get_post_meta( $this->boek_id, 'boekdb_annotatie_overwritten', true ) );
	}

	/**
	 * A field posted as a list is not a text, and is left alone.
	 */
	public function test_an_annotatie_posted_as_a_list_is_ignored() {
		$this->post_form( array( 'boekdb_annotatie' => array( 'x' ) ) );

		BoekDB_Admin_Meta_Boxes::save_meta_boxes( $this->boek_id );

		$this->assertSame( 'Uitgeverij \'t Hoofd', get_post_meta( $this->boek_id, 'boekdb_annotatie', true ) );
	}

	/**
	 * Whatever the annotatie holds, it stays inside the textarea on the edit screen.
	 */
	public function test_the_annotatie_cannot_break_out_of_its_textarea() {
		update_post_meta( $this->boek_id, 'boekdb_annotatie', '</textarea><script>alert(1)</script>' );

		ob_start();
		BoekDB_Admin_Meta_Boxes::meta_boek_fields_html( get_post( $this->boek_id ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;script&gt;', $html );
	}
}
