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
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
        add_action(self::CRON_HOOK, array(self::class, 'import'));
    }

    public static function import()
    {
        $curl          = curl_init('http://boekdb.v2.test/api/json/v1/products?updated_at=2020-01-26T11%3A49%3A37%2B01%3A00');
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
        $products = json_decode($result);
        echo '<pre>';
        foreach ($products as $product) {
            $boek                        = array();
            $boek['titel']               = $product->titel;
            $boek['isbn']                = $product->isbn;
            $boek['subtitel']            = $product->subtitel;
            $boek['deeltitel']           = $product->deeltitel;
            $boek['sectietitel']         = $product->sectietitel;
            $boek['origineletitel']      = $product->origineletitel;
            $boek['serietitel']          = $product->serietitel;
            $boek['deel']                = $product->deel;
            $boek['druk']                = $product->druk;
            $boek['uitgever']            = $product->uitgever;
            $boek['imprint']             = $product->imprint;
            $boek['flaptekst']           = $product->flaptekst;
            $boek['annotatie']           = $product->annotatie;
            $boek['taal']                = $product->taal;
            $boek['illustraties']        = $product->illustraties;
            $boek['leeftijdscategorie']  = $product->leeftijdscategorie;
            $boek['avi']                 = $product->avi;
            $boek['eersteuitleverdatum'] = $product->eersteuitleverdatum;
            $boek['verschijningsdatum']  = $product->verschijningsdatum;
            $boek['prijs']               = $product->prijs;
            $boek['actieprijs']          = $product->actieprijs;
            $boek['actieperiode_start']  = $product->actieperiode_start;
            $boek['actieperiode_einde']  = $product->actieperiode_einde;

//            $boek['subtitle'] = (string)$book_element->SubTitel;
//            $boek['nur'] = (string)$book_element->Uitgave->Nurcode;
//            $boek['genre'] = (string)$book_element->Uitgave->Genre->Omschrijving;
//            $boek['imprint'] = $imprint;
//            $boek['siteimprint'] = (string)$book_element->Uitgave->Fonds->Imprint;
//            $boek['publicationdate'] = (string)$book_element->Uitgave->VerschijningsdatumEersteDruk;
//            $boek['editionpublicationdate'] = (string)$book_element->Uitgave->Druk->Verschijningsdatum;
//
//            if($boek['publicationdate'] !== '') {
//                $date = \DateTime::createFromFormat('Y-m-d', $boek['publicationdate']);
//                $boek['sortpubdate'] =  $date->format('Y-m-d');
//            }
//            if($boek['editionpublicationdate'] !== '') {
//                $date = \DateTime::createFromFormat('Y-m-d', $boek['publicationdate']);
//                $boek['sorteditiondate'] =  $date->format('Y-m-d');
//            }


            $post_id = null;
            $post    = array(
                'ID'          => $post_id,
                'post_status' => 'publish',
                'post_type'   => 'boekdb_boek',
                'post_title'  => $boek['titel'],
                'post_name'   => sanitize_title($boek['titel'])
            );

            // create/update post
            if (is_null($post_id)) {
                $post_id = wp_insert_post($post);
            } else {
                $post_id = wp_update_post($post);
            }

            foreach ($boek as $key => $value) {
                switch ($key) {
                    default:
                        update_post_meta($post_id, $key, $value);
                        break;
                }
            }
        }
        die();
    }

}

BoekDB_Import::init();
