<?php
/**
 * BoekDB Product Importer
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

/**
 * BoekDB_Import Class.
 */
class BoekDB_Import {
	const IMPORT_HOOK  = 'boekdb_import';
	const CLEANUP_HOOK = 'boekdb_cleanup';

	const BOEKDB_DOMAIN       = 'https://boekdbv2.nl/';
	const BASE_URL            = self::BOEKDB_DOMAIN . 'api/json/v1/';
	const LIMIT               = 250;
	const DEFAULT_LAST_IMPORT = "2015-01-01T01:00:00+01:00";

	protected static $options = [
		'overwrite_images' => 0,
	];

	/**
	 * Hook in tabs.
	 */
	public static function init() {
		if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::IMPORT_HOOK );
		}
		add_action( self::IMPORT_HOOK, array( self::class, 'import' ) );
		add_action( self::CLEANUP_HOOK, array( self::class, 'clean_up' ) );
	}

	public static function import() {
		set_time_limit( 0 );

		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		// handle options
		self::$options['overwrite_images'] = get_transient( 'boekdb_import_options_overwrite_images' );

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
				boekdb_debug( 'Contains ' . count( $products ) . ' books' );

				if ( self::$options['overwrite_images'] === '1' ) {
					boekdb_debug( 'overwriting images' );
				}

				// keep updating transient
				boekdb_set_import_etalage( $etalage->id );
				foreach ( $products as $product ) {
					list( $boek_post_id, $isbn, $nstc, $slug ) = self::handle_boek( $product );
					self::handle_betrokkenen( $product, $boek_post_id );

					$thema = array();
					$nur   = array();
					$bisac = array();

					foreach ( $product->onderwerpen as $onderwerp ) {
						if ( $onderwerp->type === 'NUR' ) {
							$nur[] = self::get_taxonomy_term_id( sanitize_title( $onderwerp->code ),
								'nur', $onderwerp->waarde );
						} elseif ( $onderwerp->type === 'BISAC' ) {
							$bisac[] = self::get_taxonomy_term_id( sanitize_title( $onderwerp->code ),
								'bisac', $onderwerp->waarde );
						} elseif ( substr( $onderwerp->type, 0, 5 ) === 'Thema' ) {
							$thema[] = self::get_taxonomy_term_id( sanitize_title( boekdb_thema_omschrijving( $onderwerp->code ) ),
								'thema', boekdb_thema_omschrijving( $onderwerp->code ) );
						}
					}
					wp_set_object_terms( $boek_post_id, $nur, 'boekdb_nur_tax' );
					wp_set_object_terms( $boek_post_id, $bisac, 'boekdb_bisac_tax' );
					wp_set_object_terms( $boek_post_id, $thema, 'boekdb_thema_tax' );

					self::link_product( $boek_post_id, $isbn, $etalage->id );
					self::check_primary_title( $boek_post_id, $nstc, $slug );
				}
				$offset = $offset + self::LIMIT;
			}
			boekdb_debug( 'Finished import on ' . $etalage->name );
			self::set_last_import( $etalage->id );
		}

		// All done, release transients
		boekdb_reset_import_running();
	}

	private static function fetch_etalages() {
		global $wpdb;

		return $wpdb->get_results( "SELECT id, name, api_key, DATE_FORMAT(last_import, '%Y-%m-%d\T%H:%i:%s\+01:00') as last_import, filter_hash FROM {$wpdb->prefix}boekdb_etalages",
			OBJECT );
	}

	private static function check_available_isbns( $etalage ) {
		global $wpdb;

		$isbns = self::fetch_isbns( $etalage->api_key );
		self::trash_removed( $etalage->id, $isbns['isbns'] );

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
		if ( is_wp_error( $result ) ) {
			die( $result->get_error_message() );
		}
		$result = wp_remote_retrieve_body( $result );
		$result = json_decode( $result, true );
		if ( ! is_array( $result ) || ! isset( $result['isbns'] ) ) {
			return false;
		}

		return $result;
	}

	/**
	 * Delete posts and delete from custom table
	 *
	 * @param $etalage_id
	 * @param $isbns
	 */
	public static function trash_removed( $etalage_id, $isbns ) {
		global $wpdb;

		$prepared_query = $wpdb->prepare(
			"SELECT i.boek_id 
					FROM {$wpdb->prefix}boekdb_isbns i
					    LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON eb.boek_id = i.boek_id
					    INNER JOIN {$wpdb->posts} p ON p.ID = i.boek_id
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
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id = %d",
					$etalage_id ) . " AND boek_id IN (" . implode( ',', $post_ids ) . ")"
			);
			foreach ( $post_ids as $post_id ) {
				$result = $wpdb->get_results( $wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE boek_id = %d",
					$post_id ) );
				if ( ! is_wp_error( $result ) && count( $result ) === 0 ) {
					self::delete_posts( [ $post_id ] );
				}
			}
		}
	}

	private static function delete_posts( $post_ids ) {
		global $wpdb;

		add_action( 'before_delete_post', function ( $id ) {
			$attachments = get_attached_media( '', $id );
			foreach ( $attachments as $attachment ) {
				wp_delete_attachment( $attachment->ID, 'true' );
			}
			boekdb_debug( 'deleted attachments' );
		} );

		foreach ( $post_ids as $post_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id = %d", $post_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE boek_id = %d",
				$post_id ) );
			wp_delete_post( $post_id, true );
			wp_delete_object_term_relationships( $post_id, array(
				'boekdb_serie_tax',
				'boekdb_auteur_tax',
				'boekdb_illustrator_tax',
				'boekdb_spreker_tax',
				'boekdb_nur_tax',
				'boekdb_bisac_tax',
				'boekdb_thema_tax',
			) );

			boekdb_debug( 'deleted post ' . $post_id );
		}
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

		$result   = wp_remote_retrieve_body( $result );
		$products = json_decode( $result );
		if ( ! is_array( $products ) ) {
			return false;
		}
		boekdb_debug( count( $products ) . ' products for offset ' . $offset );

		return $products;
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
			$array        = $boek_post_id;
			$boek_post_id = (int) array_pop( $array );

			if ( count( $array ) > 0 ) {
				self::delete_posts( $array );
			}
		} else {
			$boek_post_id = self::find_field( 'boekdb_boek', 'boekdb_isbn', $boek['isbn'] );
		}

		// Sanity check
		if ( (int) $boek_post_id === 0 ) {
			$boek_post_id = null;
		}

		$slug = sanitize_title( $boek['titel'] );
		$post = array(
			'ID'          => $boek_post_id,
			'post_status' => 'publish',
			'post_type'   => 'boekdb_boek',
			'post_title'  => $boek['titel'],
		);

		// create/update post
		if ( is_null( $boek_post_id ) ) {
			$post['post_name'] = $slug;
			$boek_post_id      = wp_insert_post( $post );
		} else {
			$boek_post_id = wp_update_post( $post );
		}

		// save post meta
		foreach ( $boek as $key => $value ) {
			// handle flaptekst and annotatie
			if ( $key === 'flaptekst' || $key === 'annotatie' ) {
				// check for existing
				$overwritten = get_post_meta( $boek_post_id, 'boekdb_' . $key . '_overwritten', true );
				if ( $overwritten !== '1' ) {
					update_post_meta( $boek_post_id, 'boekdb_' . $key, $value );
				}
				update_post_meta( $boek_post_id, 'boekdb_' . $key . '_org', $value );
			} else {
				update_post_meta( $boek_post_id, 'boekdb_' . $key, $value );
			}

			// handle recensiequotes
			if ( $key === 'recensiequotes' ) {
				$current_quotes = get_post_meta( $boek_post_id, 'boekdb_recensiequotes' )[0];
				if ( count( $value ) > 0 ) {
					$import_quotes = array();
					foreach ( $value as $hash => $quote ) {
						// check if quote exists currently
						if ( isset( $current_quotes[ $hash ] ) ) {
							// get value for tonen
							$quote['tonen'] = $current_quotes[ $hash ]['tonen'];
						}
						$import_quotes[ $hash ] = $quote;
					}
					// overwrite post_meta with parsed quotes
					update_post_meta( $boek_post_id, 'boekdb_recensiequotes', $import_quotes );
				} else {
					// just write to post_meta
					update_post_meta( $boek_post_id, 'boekdb_recensiequotes', $value );
				}
			}
		}

		self::handle_serie( $product, $boek_post_id );
		self::handle_boek_files( $product, $boek_post_id );

		return array( $boek_post_id, $boek['isbn'], $boek['nstc'], $slug );
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
		$boek['inhoudsopgave']         = $product->inhoudsopgave;
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
		$boek['links']                 = [];
		$boek['literaireprijzen']      = [];
		$boek['recensiequotes']        = [];
		$boek['recensielinks']         = [];

		// overschrijfbare velden
		$boek['annotatie'] = $product->annotatie;
		$boek['flaptekst'] = $product->flaptekst;

		if ( isset ( $product->actieprijzen ) && ! is_null( $product->actieprijzen ) ) {
			foreach ( $product->actieprijzen as $actieprijs ) {
				$boek['actieprijzen'][] = [
					'actieprijs'         => $actieprijs->actieprijs,
					'actieperiode_start' => $actieprijs->actieperiode_start,
					'actieperiode_einde' => $actieprijs->actieperiode_einde,
				];
			}
		}

		if ( isset ( $product->links ) && ! is_null( $product->links ) ) {
			foreach ( $product->links as $link ) {
				$boek['links'][] = [
					'soort' => strtolower( $link->soort ),
					'url'   => $link->url,
				];
			}
		}

		if ( isset ( $product->literaireprijzen ) && ! is_null( $product->literaireprijzen ) ) {
			foreach ( $product->literaireprijzen as $prijs ) {
				$boek['literaireprijzen'][] = [
					'prestatie'    => $prijs->prestatie,
					'naam'         => $prijs->naam,
					'jaar'         => $prijs->jaar,
					'land'         => $prijs->land,
					'omschrijving' => $prijs->omschrijving,
				];
			}
		}

		if ( isset( $product->recensiequotes ) && ! is_null( $product->recensiequotes ) ) {
			foreach ( $product->recensiequotes as $quote ) {
				$boek['recensiequotes'][ md5( $quote->tekst ) ] = [
					'tekst'  => $quote->tekst,
					'auteur' => $quote->auteur,
					'bron'   => $quote->bron,
					'datum'  => $quote->datum,
					'tonen'  => true,
				];
			}
		}

		if ( isset( $product->recensielinks ) && ! is_null( $product->recensielinks ) ) {
			foreach ( $product->recensielinks as $link ) {
				$boek['recensielinks'][] = [
					'soort' => strtolower( $link->type ),
					'url'   => $link->url,
					'bron'  => $link->bron,
					'datum' => $link->datum,
				];
			}
		}

		return $boek;
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
	 * Load files for serie
	 *
	 * @param $product
	 * @param $term_id
	 */
	protected static function handle_serie_files( $product, $term_id ) {
		if ( is_null( $product->serie->beeld ) ) {
			return;
		}

		$bestand       = $product->serie->beeld;
		$hash          = md5( $bestand->url );
		$attachment_id = self::find_field( 'attachment', 'hash', $hash );
		if ( self::$options['overwrite_images'] === '1' && ! is_null( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id );
			$attachment_id = null;
		}

		if ( is_null( $attachment_id ) ) {
			$get          = wp_safe_remote_get( $bestand->url );
			$type         = $bestand->type;
			$bestandsnaam = sanitize_file_name( $bestand->bestandsnaam );
			$image        = wp_upload_bits( $bestandsnaam, null, wp_remote_retrieve_body( $get ) );

			$attachment = array(
				'post_title'     => $bestand->soort,
				'post_mime_type' => $type
			);

			$attachment_id   = wp_insert_attachment( $attachment, $image['file'] );
			$wp_upload_dir   = wp_upload_dir();
			$attachment_data = wp_generate_attachment_metadata(
				$attachment_id,
				$wp_upload_dir['path'] . '/' . $bestandsnaam );

			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			update_post_meta( $attachment_id, 'hash', $hash );
			update_term_meta( $term_id, 'boekdb_seriebeeld_id', $attachment_id );
		}
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
			if ( self::$options['overwrite_images'] === '1' && ! is_null( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id );
				$attachment_id = null;
			}

			if ( is_null( $attachment_id ) ) {
				$get          = wp_safe_remote_get( $bestand->url );
				$type         = $bestand->type;
				$bestandsnaam = sanitize_file_name( $bestand->bestandsnaam );
				$image        = wp_upload_bits( $bestandsnaam, null, wp_remote_retrieve_body( $get ) );

				$attachment = array(
					'post_title'     => $bestand->soort,
					'post_mime_type' => $type
				);

				$attachment_id   = wp_insert_attachment( $attachment, $image['file'], $boek_post_id );
				$wp_upload_dir   = wp_upload_dir();
				$attachment_data = wp_generate_attachment_metadata( $attachment_id,
					$wp_upload_dir['path'] . '/' . $bestandsnaam );

				wp_update_attachment_metadata( $attachment_id, $attachment_data );

				update_post_meta( $attachment_id, 'hash', $hash );
				if ( $bestand->soort === 'Cover' ) {
					update_post_meta( $boek_post_id, '_thumbnail_id', $attachment_id );
				} elseif ( $bestand->soort === 'Back cover' ) {
					update_post_meta( $boek_post_id, 'boekdb_file_backcover_id', $attachment_id );
				} elseif ( $bestand->soort === 'Fragment' ) {
					update_post_meta( $boek_post_id, 'boekdb_file_voorbeeld_id', $attachment_id );
				}
			} else {
				$attachment = array(
					'ID'          => $attachment_id,
					'post_parent' => $boek_post_id
				);
				wp_update_post( $attachment );

				$attachment = get_post( $attachment_id );
				if ( $attachment->post_title === 'Cover' ) {
					update_post_meta( $boek_post_id, '_thumbnail_id', $attachment_id );
				} elseif ( $attachment->post_title === 'Back cover' ) {
					update_post_meta( $boek_post_id, 'boekdb_file_backcover_id', $attachment_id );
				} elseif ( $attachment->post_title === 'Fragment' ) {
					update_post_meta( $boek_post_id, 'boekdb_file_voorbeeld_id', $attachment_id );
				}
			}
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
			if ( self::$options['overwrite_images'] === '1' && ! is_null( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id );
				$attachment_id = null;
			}

			if ( is_null( $attachment_id ) ) {
				$get          = wp_safe_remote_get( $bestand->url );
				$type         = $bestand->type;
				$bestandsnaam = sanitize_file_name( $bestand->bestandsnaam );
				$image        = wp_upload_bits( $bestandsnaam, null, wp_remote_retrieve_body( $get ) );

				$attachment = array(
					'post_title'     => 'Auteursfoto',
					'post_mime_type' => $type
				);

				$attachment_id   = wp_insert_attachment( $attachment, $image['file'] );
				$wp_upload_dir   = wp_upload_dir();
				$attachment_data = wp_generate_attachment_metadata(
					$attachment_id,
					$wp_upload_dir['path'] . '/' . $bestandsnaam );

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

	private static function check_primary_title( $post_id, $nstc, $slug ) {
		global $wpdb;

		// if nstc is null, set current book to primary
		if ( is_null( $nstc ) ) {
			update_post_meta( $post_id, 'boekdb_primary', 1 );

			return;
		}

		// get list of books by nstc
		$query = new WP_Query( array(
			'posts_per_page' => - 1,
			'post_type'      => 'boekdb_boek',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => 'boekdb_nstc',
					'value' => $nstc,
				)
			)
		) );

		// get productform and secondary slug for each book
		$books = array();
		$slugs = array();

		if ( $query->have_posts() ) {
			$posts = $query->get_posts();
			foreach ( $posts as $post ) {
				$status            = get_post_meta( $post->ID, 'boekdb_status', true );
				$verschijningsvorm = get_post_meta( $post->ID, 'boekdb_verschijningsvorm', true );
				if ( (int) $status < 30 ) {
					$books[ $post->ID ] = 'xxxxx';
				} else {
					$books[ $post->ID ] = substr( $verschijningsvorm, 0, 5 );
				}
				$slugs[ $post->ID ] = $slug . '-' . sanitize_title( $verschijningsvorm );
			}
		}

		// uasort books by productform
		// @note: make sort configurable?
		uasort( $books, array( self::class, 'sort_books_by_productform' ) );
		$post_ids = array_keys( $books );

		// set primary bit and main slug on first book
		$first_id = array_shift( $post_ids );
		unset( $books[ $first_id ] );
		update_post_meta( $first_id, 'boekdb_primair', 1 );
		wp_update_post( array(
			'post_name' => $slug,
			'ID'        => $first_id,
		) );

		// disable primary bit on all other books and set slug to secondary
		foreach ( $books as $book_id => $val ) {
			update_post_meta( $book_id, 'boekdb_primair', 0 );
			wp_update_post( array(
				'post_name' => $slugs[ $book_id ],
				'ID'        => $book_id,
			) );
		}
	}

	private static function set_last_import( $id ) {
		global $wpdb;
		$value = current_time( 'mysql', 1 );

		return $wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'last_import' => $value ),
			array( 'id' => $id ) );
	}

	public static function delete_etalage_posts( $post_ids, $deleted_etalage ) {
		global $wpdb;

		if ( $deleted_etalage > 0 ) {
			// check if post is still related to etalage
			foreach ( $post_ids as $key => $post_id ) {
				$result = $wpdb->get_results( $wpdb->prepare( "SELECT boek_id FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id != %d AND boek_id = %d LIMIT 1",
					$deleted_etalage, $post_id ) );
				if ( count( $result ) > 0 ) {
					unset( $post_ids[ $key ] );
				}
			}
			boekdb_debug( 'deleting ' . count( $post_ids ) . ' posts' );
			self::delete_posts( $post_ids );

			boekdb_debug( 'running cleanup' );
			self::clean_up();
		}
	}

	public static function clean_up() {
		global $wpdb;

		// cleanup etalage_boeken
		$etalages    = self::fetch_etalages();
		$etalage_ids = array();
		foreach ( $etalages as $etalage ) {
			$etalage_ids[] = $etalage->id;
		}
		if ( count( $etalage_ids ) > 0 ) {
			$placeholders = implode( ', ', array_fill( 0, count( $etalage_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id NOT IN ( $placeholders )",
				$etalage_ids ) );
		} else {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE boek_id IS NOT NULL" );
		}

		// cleanup boekdb_isbns
		$post_ids = array_reduce( $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'boekdb_boek'",
			ARRAY_N ), 'array_merge', array() );
		if ( count( $post_ids ) > 0 ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id NOT IN ( $placeholders )",
				$post_ids ) );
		} else {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id IS NOT NULL" );
		}

		$result   = $wpdb->get_results( "SELECT p.ID
					FROM $wpdb->posts p
					    LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON eb.boek_id = p.ID
					    LEFT JOIN {$wpdb->prefix}boekdb_etalages et ON et.id = eb.etalage_id
					WHERE p.post_type = 'boekdb_boek' GROUP BY p.ID HAVING COUNT(et.id) = 0" );
		$post_ids = array();
		foreach ( $result as $boek ) {
			$post_ids[] = (int) $boek->ID;
		}
		self::delete_posts( $post_ids );

		$result   = $wpdb->get_results( "SELECT tt.term_id, tt.taxonomy FROM $wpdb->term_taxonomy tt WHERE tt.taxonomy LIKE 'boekdb_%_tax'" );
		$term_ids = array();
		foreach ( $result as $term ) {
			$term_ids[ (int) $term->term_id ] = $term->taxonomy;
		}
		self::delete_terms( $term_ids );
	}

	public static function delete_terms( $term_ids ) {
		global $wpdb;

		foreach ( $term_ids as $term_id => $taxonomy ) {
			$args  = array(
				'posts_per_page' => 1,
				'post_type'      => 'boekdb_boek',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id
					)
				)
			);
			$query = new WP_Query( $args );
			if ( $query->post_count === 0 ) {
				wp_delete_term( $term_id, $taxonomy );
				boekdb_debug( 'deleted term ' . $term_id . ' from ' . $taxonomy );
			}
		}
	}

	private static function sort_books_by_productform( $a, $b ) {
		$sort = array(
			'Paper' => 1,
			'Hardb' => 2,
			'Luist' => 3,
			'Ebook' => 4,
			'xxxxx' => 99,
		);
		if ( isset( $sort[ $a ] ) ) {
			$a = $sort[ $a ];
		} else {
			$a = 99;
		}

		if ( isset( $sort[ $b ] ) ) {
			$b = $sort[ $b ];
		} else {
			$b = 99;
		}
		if ( $a == $b ) {
			return 0;
		}

		return ( $a < $b ) ? - 1 : 1;
	}
}

BoekDB_Import::init();

