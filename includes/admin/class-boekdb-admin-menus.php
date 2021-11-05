<?php
/**
 * Setup menus in WP admin.
 *
 * @package BoekDB
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

		add_action( 'wp_loaded', array( $this, 'save_settings' ) );
	}

	/**
	 * Add menu item.
	 */
	public function settings_menu() {
		$settings_page = add_options_page( 'BoekDB', 'BoekDB', 'activate_plugins', 'boekdb-settings',
			array( $this, 'settings_page' ) );
	}

	/**
	 * Init the settings page.
	 */
	public function settings_page() {
		BoekDB_Admin_Settings::output();
	}

	public function save_settings() {
		// We should only save on the settings page.
		if ( ! is_admin() || ! isset( $_GET['page'] ) || 'boekdb-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		BoekDB_Admin_Settings::save();
	}

}

return new BoekDB_Admin_Menus();
