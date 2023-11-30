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
	const CLEANUP_HOOK      = 'boekdb_cleanup';

	public static function init() {
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}

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
			self::cleanup();
		}
	}

	public static function cleanup() {
		global $wpdb;

		// cleanup etalage_boeken
		$etalages    = BoekDB::fetch_etalages();
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

		// cleanup boeken (check if they are not still related to an etalage)
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

		$term_ids = self::collect_term_ids_for_cleanup();
		self::delete_terms( $term_ids );
	}

	private static function collect_term_ids_for_cleanup() {
		global $wpdb;
		// Identify all the taxonomies attached to 'boekdb_boek' post type
		$relevant_taxonomies = get_object_taxonomies('boekdb_boek');
		$term_ids = array();

		// For each taxonomy, identify terms which aren't associated with any 'boekdb_boek' post.
		foreach ($relevant_taxonomies as $taxonomy) {
			$query = "
        SELECT term_id, taxonomy
        FROM {$wpdb->prefix}term_taxonomy
        WHERE taxonomy = '{$taxonomy}'
        AND NOT EXISTS (
            SELECT *
            FROM {$wpdb->prefix}term_relationships tr
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = tr.object_id
            WHERE tr.term_taxonomy_id=term_taxonomy_id AND p.post_type = 'boekdb_boek'
        )";

			$results = $wpdb->get_results($query, ARRAY_A);

			// Generate the $term_ids array where keys are term_id and values are taxonomy
			foreach ($results as $result) {
				$term_ids[$result['term_id']] = $result['taxonomy'];
			}
		}

		return $term_ids;
	}

	public static function delete_terms( $term_ids ) {
		global $wpdb;

		foreach ( $term_ids as $term_id => $taxonomy ) {
			$args  = array(
				'posts_per_page' => -1,
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
				// for contributors we need to delete the attached image (auteursfoto_id, auteursfoto_copyright)
				if ( $taxonomy === 'boekdb_auteur_tax' ) {
					// get all term_meta
					$term_meta = get_term_meta( $term_id );
					boekdb_debug( 'term_meta for term ' . $term_id . ': ' . print_r( $term_meta, true ) );

					// fetch auteursfoto_id
					$auteursfoto_id = get_term_meta( $term_id, 'auteursfoto_id', true );
					if ( $auteursfoto_id ) {
						// delete auteursfoto_id
						delete_term_meta( $term_id, 'auteursfoto_id' );
						// delete auteursfoto_copyright
						delete_term_meta( $term_id, 'auteursfoto_copyright' );
						// delete attachment
						wp_delete_attachment( $auteursfoto_id, true );
						boekdb_debug( 'deleted auteursfoto_id and auteursfoto_copyright for term ' . $term_id);
					}
				}

				// same for series: we need to delete the attached image (seriebeeld_id)
				if ( $taxonomy === 'boekdb_serie_tax' ) {
					// fetch boekdb_seriebeeld_id
					$boekdb_seriebeeld_id = get_term_meta( $term_id, 'seriebeeld_id', true );
					if ( $boekdb_seriebeeld_id ) {
						// delete boekdb_seriebeeld_id
						delete_term_meta( $term_id, 'seriebeeld_id' );
						// delete attachment
						wp_delete_attachment( $boekdb_seriebeeld_id, true );
						boekdb_debug( 'deleted seriebeeld_id for term ' . $term_id);
					}
				}

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

	public static function delete_posts( $post_ids ) {
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

}

BoekDB_Import::init();

