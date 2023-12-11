<?php
/**
 * BoekDB Admin
 *
 * @class   BoekDB_Admin
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

/**
 * BoekDB_Admin class.
 */
class BoekDB_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'includes' ) );
	}

	/**
	 * Include any classes we need within admin.
	 */
	public function includes() {
		include_once __DIR__ . '/class-boekdb-admin-menus.php';
		include_once __DIR__ . '/class-boekdb-admin-settings.php';
	}

}

return new BoekDB_Admin();
