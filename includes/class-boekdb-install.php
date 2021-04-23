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
            //self::install();
            //do_action( 'boekdb_updated' );
        }
    }

}

BoekDB_Install::init();