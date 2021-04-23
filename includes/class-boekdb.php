<?php
/**
 * BoekDB setup
 *
 * @package BoekDB
 */

defined('ABSPATH') || exit;


final class BoekDB
{
    /**
     * The single instance of the class.
     *
     * @var BoekDB
     */
    protected static $_instance = null;

    /**
     * Main BoekDB Instance.
     *
     * Ensures only one instance of BoekDB is loaded or can be loaded.
     *
     * @static
     * @return BoekDB - Main instance.
     * @see    BD()
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
}