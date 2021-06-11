<?php
/**
 * BoekDB Admin Settings Class
 *
 * @package BoekDB\Admin
 */

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
			global $wpdb;

			if ( isset( $_POST['save'] ) && 'save' === $_POST['save'] ) {
				$api_key = $_POST['etalage_api_key'];
				$name    = $_POST['etalage_name'];

				if ( strlen( $api_key ) === 0 || strlen( $name ) === 0 ) {
					self::add_error( 'Er is iets fout gegaan' );
				}

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}boekdb_etalages (`name`, `api_key`) VALUES (%s, %s)",
						$name,
						$api_key
					)
				);
				self::add_message( 'Etalage opgeslagen.' );
			} elseif ( isset( $_POST['run'] ) && 'run' === $_POST['run'] ) {
				wp_schedule_single_event( time() + 5, BoekDB_Import::CRON_HOOK );
				self::add_message( 'Import gestart...' );
			}
		}

		/**
		 * Add a message.
		 *
		 * @param  string  $text  Message.
		 */
		public static function add_message( $text ) {
			self::$messages[] = $text;
		}

		/**
		 * Add an error.
		 *
		 * @param  string  $text  Message.
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

			$etalages = $wpdb->get_results( "SELECT e.id, e.name, e.api_key, DATE_FORMAT(e.last_import, '%Y-%m-%d %H:%i:%s') as last_import, COUNT(eb.boek_id) as boeken FROM {$wpdb->prefix}boekdb_etalages e LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON e.id = eb.etalage_id GROUP BY e.id",
				OBJECT );

			return $etalages;
		}

	}

endif;
