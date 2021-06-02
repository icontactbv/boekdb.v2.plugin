<?php
/**
 * BoekDB Admin Settings Class
 *
 * @package BoekDB\Admin
 */

use Automattic\Jetpack\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BoekDB_Admin_Settings', false ) ) :

	/**
	 * BoekDB_Admin_Settings Class.
	 */
	class BoekDB_Admin_Settings {

		/**
		 * Setting pages.
		 *
		 * @var array
		 */
		private static $settings = array();

		/**
		 * Error messages.
		 *
		 * @var array
		 */
		private static $errors = array();

		/**
		 * Update messages.
		 *
		 * @var array
		 */
		private static $messages = array();

		/**
		 * Include the settings page classes.
		 */
		public static function get_settings_pages() {
			if ( empty( self::$settings ) ) {
				$settings = array();

				include_once dirname( __DIR__ ) . '/settings/class-boekdb-settings-page.php';
				self::$settings = apply_filters( 'boekdb_get_settings_pages', $settings );
			}

			return self::$settings;
		}

		/**
		 * Save the settings.
		 */
		public static function save() {
			self::add_message( __( 'Your settings have been saved.', 'boekdb' ) );

			BoekDB()->query->init_query_vars();
			BoekDB()->query->add_endpoints();
		}

		/**
		 * Add a message.
		 *
		 * @param string $text Message.
		 */
		public static function add_message( $text ) {
			self::$messages[] = $text;
		}

		/**
		 * Add an error.
		 *
		 * @param string $text Message.
		 */
		public static function add_error( $text ) {
			self::$errors[] = $text;
		}

		/**
		 * Output messages + errors.
		 */
		public static function show_messages() {
			if ( count( self::$errors ) > 0 ) {
				foreach ( self::$errors as $error ) {
					echo '<div id="message" class="error inline"><p><strong>' . esc_html( $error ) . '</strong></p></div>';
				}
			} elseif ( count( self::$messages ) > 0 ) {
				foreach ( self::$messages as $message ) {
					echo '<div id="message" class="updated inline"><p><strong>' . esc_html( $message ) . '</strong></p></div>';
				}
			}
		}

		public static function output() {
			$etalages = self::get_etalages();
			include __DIR__ . '/views/html-admin-settings.php';
		}

		public static function get_etalages() {
			global $wpdb;

			$etalages = $wpdb->get_results( "SELECT id, name, api_key, DATE_FORMAT(last_import, '%Y-%m-%d %H:%i:%s') as last_import FROM {$wpdb->prefix}boekdb_etalages", OBJECT );
			return $etalages;
		}

	}

endif;
