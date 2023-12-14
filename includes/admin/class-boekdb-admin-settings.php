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
			if ( isset( $_POST['save'] ) ) {
				self::save_etalage();
			} elseif ( isset( $_POST['run'] ) ) {
				self::run_import();
			} elseif ( isset( $_POST['test'] ) ) {
				self::test_connection();
			} elseif ( isset( $_POST['stop'] ) ) {
				self::stop_import();
			} elseif ( isset( $_POST['cleanup'] ) ) {
				self::start_cleanup();
			} elseif ( isset( $_POST['reset'] ) ) {
				self::reset_etalage();
			} elseif ( isset( $_POST['delete'] ) ) {
				self::delete_etalage();
			}
		}

		/**
		 * Run the import process.
		 *
		 * If import is not already running, either start it immediately or schedule it for execution after 5 seconds.
		 * If import is already running, add an error message.
		 *
		 * @access private
		 * @return void
		 */
		private static function run_import() {
			if ( ! boekdb_is_import_running() ) {
				if ( WP_DEBUG ) {
					BoekDB_Import::start_import();
				} else {
					wp_schedule_single_event( time() + 5, BoekDB_Import::START_IMPORT_HOOK );
				}
			} else {
				self::add_error( 'Import draait al!' );
			}
		}

		/**
		 * Test the connection to the API and handle the response.
		 *
		 * This method tests the connection to the API using the `test_api_connection()` method of the `Boekdb_Api_Service` class. It handles the response based on the success status.
		 *
		 * @return void
		 */
		private static function test_connection() {
			$testResponse = Boekdb_Api_Service::test_api_connection();
			if ( ! $testResponse['success'] ) {
				self::add_error( $testResponse['message'] );
			} else {
				self::add_message( $testResponse['message'] );
			}
		}

		/**
		 * Stop the import process.
		 *
		 * This method stops the import process by resetting the offset and running status of the boekdb_etalages table in the database.
		 * `.
		 *
		 * @return void
		 */
		private static function stop_import() {
			global $wpdb;

			$wpdb->query( "UPDATE {$wpdb->prefix}boekdb_etalages SET offset=0, running=0" );
		}

		/**
		 * Saves a new etalage to the database.
		 *
		 * @return void
		 */
		private static function save_etalage() {
			global $wpdb;

			$api_key = sanitize_text_field( $_POST['etalage_api_key'] );
			$name    = sanitize_text_field( $_POST['etalage_name'] );
			$prefix  = strtolower( sanitize_text_field( $_POST['etalage_prefix'] ) );

			if ( strlen( $api_key ) === 0 || strlen( $name ) === 0 ) {
				self::add_error( 'Er is iets fout gegaan' );
			} elseif ( ! Boekdb_Api_Service::validate_api_key( $api_key ) ) {
				self::add_error( 'API key is niet geldig' );
			} elseif ( ! empty( $prefix ) || $prefix === 'default' ) {
				// Check if the prefix already exists
				$existing_prefix = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT prefix FROM {$wpdb->prefix}boekdb_etalages WHERE prefix = %s",
						$prefix
					)
				);

				// If the prefix already exists, do not add new etalage and return error message
				if ( $existing_prefix !== null ) {
					self::add_error( 'De opgegeven prefix bestaat al!' );

					return;
				}
			}

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}boekdb_etalages (`name`, `api_key`, `prefix`) VALUES (%s, %s, %s)",
					$name,
					$api_key,
					$prefix
				)
			);

			// Flush rewrite rules after the changes made in the settings so that it takes effect immediately
			flush_rewrite_rules();
			self::add_message( 'Etalage opgeslagen.' );
		}

		/**
		 * Starts the cleanup process.
		 *
		 * If WP_DEBUG is enabled, the cleanup will be performed immediately using the BoekDB_Cleanup::cleanup() method.
		 * If WP_DEBUG is disabled, a single event will be scheduled to run the cleanup after 5 seconds using the BoekDB_Cleanup::CLEANUP_HOOK.
		 *
		 * @return void
		 */
		private static function start_cleanup() {
			if ( WP_DEBUG ) {
				BoekDB_Cleanup::cleanup();
			} else {
				wp_schedule_single_event( time() + 5, BoekDB_Cleanup::CLEANUP_HOOK );
			}
			self::add_message( 'Opruimen gestart' );
		}

		/**
		 * Resets the last import date of an etalage in the database.
		 *
		 * @return void
		 */
		private static function reset_etalage() {
			global $wpdb;
			$id = (int) $_POST['reset'];
			if ( $id > 0 ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}boekdb_etalages SET last_import=null WHERE id = %d",
						$id
					)
				);

				self::add_message( 'Reset succesvol.' );
			}
		}

		/**
		 * Deletes an etalage from the database.
		 *
		 * @return void
		 */
		private static function delete_etalage() {
			$id = (int) $_POST['delete'];
			if ( $id > 0 ) {
				BoekDB_Cleanup::delete_etalage( $id );
				set_transient( 'boekdb_admin_notice', 'Etalage is verwijderd.', 60 );
			}
		}

		/**
		 * Add a message.
		 *
		 * @param  string $text  Message.
		 */
		public static function add_message( $text ) {
			self::$messages[] = $text;
		}

		/**
		 * Add an error.
		 *
		 * @param  string $text  Message.
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

		/**
		 * Outputs the administration settings page.
		 *
		 * @return void
		 */
		public static function output() {
			$boekdb_etalages = self::get_etalages();
			$import_running  = boekdb_is_import_running();
			if ( $import_running ) {
				self::add_message( 'Er draait op dit moment een import' );
			}

			include __DIR__ . '/views/html-admin-settings.php';
		}

		/**
		 * Retrieves all the etalages from the database.
		 *
		 * @return array An associative array of etalages where the key is the etalage ID and the value is the etalage object.
		 */
		public static function get_etalages() {
			global $wpdb;

			$etalage_result = $wpdb->get_results(
				"SELECT e.id, e.name, e.prefix, e.api_key, DATE_FORMAT(e.last_import, '%Y-%m-%d %H:%i:%s') as last_import, e.isbns, e.running, e.offset, COUNT(eb.boek_id) as boeken FROM {$wpdb->prefix}boekdb_etalages e LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON e.id = eb.etalage_id GROUP BY e.id",
				OBJECT
			);
			$etalages       = array();
			foreach ( $etalage_result as $etalage ) {
				$etalages[ $etalage->id ] = $etalage;
			}

			return $etalages;
		}
	}

endif;
