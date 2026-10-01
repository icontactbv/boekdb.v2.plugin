<?php
/**
 * Covers what the daily cleanup is allowed to remove.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Cleanup;
use WP_UnitTestCase;

/**
 * Cleanup tests.
 *
 * The cleanup removes books that no longer belong to an etalage. A book being imported
 * does not belong to one yet either: the link is written after its files are downloaded,
 * which with a fragment of tens of megabytes takes a while.
 */
class CleanupTest extends WP_UnitTestCase {

	/**
	 * Creates a book post with no etalage link.
	 *
	 * @param string $ago  How long ago it was created, as a strtotime offset.
	 *
	 * @return int
	 */
	private function unlinked_book( $ago = 'now' ) {
		return self::factory()->post->create(
			array(
				'post_type'     => 'boekdb_boek',
				'post_title'    => 'Boek zonder etalage',
				'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( $ago ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $ago ) ),
			)
		);
	}

	/**
	 * A book that is still being imported has no etalage link yet. Deleting it loses the
	 * work, and the import then records an isbn pointing at a post that is gone.
	 */
	public function test_a_book_that_was_just_created_is_left_alone() {
		$post_id = $this->unlinked_book();

		BoekDB_Cleanup::cleanup();

		$this->assertNotNull( get_post( $post_id ), 'A book created moments ago was deleted.' );
	}

	/**
	 * A book that left its etalage still has to go, or every book ever imported stays on
	 * the site forever.
	 */
	public function test_a_book_that_has_been_unlinked_for_a_while_is_removed() {
		$post_id = $this->unlinked_book( '-1 day' );

		BoekDB_Cleanup::cleanup();

		$this->assertNull( get_post( $post_id ), 'An old unlinked book should have been removed.' );
	}

	/**
	 * Creates an etalage in the given state.
	 *
	 * @param int $running  0 for idle, 1 for a batch being processed.
	 *
	 * @return void
	 */
	private function etalage( $running ) {
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
				'running'     => $running,
			)
		);
	}

	/**
	 * An import that was interrupted picks its books back up: they were created earlier,
	 * but they are being worked on now and still have no etalage link while their files
	 * are downloading.
	 */
	public function test_an_older_book_that_was_just_touched_is_left_alone() {
		$post_id = $this->unlinked_book( '-1 day' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Boek dat nu wordt bijgewerkt',
			)
		);

		BoekDB_Cleanup::cleanup();

		$this->assertNotNull( get_post( $post_id ), 'A book that is being written to was deleted.' );
	}

	/**
	 * And while a batch is running, the cleanup keeps its hands off entirely: an author
	 * gets its books only after its photo has been downloaded.
	 */
	public function test_nothing_is_removed_while_an_import_is_running() {
		$this->etalage( 1 );
		$post_id = $this->unlinked_book( '-1 day' );
		$term    = self::factory()->term->create( array( 'taxonomy' => 'boekdb_auteur_tax' ) );

		BoekDB_Cleanup::cleanup();

		$this->assertNotNull( get_post( $post_id ), 'A book was deleted while an import was running.' );
		$this->assertInstanceOf( 'WP_Term', get_term( $term, 'boekdb_auteur_tax' ), 'An author was deleted while an import was running.' );
	}

	/**
	 * Creates an attachment that belongs to a book and is used as its cover.
	 *
	 * @param int $boek_post_id  The book the file hangs on.
	 *
	 * @return int
	 */
	private function cover_for( $boek_post_id ) {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'cover.png',
				'post_parent'    => $boek_post_id,
				'post_mime_type' => 'image/png',
				'post_title'     => 'Cover',
			)
		);

		update_post_meta( $boek_post_id, '_thumbnail_id', $attachment_id );

		return $attachment_id;
	}

	/**
	 * Two editions of a title share one cover: the same url, so the same file. Removing one
	 * of them must not take the cover of the other with it.
	 */
	public function test_a_cover_another_book_uses_survives() {
		$kept    = $this->unlinked_book( '-1 day' );
		$removed = $this->unlinked_book( '-1 day' );

		$cover = $this->cover_for( $removed );
		update_post_meta( $kept, '_thumbnail_id', $cover );

		BoekDB_Cleanup::delete_posts( array( $removed ) );

		$this->assertNotNull( get_post( $cover ), 'The cover is still in use by another book.' );
	}

	/**
	 * A cover nobody else uses goes with the book, or every file ever downloaded stays on
	 * the disk forever.
	 */
	public function test_a_cover_nobody_else_uses_is_removed() {
		$removed = $this->unlinked_book( '-1 day' );
		$cover   = $this->cover_for( $removed );

		BoekDB_Cleanup::delete_posts( array( $removed ) );

		$this->assertNull( get_post( $cover ), 'An unused cover should have been removed.' );
	}

	/**
	 * Links a book to an etalage under an isbn.
	 *
	 * @param int    $etalage_id  The etalage.
	 * @param string $isbn        The isbn.
	 *
	 * @return int The book.
	 */
	private function book_in_etalage( $etalage_id, $isbn ) {
		global $wpdb;

		$post_id = $this->unlinked_book( '-1 day' );

		$wpdb->replace(
			$wpdb->prefix . 'boekdb_etalage_boeken',
			array(
				'etalage_id' => $etalage_id,
				'boek_id'    => $post_id,
			)
		);
		$wpdb->replace(
			$wpdb->prefix . 'boekdb_isbns',
			array(
				'isbn'    => $isbn,
				'boek_id' => $post_id,
			)
		);

		return $post_id;
	}

	/**
	 * An etalage can end up with nothing in it, by its filters or at BoekDB. Its books have
	 * to go then too, instead of staying on the site forever.
	 */
	public function test_an_etalage_without_any_isbns_loses_its_books() {
		global $wpdb;
		$this->etalage( 0 );
		$etalage_id = (int) $wpdb->insert_id;

		$post_id = $this->book_in_etalage( $etalage_id, '9789000000041' );

		BoekDB_Cleanup::trash_removed( $etalage_id, array() );

		$this->assertNull( get_post( $post_id ), 'A book that is no longer in the etalage should go.' );
	}

	/**
	 * And a book that is still in the list stays.
	 */
	public function test_a_book_that_is_still_in_the_list_stays() {
		global $wpdb;
		$this->etalage( 0 );
		$etalage_id = (int) $wpdb->insert_id;

		$kept    = $this->book_in_etalage( $etalage_id, '9789000000042' );
		$removed = $this->book_in_etalage( $etalage_id, '9789000000043' );

		BoekDB_Cleanup::trash_removed( $etalage_id, array( '9789000000042' ) );

		$this->assertNotNull( get_post( $kept ), 'A book that is still in the etalage stays.' );
		$this->assertNull( get_post( $removed ), 'A book that left the etalage goes.' );
	}

	/**
	 * Subjects, authors and series that no book uses any more are removed. As soon as one
	 * book carried any term at all, the query that looks for unused ones found nothing, so
	 * nothing was ever cleaned up.
	 */
	public function test_a_term_no_book_uses_is_removed() {
		$used   = self::factory()->term->create( array( 'taxonomy' => 'boekdb_nur_tax' ) );
		$unused = self::factory()->term->create( array( 'taxonomy' => 'boekdb_nur_tax' ) );

		global $wpdb;
		$this->etalage( 0 );
		$etalage_id = (int) $wpdb->insert_id;

		$boek = $this->book_in_etalage( $etalage_id, '9789000000061' );
		wp_set_object_terms( $boek, array( $used ), 'boekdb_nur_tax' );

		BoekDB_Cleanup::cleanup();

		$this->assertInstanceOf( 'WP_Term', get_term( $used, 'boekdb_nur_tax' ), 'A subject a book carries stays.' );
		$this->assertNull( get_term( $unused, 'boekdb_nur_tax' ), 'A subject no book carries should be removed.' );
	}

	/**
	 * An author photo is one file, shared by everyone it portrays: the author of one book
	 * and the narrator of its audio edition. Removing an author nobody uses must not take
	 * the photo of someone who is still there.
	 */
	public function test_an_author_photo_another_term_uses_survives() {
		global $wpdb;
		$this->etalage( 0 );
		$etalage_id = (int) $wpdb->insert_id;

		$boek = $this->book_in_etalage( $etalage_id, '9789000000071' );

		$photo = self::factory()->attachment->create_object(
			array(
				'file'           => 'auteur.png',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Auteursfoto',
			)
		);

		$kept    = self::factory()->term->create( array( 'taxonomy' => 'boekdb_spreker_tax' ) );
		$removed = self::factory()->term->create( array( 'taxonomy' => 'boekdb_auteur_tax' ) );

		wp_set_object_terms( $boek, array( $kept ), 'boekdb_spreker_tax' );
		update_term_meta( $kept, 'auteursfoto_id', $photo );
		update_term_meta( $removed, 'auteursfoto_id', $photo );

		BoekDB_Cleanup::cleanup();

		$this->assertNull( get_term( $removed, 'boekdb_auteur_tax' ), 'The author nobody uses should go.' );
		$this->assertNotNull( get_post( $photo ), 'The photo is still in use by the narrator.' );
	}

	/**
	 * Stop clears the running state at once, while the batch that is busy only notices
	 * between books: its current download runs on. Until it lets go of its lock, the
	 * cleanup has to stay away.
	 */
	public function test_nothing_is_removed_while_a_batch_still_holds_its_lock() {
		global $wpdb;
		$this->etalage( 0 );
		$etalage_id = (int) $wpdb->insert_id;

		update_option(
			'boekdb_import_lock_' . $etalage_id,
			array(
				'token' => 'the-batch-that-was-stopped',
				'time'  => time(),
			),
			false
		);

		$post_id = $this->unlinked_book( '-1 day' );
		$term    = self::factory()->term->create( array( 'taxonomy' => 'boekdb_auteur_tax' ) );

		BoekDB_Cleanup::cleanup();

		$this->assertNotNull( get_post( $post_id ), 'A book was deleted while a batch was still running.' );
		$this->assertInstanceOf( 'WP_Term', get_term( $term, 'boekdb_auteur_tax' ), 'An author was deleted while a batch was still running.' );
	}

	/**
	 * A file kept because another book uses it has to end up hanging on that book, or it
	 * stays behind when that one goes as well.
	 */
	public function test_a_kept_cover_goes_with_the_last_book_that_uses_it() {
		$kept    = $this->unlinked_book( '-1 day' );
		$removed = $this->unlinked_book( '-1 day' );

		$cover = $this->cover_for( $removed );
		update_post_meta( $kept, '_thumbnail_id', $cover );

		BoekDB_Cleanup::delete_posts( array( $removed ) );
		$this->assertNotNull( get_post( $cover ), 'The cover is still in use.' );

		BoekDB_Cleanup::delete_posts( array( $kept ) );

		$this->assertNull( get_post( $cover ), 'With the last book using it gone, the cover goes too.' );
	}
}
