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
	const START_IMPORT_HOOK = 'boekdb_start_import';
	const IMPORT_HOOK       = 'boekdb_run_import';

	const BOEKDB_DOMAIN       = 'https://boekdbv2.nl/';
	const BASE_URL            = self::BOEKDB_DOMAIN . 'api/json/v1/';
	const DEFAULT_LAST_IMPORT = "2015-01-01T01:00:00+01:00";

	protected static $options = [
		'overwrite_images' => 0,
	];

	/**
	 * Hook in tabs.
	 */
	public static function init() {
		add_action( self::START_IMPORT_HOOK, array( self::class, 'start_import' ) );
		add_action( self::IMPORT_HOOK, array( self::class, 'import' ) );

		if ( ! wp_next_scheduled( self::START_IMPORT_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::START_IMPORT_HOOK );
		}
		if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
			wp_schedule_event( time(), 'minutely', self::IMPORT_HOOK );
		}

	}

	public static function import() {
		set_time_limit( 0 );
		boekdb_debug( 'Importing products...' );

		// fetch running imports
		$etalages = BoekDB::fetch_etalages( true );
		if ( count( $etalages ) === 0 ) {
			boekdb_debug( 'No imports ready to run found.' );

			return;
		}

		// do one etalage at a time!
		$etalage = reset( $etalages );

		$offset      = $etalage->offset;
		$last_import = new DateTime( $etalage->last_import, wp_timezone() );
		$last_import = $last_import->format( 'Y-m-d\TH:i:sP' );

		$products = Boekdb_Api_Service::fetch_products( $etalage->api_key, $last_import, $offset );
		if ( count( $products ) > 0 ) {
			boekdb_debug( 'Fetched ' . $etalage->name . ' with offset ' . $offset );
			boekdb_debug( 'Contains ' . count( $products ) . ' books' );

			if ( self::$options['overwrite_images'] === '1' ) {
				boekdb_debug( 'overwriting images' );
			}

			// set update running to 1 (processing)
			self::update_running( 1, $etalage );
			foreach ( $products as $product ) {
				self::check_stopped( $etalage );

				list( $boek_post_id, $isbn, $nstc, $slug ) = self::handle_boek( $product );

				boekdb_debug( 'Processing ' . $isbn );

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
			$offset = $offset + Boekdb_Api_Service::LIMIT;

			// update the offset in etalage and set running to 1 (next batch)
			self::update_offset( $offset, $etalage );
			self::update_running( 2, $etalage );

			boekdb_debug( 'Done with this batch...' );

			// there might be more etalages to import, so schedule a new import
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}
		} else {
			boekdb_debug( 'Finished import on ' . $etalage->name );
			self::set_last_import( $etalage->id );

			// reset offset and set running to 0 (finished)
			self::update_offset( 0, $etalage );
			self::update_running( 0, $etalage );

			// there might be more etalages to import, so schedule a new import
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}
		}
	}

	private static function check_stopped( $etalage ) {
		$running = self::fetch_etalage_running( $etalage->id );
		if ( $running === 0 ) {
			boekdb_debug( 'Import stopped on ' . $etalage->name );
			exit;
		}
	}

	private static function fetch_etalage_running( $id ) {
		global $wpdb;
		$sql        = $wpdb->prepare( "SELECT running FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $id );
		$running    = $wpdb->get_var( $sql );
		return (int)$running;
	}

	private static function update_running( $running, $etalage ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'running' => $running,
			),
			array( 'id' => $etalage->id )
		);
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
				BoekDB_Cleanup::delete_posts( $array );
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
		$boek                           = array();
		$boek['nstc']                   = $product->nstc;
		$boek['titel']                  = $product->titel;
		$boek['isbn']                   = $product->isbn;
		$boek['subtitel']               = $product->subtitel;
		$boek['deeltitel']              = $product->deeltitel;
		$boek['sectietitel']            = $product->sectietitel;
		$boek['origineletitel']         = $product->origineletitel;
		$boek['serietitel']             = $product->serietitel;
		$boek['deel']                   = $product->deel;
		$boek['druk']                   = $product->druk;
		$boek['verschijningsvorm']      = $product->verschijningsvorm;
		$boek['verschijningsvorm_code'] = isset( $product->verschijningsvorm_code ) ? $product->verschijningsvorm_code : null;
		$boek['uitgever']               = $product->uitgever;
		$boek['imprint']                = $product->imprint;
		$boek['inhoudsopgave']          = $product->inhoudsopgave;
		$boek['taal']                   = $product->taal;
		$boek['illustraties']           = $product->illustraties;
		$boek['lengte']                 = $product->lengte;
		$boek['breedte']                = $product->breedte;
		$boek['dikte']                  = $product->dikte;
		$boek['gewicht']                = $product->gewicht;
		$boek['paginas_hoofdwerk']      = $product->paginas_hoofdwerk;
		$boek['paginas_proloog']        = $product->paginas_proloog;
		$boek['paginas_epiloog']        = $product->paginas_epiloog;
		$boek['duur']                   = $product->duur;
		$boek['bestandsgrootte']        = $product->bestandsgrootte;
		$boek['leeftijdscategorie']     = $product->leeftijdscategorie;
		$boek['avi']                    = $product->avi;
		$boek['beschikbaarheidsdatum']  = $product->beschikbaarheidsdatum;
		$boek['publicatiedatum']        = $product->publicatiedatum;
		$boek['prijs']                  = $product->prijs;
		$boek['status']                 = $product->status;
		$boek['leverbaarheid']          = $product->leverbaarheid;
		$boek['biografie']              = $product->biografie;
		$boek['actieprijzen']           = [];
		$boek['links']                  = [];
		$boek['literaireprijzen']       = [];
		$boek['recensiequotes']         = [];
		$boek['recensielinks']          = [];

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
			update_term_meta( $term_id, 'seriebeeld_id', $attachment_id );
		} else {
			// check if seriebeeld is set
			$seriebeeld_id = get_term_meta( $term_id, 'seriebeeld_id', true );
			if ( is_null( $seriebeeld_id ) ) {
				update_term_meta( $term_id, 'seriebeeld_id', $attachment_id );
			}
		}
	}

	/**
	 * Load files from boek
	 *
	 * @param $product
	 * @param $boek_post_id
	 */
	protected static function handle_boek_files($product, $boek_post_id) {
		if (!isset($product->bestanden)) {
			return;
		}
		// needed for wordpress image related functions
		if (!function_exists('wp_generate_attachment_metadata')) {
			require_once(ABSPATH . 'wp-admin/includes/image.php');
		}

		foreach ($product->bestanden as $bestand) {
			$hash = md5($bestand->url);
			$attachment_id = self::find_field('attachment', 'hash', $hash);

			if (self::$options['overwrite_images'] === '1' && !is_null($attachment_id)) {
				wp_delete_attachment($attachment_id, true);
				$attachment_id = null;
			}

			if (is_null($attachment_id)) {
				$response = wp_safe_remote_get($bestand->url);
				if (is_wp_error($response)) {
					error_log('Error fetching file: ' . $bestand->url);
					continue;
				}

				$type = $bestand->type;
				$bestandsnaam = sanitize_file_name($bestand->bestandsnaam);
				$file = wp_upload_bits($bestandsnaam, null, wp_remote_retrieve_body($response));

				if ($file['error']) {
					error_log('Error saving file to disk: ' . $file['error']);
					continue;
				}

				$attachment = [
					'post_title' => $bestand->soort,
					'post_mime_type' => $type
				];

				$attachment_id = wp_insert_attachment($attachment, $file['file'], $boek_post_id);
				if (!is_wp_error($attachment_id)) {
					$wp_upload_dir = wp_upload_dir();
					$attachment_data = wp_generate_attachment_metadata(
						$attachment_id,
						$wp_upload_dir['path'] . '/' . basename($file['file'])
					);
					if($bestand->soort === 'Cover' || $bestand->soort === 'Back cover') {
						// check if sizes were generated
						if ( ! isset( $attachment_data['sizes'] ) || ! is_array( $attachment_data['sizes'] ) || empty( $attachment_data['sizes'] ) ) {
							error_log('Failed to generate image sizes for attachment ID: ' . $attachment_id);
						}
					}

					wp_update_attachment_metadata($attachment_id, $attachment_data);
				} else {
					error_log('Error inserting attachment: ' . $attachment_id->get_error_message());
				}

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
				// check if the file exists
				if ( ! file_exists( get_attached_file( $attachment_id ) ) ) {
					// delete the attachment
					wp_delete_attachment( $attachment_id, true );
					$attachment_id = null;

					// re-run this function
					self::handle_boek_files( $product, $boek_post_id );
				}

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
	protected static function handle_betrokkene_files($betrokkene, $term_id) {
		if (!function_exists('wp_generate_attachment_metadata')) {
			require_once(ABSPATH . 'wp-admin/includes/image.php');
		}

		foreach ($betrokkene['bestanden'] as $bestand) {
			if ($bestand->soort !== 'Auteursfoto') {
				continue;
			}

			$hash = md5($bestand->url);
			$attachment_id = self::find_field('attachment', 'hash', $hash);

			if (self::$options['overwrite_images'] === '1' && !is_null($attachment_id)) {
				wp_delete_attachment($attachment_id, true);
				$attachment_id = null;
			}

			if (is_null($attachment_id)) {
				$response = wp_safe_remote_get($bestand->url);
				if (is_wp_error($response)) {
					error_log('Error fetching file: ' . $bestand->url);
					continue;
				}

				$type = $bestand->type;
				$bestandsnaam = sanitize_file_name($bestand->bestandsnaam);
				$image = wp_upload_bits($bestandsnaam, null, wp_remote_retrieve_body($response));

				if ($image['error']) {
					error_log('Error saving file to disk: ' . $image['error']);
					continue;
				}

				$attachment = [
					'post_title' => 'Auteursfoto',
					'post_mime_type' => $type
				];

				$attachment_id = wp_insert_attachment($attachment, $image['file']);
				if (!is_wp_error($attachment_id)) {
					$wp_upload_dir = wp_upload_dir();
					$attachment_data = wp_generate_attachment_metadata(
						$attachment_id,
						$wp_upload_dir['path'] . '/' . basename($image['file'])
					);
					wp_update_attachment_metadata($attachment_id, $attachment_data);
				} else {
					error_log('Error inserting attachment: ' . $attachment_id->get_error_message());
					continue;
				}

				update_post_meta($attachment_id, 'hash', $hash);
				update_term_meta($term_id, 'auteursfoto_id', $attachment_id);
				if (isset($bestand->copyright)) {
					update_term_meta($term_id, 'auteursfoto_copyright', $bestand->copyright);
				}
			} else {
				// checks if attachment is already linked to this contributor
				$existing_auteursfoto_id = get_term_meta($term_id, 'auteursfoto_id', true);
				if ($existing_auteursfoto_id !== $attachment_id) {
					update_term_meta($term_id, 'auteursfoto_id', $attachment_id);
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
				$status                 = get_post_meta( $post->ID, 'boekdb_status', true );
				$verschijningsvorm      = get_post_meta( $post->ID, 'boekdb_verschijningsvorm', true );
				$verschijningsvorm_slug = boekdb_verschijningsvorm_slug( $verschijningsvorm );

				if ( $status !== '10' && $status !== '21' && $status !== '23' ) {
					$books[ $post->ID ] = 'xxxxx';
				} else {
					$books[ $post->ID ] = substr( $verschijningsvorm, 0, 5 );
				}

				$slugs[ $post->ID ] = $slug . '-' . $verschijningsvorm_slug;
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

	private static function update_offset( $offset, $etalage ) {
		global $wpdb;

		// also set running to 1 so next batch can be done
		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'running' => 1,
				'offset'  => $offset,
			),
			array( 'id' => $etalage->id )
		);
	}

	private static function set_last_import( $id, $value = null ) {
		global $wpdb;
		if ( is_null( $value ) ) {
			$value = current_time( 'mysql', 1 );
		}

		return $wpdb->update( $wpdb->prefix . 'boekdb_etalages', array( 'last_import' => $value ),
			array( 'id' => $id ) );
	}

	public static function start_import() {
		set_time_limit( 0 );

		// handle options
		self::$options['overwrite_images'] = get_transient( 'boekdb_import_options_overwrite_images' );

		if ( boekdb_is_import_running() ) {
			boekdb_debug( 'Import already running' );

			// check schedule
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}

			return;
		}

		$etalages = BoekDB::fetch_etalages();
		foreach ( $etalages as $etalage ) {
			self::update_running( 2, $etalage );

			$reset = self::check_available_isbns( $etalage );
			if ( $reset ) {
				boekdb_debug( 'last import has been reset' );
			}

			$last_import = $etalage->last_import;
			if ( $reset || is_null( $last_import ) ) {
				$last_import = self::DEFAULT_LAST_IMPORT;
				self::set_last_import( $etalage->id, $last_import );
			}

			// fire first import event
			wp_schedule_single_event( time(), BoekDB_Import::IMPORT_HOOK );
		}
	}

	private static function check_available_isbns( $etalage ) {
		global $wpdb;

		$isbns = Boekdb_Api_Service::fetch_isbns( $etalage->api_key );
		self::trash_removed( $etalage->id, $isbns['isbns'] );

		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'isbns' => count( $isbns['isbns'] ),
			),
			array( 'id' => $etalage->id )
		);

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
					BoekDB_Cleanup::delete_posts( [ $post_id ] );
				}
			}
		}
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
			BoekDB_Cleanup::delete_posts( $post_ids );

			boekdb_debug( 'running cleanup' );
			BoekDB_Cleanup::cleanup();
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

