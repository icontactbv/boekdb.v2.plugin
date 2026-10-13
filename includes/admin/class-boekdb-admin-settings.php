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
			// This runs on wp_loaded, which fires on every request and before wp-admin asks
			// anyone to log in, so who is asking has to be settled here.
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'boekdb-settings' ) ) {
				return;
			}

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
					BoekDB_Import::resume_import();
				} else {
					delete_option( BoekDB_Import::STOPPED_OPTION );
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
			BoekDB_Import::stop_import();
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

			// Opening the screen gets an import going again that nothing would pick up
			// before the hourly start.
			$boekdb_scheduled = BoekDB_Import::ensure_next_batch();
			$boekdb_status    = BoekDB_Import::status();
			$boekdb_notices   = self::status_notices( $boekdb_status, $boekdb_scheduled );

			include __DIR__ . '/views/html-admin-settings.php';
		}

		/**
		 * What the banner above the etalages says about the import.
		 *
		 * @param array $status     What BoekDB_Import::status() returns.
		 * @param bool  $scheduled  Whether opening the screen had to schedule the next batch.
		 *
		 * @return array Notices, each with a type (info or warning) and a text.
		 */
		public static function status_notices( $status, $scheduled = false ) {
			$notices = array();
			$waiting = 0;

			foreach ( $status['etalages'] as $etalage ) {
				if ( 'busy' === $etalage['state'] ) {
					if ( $etalage['size'] > 0 ) {
						$text = sprintf( 'Bezig met %s: boek %d van %d in de batch vanaf boek %d.', $etalage['name'], $etalage['done'], $etalage['size'], $etalage['offset'] + 1 );
					} else {
						$text = sprintf( 'Bezig met %s: de rest van de etalage is binnen, de overgeslagen boeken worden opnieuw geprobeerd.', $etalage['name'] );
					}

					$seconds   = max( 0, time() - $etalage['heartbeat'] );
					$notices[] = array(
						'type' => 'info',
						'text' => $text . ( 1 === $seconds ? ' Laatste teken van leven: 1 seconde geleden.' : sprintf( ' Laatste teken van leven: %d seconden geleden.', $seconds ) ),
					);
				} elseif ( 'stuck' === $etalage['state'] ) {
					$notices[] = array(
						'type' => 'warning',
						'text' => is_null( $etalage['heartbeat'] )
							? sprintf( '%s lijkt vastgelopen en wordt bij de volgende batch hervat.', $etalage['name'] )
							: sprintf( '%s lijkt vastgelopen sinds %s en wordt bij de volgende batch hervat.', $etalage['name'], wp_date( 'H:i', $etalage['heartbeat'] ) ),
					);
				} elseif ( 'waiting' === $etalage['state'] ) {
					++$waiting;
				}
			}

			if ( $waiting > 0 ) {
				$text = 1 === $waiting ? '1 etalage wacht' : sprintf( '%d etalages wachten', $waiting );

				if ( $scheduled ) {
					$notices[] = array(
						'type' => 'warning',
						'text' => $text . ', maar er stond geen batch ingepland. Die is nu ingepland.',
					);
				} elseif ( $status['stopped'] ) {
					$notices[] = array(
						'type' => 'info',
						'text' => $text . '. De import is gestopt, tot iemand op Draai import drukt.',
					);
				} elseif ( $status['next_batch'] ) {
					$notices[] = array(
						'type' => 'info',
						'text' => $text . '. Volgende batch: ' . ( $status['next_batch'] <= time() + MINUTE_IN_SECONDS ? 'binnen een minuut.' : 'om ' . wp_date( 'H:i', $status['next_batch'] ) . '.' ),
					);
				} else {
					$notices[] = array(
						'type' => 'info',
						'text' => $text . '.',
					);
				}
			}

			if ( count( $notices ) === 0 ) {
				$notices[] = array(
					'type' => 'info',
					'text' => 'Geen import bezig.' . ( $status['next_round'] ? ' Volgende ronde om ' . wp_date( 'H:i', $status['next_round'] ) . '.' : '' ),
				);
			}

			return $notices;
		}

		/**
		 * What the Voortgang column says about an etalage.
		 *
		 * @param array $etalage  The etalage as BoekDB_Import::status() describes it.
		 *
		 * @return string
		 */
		public static function progress_text( $etalage ) {
			switch ( $etalage['state'] ) {
				case 'busy':
					return $etalage['size'] > 0
						? sprintf( 'boek %d van %d (vanaf %d)', $etalage['done'], $etalage['size'], $etalage['offset'] + 1 )
						: 'rondt af';
				case 'stuck':
					return 'vastgelopen';
				case 'waiting':
					return $etalage['offset'] > 0 ? sprintf( 'verder vanaf boek %d', $etalage['offset'] + 1 ) : 'wacht';
				default:
					return '–';
			}
		}

		/**
		 * What the Status column says about an etalage.
		 *
		 * @param array $etalage  The etalage as BoekDB_Import::status() describes it.
		 *
		 * @return string
		 */
		public static function state_text( $etalage ) {
			switch ( $etalage['state'] ) {
				case 'busy':
					return 'Bezig';
				case 'stuck':
					return 'Vastgelopen';
				case 'waiting':
					return 'Wacht';
				default:
					return is_null( $etalage['finished'] ) ? '–' : 'Klaar';
			}
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
