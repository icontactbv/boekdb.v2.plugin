<?php
/**
 * Setup menus in WP admin.
 *
 * @package BoekDB\Admin
 * @version 2.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BoekDB_Admin_Menus', false ) ) {
	return new BoekDB_Admin_Menus();
}

/**
 * BoekDB_Admin_Menus Class.
 */
class BoekDB_Admin_Menus {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		// Add menus.
		add_action( 'admin_menu', array( $this, 'settings_menu' ), 50 );
	}

	/**
	 * Add menu item.
	 */
	public function settings_menu() {
		$settings_page = add_options_page( 'BoekDB', 'BoekDB', 'activate_plugins', 'boekdb-settings', array( $this, 'settings_page' ) );
	}

	/**
	 * Init the settings page.
	 */
	public function settings_page() {
		BoekDB_Admin_Settings::output();
	}

}

return new BoekDB_Admin_Menus();
