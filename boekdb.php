<?php
/**
 * Plugin Name: BoekDB.v2
 * Plugin URI: https://www.boekdbv2.nl/
 * Description: This WordPress plugin fetches and displays book data provided by BoekDB. Developed by Icontact B.V. for VBK uitgevers.
 * Version: 1.1.0
 * Author: Icontact B.V., Kevin de Harde
 * Author URI: https://www.icontact.nl
 * Requires at least: 5.5
 * Requires PHP: 7.0
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
 * Debug function
 *
 * @param $message
 */
function boekdb_debug( $message ) {
	if ( WP_DEBUG && WP_DEBUG_LOG ) {
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, true );
		}
		/**
		 * Don't inspect this line, it's a debug function
		 *
		 * @noinspection ForgottenDebugOutputInspection
		 */
		error_log( $message );
	}
}

BoekDB();

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
 * @param $id
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
			$quotes = unserialize( $value[0] );
			$parsed = array();
			foreach ( $quotes as $quote ) {
				if ( $quote['tonen'] ) {
					unset( $quote['tonen'] );
					$parsed[] = $quote;
				}
			}
			$data[ substr( $name, 7 ) ] = serialize( $parsed );
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
 * @param $id
 *
 * @return array
 */
function boekdb_betrokkenen_data( $id ) {
	$data = array();
	foreach ( wp_get_post_terms( $id, 'boekdb_auteur_tax' ) as $term ) {
		$data['auteurs'][] = array_merge( array( 'rol' => 'auteur' ), boekdb_betrokkene_data( $term->id, $term ) );
	}
	foreach ( wp_get_post_terms( $id, 'boekdb_spreker_tax' ) as $term ) {
		$data['sprekers'][] = array_merge( array( 'rol' => 'spreker' ), boekdb_betrokkene_data( $term->id, $term ) );
	}
	foreach ( wp_get_post_terms( $id, 'boekdb_illustrator_tax' ) as $term ) {
		$data['illustrators'][] = array_merge(
			array( 'rol' => 'illustrator' ),
			boekdb_betrokkene_data( $term->id, $term )
		);
	}

	return $data;
}

/**
 * Fetches the data for a single betrokkene
 *
 * @param $id
 * @param $term
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
 * @param $code The verschijningsvorm code
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
		'display'  => __( 'Every minute' ),
	);

	return $schedules;
}

function boekdb_get_alternate_urls( $post ) {
	// Similar code above...

	// Retrieve the etalage prefixes and their names
	global $wpdb;
	$prefix_records = $wpdb->get_results($wpdb->prepare("SELECT prefix, name FROM `{$wpdb->prefix}boekdb_etalages` WHERE boek_id=%d", $post->ID));

	// Construct the alternate URLs.
	$alternate_urls = array();
	foreach ($prefix_records as $record) {
		$etalage_name = $record->name;
		$prefix = $record->prefix;
		$alternate_urls[] = array(
			'name' => $etalage_name,
			'url'  =>  home_url( '/boek/' . esc_sql($prefix) . '/' . $post->post_name . '/' ),
		);
	}

	return $alternate_urls;
}

add_filter( 'cron_schedules', 'boekdb_add_minutely' );
