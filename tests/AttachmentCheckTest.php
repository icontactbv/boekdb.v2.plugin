<?php
/**
 * Covers telling a usable stored file from a broken one.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Attachment check tests.
 *
 * A file judged broken is downloaded again, and the old attachment replaced. One that is
 * judged broken every time is downloaded on every delivery of its book.
 */
class AttachmentCheckTest extends WP_UnitTestCase {

	/**
	 * The file written for the test.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Removes the file.
	 */
	public function tear_down() {
		if ( $this->file && file_exists( $this->file ) ) {
			wp_delete_file( $this->file );
		}

		parent::tear_down();
	}

	/**
	 * Whether the plugin judges a pdf attachment with this content broken.
	 *
	 * @param string $content  The content of the file.
	 *
	 * @return bool
	 */
	private function pdf_is_broken( $content ) {
		$upload     = wp_upload_dir();
		$this->file = trailingslashit( $upload['path'] ) . 'fragment-' . wp_generate_password( 8, false ) . '.pdf';
		file_put_contents( $this->file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$this->file,
			0,
			array( 'post_mime_type' => 'application/pdf' )
		);
		update_attached_file( $attachment_id, $this->file );

		$check = new ReflectionMethod( BoekDB_Import::class, 'attachment_is_broken' );
		if ( PHP_VERSION_ID < 80100 ) {
			$check->setAccessible( true );
		}

		return $check->invoke( null, $attachment_id );
	}

	/**
	 * A pdf with something before its header is still a pdf readers open.
	 */
	public function test_a_pdf_with_bytes_before_its_header_is_usable() {
		$this->assertFalse( $this->pdf_is_broken( "\xEF\xBB\xBF\n%PDF-1.4\n%rest of the file" ) );
	}

	/**
	 * A plain pdf is usable.
	 */
	public function test_a_plain_pdf_is_usable() {
		$this->assertFalse( $this->pdf_is_broken( "%PDF-1.4\n%rest of the file" ) );
	}

	/**
	 * An error page stored as a pdf is broken.
	 */
	public function test_an_error_page_stored_as_a_pdf_is_broken() {
		$this->assertTrue( $this->pdf_is_broken( '<html><body>503 Service Unavailable</body></html>' ) );
	}
}
