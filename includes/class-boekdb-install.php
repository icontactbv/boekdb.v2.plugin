<?php
/**
 * Installation related functions and actions.
 *
 * @package BoekDB\Classes
 * @version 0.0.1
 */

defined('ABSPATH') || exit;

/**
 * BoekDB_Install Class.
 */
class BoekDB_Install
{
    /**
     * Hook in tabs.
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'check_version'), 5);
    }

    /**
     * Check BoekDB version and run the updater is required.
     *
     * This check is done on all requests and runs if the versions do not match.
     */
    public static function check_version()
    {
        if (version_compare(get_option('boekdb_version'), BoekDB()->version, '<')) {
            self::install();
            //do_action( 'boekdb_updated' );
        }
    }

    /**
     * Install BoekDB.
     */
    public static function install()
    {
        if (!is_blog_installed()) {
            return;
        }

        // Check if we are not already running this routine.
        if ('yes' === get_transient('boekdb_installing')) {
            return;
        }

        // If we made it till here nothing is running yet, lets set the transient now.
        set_transient('boekdb_installing', 'yes', MINUTE_IN_SECONDS * 10);
        boekdb_maybe_define_constant( 'BOEKDB_INSTALLING', true );

        self::update_boekdb_version();

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
}


BoekDB_Install::init();