<?php
/**
 * Installation related functions and actions.
 *
 * @package BoekDB\Classes
 * @version 0.0.1
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
			//do_action( 'boekdb_updated' );
		}
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

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'boekdb_installing', 'yes', MINUTE_IN_SECONDS * 10 );
		define( "BOEKDB_INSTALLING", true );

		self::update_boekdb_version();
		self::register_tables();

		delete_transient( 'boekdb_installing' );

		//do_action( 'boekdb_flush_rewrite_rules' );
		//do_action( 'boekdb_installed' );
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
		$sql        = "CREATE TABLE $table_name (
			 etalage_id INTEGER NOT NULL AUTO_INCREMENT,
			 etalage_name varchar(192) NOT NULL,
			 etalage_key varchar(192) NOT NULL,
			 PRIMARY KEY (etalage_id)
			 ) $charset_collate;";
		dbDelta( $sql );

		//* Create the etalage table
		$table_name = $wpdb->prefix . 'boekdb_etalage_boeken';
		$sql        = "CREATE TABLE $table_name (
    		 id INTEGER NOT NULL AUTO_INCREMENT,
			 etalage_id INTEGER NOT NULL,
			 boek_id INTEGER NOT NULL,
			 PRIMARY KEY (id)
			 ) $charset_collate;";
		dbDelta( $sql );

	}
}


BoekDB_Install::init();