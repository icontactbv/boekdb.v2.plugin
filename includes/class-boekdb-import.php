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

	const DEFAULT_LAST_IMPORT = '2015-01-01T01:00:00+01:00';

	/**
	 * Seconds a single file download may take, before the boekdb_file_download_timeout
	 * filter.
	 */
	const DOWNLOAD_TIMEOUT = 300;

	/**
	 * Seconds before the lock of a batch that stopped reporting in is handed to the next run.
	 */
	const LOCK_TIMEOUT = 600;

	/**
	 * Option that says an import was stopped by hand.
	 */
	const STOPPED_OPTION = 'boekdb_import_stopped';

	/**
	 * Prefix of the option holding the moment a run started on an etalage.
	 */
	const START_OPTION_PREFIX = 'boekdb_import_start_';

	/**
	 * Identifies the claim this run holds, so it can tell its own lock from the one a later
	 * run took over.
	 *
	 * @var string|null
	 */
	private static $lock_token = null;

	/**
	 * Initialize the import process
	 *
	 * This method adds action hooks for starting the import and performing the import.
	 * It also schedules the import process to run hourly and minutely if not already scheduled.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::START_IMPORT_HOOK, array( self::class, 'start_import' ) );
		add_action( self::IMPORT_HOOK, array( self::class, 'import' ) );

		/**
		 * Start the import process every hour.
		 */
		if ( ! wp_next_scheduled( self::START_IMPORT_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::START_IMPORT_HOOK );
		}

		/**
		 * Check for running imports every minute.
		 */
		if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
			wp_schedule_event( time(), 'minutely', self::IMPORT_HOOK );
		}
	}

	/**
	 * Stop every import.
	 *
	 * Running batches notice through check_stopped() and put their work down. The offset
	 * stays where it was, so an import that is started again carries on instead of
	 * downloading everything from the beginning, and nothing starts by itself until someone
	 * presses Run import.
	 *
	 * @return void
	 */
	public static function stop_import() {
		global $wpdb;

		update_option( self::STOPPED_OPTION, 1, false );

		$wpdb->query( "UPDATE {$wpdb->prefix}boekdb_etalages SET running = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Take a stopped import off hold and start it.
	 *
	 * @return void
	 */
	public static function resume_import() {
		delete_option( self::STOPPED_OPTION );

		self::start_import();
	}

	/**
	 * Import products from API
	 */
	public static function import() {
		set_time_limit( 0 );
		boekdb_debug( 'Importing products...' );

		self::reclaim_stale_locks();

		// fetch running imports
		$etalages = BoekDB::fetch_etalages( true );
		if( $etalages === false ) {
			boekdb_debug( 'Error fetching etalages' );

			return;
		}

		if ( count( $etalages ) === 0 ) {
			boekdb_debug( 'No imports ready to run found.' );

			return;
		}

		// do one etalage at a time, reset() returns the first value of the array
		$etalage = reset( $etalages );

		$offset = $etalage->offset;
		if ( is_null( $etalage->last_import ) ) {
			$etalage->last_import = self::DEFAULT_LAST_IMPORT;
		}
		$last_import = new DateTime( $etalage->last_import, wp_timezone() );
		$last_import = $last_import->format( 'Y-m-d\TH:i:sP' );

		$products = Boekdb_Api_Service::fetch_products( $etalage->api_key, $last_import, $offset );

		if ( $products === false ) {
			boekdb_debug( 'Error fetching products from API' );

			return;
		}

		if ( count( $products ) > 0 ) {
			boekdb_debug( 'Fetched ' . $etalage->name . ' with offset ' . $offset );
			boekdb_debug( 'Contains ' . count( $products ) . ' books' );

			self::claim_lock( $etalage );

			// A fatal halts the script on the spot, so no code after this point runs and a
			// finally block would not either. Only a shutdown function still gets a turn.
			register_shutdown_function( array( self::class, 'release_lock_on_shutdown' ), $etalage );

			foreach ( $products as $product ) {
				if ( self::check_stopped( $etalage ) ) {
					// The import was stopped, so we stop processing this etalage
					return;
				}

				if ( ! self::holds_lock( $etalage ) ) {
					// Another run took this etalage over. Carrying on would have two imports
					// writing the same books.
					boekdb_debug( 'Lost the import lock on ' . $etalage->name );

					return;
				}

				// Says the batch is alive, so a run that takes minutes is not mistaken for one
				// that died.
				self::refresh_lock( $etalage );

				// One unusable product used to end the whole batch, leaving every book behind
				// it unimported and the offset where it was.
				try {
					list( $boek_post_id, $isbn, $nstc, $slug ) = self::handle_boek( $product );

					boekdb_debug( 'Processing ' . $isbn );

					self::handle_betrokkenen( $product, $boek_post_id );

					$thema = array();
					$nur   = array();
					$bisac = array();

					foreach ( $product->onderwerpen as $onderwerp ) {
						if ( $onderwerp->type === 'NUR' ) {
							$nur[] = self::get_taxonomy_term_id(
								sanitize_title( $onderwerp->code ),
								'nur',
								$onderwerp->waarde
							);
						} elseif ( $onderwerp->type === 'BISAC' ) {
							$bisac[] = self::get_taxonomy_term_id(
								sanitize_title( $onderwerp->code ),
								'bisac',
								$onderwerp->waarde
							);
						} elseif ( substr( $onderwerp->type, 0, 5 ) === 'Thema' ) {
							$thema[] = self::get_taxonomy_term_id(
								sanitize_title( boekdb_thema_omschrijving( $onderwerp->code ) ),
								'thema',
								boekdb_thema_omschrijving( $onderwerp->code )
							);
						}
					}
					wp_set_object_terms( $boek_post_id, $nur, 'boekdb_nur_tax' );
					wp_set_object_terms( $boek_post_id, $bisac, 'boekdb_bisac_tax' );
					wp_set_object_terms( $boek_post_id, $thema, 'boekdb_thema_tax' );

					self::link_product( $boek_post_id, $isbn, $etalage->id );
					self::check_primary_title( $boek_post_id, $nstc, $slug );
				} catch ( Throwable $e ) {
					boekdb_debug( 'Skipped ' . ( isset( $product->isbn ) ? $product->isbn : 'a product' ) . ': ' . $e->getMessage() );
				}
			}
			$offset = $offset + Boekdb_Api_Service::LIMIT;

			// update the offset in etalage and set running to 1 (next batch).
			self::update_offset( $offset, $etalage );
			self::release_lock( $etalage, 2 );

			boekdb_debug( 'Done with this batch...' );

			// there might be more etalages to import, so schedule a new import.
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}
		} else {
			boekdb_debug( 'Finished import on ' . $etalage->name );
			self::set_last_import( $etalage->id );

			// reset offset and set running to 0 (finished).
			self::update_offset( 0, $etalage );
			self::release_lock( $etalage, 0 );

			// there might be more etalages to import, so schedule a new import.
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}
		}
	}

	/**
	 * Check if the import process has stopped for a given 'etalage'
	 *
	 * @param object $etalage  The 'etalage' object
	 *
	 * @return bool Returns true if the import process has stopped for the given 'etalage', false otherwise
	 */
	private static function check_stopped( $etalage ) {
		$running = self::fetch_etalage_running( $etalage->id );
		if ( $running === 0 ) {
			boekdb_debug( 'Import stopped on ' . $etalage->name );

			return true;
		}

		return false;
	}

	/**
	 * Fetch the running status of an etalage based on its ID
	 *
	 * @param int $id  The ID of the etalage
	 *
	 * @return int The running status of the etalage
	 */
	private static function fetch_etalage_running( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'boekdb_etalages';
		$running    = $wpdb->get_var( $wpdb->prepare( "SELECT running FROM {$table_name} WHERE id = %d", $id ) );

		return (int) $running;
	}

	/**
	 * Release the lock of a batch that is ending without having finished.
	 *
	 * Runs when the script ends, which is the last thing a fatal error leaves room for. The
	 * batch itself is lost either way, but the etalage goes back in the queue instead of
	 * waiting out the lock timeout. Nothing happens if the lock has already been handed on,
	 * which is the normal end of a batch, or if the import was stopped.
	 *
	 * @param object $etalage  The etalage that was being processed.
	 *
	 * @return void
	 */
	public static function release_lock_on_shutdown( $etalage ) {
		if ( ! self::holds_lock( $etalage ) || self::fetch_etalage_running( $etalage->id ) !== 1 ) {
			return;
		}

		$error = error_get_last();
		boekdb_debug( 'Import on ' . $etalage->name . ' ended while holding its lock: ' . ( is_null( $error ) ? 'no error reported' : $error['message'] ) );

		self::release_lock( $etalage, 2 );
	}

	/**
	 * Claim an etalage for this run.
	 *
	 * @param object $etalage  The etalage to claim.
	 *
	 * @return void
	 */
	private static function claim_lock( $etalage ) {
		self::$lock_token = wp_generate_uuid4();

		update_option(
			self::lock_option( $etalage->id ),
			array(
				'token' => self::$lock_token,
				'time'  => time(),
			),
			false
		);

		self::update_running( 1, $etalage );
	}

	/**
	 * Whether the lock on an etalage is the one this run took.
	 *
	 * @param object $etalage  The etalage.
	 *
	 * @return bool
	 */
	private static function holds_lock( $etalage ) {
		if ( is_null( self::$lock_token ) ) {
			return false;
		}

		$lock = get_option( self::lock_option( $etalage->id ) );

		return is_array( $lock ) && isset( $lock['token'] ) && $lock['token'] === self::$lock_token;
	}

	/**
	 * Record that this run is still working on its etalage.
	 *
	 * @param object $etalage  The etalage.
	 *
	 * @return void
	 */
	private static function refresh_lock( $etalage ) {
		update_option(
			self::lock_option( $etalage->id ),
			array(
				'token' => self::$lock_token,
				'time'  => time(),
			),
			false
		);
	}

	/**
	 * Let go of the lock and leave the etalage in the given state.
	 *
	 * @param object $etalage  The etalage.
	 * @param int    $running  The state to leave behind: 2 for another batch, 0 for done.
	 *
	 * @return void
	 */
	private static function release_lock( $etalage, $running ) {
		delete_option( self::lock_option( $etalage->id ) );
		self::$lock_token = null;

		self::update_running( $running, $etalage );
	}

	/**
	 * Name of the option holding the moment an etalage was claimed.
	 *
	 * @param int $id  The etalage.
	 *
	 * @return string
	 */
	private static function lock_option( $id ) {
		return 'boekdb_import_lock_' . (int) $id;
	}

	/**
	 * Hand the lock of an abandoned batch to the next run.
	 *
	 * A batch marks its etalage as running while it works. Should the process die before it
	 * is done - out of memory, a fatal, a worker that is killed - that mark stays, and the
	 * etalage is never imported again: import() only looks at etalages that are waiting, and
	 * the Run button reports that an import is already running.
	 *
	 * @return void
	 */
	private static function reclaim_stale_locks() {
		global $wpdb;

		$etalages = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}boekdb_etalages WHERE running = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $etalages as $etalage ) {
			$lock = get_option( self::lock_option( $etalage->id ) );

			// A lock without a date is one from before this version, or one a run never got
			// round to writing.
			if ( is_array( $lock ) && isset( $lock['time'] ) && ( time() - (int) $lock['time'] ) < self::LOCK_TIMEOUT ) {
				continue;
			}

			boekdb_debug( 'Taking back the import lock on ' . $etalage->name );
			delete_option( self::lock_option( $etalage->id ) );
			self::update_running( 2, $etalage );
		}
	}

	/**
	 * Update the 'running' field in the 'boekdb_etalages' table
	 *
	 * @param int    $running  The new value for the 'running' field
	 * @param object $etalage  The object representing the etalage record
	 *
	 * @return void
	 */
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
	 * @param object $product  The product object
	 *
	 * @return array
	 */
	protected static function handle_boek( $product ) {
		global $wpdb;

		$boek         = self::create_boek_array( $product );
		$boek_post_id = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT boek_id FROM {$wpdb->prefix}boekdb_isbns WHERE isbn = %s",
				$boek['isbn']
			)
		);

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
			$boek_post_id = wp_insert_post( $post );
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
	 * @param object $product  The product object
	 *
	 * @return array
	 */
	protected static function create_boek_array( $product ) {
		$boek                           = array();
		$boek['nstc']                   = $product->nstc ?? null;
		$boek['titel']                  = $product->titel;
		$boek['isbn']                   = $product->isbn;
		$boek['subtitel']               = $product->subtitel ?? null;
		$boek['deeltitel']              = $product->deeltitel ?? null;
		$boek['sectietitel']            = $product->sectietitel ?? null;
		$boek['origineletitel']         = $product->origineletitel ?? null;
		$boek['serietitel']             = $product->serietitel ?? null;
		$boek['deel']                   = $product->deel ?? null;
		$boek['druk']                   = $product->druk ?? null;
		$boek['verschijningsvorm']      = $product->verschijningsvorm ?? null;
		$boek['verschijningsvorm_code'] = isset( $product->verschijningsvorm_code ) ? $product->verschijningsvorm_code : null;
		$boek['uitgever']               = $product->uitgever ?? null;
		$boek['imprint']                = $product->imprint ?? null;
		$boek['inhoudsopgave']          = $product->inhoudsopgave ?? null;
		$boek['taal']                   = $product->taal ?? null;
		$boek['illustraties']           = $product->illustraties ?? null;
		$boek['lengte']                 = $product->lengte ?? null;
		$boek['breedte']                = $product->breedte ?? null;
		$boek['dikte']                  = $product->dikte ?? null;
		$boek['gewicht']                = $product->gewicht ?? null;
		$boek['paginas_hoofdwerk']      = $product->paginas_hoofdwerk ?? null;
		$boek['paginas_proloog']        = $product->paginas_proloog ?? null;
		$boek['paginas_epiloog']        = $product->paginas_epiloog ?? null;
		$boek['duur']                   = $product->duur ?? null;
		$boek['bestandsgrootte']        = $product->bestandsgrootte ?? null;
		$boek['leeftijdscategorie']     = $product->leeftijdscategorie ?? null;
		$boek['avi']                    = $product->avi ?? null;
		$boek['beschikbaarheidsdatum']  = $product->beschikbaarheidsdatum ?? null;
		$boek['publicatiedatum']        = $product->publicatiedatum ?? null;
		$boek['prijs']                  = $product->prijs ?? null;
		$boek['status']                 = $product->status ?? null;
		$boek['leverbaarheid']          = $product->leverbaarheid ?? null;
		$boek['biografie']              = $product->biografie ?? null;
		$boek['actieprijzen']           = array();
		$boek['links']                  = array();
		$boek['literaireprijzen']       = array();
		$boek['recensiequotes']         = array();
		$boek['recensielinks']          = array();

		// overschrijfbare velden
		$boek['annotatie'] = $product->annotatie ?? null;
		$boek['flaptekst'] = $product->flaptekst ?? null;

		if ( isset( $product->actieprijzen ) && ! is_null( $product->actieprijzen ) ) {
			foreach ( $product->actieprijzen as $actieprijs ) {
				$boek['actieprijzen'][] = array(
					'actieprijs'         => $actieprijs->actieprijs,
					'actieperiode_start' => $actieprijs->actieperiode_start,
					'actieperiode_einde' => $actieprijs->actieperiode_einde,
				);
			}
		}

		if ( isset( $product->links ) && ! is_null( $product->links ) ) {
			foreach ( $product->links as $link ) {
				$boek['links'][] = array(
					'soort' => strtolower( $link->soort ?? '' ),
					'url'   => $link->url,
				);
			}
		}

		if ( isset( $product->literaireprijzen ) && ! is_null( $product->literaireprijzen ) ) {
			foreach ( $product->literaireprijzen as $prijs ) {
				$boek['literaireprijzen'][] = array(
					'prestatie'    => $prijs->prestatie,
					'naam'         => $prijs->naam,
					'jaar'         => $prijs->jaar,
					'land'         => $prijs->land,
					'omschrijving' => $prijs->omschrijving,
				);
			}
		}

		if ( isset( $product->recensiequotes ) && ! is_null( $product->recensiequotes ) ) {
			foreach ( $product->recensiequotes as $quote ) {
				$boek['recensiequotes'][ md5( $quote->tekst ) ] = array(
					'tekst'  => $quote->tekst,
					'auteur' => $quote->auteur ?? null,
					'bron'   => $quote->bron ?? null,
					'datum'  => $quote->datum ?? null,
					'tonen'  => true,
				);
			}
		}

		if ( isset( $product->recensielinks ) && ! is_null( $product->recensielinks ) ) {
			foreach ( $product->recensielinks as $link ) {
				$boek['recensielinks'][] = array(
					'soort' => strtolower( $link->type ),
					'url'   => $link->url,
					'bron'  => $link->bron,
					'datum' => $link->datum,
				);
			}
		}

		return $boek;
	}

	/**
	 * Find the post ID of a specific field based on the given key-value pair
	 *
	 * @param string     $post_type  The post type to search within
	 * @param string     $key        The meta key to search for
	 * @param string|int $value      The meta value to match against
	 *
	 * @return int|null  The ID of the post that matches the given key-value pair, or null if no match was found
	 */
	private static function find_field( $post_type, $key, $value ) {
		global $wpdb;

		// One query. A WP_Query with a meta condition loads the posts, their meta and their
		// terms, and this runs for every file of every product of every batch.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
					WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'draft', 'inherit' ) AND m.meta_value = %s
					ORDER BY p.post_date DESC, p.ID DESC",
				$key,
				$post_type,
				$value
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( count( $post_ids ) === 0 ) {
			return null;
		}

		// The last of them, which is the one the post query handed back before.
		return (int) end( $post_ids );
	}

	/**
	 * Handle the serie for a book product
	 *
	 * @param mixed $product       The product object containing the serie information
	 * @param int   $boek_post_id  The ID of the book post
	 *
	 * @return void
	 */
	protected static function handle_serie( $product, $boek_post_id ) {
		if ( isset( $product->serie ) && is_object( $product->serie ) && isset( $product->serie->id ) ) {
			// clear old taxonomy
			wp_set_object_terms( $boek_post_id, null, 'boekdb_serie_tax' );

			// set new taxonomy
			wp_set_object_terms( $boek_post_id, $product->serietitel, 'boekdb_serie_tax', false );

			// get taxonomy
			$result = wp_get_object_terms( $boek_post_id, 'boekdb_serie_tax', true );
			$term   = $result[0];

			add_term_meta( $term->term_id, 'boekdb_id', $product->serie->id, true );
			wp_update_term(
				$term->term_id,
				'boekdb_serie_tax',
				array(
					'description' => $product->serie->omschrijving,
				)
			);

			self::handle_serie_files( $product, $term->term_id );
		}
	}

	/**
	 * Load files for serie
	 *
	 * @param object $product  The product object
	 * @param int    $term_id  The term ID of the serie
	 */
	protected static function handle_serie_files( $product, $term_id ) {
		if ( ! isset( $product->serie->beeld ) ) {
			return;
		}

		$bestand       = $product->serie->beeld;
		$hash          = md5( $bestand->url );
		$attachment_id = self::find_field( 'attachment', 'hash', $hash );

		if ( is_null( $attachment_id ) ) {
			$image = self::download_file( $bestand );
			if ( is_null( $image ) ) {
				return;
			}

			$attachment = array(
				'post_title'     => $bestand->soort,
				'post_mime_type' => $image['type'],
			);

			$attachment_id = wp_insert_attachment( $attachment, $image['file'] );
			self::store_attachment_metadata( $attachment_id, $image, $bestand->soort );

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
	 * Download a file from the API into the uploads directory.
	 *
	 * @param object $bestand  The file as the API describes it.
	 *
	 * @return array|null The file as wp_handle_sideload() returns it, or null on failure.
	 */
	private static function download_file( $bestand ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		/**
		 * Filters how long a single file download may take.
		 *
		 * The five seconds wp_remote_get() allows is not enough for the fragments BoekDB
		 * serves, which run into tens of megabytes. download_url() defaults to 300 too.
		 *
		 * @param int    $timeout  Timeout in seconds.
		 * @param string $url      The file being downloaded.
		 */
		$timeout = (int) apply_filters( 'boekdb_file_download_timeout', self::DOWNLOAD_TIMEOUT, $bestand->url );

		// Streams to a temporary file. Reading the body into a string instead puts the whole
		// file in memory, which a 50MB fragment does not survive on a 128M limit.
		$tmp_file = download_url( $bestand->url, $timeout );
		if ( is_wp_error( $tmp_file ) ) {
			boekdb_debug( 'Error fetching file: ' . $bestand->url );
			boekdb_debug( $tmp_file );

			return null;
		}

		// wp_handle_sideload() takes its first argument by reference.
		$sideload = array(
			'name'     => sanitize_file_name( $bestand->bestandsnaam ),
			'tmp_name' => $tmp_file,
		);
		$file     = wp_handle_sideload( $sideload, array( 'test_form' => false ) );

		if ( isset( $file['error'] ) ) {
			boekdb_debug( 'Error saving file to disk: ' . $file['error'] );
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}

			return null;
		}

		return $file;
	}

	/**
	 * Generate and store the metadata of a downloaded attachment.
	 *
	 * Only images get metadata. Handing WordPress a PDF makes it render a preview of the
	 * first page, which costs more memory than the download it follows.
	 *
	 * @param int    $attachment_id  The attachment.
	 * @param array  $file           The file as wp_handle_sideload() returned it.
	 * @param string $soort          The BoekDB file type, e.g. Cover.
	 *
	 * @return void
	 */
	private static function store_attachment_metadata( $attachment_id, $file, $soort ) {
		// The type detected from the file itself, which the API does not always send along.
		if ( strpos( (string) $file['type'], 'image/' ) !== 0 ) {
			return;
		}

		// needed for WordPress image related functions
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file['file'] );

		if ( $soort === 'Cover' || $soort === 'Back cover' ) {
			// check if sizes were generated
			if ( ! isset( $attachment_data['sizes'] ) || ! is_array( $attachment_data['sizes'] ) || empty( $attachment_data['sizes'] ) ) {
				boekdb_debug( 'Failed to generate image sizes for attachment ID: ' . $attachment_id );
			}
		}

		wp_update_attachment_metadata( $attachment_id, $attachment_data );
	}

	/**
	 * Load files from boek
	 *
	 * @param object $product       The product object
	 * @param int    $boek_post_id  The ID of the book post
	 *
	 * @return void
	 */
	protected static function handle_boek_files( $product, $boek_post_id ) {
		if ( ! isset( $product->bestanden ) ) {
			return;
		}

		foreach ( $product->bestanden as $bestand ) {
			$hash          = md5( $bestand->url );
			$attachment_id = self::find_field( 'attachment', 'hash', $hash );

			if ( is_null( $attachment_id ) ) {
				$file = self::download_file( $bestand );
				if ( is_null( $file ) ) {
					continue;
				}

				$attachment = array(
					'post_title'     => $bestand->soort,
					'post_mime_type' => $file['type'],
				);

				$attachment_id = wp_insert_attachment( $attachment, $file['file'], $boek_post_id );
				if ( ! is_wp_error( $attachment_id ) ) {
					self::store_attachment_metadata( $attachment_id, $file, $bestand->soort );
				} else {
					boekdb_debug( 'Error inserting attachment: ' . $attachment_id->get_error_message() );
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
				$attachment = get_post( $attachment_id );

				// Saving an attachment that is already attached to this book costs a handful
				// of queries and fires every hook a site has on save_post, for every file of
				// every product.
				if ( ! is_null( $attachment ) && (int) $attachment->post_parent !== (int) $boek_post_id ) {
					wp_update_post(
						array(
							'ID'          => $attachment_id,
							'post_parent' => $boek_post_id,
						)
					);
				}
				// check if the file exists
				if ( ! file_exists( get_attached_file( $attachment_id ) ) ) {
					// delete the attachment
					wp_delete_attachment( $attachment_id, true );

					// re-run this function
					self::handle_boek_files( $product, $boek_post_id );

					// That run downloaded every file of this product again and linked them.
					// Carrying on here would overwrite those links with the attachment that
					// was just deleted.
					return;
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
	 * Handle betrokkenen for a book product
	 *
	 * @param object $product       The book product object
	 * @param int    $boek_post_id  The ID of the book post
	 *
	 * @return void
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
	 * Create an array of betrokkene (related party) data
	 *
	 * @param object $betrokkene  The betrokkene object
	 *
	 * @return array The betrokkene data array
	 */
	protected static function create_betrokkene_array( $betrokkene ) {
		$boekdb_betrokkene                         = array();
		$boekdb_betrokkene['id']                   = $betrokkene->id ?? null;
		$boekdb_betrokkene['naam']                 = $betrokkene->naam;
		$boekdb_betrokkene['boekdb_voornaam']      = $betrokkene->voornaam ?? null;
		$boekdb_betrokkene['boekdb_tussenvoegsel'] = $betrokkene->tussenvoegsel ?? null;
		$boekdb_betrokkene['boekdb_achternaam']    = $betrokkene->achternaam ?? null;
		$boekdb_betrokkene['boekdb_organisatie']   = $betrokkene->organisatie ?? null;
		$boekdb_betrokkene['boekdb_biografie']     = $betrokkene->biografie ?? null;
		$boekdb_betrokkene['boekdb_bibliografie']  = $betrokkene->bibliografie ?? null;
		$boekdb_betrokkene['bestanden']            = $betrokkene->bestanden ?? null;

		return $boekdb_betrokkene;
	}

	/**
	 * Handle the betrokkene by creating or updating a term in the specified taxonomy
	 *
	 * @param array  $betrokkene  The betrokkene data
	 * @param string $taxonomy    The taxonomy to create or update the term in
	 *
	 * @return int                The ID of the created or updated term
	 */
	protected static function handle_betrokkene( $betrokkene, $taxonomy ) {
		$term = get_term_by(
			'slug',
			sanitize_title( $betrokkene['naam'], $betrokkene['id'] ),
			'boekdb_' . $taxonomy . '_tax'
		);
		if ( $term ) {
			$term_id = $term->term_id;
			wp_update_term(
				$term_id,
				'boekdb_' . $taxonomy . '_tax',
				array(
					'name' => $betrokkene['naam'],
					'slug' => sanitize_title( $betrokkene['naam'], $betrokkene['id'] ),
				)
			);
		} else {
			$result  = wp_insert_term(
				$betrokkene['naam'],
				'boekdb_' . $taxonomy . '_tax',
				array(
					'name' => $betrokkene['naam'],
					'slug' => sanitize_title( $betrokkene['naam'], $betrokkene['id'] ),
				)
			);
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
	 * Handle betrokkene files and attach them to the corresponding term
	 *
	 * @param array $betrokkene  An array containing information about the files
	 * @param int   $term_id     The ID of the term to attach the files to
	 *
	 * @return void
	 */
	protected static function handle_betrokkene_files( $betrokkene, $term_id ) {
		foreach ( $betrokkene['bestanden'] as $bestand ) {
			if ( $bestand->soort !== 'Auteursfoto' ) {
				continue;
			}

			$hash          = md5( $bestand->url );
			$attachment_id = self::find_field( 'attachment', 'hash', $hash );

			if ( is_null( $attachment_id ) ) {
				$image = self::download_file( $bestand );
				if ( is_null( $image ) ) {
					continue;
				}

				$attachment = array(
					'post_title'     => 'Auteursfoto',
					'post_mime_type' => $image['type'],
				);

				$attachment_id = wp_insert_attachment( $attachment, $image['file'] );
				if ( ! is_wp_error( $attachment_id ) ) {
					self::store_attachment_metadata( $attachment_id, $image, $bestand->soort );
				} else {
					boekdb_debug( 'Error inserting attachment: ' . $attachment_id->get_error_message() );
					continue;
				}

				update_post_meta( $attachment_id, 'hash', $hash );
				update_term_meta( $term_id, 'auteursfoto_id', $attachment_id );
				if ( isset( $bestand->copyright ) ) {
					update_term_meta( $term_id, 'auteursfoto_copyright', $bestand->copyright );
				}
			} else {
				// checks if attachment is already linked to this contributor
				$existing_auteursfoto_id = get_term_meta( $term_id, 'auteursfoto_id', true );
				if ( $existing_auteursfoto_id !== $attachment_id ) {
					update_term_meta( $term_id, 'auteursfoto_id', $attachment_id );
				}
			}
		}
	}

	/**
	 * Get the term ID for a given taxonomy term
	 *
	 * @param string $slug      The slug of the taxonomy term
	 * @param string $taxonomy  The taxonomy name
	 * @param string $value     The value to insert if the term doesn't exist
	 *
	 * @return int|null The term ID if the term exists, null otherwise
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
				)
			);
			$term_id = $result['term_id'];
		}

		return $term_id;
	}

	/**
	 * Link a product (book) to an etalage and update the ISBNs
	 *
	 * @param int    $boek_id     The ID of the book to link
	 * @param string $isbn        The ISBN value for the book
	 * @param int    $etalage_id  The ID of the etalage to link the book to
	 *
	 * @return void
	 */
	private static function link_product( $boek_id, $isbn, $etalage_id ) {
		global $wpdb;

		$wpdb->replace(
			$wpdb->prefix . 'boekdb_etalage_boeken',
			array(
				'etalage_id' => $etalage_id,
				'boek_id'    => $boek_id,
			)
		);
		$wpdb->replace(
			$wpdb->prefix . 'boekdb_isbns',
			array(
				'isbn'    => $isbn,
				'boek_id' => $boek_id,
			)
		);
	}

	/**
	 * Check if a book should be set as the primary title based on nstc value
	 *
	 * @param int|null    $post_id  The ID of the book post
	 * @param string|null $nstc     The nstc value for filtering books
	 * @param string      $slug     The primary slug for the book
	 *
	 * @return void
	 */
	private static function check_primary_title( $post_id, $nstc, $slug ) {
		global $wpdb;

		// if nstc is null, set current book to primary
		if ( is_null( $nstc ) ) {
			update_post_meta( $post_id, 'boekdb_primary', 1 );

			return;
		}

		// Every book of this title, in one query. A WP_Query with a meta_query ran several,
		// for every product of every batch.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'boekdb_nstc'
					WHERE p.post_type = 'boekdb_boek' AND p.post_status = 'publish' AND m.meta_value = %s
					ORDER BY p.post_date DESC, p.ID DESC",
				$nstc
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		// get productform and secondary slug for each book
		$books = array();
		$slugs = array();

		if ( count( $post_ids ) > 0 ) {
			// One query for the meta of every book, instead of two per book.
			update_meta_cache( 'post', $post_ids );

			foreach ( $post_ids as $book_id ) {
				$status                 = get_post_meta( $book_id, 'boekdb_status', true );
				$verschijningsvorm      = get_post_meta( $book_id, 'boekdb_verschijningsvorm', true );
				$verschijningsvorm_slug = boekdb_verschijningsvorm_slug( $verschijningsvorm );

				if ( $status !== '10' && $status !== '21' && $status !== '23' ) {
					$books[ $book_id ] = 'xxxxx';
				} else {
					$books[ $book_id ] = substr( $verschijningsvorm, 0, 5 );
				}

				$slugs[ $book_id ] = $slug . '-' . $verschijningsvorm_slug;
			}
		}

		// uasort books by productform
		uasort( $books, array( self::class, 'sort_books_by_productform' ) );
		$post_ids = array_keys( $books );

		// set primary bit and main slug on first book
		$sorted   = array_keys( $books );
		$first_id = array_shift( $sorted );
		unset( $books[ $first_id ] );
		update_post_meta( $first_id, 'boekdb_primair', 1 );
		self::set_post_name( $first_id, $slug );

		// disable primary bit on all other books and set slug to secondary
		foreach ( $books as $book_id => $val ) {
			update_post_meta( $book_id, 'boekdb_primair', 0 );
			self::set_post_name( $book_id, $slugs[ $book_id ] );
		}
	}

	/**
	 * Give a book its slug, if it does not have it already.
	 *
	 * Saving a post that is not changing still costs a handful of queries and fires every
	 * hook a site has on save_post. With a title that has six editions, each of them saved
	 * all the others on every run.
	 *
	 * @param int    $post_id    The book.
	 * @param string $post_name  The slug it should carry.
	 *
	 * @return void
	 */
	private static function set_post_name( $post_id, $post_name ) {
		$post = get_post( $post_id );

		if ( ! is_null( $post ) && $post->post_name === $post_name ) {
			return;
		}

		wp_update_post(
			array(
				'post_name' => $post_name,
				'ID'        => $post_id,
			)
		);

		// clear the permalink transient for this book
		delete_transient( 'boekdb_permalink_' . $post_id );
	}

	/**
	 * Update the offset and running status of an etalage.
	 *
	 * @param int    $offset   The new offset value
	 * @param object $etalage  The etalage object to update
	 *
	 * @return void
	 */
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

	/**
	 * Set the last import value for a given id
	 *
	 * @param int         $id     The id of the import
	 * @param string|null $value  The value to set as the last import date. If null, the current time will be used.
	 *
	 * @return bool|int False on failure, or the number of rows updated on success.
	 */
	private static function set_last_import( $id, $value = null ) {
		global $wpdb;
		if ( is_null( $value ) ) {
			// The moment this run started, so the next one picks up everything that changed
			// while it was working. Falls back to now for a run that started before this
			// version.
			$value = get_option( self::START_OPTION_PREFIX . $id );
			delete_option( self::START_OPTION_PREFIX . $id );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			$value = current_time( 'mysql', 1 );
		}

		return $wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array( 'last_import' => $value ),
			array( 'id' => $id )
		);
	}

	/**
	 * Start the import process
	 *
	 * Set the time limit to 0 to prevent script timeout.
	 * If an import is already running, check if the import schedule is set.
	 * If not, schedule a single event to start the import.
	 *
	 * @return void
	 */
	public static function start_import() {
		set_time_limit( 0 );

		if ( get_option( self::STOPPED_OPTION ) ) {
			boekdb_debug( 'Import was stopped, not starting' );

			return;
		}

		if ( boekdb_is_import_running() ) {
			boekdb_debug( 'Import already running' );

			// check schedule
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}

			return;
		}

		try {
			$etalages = BoekDB::fetch_etalages();
			foreach ( $etalages as $etalage ) {
				// validate api key
				if ( ! Boekdb_Api_Service::validate_api_key( $etalage->api_key ) ) {
					// delete etalage
					BoekDB_Cleanup::delete_etalage( $etalage->id );
					set_transient(
						'boekdb_admin_notice',
						'Etalage ' . $etalage->name . ' is verwijderd omdat de API-sleutel ongeldig was.',
						3600
					);
				}

				// What the next run will ask BoekDB for. Taking the moment this run ends
				// instead would skip everything that changed while it was busy.
				update_option( self::START_OPTION_PREFIX . $etalage->id, current_time( 'mysql', 1 ), false );

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
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}
		} catch ( Exception $e ) {
			set_transient( 'boekdb_admin_notice', 'Let op! Connectie met BoekDB is verbroken.', 3600 );
		}
	}

	/**
	 * Check available ISBNs
	 *
	 * @param object $etalage  - The etalage object
	 *
	 * @return bool - Returns true if the filters have changed and the etalage needs to be reset, else returns false
	 */
	private static function check_available_isbns( $etalage ) {
		global $wpdb;

		$isbns = Boekdb_Api_Service::fetch_isbns( $etalage->api_key );
		if ( $isbns === false ) {
			return false;
		}
		BoekDB_Cleanup::trash_removed( $etalage->id, $isbns['isbns'] );

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

			// reset.
			return true;
		}

		// no reset.
		return false;
	}

	/**
	 * Sorts books by product form.
	 *
	 * @param string $a  The product form of book A.
	 * @param string $b  The product form of book B.
	 *
	 * @return int Returns -1 if book A should be sorted before book B,
	 *              0 if book A and book B have the same sorting priority,
	 *              or 1 if book A should be sorted after book B
	 */
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
		if ( $a === $b ) {
			return 0;
		}

		return ( $a < $b ) ? - 1 : 1;
	}
}

BoekDB_Import::init();
