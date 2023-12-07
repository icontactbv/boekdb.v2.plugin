<?php

class Boekdb_Api_Service {

	const BOEKDB_DOMAIN       = 'https://boekdbv2.nl/';
	const BASE_URL            = self::BOEKDB_DOMAIN . 'api/json/v1/';
	const LIMIT               = 100;

	public static function test_api_connection() {
		$response = wp_remote_get(self::BASE_URL . 'test');
		if(is_wp_error($response)) {
			$error_message = $response->get_error_message();
			return array(
				'success' => false,
				'message' => "Er is iets mis: $error_message"
			);
		}
		else {
			$code = wp_remote_retrieve_response_code($response);
			$result = json_decode(wp_remote_retrieve_body($response), true);
			if ($code === 200 && 'hello' === $result[0] ) {
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

	public static function check_connection_and_version() {
		$result = wp_remote_get( self::BASE_URL . 'test' );

		// Check for connection errors
		if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}

		// Fetch the latest version from the API response
		$body = wp_remote_retrieve_body( $result );
		$data = json_decode( $body, true );
		$apiVersion = $data['plugin_version'] ?? null;

		// Compare with current plugin version and set/update the option if a new version is available
		if ( $apiVersion && version_compare( $apiVersion, BoekDB()->version, '>' ) ) {
			update_option( 'boekdb_new_version_available', true );
		} else {
			delete_option( 'boekdb_new_version_available' );
		}

		return true;
	}

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
}