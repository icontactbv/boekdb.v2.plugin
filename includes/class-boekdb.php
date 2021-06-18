<?php
/**
 * BoekDB setup
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;


final class BoekDB {
	/**
	 * BoekDB version.
	 *
	 * @var string
	 */
	public $version = '0.1.1';

	/**
	 * The single instance of the class.
	 *
	 * @var BoekDB
	 */
	protected static $_instance = null;

	/**
	 * Main BoekDB Instance.
	 *
	 * Ensures only one instance of BoekDB is loaded or can be loaded.
	 *
	 * @static
	 * @return BoekDB - Main instance.
	 * @see    BoekDB()
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * BoekDb Constructor.
	 */
	public function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Define BoekDB Constants.
	 */
	private function define_constants() {
		$upload_dir = wp_upload_dir( null, false );

		$this->define( 'BOEKDB_ABSPATH', dirname( BOEKDB_PLUGIN_FILE ) . '/' );
		$this->define( 'BOEKDB_PLUGIN_BASENAME', plugin_basename( BOEKDB_PLUGIN_FILE ) );
		$this->define( 'BOEKDB_VERSION', $this->version );
	}

	/**
	 * Include required files
	 */
	public function includes() {
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-post-types.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-install.php';
		include_once BOEKDB_ABSPATH . 'includes/class-boekdb-import.php';

		include_once BOEKDB_ABSPATH . 'includes/admin/class-boekdb-admin-meta-boxes.php';
		include_once BOEKDB_ABSPATH . 'includes/admin/class-boekdb-admin.php';
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string  $name  Constant name.
	 * @param string|bool  $value  Constant value.
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Hook into actions and filters.
	 */
	private function init_hooks() {
		register_activation_hook( BOEKDB_PLUGIN_FILE, array( 'BoekDB_Install', 'install' ) );
	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', BOEKDB_PLUGIN_FILE ) );
	}

}