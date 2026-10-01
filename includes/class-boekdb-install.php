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
		add_action( 'init', array( self::class, 'check_version' ), 5 );
		add_action( 'admin_notices', array( self::class, 'boekdb_update_notice' ) );
		add_action( 'admin_notices', array( self::class, 'boekdb_admin_notice' ) );
		add_action( 'wp_ajax_dismiss_boekdb_update_notice', array( self::class, 'dismiss_boekdb_update_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_scripts' ) );

		add_filter( 'rewrite_rules_array', array( self::class, 'prefixed_rewrite_rule' ) );

		// Schedule the version check event
		if ( ! wp_next_scheduled( 'boekdb_version_check' ) ) {
			wp_schedule_event( time(), 'daily', 'boekdb_version_check' );
		}

		add_action( 'boekdb_version_check', array( self::class, 'scheduled_version_check' ) );
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
		Boekdb_Api_Service::check_connection_and_version();
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

		if ( false === Boekdb_Api_Service::check_connection_and_version() ) {
			add_action( 'admin_notices', array( self::class, 'boekdb_connection_error' ) );
		}

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'boekdb_installing', 'yes', MINUTE_IN_SECONDS * 10 );
		define( 'BOEKDB_INSTALLING', true );

		self::update_boekdb_version();
		self::register_tables();
		self::migrate_primary_meta();

		flush_rewrite_rules();

		delete_transient( 'boekdb_installing' );
	}

	/**
	 * Move books marked under the old key to the one every reader uses.
	 *
	 * Books without an nstc were marked as the primary edition under an English key, while
	 * archives and templates filter on the Dutch one. Those books were missing from every
	 * overview. Runs on upgrade, and again changes nothing.
	 *
	 * @return void
	 */
	public static function migrate_primary_meta() {
		global $wpdb;

		// Collected before the rows go, to clear the cache of exactly these books afterwards.
		$post_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'boekdb_primary'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$wpdb->query(
			"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
				SELECT old.post_id, 'boekdb_primair', old.meta_value
				FROM {$wpdb->postmeta} old
				WHERE old.meta_key = 'boekdb_primary'
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} current
					WHERE current.post_id = old.post_id AND current.meta_key = 'boekdb_primair'
				)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'boekdb_primary'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
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
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// * Create the etalage table
		$table_name = $wpdb->prefix . 'boekdb_etalages';
		$sql        = "CREATE TABLE `$table_name` (
			 `id` INTEGER NOT NULL AUTO_INCREMENT,
			 `name` varchar(192) NOT NULL,
			 `api_key` varchar(192) NOT NULL,
			 `prefix` varchar(64) DEFAULT NULL,
			 `importing` tinyint(1) NOT NULL DEFAULT 0,
			 `offset` MEDIUMINT NOT NULL DEFAULT 0,
			 `isbns` MEDIUMINT NOT NULL DEFAULT 0,
			 `last_import` DATETIME DEFAULT NULL,
			 `filter_hash` varchar(192) DEFAULT NULL,
			 `running` tinyint(1) NOT NULL DEFAULT 0,
			 PRIMARY KEY (`id`)
			 ) $charset_collate;";
		dbDelta( $sql );

		// * Create the etalage table
		$table_name = $wpdb->prefix . 'boekdb_etalage_boeken';
		$sql        = "CREATE TABLE `$table_name` (
			 `etalage_id` INTEGER NOT NULL,
			 `boek_id` INTEGER NOT NULL,
			 PRIMARY KEY (`etalage_id`,`boek_id`)
			 ) $charset_collate;";
		dbDelta( $sql );

		// * Create the isbn table
		$table_name = $wpdb->prefix . 'boekdb_isbns';
		$sql        = "CREATE TABLE `$table_name` (
			 `boek_id` INTEGER NOT NULL,
			 `isbn` CHAR(13) NOT NULL,
			 PRIMARY KEY (`boek_id`,`isbn`)
			 ) $charset_collate;";
		dbDelta( $sql );
	}

	public static function boekdb_connection_error() {
		$class   = 'notice notice-warning is-dismissible';
		$message = '<strong>BoekDB Plugin:</strong> Let op: kan geen verbinding maken met BoekDB!';

		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	public static function boekdb_update_notice() {
		if ( get_option( 'boekdb_new_version_available', false ) ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>BoekDB Plugin:</strong> Er is een nieuwe versie van de plugin beschikbaar. Installeer deze binnenkort.</p>';
			echo '</div>';
		}
	}

	public static function dismiss_boekdb_update_notice() {
		check_ajax_referer( 'boekdb_dismiss_update_notice', 'nonce' );
		update_option( 'boekdb_new_version_available', false );
		wp_die();
	}

	public static function boekdb_admin_notice() {
		// If our transient isn't available, return early
		if ( false === ( $message = get_transient( 'boekdb_admin_notice' ) ) ) {
			return;
		}

		// delete the message transient
		delete_transient( 'boekdb_admin_notice' );

		// display the message
		echo '<div class="notice notice-info is-dismissible">';
		echo "<p>$message</p>";
		echo '</div>';
	}

	public static function enqueue_scripts() {
		wp_enqueue_script( 'boekdb-admin-scripts', plugins_url( 'assets/js/admin.js', BOEKDB_PLUGIN_FILE ), array( 'jquery' ), BoekDB()->version, true );
		wp_localize_script(
			'boekdb-admin-scripts',
			'boekdb_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'boekdb_dismiss_update_notice' ),
			)
		);
	}

	public static function prefixed_rewrite_rule( $rules ) {
		global $wpdb;
		$new_rules = array();

		// Fetch the etalage prefixes from the database
		$prefixes = $wpdb->get_results( "SELECT prefix FROM {$wpdb->prefix}boekdb_etalages WHERE prefix IS NOT NULL AND prefix != ''" );

		// Generate a rewrite rule for each etalage prefix
		foreach ( $prefixes as $prefix ) {
			$new_rules[ 'boek/' . esc_sql( $prefix->prefix ) . '/([^/]+)/?$' ] = 'index.php?post_type=boekdb_boek&name=' . '{$matches[1]}';
		}

		// Return the combined rules.
		return $new_rules + $rules;
	}
}

BoekDB_Install::init();
