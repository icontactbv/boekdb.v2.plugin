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
			self::IMAGE_FILE_URL => $this->image_fixture,
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

		$attachment_id = (int) get_term_meta( $term_id, 'seriebeeld_id', true );

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
}
