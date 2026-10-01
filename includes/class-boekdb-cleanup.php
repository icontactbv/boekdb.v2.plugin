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
class BoekDB_Cleanup {
	const CLEANUP_HOOK = 'boekdb_cleanup';

	/**
	 * Seconds a book is left alone after it was last written to, so one that is still being
	 * imported is not mistaken for one that left its etalage. The link to the etalage is
	 * written after its files are downloaded, and an interrupted import picks books it
	 * created earlier back up.
	 */
	const NEW_BOOK_GRACE = 3600;

	/**
	 * Initialize the class by setting up the necessary hooks and scheduling a cleanup event.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Removes the trashed books from the etalage.
	 *
	 * @param int   $etalage_id  The ID of the etalage.
	 * @param array $isbns       An array of ISBNs.
	 *
	 * @return void
	 * @global object $wpdb        WordPress database object.
	 */
	public static function trash_removed( $etalage_id, $isbns ) {
		global $wpdb;

		$query = "SELECT i.boek_id
					FROM {$wpdb->prefix}boekdb_isbns i
					    LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON eb.boek_id = i.boek_id
					    INNER JOIN {$wpdb->posts} p ON p.ID = i.boek_id
					WHERE eb.etalage_id = %d";

		if ( count( $isbns ) > 0 ) {
			$placeholders = implode( ', ', array_fill( 0, count( $isbns ), '%s' ) );
			$query       .= " AND i.isbn NOT IN ( $placeholders )";
		}

		// An empty list leaves the condition off entirely. Writing NOT IN () produced SQL the
		// database refused, so an etalage that lost every book kept all of them.
		$result = $wpdb->get_results(
			$wpdb->prepare( $query, array_merge( array( $etalage_id ), array_values( $isbns ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		);

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
				) . ' AND boek_id IN (' . implode( ',', $post_ids ) . ')'
			);
			foreach ( $post_ids as $post_id ) {
				$result = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT boek_id FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE boek_id = %d",
						$post_id
					)
				);
				if ( ! is_wp_error( $result ) && count( $result ) === 0 ) {
					self::delete_posts( array( $post_id ) );
				}
			}
		}
	}

	/**
	 * Delete the files of a book that is being removed, except the ones still in use.
	 *
	 * A file is downloaded once and then shared: two editions of a title have the same
	 * cover, and the attachment hangs on whichever book was imported last. Removing that
	 * book used to take the cover of the other one with it.
	 *
	 * @param int $post_id  The book being deleted.
	 *
	 * @return void
	 */
	public static function delete_attached_media( $post_id ) {
		$attachments = get_attached_media( '', $post_id );

		foreach ( $attachments as $attachment ) {
			if ( self::attachment_is_in_use( $attachment->ID, $post_id ) ) {
				// Hang it on a book that is still using it, so it goes when that one does.
				self::attach_to_another_user( $attachment->ID, $post_id );

				continue;
			}

			wp_delete_attachment( $attachment->ID, true );
		}

		boekdb_debug( 'deleted attachments' );
	}

	/**
	 * Hand a file to another book that uses it.
	 *
	 * @param int $attachment_id  The file.
	 * @param int $post_id        The book being deleted.
	 *
	 * @return void
	 */
	private static function attach_to_another_user( $attachment_id, $post_id ) {
		global $wpdb;

		$new_parent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_value = %d AND post_id <> %d
					AND meta_key IN ( '_thumbnail_id', 'boekdb_file_backcover_id', 'boekdb_file_voorbeeld_id' )
					LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attachment_id,
				$post_id
			)
		);

		if ( $new_parent > 0 ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $new_parent,
				)
			);
		}
	}

	/**
	 * Whether anything still points at an attachment, leaving out whoever is being removed.
	 *
	 * @param int $attachment_id  The file.
	 * @param int $post_id        A book being deleted, or 0.
	 * @param int $term_id        A term being deleted, or 0.
	 *
	 * @return bool
	 */
	public static function attachment_is_in_use( $attachment_id, $post_id = 0, $term_id = 0 ) {
		global $wpdb;

		$used_by_a_book = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
					WHERE meta_value = %d AND post_id <> %d
					AND meta_key IN ( '_thumbnail_id', 'boekdb_file_backcover_id', 'boekdb_file_voorbeeld_id' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attachment_id,
				$post_id
			)
		);

		if ( $used_by_a_book > 0 ) {
			return true;
		}

		$used_by_a_term = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->termmeta}
					WHERE meta_value = %d AND term_id <> %d
					AND meta_key IN ( 'auteursfoto_id', 'boekdb_seriebeeld_id' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attachment_id,
				$term_id
			)
		);

		return $used_by_a_term > 0;
	}

	/**
	 * Delete posts and related data.
	 *
	 * This method deletes posts and their related data, including attachments, meta data,
	 * and taxonomy relationships. It also triggers a debug message for each deleted post.
	 *
	 * @param array $post_ids  The IDs of the posts to delete.
	 *
	 * @return void
	 */
	public static function delete_posts( $post_ids ) {
		global $wpdb;

		add_action( 'before_delete_post', array( self::class, 'delete_attached_media' ) );

		foreach ( $post_ids as $post_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id = %d", $post_id ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE boek_id = %d",
					$post_id
				)
			);
			wp_delete_post( $post_id, true );
			wp_delete_object_term_relationships(
				$post_id,
				array(
					'boekdb_serie_tax',
					'boekdb_auteur_tax',
					'boekdb_illustrator_tax',
					'boekdb_spreker_tax',
					'boekdb_nur_tax',
					'boekdb_bisac_tax',
					'boekdb_thema_tax',
				)
			);

			boekdb_debug( 'deleted post ' . $post_id );
		}
	}

	/**
	 * Delete an etalage by its ID.
	 *
	 * @param int $id  The ID of the etalage to be deleted.
	 *
	 * @return void
	 */
	public static function delete_etalage( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}boekdb_etalages WHERE id = %d", $id ) );
		$result   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT eb.boek_id FROM {$wpdb->prefix}boekdb_etalage_boeken eb WHERE eb.etalage_id = %d",
				$id
			)
		);
		$post_ids = array();
		foreach ( $result as $boek ) {
			$post_ids[] = (int) $boek->boek_id;
		}
		self::delete_etalage_posts( $post_ids, $id );
	}

	/**
	 * Delete etalage posts based on the provided post IDs and deleted etalage ID.
	 *
	 * @param array $post_ids         The array of post IDs to be deleted.
	 * @param int   $deleted_etalage  The ID of the etalage that was deleted.
	 *
	 * @return void
	 */
	public static function delete_etalage_posts( $post_ids, $deleted_etalage ) {
		global $wpdb;

		if ( $deleted_etalage > 0 ) {
			// check if post is still related to etalage
			foreach ( $post_ids as $key => $post_id ) {
				$result = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT boek_id FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id != %d AND boek_id = %d LIMIT 1",
						$deleted_etalage,
						$post_id
					)
				);
				if ( count( $result ) > 0 ) {
					unset( $post_ids[ $key ] );
				}
			}
			boekdb_debug( 'deleting ' . count( $post_ids ) . ' posts' );
			self::delete_posts( $post_ids );

			boekdb_debug( 'running cleanup' );
			self::cleanup();
		}
	}

	/**
	 * Clean up the database by removing unnecessary records.
	 *
	 * @return void
	 * @global wpdb $wpdb The global database object.
	 */
	public static function cleanup() {
		global $wpdb;

		if ( BoekDB_Import::is_working() ) {
			// A book belongs to no etalage until its files have been downloaded, and an
			// author has no books until the book that mentions it is written. Removing what
			// looks unused while that is going on deletes work in progress.
			boekdb_debug( 'Not cleaning up while an import is running' );

			return;
		}

		// cleanup etalage_boeken
		$etalages    = BoekDB::fetch_etalages();
		$etalage_ids = array();
		foreach ( $etalages as $etalage ) {
			$etalage_ids[] = $etalage->id;
		}
		if ( count( $etalage_ids ) > 0 ) {
			$placeholders = implode( ', ', array_fill( 0, count( $etalage_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE etalage_id NOT IN ( $placeholders )",
					$etalage_ids
				)
			);
		} else {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}boekdb_etalage_boeken WHERE boek_id IS NOT NULL" );
		}

		// cleanup boekdb_isbns
		$post_ids = array_reduce(
			$wpdb->get_results(
				"SELECT ID FROM $wpdb->posts WHERE post_type = 'boekdb_boek'",
				ARRAY_N
			),
			'array_merge',
			array()
		);
		if ( count( $post_ids ) > 0 ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id NOT IN ( $placeholders )",
					$post_ids
				)
			);
		} else {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}boekdb_isbns WHERE boek_id IS NOT NULL" );
		}

		// cleanup boeken (check if they are not still related to an etalage)
		$result   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID
					FROM $wpdb->posts p
					    LEFT JOIN {$wpdb->prefix}boekdb_etalage_boeken eb ON eb.boek_id = p.ID
					    LEFT JOIN {$wpdb->prefix}boekdb_etalages et ON et.id = eb.etalage_id
					WHERE p.post_type = 'boekdb_boek' AND p.post_modified_gmt < %s
					GROUP BY p.ID HAVING COUNT(et.id) = 0",
				gmdate( 'Y-m-d H:i:s', time() - self::NEW_BOOK_GRACE )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = array();
		foreach ( $result as $boek ) {
			$post_ids[] = (int) $boek->ID;
		}
		self::delete_posts( $post_ids );

		$term_ids = self::collect_term_ids_for_cleanup();
		self::delete_terms( $term_ids );
	}

	/**
	 * Collect all term IDs and their associated taxonomies for cleanup.
	 *
	 * @return array Returns an array where the keys are term IDs and the values are their taxonomies.
	 * @global wpdb $wpdb WordPress database object.
	 */
	private static function collect_term_ids_for_cleanup() {
		global $wpdb;

		// Identify all the taxonomies attached to 'boekdb_boek' post type
		$relevant_taxonomies = get_object_taxonomies( 'boekdb_boek' );
		$term_ids            = array();

		// For each taxonomy, identify terms which aren't associated with any 'boekdb_boek' post.
		foreach ( $relevant_taxonomies as $taxonomy ) {
			// The inner condition has to name the outer table, or it compares a column with
			// itself: as soon as one book carried any term, every term counted as used and
			// nothing was ever cleaned up.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt.term_id, tt.taxonomy
						FROM {$wpdb->prefix}term_taxonomy tt
						WHERE tt.taxonomy = %s
						AND NOT EXISTS (
							SELECT 1
							FROM {$wpdb->prefix}term_relationships tr
							INNER JOIN {$wpdb->prefix}posts p ON p.ID = tr.object_id
							WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.post_type = 'boekdb_boek'
						)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$taxonomy
				),
				ARRAY_A
			);

			// Generate the $term_ids array where keys are term_id and values are taxonomy
			foreach ( $results as $result ) {
				$term_ids[ $result['term_id'] ] = $result['taxonomy'];
			}
		}

		return $term_ids;
	}

	/**
	 * Delete terms from specified taxonomies and perform additional cleanup tasks if necessary.
	 *
	 * @param array $term_ids  An array of term IDs and their corresponding taxonomies.
	 *
	 * @return void
	 */
	public static function delete_terms( $term_ids ) {
		global $wpdb;

		foreach ( $term_ids as $term_id => $taxonomy ) {
			$args  = array(
				'posts_per_page' => - 1,
				'post_type'      => 'boekdb_boek',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			);
			$query = new WP_Query( $args );
			if ( $query->post_count === 0 ) {
				// for contributors we need to delete the attached image (auteursfoto_id, auteursfoto_copyright)
				if ( $taxonomy === 'boekdb_auteur_tax' ) {
					// get all term_meta
					$term_meta = get_term_meta( $term_id );

					// fetch auteursfoto_id
					$auteursfoto_id = get_term_meta( $term_id, 'auteursfoto_id', true );
					if ( $auteursfoto_id ) {
						// delete auteursfoto_id
						delete_term_meta( $term_id, 'auteursfoto_id' );
						// delete auteursfoto_copyright
						delete_term_meta( $term_id, 'auteursfoto_copyright' );

						// The same photo portrays the author of a book and the narrator of
						// its audio edition, under terms of their own.
						if ( ! self::attachment_is_in_use( $auteursfoto_id, 0, $term_id ) ) {
							wp_delete_attachment( $auteursfoto_id, true );
						}

						boekdb_debug( 'deleted auteursfoto_id and auteursfoto_copyright for term ' . $term_id );
					}
				}

				// same for series: we need to delete the attached image (seriebeeld_id)
				if ( $taxonomy === 'boekdb_serie_tax' ) {
					// fetch boekdb_seriebeeld_id
					$boekdb_seriebeeld_id = get_term_meta( $term_id, 'boekdb_seriebeeld_id', true );
					if ( $boekdb_seriebeeld_id ) {
						// delete boekdb_seriebeeld_id
						delete_term_meta( $term_id, 'boekdb_seriebeeld_id' );

						// Two series can carry the same image.
						if ( ! self::attachment_is_in_use( $boekdb_seriebeeld_id, 0, $term_id ) ) {
							wp_delete_attachment( $boekdb_seriebeeld_id, true );
						}

						boekdb_debug( 'deleted seriebeeld_id for term ' . $term_id );
					}
				}

				wp_delete_term( $term_id, $taxonomy );
				boekdb_debug( 'deleted term ' . $term_id . ' from ' . $taxonomy );
			}
		}
	}
}

BoekDB_Cleanup::init();
