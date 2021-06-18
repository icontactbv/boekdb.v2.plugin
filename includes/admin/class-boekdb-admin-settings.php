<?php
/**
 * BoekDB Admin Settings Class
 *
 * @package BoekDB
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
			} elseif ( isset( $_POST['run'] ) && 'run' === $_POST['run'] ) {
				if ( ! boekdb_is_import_running() ) {
					wp_schedule_single_event( time(), BoekDB_Import::CRON_HOOK );
					boekdb_set_import_running();
				} else {
					self::add_error( 'Import draait al!' );
				}
			} elseif ( isset ( $_POST['test'] ) && 'test' === $_POST['test'] ) {
				// do a quick test of wp_remote_get
				$response = wp_remote_get( BoekDB_Import::BASE_URL . 'test' );
				if ( is_wp_error( $response ) ) {
					$error_message = $response->get_error_message();
					self::add_error( "Er is iets mis: $error_message" );
				} else {
					$code   = wp_remote_retrieve_response_code( $response );
					$result = json_decode( wp_remote_retrieve_body( $response ) );
					if ( $code === 200 && 'hello' === $result[0] ) {
						self::add_message( 'Connectie met BoekDB is ok.' );
					} else {
						self::add_error( 'Er is iets mis, response: ' . $code );
					}
				}
			} elseif ( isset ( $_POST['reset'] ) ) {
				$id = (int) $_POST['reset'];
				if ( $id > 0 ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}boekdb_etalages SET last_import=null WHERE id = %d",
						$id ) );

					self::add_message( 'Reset succesvol.' );
				}
			} elseif ( isset ( $_POST['delete'] ) ) {
				$id = (int) $_POST['delete'];
				if ( $id > 0 ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d",
						$id ) );

					self::add_message( 'Etalage is verwijderd.' );
				}
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
				$currently_running = boekdb_get_import_etalage();
				if ( $currently_running ) {
					if ( ! isset( $etalages[ $currently_running ] ) ) {
						boekdb_reset_import_running();
					} else {
						self::add_message( 'Er draait op dit moment een import (' . $etalages[ $currently_running ]->name . ')' );
					}
				} else {
					self::add_message( 'Import gestart...' );
				}
			}

			include __DIR__ . '/views/html-admin-settings.php';
		}

		public static function get_etalages() {
			global $wpdb;

			$etalage_result = $wpdb->get_results( "SELECT e.id, e.name, e.api_key, DATE_FORMAT(e.last_import, '%Y-%m-%d %H:%i:%s') as last_import, COUNT(eb.boek_id) as boeken FROM {$wpdb->prefix}boekdb_etalages e LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON e.id = eb.etalage_id GROUP BY e.id",
				OBJECT );
			$etalages       = array();
			foreach ( $etalage_result as $etalage ) {
				$etalages[ $etalage->id ] = $etalage;
			}

			return $etalages;
		}

	}

endif;
