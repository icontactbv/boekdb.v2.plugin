<?php
/**
 * BoekDB API Services
 *
 * @package BoekDB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class Boekdb_Api_Service {

	const BOEKDB_DOMAIN = 'https://boekdbv2.nl/';
	const BASE_URL      = self::BOEKDB_DOMAIN . 'api/json/v1/';
	const LIMIT         = 100;

	/**
	 * Test API connection
	 *
	 * @return array Connection status and error/success message
	 */
	public static function test_api_connection() {
		$response = wp_remote_get( self::BASE_URL . 'test' );
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			return array(
				'success' => false,
				'message' => "Er is iets mis: $error_message"
			);
		} else {
			$code   = wp_remote_retrieve_response_code( $response );
			$result = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code === 200 && 'hello' === $result[0] ) {
				return array(
					'success' => true,
					'message' => 'Connectie met BoekDB is ok.'
				);
			} else {
				return array(
					'success' => false,
					'message' => 'Er is iets mis, response: ' . $code
				);
			}
		}
	}

	/**
	 * Check API connection and version
	 *
	 * @return bool The state of the connection
	 */
	public static function check_connection_and_version() {
		$result = wp_remote_get( self::BASE_URL . 'test' );

		// Check for connection errors
		if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}

		// Fetch the latest version from the API response
		$body       = wp_remote_retrieve_body( $result );
		$data       = json_decode( $body, true );
		$apiVersion = $data['plugin_version'] ?? null;

		// Compare with current plugin version and set/update the option if a new version is available
		if ( $apiVersion && version_compare( $apiVersion, BoekDB()->version, '>' ) ) {
			update_option( 'boekdb_new_version_available', true );
		} else {
			update_option( 'boekdb_new_version_available', false );
		}

		return true;
	}

	/**
	 * Fetch products
	 *
	 * @param string  $api_key      API Key
	 * @param string  $last_import  Last import date
	 * @param int     $offset       Offset value
	 *
	 * @return array|bool List of products or false if failed
	 */
	public static function fetch_products( $api_key, $last_import, $offset ) {
		$response = wp_remote_get(
			self::BASE_URL . 'products?updated_at=' . urlencode( $last_import ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'x-limit'       => self::LIMIT,
					'x-offset'      => $offset,
				),
				'timeout' => 30,
			)
		);

		$result   = wp_remote_retrieve_body( $response );
		$products = json_decode( $result );
		if ( ! is_array( $products ) ) {
			boekdb_debug( 'Error fetching products?' );
			boekdb_debug( $response );

			return false;
		}
		boekdb_debug( count( $products ) . ' products for offset ' . $offset );

		return $products;
	}

	/**
	 * Fetch ISBNs
	 *
	 * @param string  $api_key  API Key
	 *
	 * @return array|bool List of ISBNs or false if failed
	 */
	public static function fetch_isbns( $api_key ) {
		$result = wp_remote_get(
			self::BASE_URL . 'isbns',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $result ) ) {
			die( $result->get_error_message() );
		}
		$result = wp_remote_retrieve_body( $result );
		$result = json_decode( $result, true );
		if ( ! is_array( $result ) || ! isset( $result['isbns'] ) ) {
			return false;
		}

		return $result;
	}

	/**
	 * Touch product
	 *
	 * @param int  $post_id  Post ID
	 *
	 * @return bool|string 'true' if successful or error message string if failed
	 */
	public static function touch_product( $post_id ) {
		global $wpdb;

		// Fetch the isbn using the post_id
		$isbn = $wpdb->get_var( $wpdb->prepare( "SELECT isbn FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id = %d",
			$post_id ) );

		if ( ! $isbn ) {
			return false;
		}

		// Fetch the first api_key from the database
		$api_key = $wpdb->get_var( "SELECT api_key FROM {$wpdb->prefix}boekdb_etalages LIMIT 1" );

		$url = self::BASE_URL . 'products/' . $isbn;

		$response = wp_remote_request( $url, array(
			'method'  => 'PUT',
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			return "Something went wrong: $error_message";
		}

		return true;
	}

	/**
	 * Validate API Key
	 *
	 * @param string  $api_key  API Key
	 *
	 * @return bool 'true' if valid 'false' if invalid
	 */
	public static function validate_api_key( $api_key ) {
		boekdb_debug( 'Validating API key: ' . $api_key );

		// Make a request to any read-only endpoint
		$response = wp_remote_get( self::BASE_URL . 'validate', [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
			],
		] );

		boekdb_debug($response);

		// Check if the API key is invalid (Unauthorized)
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) == 401 ) {
			boekdb_debug( 'Invalid API key: ' . $api_key );
			return false;
		}

		return true;
	}
}