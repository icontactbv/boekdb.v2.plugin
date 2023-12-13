<?php
/**
 * BoekDB setup
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BoekDB
 *
 * The BoekDB class represents the main functionality of the BoekDB plugin.
 */
final class BoekDB {
	/**
	 * The single instance of the class.
	 *
	 * @var BoekDB
	 */

	protected static $instance = null;
	/**
	 * BoekDB version.
	 *
	 * @var string
	 */
	public $version = '1.1.0';

	/**
	 * BoekDb Constructor.
	 */
	public function __construct() {
		if ( ! defined( 'BOEKDB_VERSION' ) ) {
			define( 'BOEKDB_VERSION', $this->version );
		}
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required files
	 */
	public function includes() {
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-post-types.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-install.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-api-service.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-import.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-translations.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-cleanup.php';

		include_once BOEKDB_ABSPATH . 'includes/admin/class-boekdb-admin-meta-boxes.php';
		include_once BOEKDB_ABSPATH . 'includes/admin/class-boekdb-admin.php';
	}

	/**
	 * Hook into actions and filters.
	 */
	private function init_hooks() {
		register_activation_hook( BOEKDB_PLUGIN_FILE, array( 'BoekDB_Install', 'install' ) );
	}

	/**
	 * Main BoekDB Instance.
	 *
	 * Ensures only one instance of BoekDB is loaded or can be loaded.
	 *
	 * @static
	 *
	 * @return BoekDB - Main instance.
	 * @see    BoekDB()
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the plugin url.
	 *
	 * Can be used by WordPress.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', BOEKDB_PLUGIN_FILE ) );
	}

	const QUERY_READY_ETALAGES = "SELECT id, name, api_key, running, isbns, offset, DATE_FORMAT(last_import, '%Y-%m-%d\T%H:%i:%s\+01:00') as last_import, filter_hash FROM {:prefix}boekdb_etalages WHERE running = 2";
	const QUERY_ALL_ETALAGES   = "SELECT id, name, api_key, running, isbns, offset, DATE_FORMAT(last_import, '%Y-%m-%d\T%H:%i:%s\+01:00') as last_import, filter_hash FROM {:prefix}boekdb_etalages";

	/**
	 * Fetch etalages
	 *
	 * @param bool $readytorun  A flag to check readiness.
	 *
	 * @return array An array of etalages.
	 */
	public static function fetch_etalages( $readytorun = false ) {
		global $wpdb;

		$fetch = $readytorun ? self::QUERY_READY_ETALAGES : self::QUERY_ALL_ETALAGES;
		$fetch = str_replace( '{:prefix}', $wpdb->prefix, $fetch );

		return $wpdb->get_results( $fetch, OBJECT );
	}
}
