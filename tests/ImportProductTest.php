<?php
/**
 * Pushes realistic API payloads through the real import path and checks it runs clean.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use BoekDB_Import;
use WP_UnitTestCase;

/**
 * Import integration tests, covering fully populated, null and sparse product payloads.
 */
class ImportProductTest extends WP_UnitTestCase {

	/**
	 * The id of the etalage row created in set_up().
	 *
	 * @var int
	 */
	private $etalage_id;

	/**
	 * The JSON body returned for the products endpoint by intercept_http_request().
	 *
	 * @var string
	 */
	private $products_body;

	/**
	 * Creates a ready-to-run etalage and starts intercepting outbound HTTP requests.
	 */
	public function set_up() {
		parent::set_up();

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
				'running'     => 2,
			)
		);
		$this->etalage_id = (int) $wpdb->insert_id;

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 9, 3 );
	}

	/**
	 * Stops intercepting outbound HTTP requests.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 9 );

		parent::tear_down();
	}

	/**
	 * Returns a canned response for known BoekDB endpoints, otherwise leaves $preempt
	 * untouched so the bootstrap's blocker filter turns it into a WP_Error (e.g. image
	 * downloads).
	 *
	 * @param mixed  $preempt  The pre-empted response, or false.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 *
	 * @return mixed
	 */
	public function intercept_http_request( $preempt, $args, $url ) {
		if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . 'products?updated_at=' ) ) {
			return $this->canned_response( $this->products_body );
		}

		if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . 'isbns' ) ) {
			return $this->canned_response(
				wp_json_encode(
					array(
						'filters' => 'abc',
						'isbns'   => array( '9789000000001' ),
					)
				)
			);
		}

		if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . 'validate' ) ) {
			return $this->canned_response( wp_json_encode( array( 'valid' => true ) ) );
		}

		if ( 0 === strpos( $url, Boekdb_Api_Service::BASE_URL . 'test' ) ) {
			return $this->canned_response(
				wp_json_encode(
					array(
						'0'              => 'hello',
						'plugin_version' => '1.1.1',
					)
				)
			);
		}

		return $preempt;
	}

	/**
	 * Builds a canned wp_remote_get()-shaped response array for a given JSON body.
	 *
	 * @param string $body  The JSON encoded response body.
	 *
	 * @return array
	 */
	private function canned_response( $body ) {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => $body,
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Reads a fixture file's raw contents.
	 *
	 * @param string $name  The fixture file name.
	 *
	 * @return string
	 */
	private function load_fixture( $name ) {
		return file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Finds the boek post id linked to an isbn through the boekdb_isbns table.
	 *
	 * @param string $isbn  The isbn to look up.
	 *
	 * @return int
	 */
	private function get_boek_post_id_by_isbn( $isbn ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s",
				$isbn
			)
		);
	}

	/**
	 * Asserts the common import outcome shared by all fixtures: exactly one boekdb_boek
	 * post for the isbn, with the expected title, isbn meta, etalage link and advanced
	 * offset.
	 *
	 * @param string $isbn            The imported isbn.
	 * @param string $expected_titel  The expected post_title.
	 *
	 * @return int The imported post id.
	 */
	private function assert_product_was_imported( $isbn, $expected_titel ) {
		global $wpdb;

		$post_id = $this->get_boek_post_id_by_isbn( $isbn );
		$this->assertGreaterThan( 0, $post_id, 'a boek post should be linked to the isbn' );

		$posts = get_posts(
			array(
				'post_type'   => 'boekdb_boek',
				'post_status' => 'any',
				'meta_key'    => 'boekdb_isbn',
				'meta_value'  => $isbn,
			)
		);
		$this->assertCount( 1, $posts, 'exactly one boekdb_boek post should exist for the isbn' );

		$post = get_post( $post_id );
		$this->assertSame( 'boekdb_boek', $post->post_type );
		$this->assertSame( $expected_titel, $post->post_title );
		$this->assertSame( $isbn, get_post_meta( $post_id, 'boekdb_isbn', true ) );

		$linked = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id = %d AND boek_id = %d",
				$this->etalage_id,
				$post_id
			)
		);
		$this->assertSame( 1, $linked, 'the book should be linked to the etalage' );

		$offset = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `offset` FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d",
				$this->etalage_id
			)
		);
		$this->assertSame( 100, $offset, 'the etalage offset should have advanced by one batch' );

		return $post_id;
	}

	/**
	 * Imports a product with every field of the Product schema populated.
	 *
	 * @test
	 */
	public function imports_a_fully_populated_product() {
		$this->products_body = $this->load_fixture( 'product-full.json' );

		BoekDB_Import::import();

		$post_id = $this->assert_product_was_imported( '9789000000001', 'De Nachtwacht' );

		$auteur_terms = wp_get_object_terms( $post_id, 'boekdb_auteur_tax' );
		$this->assertNotEmpty( $auteur_terms, 'the auteur term should be attached to the book' );
		$this->assertSame( 'Anna de Vries', $auteur_terms[0]->name );

		$nur_terms = wp_get_object_terms( $post_id, 'boekdb_nur_tax' );
		$this->assertNotEmpty( $nur_terms, 'a NUR term should be attached to the book' );
	}

	/**
	 * Imports a product whose optional fields are explicitly null and whose optional
	 * arrays are empty.
	 *
	 * @test
	 */
	public function imports_a_product_whose_optional_fields_are_null() {
		$this->products_body = $this->load_fixture( 'product-nulls.json' );

		BoekDB_Import::import();

		$this->assert_product_was_imported( '9789000000002', 'Boek Zonder Extra Gegevens' );
	}

	/**
	 * Imports a product whose optional fields are entirely absent from the payload.
	 *
	 * @test
	 */
	public function imports_a_product_whose_optional_fields_are_absent() {
		$this->products_body = $this->load_fixture( 'product-sparse.json' );

		BoekDB_Import::import();

		$this->assert_product_was_imported( '9789000000003', 'Sparse Boek' );
	}

	/**
	 * Imports a product whose top-level fields are populated but whose nested object
	 * scalars (contributor, collection, prize, review and quote fields) are explicitly
	 * null wherever the schema or the API mutator allows it.
	 *
	 * @test
	 */
	public function imports_a_product_whose_nested_fields_are_null() {
		$this->products_body = $this->load_fixture( 'product-nested-nulls.json' );

		BoekDB_Import::import();

		$post_id = $this->assert_product_was_imported( '9789000000004', 'Schaduwen Zonder Namen' );

		$auteur_terms = wp_get_object_terms( $post_id, 'boekdb_auteur_tax' );
		$this->assertNotEmpty( $auteur_terms, 'the auteur term should still be attached despite the nested nulls' );
		$this->assertSame( 'Nulls Auteur', $auteur_terms[0]->name );
	}

	/**
	 * Imports a product whose nested objects (a contributor, a file, a subject, a link
	 * and a review quote) each carry only a handful of keys, the rest being entirely
	 * absent rather than null.
	 *
	 * @test
	 */
	public function imports_a_product_whose_nested_objects_are_sparse() {
		$this->products_body = $this->load_fixture( 'product-sparse-nested.json' );

		BoekDB_Import::import();

		$this->assert_product_was_imported( '9789000000005', 'Sparse Geneste Boek' );
	}
}
