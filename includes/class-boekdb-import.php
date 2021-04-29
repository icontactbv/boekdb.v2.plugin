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
class BoekDB_Import
{
    const CRON_HOOK = 'boekdb_import';

    /**
     * Hook in tabs.
     */
    public static function init()
    {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK);
        }
        add_action(self::CRON_HOOK, array(self::class, 'import'));
    }

    public static function import()
    {
        $curl = curl_init('http://boekdb.v2.test/api/json/v1/products?updated_at=2020-01-26T11%3A49%3A37%2B01%3A00');
        $authorization = "Authorization: Bearer j8mG6QORW04kgiEwH3G7hybmm0gEKU32dNUmyVtFGC08YXt9sRHlzkH8WTGkp7IJ";

        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'x-limit: 500',
            $authorization
        ));
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);

        $result = curl_exec($curl);
        curl_close($curl);
        var_dump(json_decode($result));
        die();

    }

}

BoekDB_Import::init();
