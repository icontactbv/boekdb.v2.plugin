<?php
/**
 * Covers how the import pulls files off the API: streamed to disk, never buffered.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use BoekDB_Import;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * File download tests.
 *
 * Sites run on a 128M memory limit while BoekDB serves PDF fragments of 50MB and up, so a
 * download that lands in memory is fatal. These tests pin the download to a streaming path.
 */
class FileDownloadTest extends WP_UnitTestCase {

	/**
	 * Size of the file served by the canned HTTP response. Large enough that buffering it
	 * pushes the peak well past anything the rest of the run allocates.
	 */
	const FIXTURE_BYTES = 67108864;

	/**
	 * URL the canned response answers with the large file.
	 */
	const FILE_URL = 'https://prd-boekdb.s3.eu-central-1.amazonaws.com/large-fragment';

	/**
	 * URL the canned response answers with a handful of bytes.
	 */
	const SMALL_FILE_URL = 'https://prd-boekdb.s3.eu-central-1.amazonaws.com/small-fragment';

	/**
	 * URL the canned response answers with a one pixel image.
	 */
	const IMAGE_FILE_URL = 'https://www.boekdbv2.nl/productfile/cover/xlarge';

	/**
	 * A second url answering with that same image, standing in for a file that was replaced
	 * at BoekDB and therefore carries a new url.
	 */
	const OTHER_IMAGE_FILE_URL = 'https://www.boekdbv2.nl/productfile/nieuwe-cover/xlarge';

	/**
	 * Path of the file served as the response body.
	 *
	 * @var string
	 */
	private $fixture;

	/**
	 * Path of the small file served as the response body.
	 *
	 * @var string
	 */
	private $small_fixture;

	/**
	 * Path of the image served as the response body.
	 *
	 * @var string
	 */
	private $image_fixture;

	/**
	 * The arguments of the last intercepted request.
	 *
	 * @var array
	 */
	private $request_args = array();

	/**
	 * Writes the fixture file and starts intercepting outbound HTTP requests.
	 */
	public function set_up() {
		parent::set_up();

		$this->fixture = wp_tempnam( 'boekdb-large-fragment' );
		$handle        = fopen( $this->fixture, 'wb' );
		fwrite( $handle, '%PDF-1.4' );
		$chunk = str_repeat( 'x', 1048576 );
		for ( $written = 0; $written < self::FIXTURE_BYTES; $written += 1048576 ) {
			fwrite( $handle, $chunk );
		}
		fclose( $handle );

		$this->small_fixture = wp_tempnam( 'boekdb-small-fragment' );
		file_put_contents( $this->small_fixture, '%PDF-1.4 small' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		// A one pixel PNG, so no image library is needed to produce one.
		$this->image_fixture = wp_tempnam( 'boekdb-cover' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			$this->image_fixture,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		);

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 9, 3 );
	}

	/**
	 * Removes the fixture, the downloaded attachments and the filter.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 9 );

		// By hash rather than by what a test collected: a failed assertion would otherwise
		// leave the downloaded file behind.
		foreach ( array( self::FILE_URL, self::SMALL_FILE_URL, self::IMAGE_FILE_URL ) as $url ) {
			$attachments = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'meta_key'    => 'hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => md5( $url ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'      => 'ids',
				)
			);

			foreach ( $attachments as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		foreach ( array( $this->fixture, $this->small_fixture, $this->image_fixture ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		parent::tear_down();
	}

	/**
	 * Answers the file URL the way WordPress would: a streaming request writes the body to
	 * the requested file and returns an empty body, anything else returns the body itself.
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $args, $url ) {
		$fixtures = array(
			self::FILE_URL       => $this->fixture,
			self::SMALL_FILE_URL => $this->small_fixture,
			self::IMAGE_FILE_URL       => $this->image_fixture,
			self::OTHER_IMAGE_FILE_URL => $this->image_fixture,
		);

		if ( ! isset( $fixtures[ $url ] ) ) {
			return $preempt;
		}

		$this->request_args = $args;
		$source             = $fixtures[ $url ];

		$response = array(
			'headers'  => array( 'content-type' => 'application/pdf' ),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);

		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( $source, $args['filename'] );
			$response['filename'] = $args['filename'];

			return $response;
		}

		$response['body'] = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return $response;
	}

	/**
	 * Runs the import's file handling for a single product file.
	 *
	 * @param string $soort         The BoekDB file type, e.g. Cover or Fragment.
	 * @param string $type          The mime type the API sends along.
	 * @param string $url           The file to import.
	 * @param string $bestandsnaam  The file name the API sends along.
	 * @param int    $boek_post_id  The book to import into, or null for a fresh one.
	 *
	 * @return int The post the files were attached to.
	 */
	private function import_file( $soort = 'Fragment', $type = 'application/pdf', $url = self::FILE_URL, $bestandsnaam = null, $boek_post_id = null ) {
		$product = (object) array(
			'bestanden' => array(
				(object) array(
					'soort'        => $soort,
					'type'         => $type,
					'url'          => $url,
					'bestandsnaam' => is_null( $bestandsnaam ) ? basename( $url ) . '.pdf' : $bestandsnaam,
				),
			),
		);

		if ( is_null( $boek_post_id ) ) {
			$boek_post_id = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
		}

		$this->invoke_import( 'handle_boek_files', array( $product, $boek_post_id ) );

		return $boek_post_id;
	}

	/**
	 * Calls one of the import's protected file handlers.
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
	 * Measures what an import adds to peak memory, with the fixed start-up cost paid first.
	 *
	 * WordPress loads its admin files and the finfo magic database on the first import, over
	 * 10MB that says nothing about the download, so a small file goes through the same path
	 * before the measurement starts.
	 *
	 * @param callable $import  Imports the large file.
	 *
	 * @return void
	 */
	private function assert_import_streams( callable $import ) {
		$this->import_file( 'Fragment', 'application/pdf', self::SMALL_FILE_URL );

		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			// PHP 8.2 and up. Without it the peak of an earlier test can mask this one.
			memory_reset_peak_usage(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.memory_reset_peak_usageFound
		}

		$this->request_args = array();
		$before             = memory_get_peak_usage();

		$import();

		$growth = memory_get_peak_usage() - $before;

		$this->assertTrue( ! empty( $this->request_args['stream'] ), 'The file has to be requested as a stream.' );
		$this->assertNotEmpty( $this->request_args['filename'], 'A streamed request needs a file to write to.' );
		// Half the file size: enough room for the ~13MB finfo spends on its magic database
		// while sniffing the mime type, far too little for the file itself.
		$this->assertLessThan(
			self::FIXTURE_BYTES / 2,
			$growth,
			sprintf( 'Downloading a %dMB file grew peak memory by %dMB; it must stream to disk.', self::FIXTURE_BYTES / 1048576, $growth / 1048576 )
		);
	}

	/**
	 * The whole point: a file far larger than a shared host's memory limit has to arrive
	 * without ever being held in a PHP string.
	 */
	public function test_large_file_is_not_held_in_memory() {
		$boek_post_id = null;

		$this->assert_import_streams(
			function () use ( &$boek_post_id ) {
				$boek_post_id = $this->import_file();
			}
		);

		$attachment_id = (int) get_post_meta( $boek_post_id, 'boekdb_file_voorbeeld_id', true );
		$this->assertNotSame( 0, $attachment_id, 'The fragment should have been attached to the book.' );
		$this->assertSame( md5( self::FILE_URL ), get_post_meta( $attachment_id, 'hash', true ) );
		$this->assertSame( self::FIXTURE_BYTES + 8, filesize( get_attached_file( $attachment_id ) ) );
	}

	/**
	 * The download timeout has to be settable, because a 50MB fragment does not arrive
	 * within the five seconds WordPress allows by default.
	 */
	public function test_download_timeout_is_filterable() {
		add_filter(
			'boekdb_file_download_timeout',
			function () {
				return 123;
			}
		);

		$this->import_file();

		$this->assertSame( 123, $this->request_args['timeout'] );
	}

	/**
	 * Generating attachment metadata for a PDF makes WordPress render a preview, which on a
	 * 50MB file costs more memory than the download it replaced.
	 */
	public function test_pdf_import_skips_attachment_metadata() {
		$generated = false;

		add_filter(
			'wp_generate_attachment_metadata',
			function ( $metadata ) use ( &$generated ) {
				$generated = true;

				return $metadata;
			}
		);

		$this->import_file();

		$this->assertFalse( $generated, 'A PDF should not be handed to wp_generate_attachment_metadata().' );
	}

	/**
	 * A serie image runs through its own download, and has the same problem.
	 */
	public function test_serie_image_is_not_held_in_memory() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );

		$product = (object) array(
			'serie' => (object) array(
				'beeld' => (object) array(
					'soort'        => 'Seriebeeld',
					'type'         => 'application/pdf',
					'url'          => self::FILE_URL,
					'bestandsnaam' => 'seriebeeld.pdf',
				),
			),
		);

		$this->assert_import_streams(
			function () use ( $product, $term_id ) {
				$this->invoke_import( 'handle_serie_files', array( $product, $term_id ) );
			}
		);

		$attachment_id = (int) get_term_meta( $term_id, 'boekdb_seriebeeld_id', true );

		$this->assertNotSame( 0, $attachment_id, 'The serie image should have been attached to the term.' );
		$this->assertSame( self::FIXTURE_BYTES + 8, filesize( get_attached_file( $attachment_id ) ) );
	}

	/**
	 * An author photo runs through a third download, and has the same problem.
	 */
	public function test_author_photo_is_not_held_in_memory() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'boekdb_auteur_tax' ) );

		$betrokkene = array(
			'bestanden' => array(
				(object) array(
					'soort'        => 'Auteursfoto',
					'type'         => 'application/pdf',
					'url'          => self::FILE_URL,
					'bestandsnaam' => 'auteursfoto.pdf',
				),
			),
		);

		$this->assert_import_streams(
			function () use ( $betrokkene, $term_id ) {
				$this->invoke_import( 'handle_betrokkene_files', array( $betrokkene, $term_id ) );
			}
		);

		$attachment_id = (int) get_term_meta( $term_id, 'auteursfoto_id', true );

		$this->assertNotSame( 0, $attachment_id, 'The photo should have been attached to the term.' );
		$this->assertSame( self::FIXTURE_BYTES + 8, filesize( get_attached_file( $attachment_id ) ) );
	}

	/**
	 * The API does not always send a type. The file on disk still has one, and a cover
	 * without image sizes is a broken cover.
	 */
	public function test_cover_without_api_type_is_stored_as_an_image() {
		$boek_post_id = $this->import_file( 'Cover', '', self::IMAGE_FILE_URL, 'cover.png' );

		$attachment_id = (int) get_post_meta( $boek_post_id, '_thumbnail_id', true );
		$this->assertNotSame( 0, $attachment_id, 'The cover should have been attached to the book.' );

		$this->assertSame(
			'image/png',
			get_post_mime_type( $attachment_id ),
			'Without a type from the API the attachment has to carry the type of the file itself.'
		);
		$this->assertTrue( wp_attachment_is_image( $attachment_id ), 'WordPress only sizes what it sees as an image.' );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertSame( 1, $metadata['width'], 'Image metadata should describe the image.' );
		$this->assertSame( 1, $metadata['height'], 'Image metadata should describe the image.' );
	}

	/**
	 * A file that disappeared from disk is fetched again. The book has to end up pointing at
	 * what was just downloaded, not at nothing: an empty _thumbnail_id is a book without a
	 * cover on every template that shows one.
	 */
	public function test_a_cover_whose_file_went_missing_is_replaced() {
		$boek_post_id = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$first = (int) get_post_meta( $boek_post_id, '_thumbnail_id', true );
		$this->assertNotSame( 0, $first, 'The cover should have been attached on the first import.' );

		unlink( get_attached_file( $first ) );

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $boek_post_id );

		$replacement = (int) get_post_meta( $boek_post_id, '_thumbnail_id', true );

		$this->assertNotSame( 0, $replacement, 'After a repair the book still needs a cover.' );
		$this->assertNotSame( $first, $replacement, 'The replacement is a new attachment.' );
		$this->assertFileExists( get_attached_file( $replacement ), 'And its file should be on disk.' );
	}

	/**
	 * Every file of every product is looked up by hash, on every run. That lookup has to be
	 * one query, not a post query with a meta condition and everything WordPress loads
	 * around it.
	 */
	public function test_a_file_that_is_already_there_costs_few_queries() {
		global $wpdb;

		$boek_post_id = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$before = $wpdb->num_queries;

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $boek_post_id );

		$queries = $wpdb->num_queries - $before;

		$this->assertLessThan( 7, $queries, sprintf( 'Recognising one known file took %d queries.', $queries ) );
	}

	/**
	 * Sites hold attachments whose file is an error page: BoekDB answered 500 for a while
	 * and the old code wrote whatever came back. The url never changed, so the hash still
	 * matches and the file is never fetched again.
	 */
	public function test_an_attachment_holding_an_error_page_is_fetched_again() {
		$boek_post_id = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$broken = (int) get_post_meta( $boek_post_id, '_thumbnail_id', true );
		file_put_contents( get_attached_file( $broken ), '<html><body>500 Internal Server Error</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $boek_post_id );

		$replacement = (int) get_post_meta( $boek_post_id, '_thumbnail_id', true );
		$this->assertNotSame( 0, $replacement, 'The book should still have a cover.' );
		$this->assertNotFalse(
			getimagesize( get_attached_file( $replacement ) ),
			'The cover on disk should be an image again.'
		);
	}

	/**
	 * Author photos run through their own download, and had no repair at all.
	 */
	public function test_an_author_photo_whose_file_went_missing_is_replaced() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'boekdb_auteur_tax' ) );

		$betrokkene = array(
			'bestanden' => array(
				(object) array(
					'soort'        => 'Auteursfoto',
					'type'         => 'image/png',
					'url'          => self::IMAGE_FILE_URL,
					'bestandsnaam' => 'auteur.png',
				),
			),
		);

		$this->invoke_import( 'handle_betrokkene_files', array( $betrokkene, $term_id ) );

		$first = (int) get_term_meta( $term_id, 'auteursfoto_id', true );
		$this->assertNotSame( 0, $first, 'The photo should have been attached on the first import.' );

		unlink( get_attached_file( $first ) );

		$this->invoke_import( 'handle_betrokkene_files', array( $betrokkene, $term_id ) );

		$replacement = (int) get_term_meta( $term_id, 'auteursfoto_id', true );
		$this->assertNotSame( 0, $replacement, 'The author should still have a photo.' );
		$this->assertFileExists( get_attached_file( $replacement ), 'And its file should be on disk.' );
	}

	/**
	 * Imports the serie image of a product into a term.
	 *
	 * @param int    $term_id  The serie term.
	 * @param string $url      The image to import.
	 *
	 * @return void
	 */
	private function import_serie_image( $term_id, $url = self::IMAGE_FILE_URL ) {
		$product = (object) array(
			'serie' => (object) array(
				'beeld' => (object) array(
					'soort'        => 'Seriebeeld',
					'type'         => 'image/png',
					'url'          => $url,
					'bestandsnaam' => basename( wp_parse_url( $url, PHP_URL_PATH ) ) . '.png',
				),
			),
		);

		$this->invoke_import( 'handle_serie_files', array( $product, $term_id ) );
	}

	/**
	 * The image of a serie is read back through boekdb_serie_data(), which themes use. It
	 * has to be stored where that function looks.
	 */
	public function test_a_serie_image_can_be_read_back() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );

		$this->import_serie_image( $term_id );

		$data = boekdb_serie_data( $term_id );

		$this->assertNotNull( $data['serie_beeld_id'], 'The serie image should be readable.' );
		$this->assertFileExists( get_attached_file( (int) $data['serie_beeld_id'] ) );
	}

	/**
	 * Two series can carry the same image. The second one finds the file already there, and
	 * still needs its own link to it.
	 */
	public function test_a_second_serie_with_the_same_image_is_linked_too() {
		$first  = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		$second = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );

		$this->import_serie_image( $first );
		$this->import_serie_image( $second );

		$data = boekdb_serie_data( $second );

		$this->assertNotNull( $data['serie_beeld_id'], 'The second serie should have the image too.' );
	}

	/**
	 * The same file can arrive as the back cover of one book and the cover of another. Which
	 * of the two it is, is a property of the book it comes with, not of the file.
	 */
	public function test_a_file_reused_in_another_role_is_linked_in_that_role() {
		$first = $this->import_file( 'Back cover', 'image/png', self::IMAGE_FILE_URL, 'plaat.png' );
		$this->assertNotSame( '', get_post_meta( $first, 'boekdb_file_backcover_id', true ), 'The first book has it as its back cover.' );

		$second = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'plaat.png' );

		$this->assertNotSame( '', get_post_meta( $second, '_thumbnail_id', true ), 'The second book should have it as its cover.' );
	}

	/**
	 * One file is shared by every book and author that uses that url. Replacing an unusable
	 * one has to take those along, or they keep pointing at an attachment that is gone.
	 */
	public function test_replacing_an_unusable_file_takes_the_other_references_along() {
		$first = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$broken = (int) get_post_meta( $first, '_thumbnail_id', true );

		// Another book and an author point at the same file.
		$second = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
		update_post_meta( $second, '_thumbnail_id', $broken );
		$term = self::factory()->term->create( array( 'taxonomy' => 'boekdb_auteur_tax' ) );
		update_term_meta( $term, 'auteursfoto_id', $broken );

		file_put_contents( get_attached_file( $broken ), '<html><body>500 Internal Server Error</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $first );

		$replacement = (int) get_post_meta( $first, '_thumbnail_id', true );

		$this->assertNotSame( $broken, $replacement, 'The file should have been replaced.' );
		$this->assertSame( (string) $replacement, get_post_meta( $second, '_thumbnail_id', true ), 'The other book should point at the new file.' );
		$this->assertSame( (string) $replacement, get_term_meta( $term, 'auteursfoto_id', true ), 'And so should the author.' );
	}

	/**
	 * When the image of a serie changes it arrives under another url, and the site may
	 * already hold that file from a different serie. The serie has to carry the image it is
	 * given now, not the one it was given first.
	 */
	public function test_a_serie_takes_on_an_image_the_site_already_had() {
		$other = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		$this->import_serie_image( $other, self::OTHER_IMAGE_FILE_URL );
		$shared = (int) get_term_meta( $other, 'boekdb_seriebeeld_id', true );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'boekdb_serie_tax' ) );
		$this->import_serie_image( $term_id );
		$first = (int) get_term_meta( $term_id, 'boekdb_seriebeeld_id', true );

		// Its image changes to the one the other serie already brought in.
		$this->import_serie_image( $term_id, self::OTHER_IMAGE_FILE_URL );

		$after = (int) get_term_meta( $term_id, 'boekdb_seriebeeld_id', true );

		$this->assertNotSame( $first, $after, 'The serie should no longer carry its old image.' );
		$this->assertSame( $shared, $after, 'It carries the image it was given now.' );
	}

	/**
	 * When the replacement cannot be fetched, the site is better off with the file it has
	 * than with no file and a book pointing at nothing.
	 */
	public function test_a_failed_replacement_leaves_the_books_as_they_were() {
		$boek = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$broken = (int) get_post_meta( $boek, '_thumbnail_id', true );
		file_put_contents( get_attached_file( $broken ), '<html><body>500 Internal Server Error</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		// The download of the replacement times out.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				return self::IMAGE_FILE_URL === $url ? new \WP_Error( 'http_request_failed', 'Operation timed out' ) : $preempt;
			},
			// After the canned response this class serves.
			11,
			3
		);

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $boek );

		$this->assertSame( (string) $broken, get_post_meta( $boek, '_thumbnail_id', true ), 'The book should still point at the file it had.' );
		$this->assertNotNull( get_post( $broken ), 'Which therefore has to still exist.' );
	}

	/**
	 * Replacing a file takes a while. A book that got another cover in the meantime keeps
	 * the new one instead of being sent back to the file that was being replaced.
	 */
	public function test_a_reference_that_changed_during_the_download_is_left_alone() {
		$boek = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$broken = (int) get_post_meta( $boek, '_thumbnail_id', true );

		$other = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
		update_post_meta( $other, '_thumbnail_id', $broken );

		file_put_contents( get_attached_file( $broken ), '<html><body>500 Internal Server Error</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		$newer = self::factory()->attachment->create_object(
			array(
				'file'           => 'nieuwere-cover.png',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Cover',
			)
		);

		// While the replacement is being fetched, that other book gets a cover of its own.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $other, $newer ) {
				if ( self::IMAGE_FILE_URL === $url ) {
					update_post_meta( $other, '_thumbnail_id', $newer );
				}

				return $preempt;
			},
			8,
			3
		);

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $boek );

		$this->assertSame( (string) $newer, get_post_meta( $other, '_thumbnail_id', true ), 'The newer cover should have been left alone.' );
	}

	/**
	 * An error page served with a 200 passes for a download. WordPress refuses it as a file,
	 * and by then the old attachment must still be there: a later attempt that does succeed
	 * can only hand the shared references over if there is something to hand over.
	 */
	public function test_an_error_page_served_as_an_image_keeps_the_old_file() {
		$boek = $this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png' );

		$broken = (int) get_post_meta( $boek, '_thumbnail_id', true );

		$other = self::factory()->post->create( array( 'post_type' => 'boekdb_boek' ) );
		update_post_meta( $other, '_thumbnail_id', $broken );

		file_put_contents( get_attached_file( $broken ), '<html><body>500 Internal Server Error</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		// The replacement comes back as an error page with a 200.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( self::IMAGE_FILE_URL !== $url ) {
					return $preempt;
				}

				if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], '<html><body>502 Bad Gateway</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
				}

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
				);
			},
			11,
			3
		);

		$this->import_file( 'Cover', 'image/png', self::IMAGE_FILE_URL, 'cover.png', $boek );

		$this->assertNotNull( get_post( $broken ), 'The old file should still be there.' );
		$this->assertSame( (string) $broken, get_post_meta( $other, '_thumbnail_id', true ), 'And the other book should still point at it.' );
	}
}
