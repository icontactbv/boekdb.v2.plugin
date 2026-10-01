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
	 * Prefix of the option a run holds its claim on an etalage in.
	 */
	const LOCK_OPTION_PREFIX = 'boekdb_import_lock_';

	/**
	 * Option that says an import was stopped by hand.
	 */
	const STOPPED_OPTION = 'boekdb_import_stopped';

	/**
	 * Prefix of the option holding the moment a run started on an etalage.
	 */
	const START_OPTION_PREFIX = 'boekdb_import_start_';

	/**
	 * Prefix of the option holding the books of an etalage that could not be imported.
	 */
	const FAILED_OPTION_PREFIX = 'boekdb_import_failed_';

	/**
	 * How often a book that cannot be imported is fetched again before it is left alone.
	 */
	const FAILED_ATTEMPTS = 3;

	/**
	 * Identifies the claim this run holds, so it can tell its own lock from the one a later
	 * run took over.
	 *
	 * @var string|null
	 */
	private static $lock_token = null;

	/**
	 * The lock exactly as this run last wrote it, so a change can be made conditional on
	 * the lock still being that one.
	 *
	 * @var string|null
	 */
	private static $lock_value = null;

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
		if ( $etalages === false ) {
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

			if ( self::is_stopped() ) {
				// Stop was pressed while this batch was being fetched.
				boekdb_debug( 'Import was stopped while fetching ' . $etalage->name );

				return;
			}

			if ( ! self::claim_lock( $etalage ) ) {
				return;
			}

			foreach ( $products as $product ) {
				if ( self::check_stopped( $etalage ) ) {
					// The import was stopped, so we stop processing this etalage. Its lock
					// goes with it, or starting again is refused until that times out.
					self::release_lock( $etalage, 0 );

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
				// it unimported and the offset where it was. A product that cannot be
				// imported is written down and fetched again on its own at the end of a run.
				$imported = self::import_product( $product, $etalage );

				if ( ! self::holds_lock( $etalage ) ) {
					// Writing a book can take longer than the lock lasts. What is still to be
					// retried belongs to whoever owns the etalage now.
					boekdb_debug( 'Lost the import lock on ' . $etalage->name );

					return;
				}

				if ( $imported ) {
					self::forget_failed_product( $etalage, $product );
				} else {
					self::remember_failed_product( $etalage, $product );
				}
			}
			$offset = $offset + Boekdb_Api_Service::LIMIT;

			if ( ! self::holds_lock( $etalage ) ) {
				// The etalage belongs to another run now, and so does its progress.
				boekdb_debug( 'Lost the import lock on ' . $etalage->name );
				self::$lock_token = null;

				return;
			}

			// update the offset in etalage and set running to 1 (next batch), unless Stop was
			// pressed while this batch was being written.
			self::update_offset( $offset, $etalage );
			$stopped = self::is_stopped();
			self::release_lock( $etalage, $stopped ? 0 : 2 );

			if ( $stopped ) {
				boekdb_debug( 'Import was stopped during ' . $etalage->name );

				return;
			}

			boekdb_debug( 'Done with this batch...' );

			// there might be more etalages to import, so schedule a new import.
			if ( ! wp_next_scheduled( self::IMPORT_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMPORT_HOOK );
			}
		} else {
			if ( ! self::claim_lock( $etalage ) ) {
				// Another run owns this etalage by now, and with it the say over where the
				// import got to.
				boekdb_debug( 'Another run owns ' . $etalage->name );

				return;
			}

			boekdb_debug( 'Finished import on ' . $etalage->name );

			// Nothing left to fetch in batches, so this is the moment for the books that were
			// skipped along the way.
			self::retry_failed_products( $etalage );

			if ( ! self::holds_lock( $etalage ) ) {
				// Lost along the way, and with it the say over where the import got to.
				boekdb_debug( 'Lost the import lock on ' . $etalage->name );
				self::$lock_token = null;
				self::$lock_value = null;

				return;
			}

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
	 * The books of an etalage the import could not get in.
	 *
	 * @param int $etalage_id  The etalage.
	 *
	 * @return array The isbns, in the order they were written down.
	 */
	public static function failed_products( $etalage_id ) {
		$failed = get_option( self::FAILED_OPTION_PREFIX . (int) $etalage_id );

		if ( ! is_array( $failed ) ) {
			return array();
		}

		return array_map( 'strval', array_keys( $failed ) );
	}

	/**
	 * Write down a book that could not be imported, to fetch again later.
	 *
	 * @param object $etalage  The etalage being imported.
	 * @param object $product  The product that failed.
	 *
	 * @return void
	 */
	private static function remember_failed_product( $etalage, $product ) {
		if ( ! isset( $product->isbn ) ) {
			return;
		}

		$failed = get_option( self::FAILED_OPTION_PREFIX . $etalage->id );
		if ( ! is_array( $failed ) ) {
			$failed = array();
		}

		$attempts                 = isset( $failed[ $product->isbn ] ) ? (int) $failed[ $product->isbn ] : 0;
		$failed[ $product->isbn ] = $attempts + 1;

		update_option( self::FAILED_OPTION_PREFIX . $etalage->id, $failed, false );
	}

	/**
	 * Forget the books waiting for a retry that the etalage no longer carries.
	 *
	 * A book leaves an etalage without its filters changing: it goes out of print and the
	 * isbn list gets shorter. The cleanup removes the book, and fetching by isbn knows no
	 * filters, so without this the retries bring it straight back.
	 *
	 * @param object $etalage  The etalage.
	 * @param array  $isbns    The isbns it carries now.
	 *
	 * @return void
	 */
	private static function drop_retries_outside( $etalage, $isbns ) {
		$failed = get_option( self::FAILED_OPTION_PREFIX . $etalage->id );
		if ( ! is_array( $failed ) || count( $failed ) === 0 ) {
			return;
		}

		$kept = array_intersect_key( $failed, array_flip( $isbns ) );

		if ( count( $kept ) === count( $failed ) ) {
			return;
		}

		if ( count( $kept ) === 0 ) {
			delete_option( self::FAILED_OPTION_PREFIX . $etalage->id );

			return;
		}

		update_option( self::FAILED_OPTION_PREFIX . $etalage->id, $kept, false );
	}

	/**
	 * Take a book off the list of books to fetch again.
	 *
	 * @param object $etalage  The etalage being imported.
	 * @param object $product  The product that went in fine.
	 *
	 * @return void
	 */
	private static function forget_failed_product( $etalage, $product ) {
		if ( ! isset( $product->isbn ) ) {
			return;
		}

		$failed = get_option( self::FAILED_OPTION_PREFIX . $etalage->id );
		if ( ! is_array( $failed ) || ! isset( $failed[ $product->isbn ] ) ) {
			return;
		}

		unset( $failed[ $product->isbn ] );

		if ( count( $failed ) === 0 ) {
			delete_option( self::FAILED_OPTION_PREFIX . $etalage->id );

			return;
		}

		update_option( self::FAILED_OPTION_PREFIX . $etalage->id, $failed, false );
	}

	/**
	 * Fetch the books that could not be imported earlier, one by one.
	 *
	 * Runs at the end of a run, when there is nothing left to fetch in batches. A book that
	 * keeps failing is left alone after a few tries, and stays on the list so it can be
	 * looked into.
	 *
	 * @param object $etalage  The etalage that just finished.
	 *
	 * @return void
	 */
	private static function retry_failed_products( $etalage ) {
		$failed = get_option( self::FAILED_OPTION_PREFIX . $etalage->id );
		if ( ! is_array( $failed ) || count( $failed ) === 0 ) {
			return;
		}

		foreach ( $failed as $isbn => $attempts ) {
			if ( (int) $attempts >= self::FAILED_ATTEMPTS ) {
				continue;
			}

			if ( self::is_stopped() || ! self::holds_lock( $etalage ) ) {
				// These write books like any batch does, so Stop and a takeover reach them
				// too.
				boekdb_debug( 'Stopped retrying on ' . $etalage->name );

				break;
			}

			self::refresh_lock( $etalage );

			$product = Boekdb_Api_Service::fetch_product( $etalage->api_key, $isbn );
			if ( false === $product ) {
				$failed[ $isbn ] = (int) $attempts + 1;
				continue;
			}

			if ( self::is_stopped() || ! self::holds_lock( $etalage ) ) {
				// Stop can be pressed while a book is being fetched, as a takeover can
				// happen then.
				boekdb_debug( 'Stopped retrying on ' . $etalage->name );

				break;
			}

			if ( self::import_product( $product, $etalage ) ) {
				unset( $failed[ $isbn ] );
				continue;
			}

			$failed[ $isbn ] = (int) $attempts + 1;
		}

		if ( ! self::holds_lock( $etalage ) ) {
			// The etalage changed hands while this ran. What is still to be retried is the
			// new owner's bookkeeping, not this run's.
			return;
		}

		if ( count( $failed ) === 0 ) {
			delete_option( self::FAILED_OPTION_PREFIX . $etalage->id );

			return;
		}

		update_option( self::FAILED_OPTION_PREFIX . $etalage->id, $failed, false );
	}

	/**
	 * Import one product.
	 *
	 * @param object $product  The product as the API describes it.
	 * @param object $etalage  The etalage being imported.
	 *
	 * @return bool Whether it was imported.
	 */
	private static function import_product( $product, $etalage ) {
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

			return true;
		} catch ( Throwable $e ) {
			boekdb_debug( 'Skipped ' . ( isset( $product->isbn ) ? $product->isbn : 'a product' ) . ': ' . $e->getMessage() );

			return false;
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
	 * @return bool Whether this run got it.
	 */
	private static function claim_lock( $etalage ) {
		global $wpdb;

		$name = self::lock_option( $etalage->id );
		$lock = self::read_lock( $etalage->id );

		if ( is_array( $lock ) && isset( $lock['time'] ) && ( time() - (int) $lock['time'] ) < self::LOCK_TIMEOUT ) {
			// Someone else is working on this etalage. Two runs can come back from the API
			// with the same batch; only one of them gets to write it.
			boekdb_debug( 'Another run is already working on ' . $etalage->name );

			return false;
		}

		if ( false !== $lock ) {
			// Only the dead lock this run saw. One that was refreshed in between is left
			// alone, and the insert below then fails.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, maybe_serialize( $lock ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			);
		}

		$token = wp_generate_uuid4();
		$value = maybe_serialize(
			array(
				'token' => $token,
				'time'  => time(),
			)
		);

		// A plain insert, which the unique key on option_name makes fail when another run
		// got there first. add_option() would overwrite that run's lock: it inserts with
		// ON DUPLICATE KEY UPDATE.
		$suppressed = $wpdb->suppress_errors( true );
		$inserted   = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => 'off',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppressed );

		self::forget_cached_lock( $name );

		if ( ! $inserted ) {
			boekdb_debug( 'Another run claimed ' . $etalage->name . ' first' );

			return false;
		}

		self::$lock_token = $token;
		self::$lock_value = $value;
		self::update_running( 1, $etalage );

		// A fatal halts the script on the spot, so no code after it runs and a finally block
		// would not either. Only a shutdown function still gets a turn.
		register_shutdown_function( array( self::class, 'release_lock_on_shutdown' ), $etalage );

		return true;
	}

	/**
	 * Drop what WordPress remembers about a lock option, after it was written past the
	 * option functions.
	 *
	 * @param string $name  The option name.
	 *
	 * @return void
	 */
	private static function forget_cached_lock( $name ) {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
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

		$lock = self::read_lock( $etalage->id );

		return is_array( $lock ) && isset( $lock['token'] ) && $lock['token'] === self::$lock_token;
	}

	/**
	 * Whether a batch is working on an etalage right now.
	 *
	 * The running state of an etalage is cleared the moment Stop is pressed, while the batch
	 * that is busy only notices between books. Its lock is what says it is still there.
	 *
	 * @return bool
	 */
	public static function is_working() {
		global $wpdb;

		if ( boekdb_is_import_running() ) {
			return true;
		}

		$locks = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::LOCK_OPTION_PREFIX ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		foreach ( $locks as $lock ) {
			$lock = maybe_unserialize( $lock );

			if ( is_array( $lock ) && isset( $lock['time'] ) && ( time() - (int) $lock['time'] ) < self::LOCK_TIMEOUT ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an import was stopped by hand.
	 *
	 * Straight from the database: Stop is pressed in another request, which this one would
	 * otherwise not see.
	 *
	 * @return bool
	 */
	private static function is_stopped() {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STOPPED_OPTION ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Read the lock of an etalage.
	 *
	 * Straight from the database: the run that takes a lock over is another process, and
	 * this one would otherwise keep seeing the value it cached when it claimed.
	 *
	 * @param int $id  The etalage.
	 *
	 * @return array|false The lock, or false when there is none.
	 */
	private static function read_lock( $id ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::lock_option( $id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);

		if ( is_null( $value ) ) {
			return false;
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Record that this run is still working on its etalage.
	 *
	 * @param object $etalage  The etalage.
	 *
	 * @return void
	 */
	private static function refresh_lock( $etalage ) {
		global $wpdb;

		if ( is_null( self::$lock_value ) ) {
			return;
		}

		$name  = self::lock_option( $etalage->id );
		$value = maybe_serialize(
			array(
				'token' => self::$lock_token,
				'time'  => time(),
			)
		);

		// Only over the lock this run wrote: between the check above and this write, another
		// run can have taken the etalage over.
		$written = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$value,
				$name,
				self::$lock_value
			)
		);

		self::forget_cached_lock( $name );

		if ( $written ) {
			self::$lock_value = $value;

			return;
		}

		// Nothing was written, so the lock is not what this run last left behind. If it is
		// still this run's lock, it was written by something else holding the same claim and
		// the heartbeat carries on from there.
		$lock = self::read_lock( $etalage->id );
		if ( ! is_array( $lock ) || ! isset( $lock['token'] ) || $lock['token'] !== self::$lock_token ) {
			self::$lock_token = null;
			self::$lock_value = null;

			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$value,
				$name,
				maybe_serialize( $lock )
			)
		);

		self::forget_cached_lock( $name );
		self::$lock_value = $value;
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
		global $wpdb;

		$name = self::lock_option( $etalage->id );

		if ( ! is_null( self::$lock_value ) ) {
			// The state of the etalage is written first, in a statement that only applies
			// while the lock is still this run's. Letting go of the lock and writing the
			// state were two steps, and a run claiming the etalage in between had its state
			// overwritten by the one on its way out.
			self::update_running_while_holding_lock( $etalage, $running );

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					$name,
					self::$lock_value
				)
			);

			self::forget_cached_lock( $name );
			self::$lock_token = null;
			self::$lock_value = null;

			return;
		}

		// No lock of this run to let go of, which is how a run that found nothing ends.
		if ( false !== self::read_lock( $etalage->id ) ) {
			return;
		}

		self::update_running( $running, $etalage );
	}

	/**
	 * Write the state of an etalage, but only while this run still holds its lock.
	 *
	 * @param object $etalage  The etalage.
	 * @param int    $running  The state to leave behind.
	 *
	 * @return void
	 */
	private static function update_running_while_holding_lock( $etalage, $running ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}boekdb_etalages SET running = %d
					WHERE id = %d
					AND EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$running,
				$etalage->id,
				self::lock_option( $etalage->id ),
				self::$lock_value
			)
		);
	}

	/**
	 * Name of the option holding the moment an etalage was claimed.
	 *
	 * @param int $id  The etalage.
	 *
	 * @return string
	 */
	private static function lock_option( $id ) {
		return self::LOCK_OPTION_PREFIX . (int) $id;
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

			if ( is_array( $lock ) ) {
				// Only the dead lock this run saw. Two runs can read the same dead lock, and
				// the second must not throw away the claim the first has just made.
				$removed = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
						self::lock_option( $etalage->id ),
						maybe_serialize( $lock )
					)
				);

				self::forget_cached_lock( self::lock_option( $etalage->id ) );

				if ( ! $removed ) {
					continue;
				}
			}

			// Only while the etalage is still free: between the delete above and this write
			// another run can have claimed it.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}boekdb_etalages SET running = 2
						WHERE id = %d
						AND NOT EXISTS ( SELECT 1 FROM {$wpdb->options} WHERE option_name = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					$etalage->id,
					self::lock_option( $etalage->id )
				)
			);
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
			} elseif ( $key === 'recensiequotes' ) {
				update_post_meta( $boek_post_id, 'boekdb_recensiequotes', self::keep_hidden_quotes_hidden( $boek_post_id, $value ) );
			} else {
				update_post_meta( $boek_post_id, 'boekdb_' . $key, $value );
			}
		}

		self::handle_serie( $product, $boek_post_id );
		self::handle_boek_files( $product, $boek_post_id );

		return array( $boek_post_id, $boek['isbn'], $boek['nstc'], $slug );
	}

	/**
	 * Carry the visibility an editor set over to the quotes that come in.
	 *
	 * Whether a review quote is shown is a choice made on the site, not at BoekDB, so it is
	 * read off what is stored before anything is written over it.
	 *
	 * @param int   $boek_post_id  The book.
	 * @param array $quotes        The quotes as they arrived.
	 *
	 * @return array
	 */
	private static function keep_hidden_quotes_hidden( $boek_post_id, $quotes ) {
		if ( ! is_array( $quotes ) || count( $quotes ) === 0 ) {
			return $quotes;
		}

		$current = get_post_meta( $boek_post_id, 'boekdb_recensiequotes', true );
		if ( ! is_array( $current ) ) {
			return $quotes;
		}

		foreach ( $quotes as $hash => $quote ) {
			if ( isset( $current[ $hash ]['tonen'] ) ) {
				$quotes[ $hash ]['tonen'] = $current[ $hash ]['tonen'];
			}
		}

		return $quotes;
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

		$bestand = $product->serie->beeld;
		$hash    = md5( $bestand->url );
		list( $attachment_id, $replaced_id, $references ) = self::usable_attachment( $hash );

		if ( is_null( $attachment_id ) ) {
			$image = self::download_file( $bestand, $replaced_id );
			if ( is_null( $image ) ) {
				return;
			}

			$attachment = array(
				'post_title'     => $bestand->soort,
				'post_mime_type' => $image['type'],
			);

			$attachment_id = wp_insert_attachment( $attachment, $image['file'] );
			self::take_over_references( $replaced_id, $references, $attachment_id );
			self::store_attachment_metadata( $attachment_id, $image, $bestand->soort );

			update_post_meta( $attachment_id, 'hash', $hash );
			// The name boekdb_serie_data() reads it back under.
			update_term_meta( $term_id, 'boekdb_seriebeeld_id', $attachment_id );
		} else {
			// The file is already on the site, from another serie or an earlier run. The
			// serie carries the image it is given now, which is not always the one it was
			// given first: a changed image arrives under another url.
			update_term_meta( $term_id, 'boekdb_seriebeeld_id', $attachment_id );
		}
	}

	/**
	 * Whether the file behind an attachment is unusable.
	 *
	 * Sites hold attachments whose file is an error page, from the days the plugin wrote
	 * whatever came back. Only the header of the file is read, because this runs for every
	 * file of every product of every batch.
	 *
	 * @param int $attachment_id  The attachment.
	 *
	 * @return bool
	 */
	private static function attachment_is_broken( $attachment_id ) {
		$path = get_attached_file( $attachment_id );

		if ( empty( $path ) || ! file_exists( $path ) || filesize( $path ) === 0 ) {
			return true;
		}

		$type = (string) get_post_mime_type( $attachment_id );

		if ( strpos( $type, 'image/' ) === 0 ) {
			// Reads the header, not the image.
			return getimagesize( $path ) === false;
		}

		if ( 'application/pdf' === $type ) {
			$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				return true;
			}
			$head = fread( $handle, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return '%PDF-' !== $head;
		}

		return false;
	}

	/**
	 * The attachment the site already has for this file, if it still holds a usable file.
	 *
	 * An attachment whose file went missing or turned out to be an error page is reported
	 * back along with everything pointing at it, so the caller can download the file again
	 * and hand those references over. It is only removed once that replacement is in.
	 *
	 * @param string $hash  The hash of the file url.
	 *
	 * @return array The attachment to use or null, the unusable one or null, and what points at it.
	 */
	private static function usable_attachment( $hash ) {
		$attachment_id = self::find_field( 'attachment', 'hash', $hash );

		if ( is_null( $attachment_id ) ) {
			return array( null, null, array() );
		}

		if ( ! self::attachment_is_broken( $attachment_id ) ) {
			return array( $attachment_id, null, array() );
		}

		// Left in place until the replacement is in: a download that fails leaves the site
		// with the file it had rather than with none at all.
		return array( null, $attachment_id, self::references_to_attachment( $attachment_id ) );
	}

	/**
	 * Everything pointing at an attachment: books through their post meta, authors and
	 * series through their term meta.
	 *
	 * @param int $attachment_id  The attachment.
	 *
	 * @return array
	 */
	private static function references_to_attachment( $attachment_id ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key FROM {$wpdb->postmeta}
					WHERE meta_value = %d
					AND meta_key IN ( '_thumbnail_id', 'boekdb_file_backcover_id', 'boekdb_file_voorbeeld_id' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$attachment_id
			)
		);

		$terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_key FROM {$wpdb->termmeta}
					WHERE meta_value = %d
					AND meta_key IN ( 'auteursfoto_id', 'boekdb_seriebeeld_id' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$attachment_id
			)
		);

		return array(
			'posts' => $posts,
			'terms' => $terms,
		);
	}

	/**
	 * Hand everything that pointed at an unusable file to its replacement.
	 *
	 * @param int|null $replaced_id    The attachment being replaced, or null.
	 * @param array    $references     What pointed at it.
	 * @param int      $attachment_id  The attachment that takes its place.
	 *
	 * @return void
	 */
	private static function take_over_references( $replaced_id, $references, $attachment_id ) {
		if ( is_null( $replaced_id ) || is_wp_error( $attachment_id ) ) {
			return;
		}

		// Only where the old file is still the one in use: a book that was given another
		// cover while this download ran keeps the newer one.
		foreach ( $references['posts'] as $reference ) {
			update_post_meta( $reference->post_id, $reference->meta_key, $attachment_id, $replaced_id );
		}

		foreach ( $references['terms'] as $reference ) {
			update_term_meta( $reference->term_id, $reference->meta_key, $attachment_id, $replaced_id );
		}
	}

	/**
	 * Download a file from the API into the uploads directory.
	 *
	 * @param object   $bestand      The file as the API describes it.
	 * @param int|null $replaced_id  An unusable attachment to remove once the bytes are in.
	 *
	 * @return array|null The file as wp_handle_sideload() returns it, or null on failure.
	 */
	private static function download_file( $bestand, $replaced_id = null ) {
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

		$name = sanitize_file_name( $bestand->bestandsnaam );

		// An error page served with a 200 passes for a download. WordPress refuses it as a
		// file a few lines down, and by then the file it was meant to replace must still be
		// there.
		$checked = wp_check_filetype_and_ext( $tmp_file, $name );
		if ( empty( $checked['type'] ) ) {
			boekdb_debug( 'Discarding ' . $bestand->url . ': not a ' . $bestand->type );
			unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

			return null;
		}

		// The bytes are in and they are what they claim to be, so the unusable file this
		// replaces can go. Not a moment earlier: a download that fails leaves the site with
		// the file it had. Not a moment later either, because the replacement takes the same
		// name and would go with it.
		if ( ! is_null( $replaced_id ) ) {
			boekdb_debug( 'Replacing unusable attachment ' . $replaced_id );
			wp_delete_attachment( $replaced_id, true );
		}

		// wp_handle_sideload() takes its first argument by reference.
		$sideload = array(
			'name'     => $name,
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
			$hash = md5( $bestand->url );
			list( $attachment_id, $replaced_id, $references ) = self::usable_attachment( $hash );

			if ( is_null( $attachment_id ) ) {
				$file = self::download_file( $bestand, $replaced_id );
				if ( is_null( $file ) ) {
					continue;
				}

				$attachment = array(
					'post_title'     => $bestand->soort,
					'post_mime_type' => $file['type'],
				);

				$attachment_id = wp_insert_attachment( $attachment, $file['file'], $boek_post_id );
				self::take_over_references( $replaced_id, $references, $attachment_id );
				if ( ! is_wp_error( $attachment_id ) ) {
					self::store_attachment_metadata( $attachment_id, $file, $bestand->soort );
				} else {
					boekdb_debug( 'Error inserting attachment: ' . $attachment_id->get_error_message() );
				}

				update_post_meta( $attachment_id, 'hash', $hash );
				self::link_file_to_boek( $boek_post_id, $attachment_id, $bestand->soort );
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

				// On the role this product gives the file, not the one it had when another
				// book first brought it in: the same image is the back cover of one edition
				// and the cover of another.
				self::link_file_to_boek( $boek_post_id, $attachment_id, $bestand->soort );
			}
		}
	}


	/**
	 * Point a book at one of its files.
	 *
	 * @param int    $boek_post_id   The book.
	 * @param int    $attachment_id  The file.
	 * @param string $soort          The role BoekDB gives the file for this book.
	 *
	 * @return void
	 */
	private static function link_file_to_boek( $boek_post_id, $attachment_id, $soort ) {
		$meta_keys = array(
			'Cover'      => '_thumbnail_id',
			'Back cover' => 'boekdb_file_backcover_id',
			'Fragment'   => 'boekdb_file_voorbeeld_id',
		);

		if ( ! isset( $meta_keys[ $soort ] ) ) {
			return;
		}

		update_post_meta( $boek_post_id, $meta_keys[ $soort ], $attachment_id );
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

			$hash = md5( $bestand->url );
			list( $attachment_id, $replaced_id, $references ) = self::usable_attachment( $hash );

			if ( is_null( $attachment_id ) ) {
				$image = self::download_file( $bestand, $replaced_id );
				if ( is_null( $image ) ) {
					continue;
				}

				$attachment = array(
					'post_title'     => 'Auteursfoto',
					'post_mime_type' => $image['type'],
				);

				$attachment_id = wp_insert_attachment( $attachment, $image['file'] );
				self::take_over_references( $replaced_id, $references, $attachment_id );
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

		// A lookup on the primary key, to tell a book joining this etalage from one that was
		// already in it.
		$already_linked = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id = %d AND boek_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$etalage_id,
				$boek_id
			)
		);

		$wpdb->replace(
			$wpdb->prefix . 'boekdb_etalage_boeken',
			array(
				'etalage_id' => $etalage_id,
				'boek_id'    => $boek_id,
			)
		);

		if ( 0 === $already_linked ) {
			// The url of a book carries the prefix of its etalage, so the one worked out
			// before this is no longer right.
			delete_transient( 'boekdb_permalink_' . $boek_id );
		}
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
			self::set_primary( $post_id, 1 );

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
		self::set_primary( $first_id, 1 );
		self::set_post_name( $first_id, $slug );

		// disable primary bit on all other books and set slug to secondary
		foreach ( $books as $book_id => $val ) {
			self::set_primary( $book_id, 0 );
			self::set_post_name( $book_id, $slugs[ $book_id ] );
		}
	}

	/**
	 * Mark a book as the primary edition of its title, or as one of the others.
	 *
	 * Which of the two it is decides the url the book gets, so a book that changes sides
	 * has to lose its cached permalink even when nothing else about it changes.
	 *
	 * @param int $post_id  The book.
	 * @param int $primary  1 for the primary edition, 0 for the others.
	 *
	 * @return void
	 */
	private static function set_primary( $post_id, $primary ) {
		if ( update_post_meta( $post_id, 'boekdb_primair', $primary ) ) {
			delete_transient( 'boekdb_permalink_' . $post_id );
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

		if ( self::is_stopped() ) {
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
				// instead would skip everything that changed while it was busy. A run that
				// was stopped and started again keeps the moment it first began.
				if ( ! get_option( self::START_OPTION_PREFIX . $etalage->id ) ) {
					update_option( self::START_OPTION_PREFIX . $etalage->id, current_time( 'mysql', 1 ), false );
				}

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
		self::drop_retries_outside( $etalage, $isbns['isbns'] );

		$wpdb->update(
			$wpdb->prefix . 'boekdb_etalages',
			array(
				'isbns' => count( $isbns['isbns'] ),
			),
			array( 'id' => $etalage->id )
		);

		if ( $isbns['filters'] !== $etalage->filter_hash ) {
			// Other filters mean another set of books, imported from the start. Keeping the
			// offset of the previous selection would skip everything before it.
			$wpdb->update(
				$wpdb->prefix . 'boekdb_etalages',
				array(
					'filter_hash' => $isbns['filters'],
					'last_import' => null,
					'offset'      => 0,
				),
				array( 'id' => $etalage->id )
			);

			// Books outside the new filters are removed from the site, and the retries
			// fetch by isbn, which knows no filters: they would bring them back.
			delete_option( self::FAILED_OPTION_PREFIX . $etalage->id );

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
