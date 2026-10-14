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
	 * The API answers on www and redirects the bare domain, so every request there makes a
	 * detour. A client that dropped the api key on a redirect to another host would get a
	 * 401, which the start of an import takes for an invalid key.
	 */
	public function test_the_api_is_called_on_the_host_that_answers() {
		$this->assertStringStartsWith( 'https://www.boekdbv2.nl/', Boekdb_Api_Service::BASE_URL );
	}

	/**
	 * BoekDB can tell from its logs which site calls it, from the user agent WordPress sends.
	 * Every request also says which version of the plugin made it.
	 */
	public function test_every_request_says_which_site_and_plugin_version_made_it() {
		$agents  = array();
		$capture = function ( $preempt, $args, $url ) use ( &$agents ) {
			$agents[ $url ] = $args['user-agent'];

			return $preempt;
		};
		add_filter( 'pre_http_request', $capture, 9, 3 );

		Boekdb_Api_Service::test_api_connection();
		Boekdb_Api_Service::check_connection_and_version();
		Boekdb_Api_Service::fetch_products( 'test-key', '2026-01-01T00:00:00+01:00', 0 );
		Boekdb_Api_Service::fetch_product( 'test-key', '9789000000000' );
		Boekdb_Api_Service::fetch_isbns( 'test-key' );

		remove_filter( 'pre_http_request', $capture, 9 );

		$this->assertCount( 4, $agents, 'Test, products, a product and isbns.' );

		$expected = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ) . '; BoekDB/' . BoekDB()->version;
		foreach ( $agents as $url => $agent ) {
			$this->assertSame( $expected, $agent, $url );
		}
	}
}
