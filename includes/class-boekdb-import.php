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
class BoekDB_Import {
	const CRON_HOOK = 'boekdb_import';

	/**
	 * Hook in tabs.
	 */
	public static function init() {
		// debug:
		//add_action( 'init', array( self::class, 'import' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
		add_action( self::CRON_HOOK, array( self::class, 'import' ) );
	}

	public static function import() {
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$curl          = curl_init( 'https://boekdbv2.nl/api/json/v1/products?updated_at=2020-01-26T11%3A49%3A37%2B01%3A00' );
		$authorization = "Authorization: Bearer j8mG6QORW04kgiEwH3G7hybmm0gEKU32dNUmyVtFGC08YXt9sRHlzkH8WTGkp7IJ";

		curl_setopt( $curl, CURLOPT_HTTPHEADER, array(
			'x-limit: 10',
			$authorization
		) );
		curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, "GET" );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, 1 );

		$result = curl_exec( $curl );
		curl_close( $curl );
		$products = json_decode( $result );

		foreach ( $products as $product ) {
			$boek                        = array();
			$boek['nstc']                = $product->nstc;
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

			$boek_post_id = self::find_field( 'boekdb_boek', 'boekdb_isbn', $boek['isbn'] );
			if ( ! is_null( $boek['nstc'] ) ) {
				$nstc = self::find_field( 'boekdb_boek', 'boekdb_nstc', $boek['nstc'] );
				if ( $nstc == $boek_post_id ) {
					$nstc = null;
				}
			}
			$post = array(
				'ID'          => $boek_post_id,
				'post_status' => 'publish',
				'post_type'   => 'boekdb_boek',
				'post_title'  => $boek['titel'],
				'post_name'   => sanitize_title( $boek['titel'] ),
				'post_parent' => $nstc,
			);

			// create/update post
			if ( is_null( $boek_post_id ) ) {
				$boek_post_id = wp_insert_post( $post );
			} else {
				$boek_post_id = wp_update_post( $post );
			}

			// save post meta
			foreach ( $boek as $key => $value ) {
				switch ( $key ) {
					default:
						update_post_meta( $boek_post_id, 'boekdb_' . $key, $value );
						break;
				}
			}

			foreach ( $product->bestanden as $bestand ) {
				if ( $bestand->soort === 'Cover' ) {
					$hash          = md5( $bestand->url );
					$attachment_id = self::find_field( 'attachment', 'hash', $hash );
					if ( is_null( $attachment_id ) ) {
						// delete old cover
						$cover_id = get_attached_media( 'image', $boek_post_id );
						$wp_upload_dir = wp_upload_dir();

						$get           = wp_safe_remote_get( $bestand->url );
						$type          = wp_remote_retrieve_header( $get, 'content-type' );
						$image         = wp_upload_bits( $bestand->bestandsnaam, null,
							wp_remote_retrieve_body( $get ) );
						$attachment    = array(
							'post_title'     => $bestand->bestandsnaam,
							'post_mime_type' => $type
						);

						$attachment_id = wp_insert_attachment( $attachment, $image['file'], $boek_post_id );
						$attachment_data = wp_generate_attachment_metadata($attachment_id, $wp_upload_dir['path'] . '/' . $bestand->bestandsnaam);

						wp_update_attachment_metadata($attachment_id, $attachment_data);

						update_post_meta($attachment_id, 'hash', $hash);
						update_post_meta($boek_post_id, '_thumbnail_id', $attachment_id);

					}
				}
			}

			$medewerker_term_ids = array();
			foreach ( $product->medewerkers as $medewerker ) {
				$boekdb_medewerker = array();
				/*
					 "id": 1,
	                "voornaam": "Ken",
	                "tussenvoegsel": null,
	                "achternaam": "Blanchard",
	                "organisatie": null,
	                "biografie": null,
	                "bibliografie": null,
	                "rol": "Auteur",
	                "bestanden": []
				 */
				$boekdb_medewerker['id']                   = $medewerker->id;
				$boekdb_medewerker['naam']                 = $medewerker->naam;
				$boekdb_medewerker['boekdb_voornaam']      = $medewerker->voornaam;
				$boekdb_medewerker['boekdb_tussenvoegsel'] = $medewerker->tussenvoegsel;
				$boekdb_medewerker['boekdb_achternaam']    = $medewerker->achternaam;
				$boekdb_medewerker['boekdb_organisatie']   = $medewerker->organisatie;
				$boekdb_medewerker['boekdb_biografie']     = $medewerker->biografie;
				$boekdb_medewerker['boekdb_bibliografie']  = $medewerker->bibliografie;

				$medewerker_post_id = self::find_field( 'boekdb_medewerker', 'boekdb_id', $medewerker->id );
				$post               = array(
					'ID'          => $medewerker_post_id,
					'post_status' => 'publish',
					'post_type'   => 'boekdb_medewerker',
					'post_title'  => $boekdb_medewerker['naam'],
					'post_name'   => sanitize_title( $boekdb_medewerker['naam'] ),
				);

				// create/update post
				if ( is_null( $medewerker_post_id ) ) {
					$medewerker_post_id = wp_insert_post( $post );
				} else {
					$medewerker_post_id = wp_update_post( $post );
				}

				// save post meta
				foreach ( $boekdb_medewerker as $key => $value ) {
					switch ( $key ) {
						default:
							update_post_meta( $medewerker_post_id, 'boekdb_' . $key, $value );
							break;
					}
				}

				$term = get_term_by( 'slug', $boekdb_medewerker['id'], 'boekdb_medewerker_tax' );
				if ( $term ) {
					$term_id = $term->term_id;
				} else {
					$result  = wp_insert_term(
						$medewerker->naam,
						'boekdb_medewerker_tax',
						array(
							'name' => $medewerker->naam,
							'slug' => (int) $boekdb_medewerker['id']
						) );
					$term_id = $result['term_id'];
				}
				$medewerker_term_ids[] = $term_id;
				wp_set_object_terms( $medewerker_post_id, $term_id, 'boekdb_medewerker_tax' );
				update_term_meta( $term_id, 'rol', $medewerker->rol );
			}
			wp_set_object_terms( $boek_post_id, $medewerker_term_ids, 'boekdb_medewerker_tax', false );
		}
	}


	private static function find_field(
		$post_type,
		$key,
		$value
	) {
		$args    = array(
			'post_type'   => $post_type,
			'post_status' => array( 'publish', 'draft', 'inherit' ),
			'meta_query'  => array(
				array(
					'key'   => $key,
					'value' => $value
				)
			)
		);
		$query   = new \WP_Query( $args );
		$post_id = null;
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
			}
		}
		wp_reset_postdata();

		return $post_id;
	}
}

BoekDB_Import::init();

