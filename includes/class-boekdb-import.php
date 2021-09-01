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
class BoekDB_Import {
	const CRON_HOOK           = 'boekdb_import';
	const BOEKDB_DOMAIN       = 'https://boekdbv2.nl/';
	const BASE_URL            = self::BOEKDB_DOMAIN . 'api/json/v1/';
	const LIMIT               = 250;
	const DEFAULT_LAST_IMPORT = "2015-01-01T01:00:00+01:00";

	/**
	 * Hook in tabs.
	 */
	public static function init() {
		// debug:
		if ( WP_DEBUG ) {
//			flush_rewrite_rules();
//			add_action( 'init', array( self::class, 'import' ) );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
		add_action( self::CRON_HOOK, array( self::class, 'import' ) );
	}

	public static function import() {
		set_time_limit( 0 );

		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$etalages = self::fetch_etalages();
		foreach ( $etalages as $etalage ) {
			boekdb_set_import_running();
			boekdb_set_import_etalage( $etalage->id );
			$reset = self::check_available_isbns( $etalage );

			$offset      = 0;
			$last_import = $etalage->last_import;
			if ( $reset || is_null( $last_import ) ) {
				$last_import = self::DEFAULT_LAST_IMPORT;
			}

			$last_import = new DateTime( $last_import, wp_timezone() );
			$last_import = $last_import->format( 'Y-m-d\TH:i:sP' );

			while ( $products = self::fetch_products( $etalage->api_key, $last_import, $offset ) ) {
				boekdb_debug( 'Fetched ' . $etalage->name . ' with offset ' . $offset );

				// keep updating transient
				boekdb_set_import_etalage( $etalage->id );
				foreach ( $products as $product ) {
					list( $boek_post_id, $isbn ) = self::handle_boek( $product );
					self::handle_betrokkenen( $product, $boek_post_id );
					foreach ( $product->onderwerpen as $onderwerp ) {
						if ( $onderwerp->type === 'NUR' || $onderwerp->type === 'BISAC' ) {
							$term_id = self::get_taxonomy_term_id( sanitize_title( $onderwerp->code ),
								strtolower( $onderwerp->type ),
								$onderwerp->waarde );
							wp_set_object_terms( $boek_post_id, $term_id,
								'boekdb_' . strtolower( $onderwerp->type ) . '_tax' );
						} elseif ( substr( $onderwerp->type, 0, 5 ) === 'Thema' ) {
							$term_id = self::get_taxonomy_term_id( sanitize_title( $onderwerp->code ), 'thema',
								$onderwerp->waarde );
							wp_set_object_terms( $boek_post_id, $term_id, 'boekdb_thema_tax' );
						}
					}

					self::link_product( $boek_post_id, $isbn, $etalage->id );
				}
				$offset = $offset + self::LIMIT;
			}
			boekdb_debug( 'Finished import on ' . $etalage->name );
			self::set_last_import( $etalage->id );
		}

		// All done, release transient
		boekdb_reset_import_running();
	}


	private static function check_available_isbns( $etalage ) {
		global $wpdb;

		$isbns = self::fetch_isbns( $etalage->api_key );

		self::unpublish( $etalage->id, $isbns['isbns'] );

		if ( $isbns['filters'] !== $etalage->filter_hash ) {
			$wpdb->update(
				$wpdb->prefix . 'boekdb_etalages',
				array(
					'filter_hash' => $isbns['filters'],
					'last_import' => null,
				),
				array( 'id' => $etalage->id )
			);

			// reset
			return true;
		}

		// no reset
		return false;
	}

	private static function fetch_isbns( $api_key ) {
		$result = wp_remote_get(
			self::BASE_URL . 'isbns',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				)
			)
		);

		$result = wp_remote_retrieve_body( $result );

		$result = json_decode( $result, true );
		if ( ! is_array( $result ) || ! isset( $result['isbns'] ) ) {
			return false;
		}

		return $result;
	}

	private static function link_product( $boek_id, $isbn, $etalage_id ) {
		global $wpdb;

		$wpdb->replace( $wpdb->prefix . 'boekdb_etalage_boeken', array(
			'etalage_id' => $etalage_id,
			'boek_id'    => $boek_id,
		) );
		$wpdb->replace( $wpdb->prefix . 'boekdb_isbns', array(
			'isbn'    => $isbn,
			'boek_id' => $boek_id,
		) );
	}

	private static function set_last_import( $id ) {
		global $wpdb;
		$value = current_time( 'mysql', 1 );

		return $wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'last_import' => $value ),
			array( 'id' => $id ) );
	}

	private static function fetch_etalages() {
		global $wpdb;

		$etalages = $wpdb->get_results( "SELECT id, name, api_key, DATE_FORMAT(last_import, '%Y-%m-%d\T%H:%i:%s\+01:00') as last_import, filter_hash FROM {$wpdb->prefix}boekdb_etalages",
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
		$result = wp_remote_get(
			self::BASE_URL . 'products?updated_at=' . urlencode( $last_import ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'x-limit'       => self::LIMIT,
					'x-offset'      => $offset,
				)
			)
		);

		$result = wp_remote_retrieve_body( $result );

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
		$boek                          = array();
		$boek['nstc']                  = $product->nstc;
		$boek['titel']                 = $product->titel;
		$boek['isbn']                  = $product->isbn;
		$boek['subtitel']              = $product->subtitel;
		$boek['deeltitel']             = $product->deeltitel;
		$boek['sectietitel']           = $product->sectietitel;
		$boek['origineletitel']        = $product->origineletitel;
		$boek['serietitel']            = $product->serietitel;
		$boek['deel']                  = $product->deel;
		$boek['druk']                  = $product->druk;
		$boek['verschijningsvorm']     = $product->verschijningsvorm;
		$boek['uitgever']              = $product->uitgever;
		$boek['imprint']               = $product->imprint;
		$boek['flaptekst']             = $product->flaptekst;
		$boek['annotatie']             = $product->annotatie;
		$boek['taal']                  = $product->taal;
		$boek['illustraties']          = $product->illustraties;
		$boek['lengte']                = $product->lengte;
		$boek['breedte']               = $product->breedte;
		$boek['dikte']                 = $product->dikte;
		$boek['gewicht']               = $product->gewicht;
		$boek['paginas_hoofdwerk']     = $product->paginas_hoofdwerk;
		$boek['paginas_proloog']       = $product->paginas_proloog;
		$boek['paginas_epiloog']       = $product->paginas_epiloog;
		$boek['duur']                  = $product->duur;
		$boek['bestandsgrootte']       = $product->bestandsgrootte;
		$boek['leeftijdscategorie']    = $product->leeftijdscategorie;
		$boek['avi']                   = $product->avi;
		$boek['beschikbaarheidsdatum'] = $product->beschikbaarheidsdatum;
		$boek['publicatiedatum']       = $product->publicatiedatum;
		$boek['prijs']                 = $product->prijs;
		$boek['status']                = $product->status;
		$boek['leverbaarheid']         = $product->leverbaarheid;
		$boek['biografie']             = $product->biografie;
		$boek['actieprijzen']          = [];
		if ( isset ( $product->actieprijzen ) && ! is_null( $product->actieprijzen ) ) {
			foreach ( $product->actieprijzen as $actieprijs ) {
				$boek['actieprijzen'][] = [
					'actieprijs'         => $actieprijs->actieprijs,
					'actieperiode_start' => $actieprijs->actieperiode_start,
					'actieperiode_einde' => $actieprijs->actieperiode_einde,
				];
			}
		}
		$boek['links'];
		if ( isset ( $product->links ) && ! is_null( $product->links ) ) {
			foreach ( $product->links as $link) {
				$boek['links'][] = [
					'soort' => strtolower($link->soort),
					'url' => $link->url,
				];
			}
		}

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
		$boekdb_betrokkene                         = array();
		$boekdb_betrokkene['id']                   = $betrokkene->id;
		$boekdb_betrokkene['naam']                 = $betrokkene->naam;
		$boekdb_betrokkene['boekdb_voornaam']      = $betrokkene->voornaam;
		$boekdb_betrokkene['boekdb_tussenvoegsel'] = $betrokkene->tussenvoegsel;
		$boekdb_betrokkene['boekdb_achternaam']    = $betrokkene->achternaam;
		$boekdb_betrokkene['boekdb_organisatie']   = $betrokkene->organisatie;
		$boekdb_betrokkene['boekdb_biografie']     = $betrokkene->biografie;
		$boekdb_betrokkene['boekdb_bibliografie']  = $betrokkene->bibliografie;
		$boekdb_betrokkene['bestanden']            = $betrokkene->bestanden;

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
			$hash          = md5( $bestand->url );
			$attachment_id = self::find_field( 'attachment', 'hash', $hash );
			if ( is_null( $attachment_id ) ) {
				$get   = wp_safe_remote_get( $bestand->url );
				$type  = $bestand->type;
				$image = wp_upload_bits( $bestand->bestandsnaam, null, wp_remote_retrieve_body( $get ) );

				$attachment = array(
					'post_title'     => $bestand->soort,
					'post_mime_type' => $type
				);

				$attachment_id   = wp_insert_attachment( $attachment, $image['file'], $boek_post_id );
				$wp_upload_dir   = wp_upload_dir();
				$attachment_data = wp_generate_attachment_metadata( $attachment_id,
					$wp_upload_dir['path'] . '/' . $bestand->bestandsnaam );

				wp_update_attachment_metadata( $attachment_id, $attachment_data );

				update_post_meta( $attachment_id, 'hash', $hash );
				if ( $bestand->soort === 'Cover' ) {
					update_post_meta( $boek_post_id, '_thumbnail_id', $attachment_id );
				} elseif ( $bestand->soort === 'Back cover' ) {
					update_post_meta( $boek_post_id, 'boekdb_file_backcover_id', $attachment_id );
				} elseif ( $bestand->soort === 'Fragment' ) {
					update_post_meta( $boek_post_id, 'boekdb_file_voorbeeld_id', $attachment_id );
				}
			}
		}
	}

	/**
	 * Load the boek and return the post_id
	 *
	 * @param $product
	 *
	 * @return array
	 */
	protected static function handle_boek( $product ) {
		global $wpdb;

		$boek         = self::create_boek_array( $product );
		$boek_post_id = $wpdb->get_col( $wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s",
			$boek['isbn'] ) );

		if ( count( $boek_post_id ) > 0 ) {
			$boek_post_id = (int) $boek_post_id[0];
		} else {
			$boek_post_id = self::find_field( 'boekdb_boek', 'boekdb_isbn', $boek['isbn'] );
		}

		$post = array(
			'ID'          => $boek_post_id,
			'post_status' => 'publish',
			'post_type'   => 'boekdb_boek',
			'post_title'  => $boek['titel'],
			'post_name'   => sanitize_title( $boek['titel'] ),
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

		self::handle_serie( $product, $boek_post_id );
		self::handle_boek_files( $product, $boek_post_id );

		return array( $boek_post_id, $boek['isbn'] );
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
	 * Handle betrokkene
	 *
	 * @param $slug
	 * @param $taxonomy
	 * @param $value
	 *
	 * @return int|mixed
	 */
	protected static function handle_betrokkene( $betrokkene, $taxonomy ) {
		$term = get_term_by( 'slug', sanitize_title( $betrokkene['naam'], $betrokkene['id'] ),
			'boekdb_' . $taxonomy . '_tax' );
		if ( $term ) {
			$term_id = $term->term_id;
			wp_update_term(
				$term_id,
				'boekdb_' . $taxonomy . '_tax',
				array(
					'name' => $betrokkene['naam'],
					'slug' => sanitize_title( $betrokkene['naam'], $betrokkene['id'] ),
				) );
		} else {
			$result  = wp_insert_term(
				$betrokkene['naam'],
				'boekdb_' . $taxonomy . '_tax',
				array(
					'name' => $betrokkene['naam'],
					'slug' => sanitize_title( $betrokkene['naam'], $betrokkene['id'] ),
				) );
			$term_id = $result['term_id'];
		}

		$meta = array(
			'voornaam'      => $betrokkene['boekdb_voornaam'],
			'tussenvoegsel' => $betrokkene['boekdb_tussenvoegsel'],
			'achternaam'    => $betrokkene['boekdb_achternaam'],
			'organisatie'   => $betrokkene['boekdb_organisatie'],
			'biografie'     => $betrokkene['boekdb_biografie'],
			'bibliografie'  => $betrokkene['boekdb_bibliografie'],
		);
		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}

		if ( isset( $betrokkene['bestanden'] ) && count( $betrokkene['bestanden'] ) > 0 ) {
			self::handle_betrokkene_files( $betrokkene, $term_id );
		}

		return $term_id;
	}

	/**
	 * Parse collection
	 *
	 * @param $product
	 * @param $boek_post_id
	 */
	protected static function handle_serie( $product, $boek_post_id ) {
		if ( is_object( $product->serie ) && isset( $product->serie->id ) ) {
			// clear old taxonomy
			wp_set_object_terms( $boek_post_id, null, 'boekdb_serie_tax' );

			// set new taxonomy
			wp_set_object_terms( $boek_post_id, $product->serietitel, 'boekdb_serie_tax', false );

			// get taxonomy
			$result = wp_get_object_terms( $boek_post_id, 'boekdb_serie_tax', true );
			$term   = $result[0];

			add_term_meta( $term->term_id, 'boekdb_id', $product->serie->id, true );
			wp_update_term( $term->term_id, 'boekdb_serie_tax', array(
				'description' => $product->serie->omschrijving
			) );

			self::handle_serie_files( $product, $term->term_id );
		}
	}

	/**
	 * Load files for contributor
	 *
	 * @param $betrokkene
	 * @param $term_id
	 */
	protected static function handle_betrokkene_files( $betrokkene, $term_id ) {
		foreach ( $betrokkene['bestanden'] as $bestand ) {
			if ( $bestand->soort !== 'Auteursfoto' ) {
				return;
			}

			$hash          = md5( $bestand->url );
			$attachment_id = self::find_field( 'attachment', 'hash', $hash );

			if ( is_null( $attachment_id ) ) {
				$get   = wp_safe_remote_get( $bestand->url );
				$type  = $bestand->type;
				$image = wp_upload_bits( $bestand->bestandsnaam, null, wp_remote_retrieve_body( $get ) );

				$attachment = array(
					'post_title'     => 'Auteursfoto',
					'post_mime_type' => $type
				);

				$attachment_id   = wp_insert_attachment( $attachment, $image['file'] );
				$wp_upload_dir   = wp_upload_dir();
				$attachment_data = wp_generate_attachment_metadata(
					$attachment_id,
					$wp_upload_dir['path'] . '/' . $bestand->bestandsnaam );

				wp_update_attachment_metadata( $attachment_id, $attachment_data );

				update_post_meta( $attachment_id, 'hash', $hash );
				update_term_meta( $term_id, 'auteursfoto_id', $attachment_id );
				if ( ! is_null( $bestand->copyright ) ) {
					update_term_meta( $term_id, 'auteursfoto_copyright', $bestand->copyright );
				}
			}
		}
	}

	/**
	 * Load files for serie
	 *
	 * @param $product
	 * @param $term_id
	 */
	protected static function handle_serie_files( $product, $term_id ) {
		if ( is_null( $product->serie->beeld ) ) {
			return;
		}
		$hash          = md5( $product->serie->beeld->url );
		$attachment_id = self::find_field( 'attachment', 'hash', $hash );
		if ( is_null( $attachment_id ) ) {
			$get   = wp_safe_remote_get( $product->serie->beeld->url );
			$type  = $bestand->type;
			$image = wp_upload_bits( $product->serie->beeld->bestandsnaam, null, wp_remote_retrieve_body( $get ) );

			$attachment = array(
				'post_title'     => $product->serie->beeld->soort,
				'post_mime_type' => $type
			);

			$attachment_id   = wp_insert_attachment( $attachment, $image['file'] );
			$wp_upload_dir   = wp_upload_dir();
			$attachment_data = wp_generate_attachment_metadata(
				$attachment_id,
				$wp_upload_dir['path'] . '/' . $product->serie->beeld->bestandsnaam );

			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			update_post_meta( $attachment_id, 'hash', $hash );
			update_term_meta( $term_id, 'boekdb_seriebeeld_id', $attachment_id );
		}
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
				$term_id            = self::handle_betrokkene( $boekdb_betrokkene, $rol );
				$term_ids[ $rol ][] = $term_id;
			} else {
				$betrokkenen_meta[ $rol ] = ( isset( $betrokkenen_meta[ $rol ] ) ? $betrokkenen_meta[ $rol ] . ', ' : '' ) . $boekdb_betrokkene['naam'];
			}
		}

		wp_set_object_terms( $boek_post_id, $term_ids['auteur'], 'boekdb_auteur_tax', false );
		wp_set_object_terms( $boek_post_id, $term_ids['illustrator'], 'boekdb_illustrator_tax', false );
		wp_set_object_terms( $boek_post_id, $term_ids['spreker'], 'boekdb_spreker_tax', false );

		// Overige betrokkenen
		foreach ( $betrokkenen_meta as $key => $value ) {
			update_post_meta( $boek_post_id, 'boekdb_' . $key, $value );
		}
	}

	/**
	 * Unpublish posts and delete from custom table
	 *
	 * @param $etalage_id
	 * @param $isbns
	 */
	private static function unpublish( $etalage_id, $isbns ) {
		global $wpdb;

		$prepared_query = $wpdb->prepare(
			"SELECT i.boek_id 
					FROM {$wpdb->prefix}boekdb_isbns i
					    LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON eb.boek_id = i.boek_id
					    INNER JOIN {$wpdb->posts} p ON p.ID = i.boek_id AND p.post_status = 'publish'
					WHERE eb.etalage_id = %d",
			$etalage_id );
		$prepared_query .= " AND i.isbn NOT IN (";
		foreach ( $isbns as $isbn ) {
			$prepared_query .= $wpdb->prepare( '%s,', $isbn );
		}
		$prepared_query = substr( $prepared_query, 0, - 1 ) . ")";
		$result         = $wpdb->get_results( $prepared_query );

		$post_ids = array();
		foreach ( $result as $boek ) {
			$post_ids[] = (int) $boek->boek_id;
		}

		// delete from link table
		if ( count( $post_ids ) > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id = %d",
					$etalage_id
				) . " AND boek_id IN (" . implode( ',', $post_ids ) . ")"
			);
			$args  = array(
				'post_type'   => 'boekdb_boek',
				'include'     => $post_ids,
				'numberposts' => - 1,
			);
			$posts = get_posts( $args );
			foreach ( $posts as $post ) {
				$query = array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
				);
				wp_update_post( $query, true );
			}
		}
	}
}

BoekDB_Import::init();

