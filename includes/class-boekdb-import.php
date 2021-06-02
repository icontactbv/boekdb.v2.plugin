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
	const CRON_HOOK           = 'boekdb_import';
	const BASE_URL            = 'https://boekdbv2.nl/api/json/v1/products';
	const LIMIT               = 250;
	const DEFAULT_LAST_IMPORT = "2015-01-01T01:00:00+01:00";


	/**
	 * Hook in tabs.
	 */
	public static function init() {
		// debug:
		add_action( 'init', array( self::class, 'import' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
		add_action( self::CRON_HOOK, array( self::class, 'import' ) );
	}

	public static function import() {
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$etalages = self::fetch_etalages();
		foreach ( $etalages as $etalage ) {
			$offset      = 0;
			$last_import = $etalage->last_import;
			if ( is_null( $last_import ) ) {
				$last_import = self::DEFAULT_LAST_IMPORT;
			}
			$last_import = new DateTime($last_import, wp_timezone());
			$last_import = $last_import->format('Y-m-d\TH:i:sP');

			while ( $products = self::fetch_products( $etalage->api_key, $last_import, $offset ) ) {
				foreach ( $products as $product ) {
					$boek_post_id = self::handle_boek( $product );
					self::handle_betrokkenen( $product, $boek_post_id );
					foreach ( $product->onderwerpen as $onderwerp ) {
						if ( $onderwerp->type === 'NUR' || $onderwerp->type === 'BISAC' ) {
							$term_id = self::get_taxonomy_term_id( $onderwerp->code, strtolower( $onderwerp->type ),
								$onderwerp->waarde );
							wp_set_object_terms( $boek_post_id, $term_id,
								'boekdb_' . strtolower( $onderwerp->type ) . '_tax' );
						} elseif ( substr( $onderwerp->type, 0, 5 ) === 'Thema' ) {
							$term_id = self::get_taxonomy_term_id( $onderwerp->code, 'thema', $onderwerp->waarde );
							wp_set_object_terms( $boek_post_id, $term_id, 'boekdb_thema_tax' );
						}
					}
					self::link_product_to_etalage( $boek_post_id, $etalage->id );
				}
				$offset = $offset + self::LIMIT;
			}
			self::set_last_import( $etalage->id );
		}
	}

	private static function link_product_to_etalage( $boek_id, $etalage_id ) {
		global $wpdb;

		return $wpdb->replace($wpdb->prefix . 'boekdb_etalage_boeken', array(
			'etalage_id' => $etalage_id,
			'boek_id' => $boek_id,
		));
	}

	private static function set_last_import( $id ) {
		global $wpdb;
		return $wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'last_import' => current_time( 'mysql', 1 ) ),
			array( 'id' => $id ) );
	}

	private static function fetch_etalages() {
		global $wpdb;

		$etalages = $wpdb->get_results( "SELECT id, api_key, DATE_FORMAT(last_import, '%Y-%m-%d\T%H:%i:%s\+01:00') as last_import FROM {$wpdb->prefix}boekdb_etalages",
			OBJECT );

		return $etalages;
	}

	private static function find_field( $post_type, $key, $value ) {
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

	/**
	 * Fetch from BoekDB
	 *
	 * @return array|boolean
	 */
	protected static function fetch_products( $api_key, $last_import, $offset ) {
		$curl          = curl_init( self::BASE_URL . '?updated_at=' . urlencode( $last_import ) );
		$authorization = "Authorization: Bearer " . $api_key;

		curl_setopt( $curl, CURLOPT_HTTPHEADER, array(
			'x-limit: ' . self::LIMIT,
			'x-offset: ' . $offset,
			$authorization
		) );
		curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, "GET" );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, 1 );

		// curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(__class__, "handle_header"));

		$result = curl_exec( $curl );
		curl_close( $curl );

		$products = json_decode( $result );
		if ( ! is_array( $products ) ) {
			return false;
		}

		return $products;
	}

	/**
	 * Create boek array from product
	 *
	 * @param $product
	 *
	 * @return array
	 */
	protected static function create_boek_array( $product ) {
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
		$boek['verschijningsvorm']   = $product->verschijningsvorm;
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

		return $boek;
	}

	/**
	 * Create betrokkene array from contributor
	 *
	 * @param $betrokkene
	 *
	 * @return array
	 */
	protected static function create_betrokkene_array( $betrokkene ) {
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
		$boekdb_betrokkene                         = array();
		$boekdb_betrokkene['id']                   = $betrokkene->id;
		$boekdb_betrokkene['naam']                 = $betrokkene->naam;
		$boekdb_betrokkene['boekdb_voornaam']      = $betrokkene->voornaam;
		$boekdb_betrokkene['boekdb_tussenvoegsel'] = $betrokkene->tussenvoegsel;
		$boekdb_betrokkene['boekdb_achternaam']    = $betrokkene->achternaam;
		$boekdb_betrokkene['boekdb_organisatie']   = $betrokkene->organisatie;
		$boekdb_betrokkene['boekdb_biografie']     = $betrokkene->biografie;
		$boekdb_betrokkene['boekdb_bibliografie']  = $betrokkene->bibliografie;

		return $boekdb_betrokkene;
	}

	/**
	 * Load files from boek
	 *
	 * @param $product
	 * @param $boek_post_id
	 */
	protected static function handle_boek_files( $product, $boek_post_id ) {
		if ( ! isset( $product->bestanden ) ) {
			return;
		}
		foreach ( $product->bestanden as $bestand ) {
			if ( $bestand->soort === 'Cover' ) {
				$hash          = md5( $bestand->url );
				$attachment_id = self::find_field( 'attachment', 'hash', $hash );
				if ( is_null( $attachment_id ) ) {
					// delete old cover
					// $cover_id      = get_attached_media( 'image', $boek_post_id );
					$get   = wp_safe_remote_get( $bestand->url );
					$type  = wp_remote_retrieve_header( $get, 'content-type' );
					$image = wp_upload_bits( $bestand->bestandsnaam, null, wp_remote_retrieve_body( $get ) );

					$attachment = array(
						'post_title'     => $bestand->bestandsnaam,
						'post_mime_type' => $type
					);

					$attachment_id   = wp_insert_attachment( $attachment, $image['file'], $boek_post_id );
					$wp_upload_dir   = wp_upload_dir();
					$attachment_data = wp_generate_attachment_metadata( $attachment_id,
						$wp_upload_dir['path'] . '/' . $bestand->bestandsnaam );

					wp_update_attachment_metadata( $attachment_id, $attachment_data );

					update_post_meta( $attachment_id, 'hash', $hash );
					update_post_meta( $boek_post_id, '_thumbnail_id', $attachment_id );
				}
			}
		}
	}

	/**
	 * Load the boek and return the post_id
	 *
	 * @param $product
	 *
	 * @return integer
	 */
	protected static function handle_boek( $product ) {
		$boek = self::create_boek_array( $product );

		$boek_post_id = self::find_field( 'boekdb_boek', 'boekdb_isbn', $boek['isbn'] );

		$nstc = null;
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

		self::handle_boek_files( $product, $boek_post_id );

		return $boek_post_id;
	}

	/**
	 * Get taxonomy term_id
	 *
	 * @param $slug
	 * @param $taxonomy
	 * @param $value
	 *
	 * @return int|mixed
	 */
	protected static function get_taxonomy_term_id( $slug, $taxonomy, $value ) {
		$term = get_term_by( 'slug', $slug, 'boekdb_' . $taxonomy . '_tax' );
		if ( $term ) {
			$term_id = $term->term_id;
		} else {
			$result  = wp_insert_term(
				$value,
				'boekdb_' . $taxonomy . '_tax',
				array(
					'name' => $value,
					'slug' => $slug,
				) );
			$term_id = $result['term_id'];
		}

		return $term_id;
	}

	/**
	 * Parse contributors
	 *
	 * @param $product
	 * @param $boek_post_id
	 */
	protected static function handle_betrokkenen( $product, $boek_post_id ) {
		$term_ids = array(
			'auteur'      => array(),
			'illustrator' => array(),
			'spreker'     => array(),
		);

		$betrokkenen_meta[] = array();

		foreach ( $product->betrokkenen as $betrokkene ) {
			$boekdb_betrokkene = self::create_betrokkene_array( $betrokkene );
			$rol               = strtolower( $betrokkene->rol );
			if ( $rol === 'voorlezer' || $rol === 'verteller' ) {
				$rol = 'spreker';
			}

			if ( $rol === 'auteur' || $rol === 'illustrator' || $rol === 'spreker' ) {
				$betrokkene_post_id = self::find_field( 'boekdb_betrokkene', 'boekdb_id', $betrokkene->id );
				$post               = array(
					'ID'          => $betrokkene_post_id,
					'post_status' => 'publish',
					'post_type'   => 'boekdb_betrokkene',
					'post_title'  => $boekdb_betrokkene['naam'],
					'post_name'   => sanitize_title( $boekdb_betrokkene['naam'] ),
				);

				// create/update post
				if ( is_null( $betrokkene_post_id ) ) {
					$betrokkene_post_id = wp_insert_post( $post );
				} else {
					$betrokkene_post_id = wp_update_post( $post );
				}

				// save post meta
				foreach ( $boekdb_betrokkene as $key => $value ) {
					switch ( $key ) {
						default:
							update_post_meta( $betrokkene_post_id, 'boekdb_' . $key, $value );
							break;
					}
				}

				$betrokkene_id = $boekdb_betrokkene['id'];

				$term_id = self::get_taxonomy_term_id( $betrokkene_id, $rol, $betrokkene->naam );
				wp_set_object_terms( $betrokkene_post_id, $term_id, 'boekdb_' . $rol . '_tax' );
				$term_ids[ $rol ][] = $term_id;
			} else {
				$betrokkenen_meta[ $rol ] = ( isset( $betrokkenen_meta[ $rol ] ) ? $betrokkenen_meta[ $rol ] . ', ' : '' ) . $boekdb_betrokkene['naam'];
			}
		}

		wp_set_object_terms( $boek_post_id, $term_ids['auteur'], 'boekdb_auteur_tax', false );
		wp_set_object_terms( $boek_post_id, $term_ids['illustrator'], 'boekdb_illustrator_tax', false );
		wp_set_object_terms( $boek_post_id, $term_ids['spreker'], 'boekdb_spreker_tax', false );
		foreach ( $betrokkenen_meta as $key => $value ) {
			update_post_meta( $boek_post_id, 'boekdb_' . $key, $value );
		}
	}
}

BoekDB_Import::init();

