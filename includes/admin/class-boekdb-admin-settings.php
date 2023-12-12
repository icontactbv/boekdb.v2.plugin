<?php
/**
 * BoekDB Admin Settings Class
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

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
		 * Save the settings.
		 */
		public static function save() {
			// Using isset() checks for each button here
			if (isset($_POST['save'])) {
				self::save_etalage();
			} elseif (isset($_POST['run'])) {
				self::run_import();
			} elseif (isset($_POST['test'])) {
				self::test_connection();
			} elseif (isset($_POST['stop'])) {
				self::stop_import();
			} elseif (isset($_POST['cleanup'])) {
				self::start_cleanup();
			} elseif (isset($_POST['reset'])) {
				self::reset_etalage();
			} elseif (isset($_POST['delete'])) {
				self::delete_etalage();
			}
		}

		private static function run_import() {
			if (! boekdb_is_import_running()) {
				if(WP_DEBUG) {
					BoekDB_Import::start_import();
				} else {
					wp_schedule_single_event(time() + 5, BoekDB_Import::START_IMPORT_HOOK);
				}
			} else {
				self::add_error('Import draait al!');
			}
		}

		private static function test_api_connection() {
			$testResponse = Boekdb_Api_Service::test_api_connection();
			if(!$testResponse['success']) {
				self::add_error($testResponse['message']);
			} else {
				self::add_message($testResponse['message']);
			}
		}

		private static function stop_import() {
			global $wpdb;

			$wpdb->query("UPDATE {$wpdb->prefix}boekdb_etalages SET offset=0, running=0");
		}

		private static function save_etalage() {
			global $wpdb;

			$api_key = sanitize_text_field($_POST['etalage_api_key']);
			$name    = sanitize_text_field($_POST['etalage_name']);

			if ( strlen( $api_key ) === 0 || strlen( $name ) === 0 ) {
				self::add_error( 'Er is iets fout gegaan' );
			} elseif(! Boekdb_Api_Service::validate_api_key( $api_key )) {
				self::add_error( 'API key is niet geldig' );
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}boekdb_etalages (`name`, `api_key`) VALUES (%s, %s)",
						$name,
						$api_key
					)
				);
				self::add_message( 'Etalage opgeslagen.' );
			}
		}

		private static function start_cleanup() {
			if(WP_DEBUG) {
				BoekDB_Cleanup::cleanup();
			} else {
				wp_schedule_single_event(time() + 5, BoekDB_Cleanup::CLEANUP_HOOK);
			}
			self::add_message('Opruimen gestart');
		}

		private static function reset_etalage() {
			global $wpdb;
			$id = (int) $_POST['reset'];
			if ( $id > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}boekdb_etalages SET last_import=null WHERE id = %d",
					$id ) );

				self::add_message( 'Reset succesvol.' );
			}
		}

		private static function delete_etalage() {
			$id = (int) $_POST['delete'];
			if ( $id > 0 ) {
				BoekDB_Cleanup::delete_etalage($id);
				set_transient( 'boekdb_admin_notice', 'Etalage is verwijderd.', 60 );
			}
		}

		/**
		 * Add a message.
		 *
		 * @param string  $text  Message.
		 */
		public static function add_message( $text ) {
			self::$messages[] = $text;
		}

		/**
		 * Add an error.
		 *
		 * @param string  $text  Message.
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
			$etalages       = self::get_etalages();
			$import_running = boekdb_is_import_running();
			if ( $import_running ) {
				self::add_message( 'Er draait op dit moment een import' );
			}

			include __DIR__ . '/views/html-admin-settings.php';
		}

		public static function get_etalages() {
			global $wpdb;

			$etalage_result = $wpdb->get_results( "SELECT e.id, e.name, e.api_key, DATE_FORMAT(e.last_import, '%Y-%m-%d %H:%i:%s') as last_import, e.isbns, e.running, e.offset, COUNT(eb.boek_id) as boeken FROM {$wpdb->prefix}boekdb_etalages e LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON e.id = eb.etalage_id GROUP BY e.id", OBJECT );
			$etalages       = array();
			foreach ( $etalage_result as $etalage ) {
				$etalages[ $etalage->id ] = $etalage;
			}

			return $etalages;
		}

	}

endif;
