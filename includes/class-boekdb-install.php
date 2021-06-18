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

	public static function test_connection() {
		$result = wp_remote_get( BoekDB_Import::BOEKDB_DOMAIN );
		if ( ! is_wp_error( $result ) && 200 !== wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}

		return true;
	}

	public static function boekdb_connection_error() {
		$class   = 'notice notice-warning';
		$message = 'Let op: kan geen verbinding maken met BoekDB!';

		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
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

		delete_transient( 'boekdb_installing' );
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
			 `last_import` DATETIME DEFAULT NULL,
			 `filter_hash` varchar(192) DEFAULT NULL,
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
}


BoekDB_Install::init();