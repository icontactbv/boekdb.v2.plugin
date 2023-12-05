<?php
/**
 * Installation related functions and actions.
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

/**
 * BoekDB_Install Class.
 */
class BoekDB_Install {
	/**
	 * Hook in tabs.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'display_update_notice' ) );

		// Schedule the version check event
		if ( ! wp_next_scheduled( 'boekdb_version_check' ) ) {
			wp_schedule_event( time(), 'daily', 'boekdb_version_check' );
		}

		add_action( 'boekdb_version_check', array( __CLASS__, 'scheduled_version_check' ) );
	}

	/**
	 * Check BoekDB version and run the updater is required.
	 *
	 * This check is done on all requests and runs if the versions do not match.
	 */
	public static function check_version() {
		if ( version_compare( get_option( 'boekdb_version' ), BoekDB()->version, '<' ) ) {
			self::install();
		}
	}

	/**
	 * This is a scheduled event to check the BoekDB version and display a notice if a upgrade is required.
	 */
	public static function scheduled_version_check() {
		self::test_connection();
	}

	/**
	 * Install BoekDB.
	 */
	public static function install() {
		if ( ! is_blog_installed() ) {
			return;
		}

		// Check if we are not already running this routine.
		if ( 'yes' === get_transient( 'boekdb_installing' ) ) {
			return;
		}

		if ( false === self::test_connection() ) {
			add_action( 'admin_notices', array( __CLASS__, 'boekdb_connection_error' ) );
		}

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'boekdb_installing', 'yes', MINUTE_IN_SECONDS * 10 );
		define( "BOEKDB_INSTALLING", true );

		self::update_boekdb_version();
		self::register_tables();

		flush_rewrite_rules();

		delete_transient( 'boekdb_installing' );
	}

	public static function test_connection() {
		$result = wp_remote_get( BoekDB_Import::BOEKDB_DOMAIN );

		// Check for connection errors
		if ( is_wp_error( $result ) || 200 !== wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}

		// Fetch the latest version from the API response
		$body = wp_remote_retrieve_body( $result );
		$data = json_decode( $body, true );
		$apiVersion = $data['plugin_version'] ?? null;

		error_log('Data: ' . $body);
		error_log('Decoded: ' . var_export($data, true));

		// debug
		$apiVersion = '1.1.0';
		error_log( 'API version: ' . $apiVersion );
		error_log( 'Plugin version: ' . BoekDB()->version );
		error_log( 'Compare: ' . version_compare( $apiVersion, BoekDB()->version, '>' ));

		// Compare with current plugin version and set/update the option if a new version is available
		if ( $apiVersion && version_compare( $apiVersion, BoekDB()->version, '>' ) ) {
			update_option( 'boekdb_new_version_available', true );
		} else {
			delete_option( 'boekdb_new_version_available' );
		}

		return true;
	}

	/**
	 * Update BoekDB version to current.
	 */
	private static function update_boekdb_version() {
		update_option( 'boekdb_version', BoekDB()->version );
	}

	private static function register_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		//* Create the etalage table
		$table_name = $wpdb->prefix . 'boekdb_etalages';
		$sql        = "CREATE TABLE `$table_name` (
			 `id` INTEGER NOT NULL AUTO_INCREMENT,
			 `name` varchar(192) NOT NULL,
			 `api_key` varchar(192) NOT NULL,
			 `importing` tinyint(1) NOT NULL DEFAULT 0,
			 `offset` MEDIUMINT NOT NULL DEFAULT 0,
			 `isbns` MEDIUMINT NOT NULL DEFAULT 0,
			 `last_import` DATETIME DEFAULT NULL,
			 `filter_hash` varchar(192) DEFAULT NULL,
			 `running` tinyint(1) NOT NULL DEFAULT 0,
			 PRIMARY KEY (`id`)
			 ) $charset_collate;";
		dbDelta( $sql );

		//* Create the etalage table
		$table_name = $wpdb->prefix . 'boekdb_etalage_boeken';
		$sql        = "CREATE TABLE `$table_name` (
			 `etalage_id` INTEGER NOT NULL,
			 `boek_id` INTEGER NOT NULL,
			 PRIMARY KEY (`etalage_id`,`boek_id`)
			 ) $charset_collate;";
		dbDelta( $sql );

		//* Create the isbn table
		$table_name = $wpdb->prefix . 'boekdb_isbns';
		$sql        = "CREATE TABLE `$table_name` (
			 `boek_id` INTEGER NOT NULL,
			 `isbn` CHAR(13) NOT NULL,
			 PRIMARY KEY (`boek_id`,`isbn`)
			 ) $charset_collate;";
		dbDelta( $sql );
	}

	public static function boekdb_connection_error() {
		$class   = 'notice notice-warning';
		$message = 'Let op: kan geen verbinding maken met BoekDB!';

		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	public static function display_update_notice() {
		if ( get_option( 'boekdb_new_version_available', false ) ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>BoekDB Plugin:</strong> Er is een nieuwe versie van de plugin beschikbaar. Installeer deze binnenkort.</p>';
			echo '</div>';
		}
	}

}

BoekDB_Install::init();