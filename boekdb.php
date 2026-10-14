<?php
/**
 * Plugin Name: BoekDB.v2
 * Plugin URI: https://www.boekdbv2.nl/
 * Description: This WordPress plugin fetches and displays book data provided by BoekDB. Developed by Icontact B.V. for VBK Uitgevers B.V.
 * Version: 1.2.0
 * Author: Icontact B.V., Kevin de Harde
 * Author URI: https://www.icontact.nl
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BOEKDB_PLUGIN_FILE' ) ) {
	define( 'BOEKDB_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'BOEKDB_ABSPATH' ) ) {
	define( 'BOEKDB_ABSPATH', dirname( BOEKDB_PLUGIN_FILE ) . '/' );
}
if ( ! defined( 'BOEKDB_PLUGIN_BASENAME' ) ) {
	define( 'BOEKDB_PLUGIN_BASENAME', plugin_basename( BOEKDB_PLUGIN_FILE ) );
}

/**
 * Main instance of BoekDB.
 */
if ( ! class_exists( 'BoekDB', false ) ) {
	include_once dirname( BOEKDB_PLUGIN_FILE ) . '/includes/class-boekdb.php';
}

$boekdb_import_options = array(
	'overwrite_images',
);

/**
 * Returns the main instance of BoekDB.
 *
 * @return BoekDB
 */
function BoekDB() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return BoekDB::instance();
}

/**
 * Writes a message to the debug log, when the site keeps one.
 *
 * @param mixed $message  The message, or a value to dump.
 */
function boekdb_debug( $message ) {
	if ( WP_DEBUG && WP_DEBUG_LOG ) {
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writing to the debug log is what this function is for.
		}
		/**
		 * Don't inspect this line, it's a debug function
		 *
		 * @noinspection ForgottenDebugOutputInspection
		 */
		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Writing to the debug log is what this function is for.
	}
}

BoekDB();

/**
 * Lets a long run of the import or the cleanup go on past the usual time limit.
 *
 * Hosts can list set_time_limit() in disable_functions, and PHP 8 then does not define it
 * at all: calling it would be a fatal error.
 *
 * @return void
 */
function boekdb_lift_time_limit() {
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}
}

/**
 * Whether any etalage is being imported or waits for its next batch.
 *
 * @return bool
 */
function boekdb_is_import_running() {
	global $wpdb;

	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}boekdb_etalages WHERE running > 0" );
	if ( $count > 0 ) {
		boekdb_debug( 'Import is running' );

		return true;
	}
	boekdb_debug( 'Import is not running' );

	return false;
}

/**
 * Fetches the data for a single book
 *
 * @param int $id  The ID of the book.
 *
 * @return array
 */
function boekdb_boek_data( $id ) {
	$data = array();
	$meta = get_post_meta( $id );
	foreach ( $meta as $name => $value ) {
		if ( substr( $name, 0, 7 ) === 'boekdb_' ) {
			$data[ substr( $name, 7 ) ] = $value[0];
		}
		if ( $name === 'boekdb_recensiequotes' ) {
			$quotes = maybe_unserialize( $value[0] );
			$parsed = array();
			// A value that is not a list of quotes gives no quotes, rather than an error on
			// every page that shows the book.
			foreach ( is_array( $quotes ) ? $quotes : array() as $quote ) {
				if ( is_array( $quote ) && ! empty( $quote['tonen'] ) ) {
					unset( $quote['tonen'] );
					$parsed[] = $quote;
				}
			}
			$data[ substr( $name, 7 ) ] = serialize( $parsed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Templates read this field as a serialized string, as they always have.
		}
	}

	$series = wp_get_object_terms( $id, 'boekdb_serie_tax' );
	if ( ! empty( $series ) ) {
		$data = array_merge( $data, boekdb_serie_data( $series[0]->term_id, $series[0] ) );
	}

	return $data;
}

/**
 * Fetches the data for all betrokkenen
 *
 * @param int $id  The ID of the book.
 *
 * @return array
 */
function boekdb_betrokkenen_data( $id ) {
	$data = array();
	foreach ( wp_get_post_terms( $id, 'boekdb_auteur_tax' ) as $term ) {
		$data['auteurs'][] = array_merge( array( 'rol' => 'auteur' ), boekdb_betrokkene_data( $term->term_id, $term ) );
	}
	foreach ( wp_get_post_terms( $id, 'boekdb_spreker_tax' ) as $term ) {
		$data['sprekers'][] = array_merge( array( 'rol' => 'spreker' ), boekdb_betrokkene_data( $term->term_id, $term ) );
	}
	foreach ( wp_get_post_terms( $id, 'boekdb_illustrator_tax' ) as $term ) {
		$data['illustrators'][] = array_merge(
			array( 'rol' => 'illustrator' ),
			boekdb_betrokkene_data( $term->term_id, $term )
		);
	}

	return $data;
}

/**
 * Fetches the data for a single betrokkene
 *
 * @param int          $id    The ID of the term.
 * @param WP_Term|null $term  The term, when the caller has it already.
 *
 * @return array
 */
function boekdb_betrokkene_data( $id, $term = null ) {
	if ( is_null( $term ) ) {
		$term = get_term( $id );
	}

	$data = array();
	foreach ( get_term_meta( $term->term_id ) as $key => $meta ) {
		$data[ $key ] = $meta[0];
	}

	return $data;
}

/**
 * Fetches the data for a single serie
 *
 * @param int          $id    The ID of the serie.
 * @param WP_Term|null $term  Optional. The WP_Term object of the serie. Defaults to null.
 *
 * @return array The data of the serie.
 */
function boekdb_serie_data( $id, $term = null ) {
	if ( is_null( $term ) ) {
		$term = get_term( $id );
	}

	$meta  = get_term_meta( $term->term_id );
	$beeld = isset( $meta['boekdb_seriebeeld_id'] ) ? $meta['boekdb_seriebeeld_id'][0] : null;

	$data                       = array();
	$data['serie_omschrijving'] = $term->description;
	$data['serie_beeld_id']     = $beeld;

	return $data;
}

/**
 * The name this plugin used to mark some books with, kept working for sites that read it.
 */
define( 'BOEKDB_PRIMARY_ALIAS', 'boekdb_primary' );

add_filter( 'get_post_metadata', 'boekdb_read_primary_alias', 10, 4 );
add_action( 'pre_get_posts', 'boekdb_query_primary_alias' );

/**
 * Serves the primary mark to anything still asking for it under the old name.
 *
 * @param mixed  $value      The value the meta functions would return, null to let them.
 * @param int    $object_id  The post.
 * @param string $meta_key   The name being asked for.
 * @param bool   $single     Whether one value was asked for.
 *
 * @return mixed
 */
function boekdb_read_primary_alias( $value, $object_id, $meta_key, $single ) {
	if ( BOEKDB_PRIMARY_ALIAS !== $meta_key ) {
		return $value;
	}

	$stored = get_post_meta( $object_id, 'boekdb_primair', $single );

	// A filter that answers for a single value hands back an array to take it from.
	return $single ? array( $stored ) : $stored;
}

/**
 * Points a query at the name the mark is stored under.
 *
 * @param WP_Query $query  The query about to run.
 *
 * @return void
 */
function boekdb_query_primary_alias( $query ) {
	if ( BOEKDB_PRIMARY_ALIAS === $query->get( 'meta_key' ) ) {
		$query->set( 'meta_key', 'boekdb_primair' );
	}

	$meta_query = $query->get( 'meta_query' );
	if ( is_array( $meta_query ) ) {
		$query->set( 'meta_query', boekdb_rename_primary_clauses( $meta_query ) );
	}
}

/**
 * Renames the old name wherever it appears in a meta query, however deeply nested.
 *
 * @param array $clauses  A meta query, or one of its groups.
 *
 * @return array
 */
function boekdb_rename_primary_clauses( $clauses ) {
	foreach ( $clauses as $index => $clause ) {
		if ( is_array( $clause ) ) {
			$clauses[ $index ] = boekdb_rename_primary_clauses( $clause );
			continue;
		}

		if ( 'key' === $index && BOEKDB_PRIMARY_ALIAS === $clause ) {
			$clauses[ $index ] = 'boekdb_primair';
		}
	}

	return $clauses;
}

/**
 * Checks if a string starts with a given substring
 *
 * @param string $haystack  The string to search within
 * @param string $needle    The substring to search for
 *
 * @return bool Returns true if the $haystack starts with $needle, false otherwise
 */
function boekdb_startswith( $haystack, $needle ) {
	return 0 === strpos( $haystack, $needle );
}


/**
 * Returns the slug for a given verschijningsvorm code
 *
 * @param string $code  The verschijningsvorm code
 *
 * @return string The verschijningsvorm slug
 */
function boekdb_verschijningsvorm_slug( $code ) {
	if ( isset( BoekDB_Translations::$verschijningsvorm[ $code ] ) ) {
		return sanitize_title( BoekDB_Translations::$verschijningsvorm[ $code ] );
	}

	return sanitize_title( $code );
}


/**
 * Returns the description of a THEMA code
 *
 * @param string $code  THEMA code
 *
 * @return string The description, otherwise the provided code
 */
function boekdb_thema_omschrijving( $code ) {
	$code = strtoupper( $code );
	if ( isset( BoekDB_Translations::$thema[ $code ] ) ) {
		return BoekDB_Translations::$thema[ $code ];
	}

	return $code;
}

/**
 * Joins the necessary tables to retrieve etalage data for the query
 *
 * @param string   $join   The current join clauses of the query
 * @param WP_Query $query  The WP_Query object for the current query
 *
 * @return string The updated join clauses with etalage tables joined
 */
function boekdb_etalage_join( $join, $query ) {
	global $wpdb;
	if ( ! is_admin() && ! $query->is_single() && $query->get( 'post_type' ) === 'boekdb_boek' && $query->get(
		'etalage',
		false
	) && strlen( $query->get( 'etalage', false ) ) > 0 ) {
		$table1 = $wpdb->prefix . 'boekdb_etalage_boeken';
		$table2 = $wpdb->prefix . 'boekdb_etalages';
		$join  .= $wpdb->prepare( " LEFT JOIN {$table2} boekdb_e ON boekdb_e.name = %s", $query->get( 'etalage' ) );
		$join  .= " INNER JOIN {$table1} boekdb_eb ON boekdb_eb.boek_id = {$wpdb->posts}.ID AND boekdb_eb.etalage_id = boekdb_e.id";
	}

	return $join;
}

add_filter( 'posts_join', 'boekdb_etalage_join', 10, 2 );

/**
 * Adds a 'minutely' schedule to the existing set of schedules
 *
 * @param array $schedules  The existing set of schedules
 *
 * @return array The updated set of schedules
 */
function boekdb_add_minutely( $schedules ) {
	$schedules['minutely'] = array(
		'interval' => 60,
		'display'  => __( 'Every minute', 'boekdb' ),
	);

	return $schedules;
}

/**
 * Fetches the alternate URLs for a given post ID
 *
 * @param int $post_id  The ID of the post
 *
 * @return array An array of alternate URLs with their corresponding etalage names
 */
function boekdb_get_alternate_urls( $post_id ) {
	global $wpdb;

	$prefix_records = $wpdb->get_results(
		$wpdb->prepare(
			"
            SELECT e.prefix, e.name 
            FROM `{$wpdb->prefix}boekdb_etalages` AS e
            JOIN `{$wpdb->prefix}boekdb_etalage_boeken` AS eb ON e.id = eb.etalage_id
            WHERE eb.boek_id = %d",
			$post_id
		)
	);

	$alternate_urls = array();

	// If there are no prefixes, return the empty array
	if ( empty( $prefix_records ) ) {
		return $alternate_urls;
	}

	foreach ( $prefix_records as $record ) {
		$etalage_name     = $record->name;
		$prefix           = $record->prefix;
		$alternate_urls[] = array(
			'name' => $etalage_name,
			'url'  => home_url( '/boek/' . esc_sql( $prefix ) . '/' . get_post_field( 'post_name', $post_id ) . '/' ),
		);
	}

	// Add the default URL
	$alternate_urls[] = array(
		'name' => 'default',
		'url'  => home_url( '/boek/' . get_post_field( 'post_name', $post_id ) . '/' ),
	);

	return $alternate_urls;
}

/**
 * Whether a url is one of a book on this site, as an alternate url has to be.
 *
 * It becomes the permalink of the book, so one anywhere else is not used.
 *
 * @param mixed $url  The url.
 *
 * @return bool
 */
function boekdb_is_book_url( $url ) {
	return is_string( $url ) && 0 === strpos( $url, home_url( '/boek/' ) );
}

add_filter( 'cron_schedules', 'boekdb_add_minutely' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Batches follow one another as fast as they can.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	add_filter( 'post_type_link', 'boekdb_modify_boek_permalink', 10, 2 );

	/**
	 * The permalink of a book: the alternate url chosen for it, or the one under the prefix
	 * of its etalage.
	 *
	 * @param string  $post_link  The permalink WordPress made.
	 * @param WP_Post $post       The post.
	 *
	 * @return string
	 */
	function boekdb_modify_boek_permalink( $post_link, $post ) {
		// Only apply changes for 'boekdb_boek' post type
		if ( 'boekdb_boek' === $post->post_type ) {
			// Try to get the link from the cache
			$cached_post_link = get_transient( 'boekdb_permalink_' . $post->ID );

			// If not in the cache
			if ( $cached_post_link === false || $cached_post_link === '' ) {
				$selected_url = get_post_meta( $post->ID, 'selected_alternate_url', true );
				$prefix       = boekdb_get_etalage_prefix( $post->ID );

				if ( boekdb_is_book_url( $selected_url ) ) {
					// Use selected alternate URL if it exists
					$post_link = $selected_url;
				} elseif ( $prefix !== '' ) {
					// Use prefix if it exists
					$post_link = home_url( '/boek/' . esc_sql( $prefix ) . '/' . $post->post_name . '/' );
				}

				// Cache the link for 12 hours
				set_transient( 'boekdb_permalink_' . $post->ID, $post_link, 12 * HOUR_IN_SECONDS );
			} else {
				$post_link = $cached_post_link;
			}
		}
		return $post_link;
	}
}

/**
 * Retrieves the etalage prefix for a given book post ID
 *
 * @param int $boek_post_id  The ID of the book post
 *
 * @return string|null The etalage prefix if found, empty string otherwise
 */
function boekdb_get_etalage_prefix( $boek_post_id ) {
	global $wpdb;
	$prefix = $wpdb->get_var(
		$wpdb->prepare(
			"
        SELECT e.prefix 
        FROM `{$wpdb->prefix}boekdb_etalages` AS e 
        JOIN `{$wpdb->prefix}boekdb_etalage_boeken` AS eb 
        ON e.id = eb.etalage_id 
        WHERE eb.boek_id = %d 
        AND e.prefix IS NOT NULL 
        AND e.prefix != '' LIMIT 1",
			$boek_post_id
		)
	);

	return $prefix !== null ? $prefix : '';
}
