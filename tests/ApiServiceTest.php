<?php
/**
 * Covers how the API service behaves when BoekDB cannot be reached.
 *
 * @package BoekDB
 */

namespace BoekDB\Tests;

use Boekdb_Api_Service;
use WP_UnitTestCase;

/**
 * API service tests.
 *
 * Outbound HTTP is blocked in the bootstrap, so every request here comes back as the
 * WP_Error a site sees when BoekDB is unreachable.
 */
class ApiServiceTest extends WP_UnitTestCase {

	/**
	 * A network error has to be reported back, not end the request. Ending it takes the
	 * admin page, the cron run or whatever else was being served down with it.
	 */
	public function test_unreachable_api_reports_failure_instead_of_ending_the_request() {
		$this->assertFalse( Boekdb_Api_Service::fetch_isbns( 'test-key' ) );
	}

	/**
	 * The same for the products endpoint, which already behaved.
	 */
	public function test_unreachable_api_returns_no_products() {
		$this->assertFalse( Boekdb_Api_Service::fetch_products( 'test-key', '2026-01-01T00:00:00+01:00', 0 ) );
	}

	/**
	 * The API answers on www only and redirects the bare domain. Every request would make a
	 * detour, and a client that drops the api key on a redirect to another host gets a 401,
	 * which the start of an import takes for an invalid key.
	 */
	public function test_the_api_is_called_on_the_host_that_answers() {
		$this->assertStringStartsWith( 'https://www.boekdbv2.nl/', Boekdb_Api_Service::BASE_URL );
	}
}
