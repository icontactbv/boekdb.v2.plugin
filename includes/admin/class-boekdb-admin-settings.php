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
			$test_response = Boekdb_Api_Service::test_api_connection();
			if ( ! $test_response['success'] ) {
				self::add_error( $test_response['message'] );
			} else {
				self::add_message( $test_response['message'] );
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

			// WordPress slashes the form data. Unslashed, or a name with a quote in it is stored
			// with a backslash.
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- save() checked the nonce.
			$api_key = sanitize_text_field( wp_unslash( isset( $_POST['etalage_api_key'] ) ? $_POST['etalage_api_key'] : '' ) );
			$name    = sanitize_text_field( wp_unslash( isset( $_POST['etalage_name'] ) ? $_POST['etalage_name'] : '' ) );
			$prefix  = strtolower( sanitize_text_field( wp_unslash( isset( $_POST['etalage_prefix'] ) ? $_POST['etalage_prefix'] : '' ) ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			// Each error ends here. The etalage used to be stored anyway, under the error.
			if ( strlen( $api_key ) === 0 || strlen( $name ) === 0 ) {
				self::add_error( 'Er is iets fout gegaan' );

				return;
			}

			try {
				$valid = Boekdb_Api_Service::validate_api_key( $api_key );
			} catch ( Exception $e ) {
				// BoekDB could not be reached. Uncaught, this ended the request in a fatal error.
				self::add_error( 'Geen verbinding met BoekDB, de etalage is niet opgeslagen.' );

				return;
			}

			if ( ! $valid ) {
				self::add_error( 'API key is niet geldig' );

				return;
			}

			if ( ! empty( $prefix ) ) {
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
			$id = isset( $_POST['reset'] ) ? (int) $_POST['reset'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() checked the nonce.
			if ( $id > 0 ) {
				$wpdb->query(
					$wpdb->prepare(
						// Stop keeps the offset, so a reset that left it would skip the books before it.
						"UPDATE {$wpdb->prefix}boekdb_etalages SET last_import=null, `offset`=0 WHERE id = %d",
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
			$id = isset( $_POST['delete'] ) ? (int) $_POST['delete'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() checked the nonce.
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
						/* translators: 1: etalage name, 2: book being worked on, 3: books in the batch, 4: first book of the batch. */
						$text = sprintf( __( 'Bezig met %1$s: boek %2$d van %3$d in de batch vanaf boek %4$d.', 'boekdb' ), $etalage['name'], $etalage['done'], $etalage['size'], $etalage['offset'] + 1 );
					} else {
						/* translators: %s: etalage name. */
						$text = sprintf( __( 'Bezig met %s: de rest van de etalage is binnen, de overgeslagen boeken worden opnieuw geprobeerd.', 'boekdb' ), $etalage['name'] );
					}

					$seconds   = max( 0, time() - $etalage['heartbeat'] );
					$notices[] = array(
						'type' => 'info',
						/* translators: %d: seconds since the batch last reported in. */
						'text' => $text . ' ' . sprintf( _n( 'Laatste teken van leven: %d seconde geleden.', 'Laatste teken van leven: %d seconden geleden.', $seconds, 'boekdb' ), $seconds ),
					);
				} elseif ( 'stuck' === $etalage['state'] ) {
					$notices[] = array(
						'type' => 'warning',
						'text' => is_null( $etalage['heartbeat'] )
							/* translators: %s: etalage name. */
							? sprintf( __( '%s lijkt vastgelopen en wordt bij de volgende batch hervat.', 'boekdb' ), $etalage['name'] )
							/* translators: 1: etalage name, 2: time it last reported in. */
							: sprintf( __( '%1$s lijkt vastgelopen sinds %2$s en wordt bij de volgende batch hervat.', 'boekdb' ), $etalage['name'], wp_date( 'H:i', $etalage['heartbeat'] ) ),
					);
				} elseif ( 'waiting' === $etalage['state'] ) {
					++$waiting;
				}
			}

			if ( $waiting > 0 ) {
				/* translators: %d: number of waiting etalages. */
				$text = sprintf( _n( '%d etalage wacht', '%d etalages wachten', $waiting, 'boekdb' ), $waiting );

				if ( $scheduled ) {
					$notices[] = array(
						'type' => 'warning',
						/* translators: %s: how many etalages wait. */
						'text' => sprintf( __( '%s, maar er stond geen batch ingepland. Die is nu ingepland.', 'boekdb' ), $text ),
					);
				} elseif ( $status['stopped'] ) {
					$notices[] = array(
						'type' => 'info',
						/* translators: %s: how many etalages wait. */
						'text' => sprintf( __( '%s. De import is gestopt, tot iemand op Draai import drukt.', 'boekdb' ), $text ),
					);
				} elseif ( $status['next_batch'] && $status['next_batch'] <= time() + MINUTE_IN_SECONDS ) {
					$notices[] = array(
						'type' => 'info',
						/* translators: %s: how many etalages wait. */
						'text' => sprintf( __( '%s. Volgende batch: binnen een minuut.', 'boekdb' ), $text ),
					);
				} elseif ( $status['next_batch'] ) {
					$notices[] = array(
						'type' => 'info',
						/* translators: 1: how many etalages wait, 2: time of the next batch. */
						'text' => sprintf( __( '%1$s. Volgende batch: om %2$s.', 'boekdb' ), $text, wp_date( 'H:i', $status['next_batch'] ) ),
					);
				} else {
					$notices[] = array(
						'type' => 'info',
						'text' => $text . '.',
					);
				}
			}

			if ( count( $notices ) === 0 && $status['stopped'] ) {
				// Every etalage is idle after Stop, and the hourly round does nothing until
				// the import is started again.
				$notices[] = array(
					'type' => 'warning',
					'text' => __( 'De import is gestopt en draait niet tot iemand op Draai import drukt.', 'boekdb' ),
				);
			}

			if ( count( $notices ) === 0 ) {
				$notices[] = array(
					'type' => 'info',
					'text' => $status['next_round']
						/* translators: %s: time of the next round. */
						? sprintf( __( 'Geen import bezig. Volgende ronde om %s.', 'boekdb' ), wp_date( 'H:i', $status['next_round'] ) )
						: __( 'Geen import bezig.', 'boekdb' ),
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
						/* translators: 1: book being worked on, 2: books in the batch, 3: first book of the batch. */
						? sprintf( __( 'boek %1$d van %2$d (vanaf %3$d)', 'boekdb' ), $etalage['done'], $etalage['size'], $etalage['offset'] + 1 )
						: __( 'rondt af', 'boekdb' );
				case 'stuck':
					return __( 'vastgelopen', 'boekdb' );
				case 'stopped':
					/* translators: %d: book the import stopped at. */
					return sprintf( __( 'gestopt bij boek %d', 'boekdb' ), $etalage['offset'] + 1 );
				case 'waiting':
					return $etalage['offset'] > 0
						/* translators: %d: book the next batch starts at. */
						? sprintf( __( 'verder vanaf boek %d', 'boekdb' ), $etalage['offset'] + 1 )
						: __( 'wacht', 'boekdb' );
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
					return __( 'Bezig', 'boekdb' );
				case 'stuck':
					return __( 'Vastgelopen', 'boekdb' );
				case 'waiting':
					return __( 'Wacht', 'boekdb' );
				case 'stopped':
					return __( 'Gestopt', 'boekdb' );
				default:
					return is_null( $etalage['finished'] ) ? '–' : __( 'Klaar', 'boekdb' );
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
