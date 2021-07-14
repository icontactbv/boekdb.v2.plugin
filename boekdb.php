<?php
/**
 * Plugin Name: BoekDB.v2
 * Plugin URI: https://www.boekdbv2.nl/
 * Description: Wordpress plugin for BoekDBv2 data.
 * Version: 0.1.4
 * Author: Icontact B.V.
 * Author URI: http://www.icontact.nl
 * Requires at least: 5.5
 * Requires PHP: 7.0
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BOEKDB_PLUGIN_FILE' ) ) {
	define( 'BOEKDB_PLUGIN_FILE', __FILE__ );
}

// Include the main BoekDB class.
if ( ! class_exists( 'BoekDB', false ) ) {
	include_once dirname( BOEKDB_PLUGIN_FILE ) . '/includes/class-boekdb.php';
}

/**
 * Returns the main instance of BoekDB.
 *
 * @return BoekDB
 */
function BoekDB() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return BoekDB::instance();
}

function boekdb_debug( $message ) {
	if ( WP_DEBUG && WP_DEBUG_LOG ) {
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, true );
		}
		// debug
		error_log( $message );
	}
}

BoekDB();
function boekdb_set_import_running() {
	boekdb_debug( 'set import running transient' );
	set_transient( 'boekdb_import_running', true, MINUTE_IN_SECONDS * 10 );
}

function boekdb_is_import_running() {
	boekdb_debug( 'is import running transient: ' . var_export( get_transient( 'boekdb_import_running' ), true ) );

	return get_transient( 'boekdb_import_running' );
}

function boekdb_reset_import_running() {
	boekdb_debug( 'reset import running transient' );
	delete_transient( 'boekdb_import_running' );
	delete_transient( 'boekdb_import_etalage' );
}

function boekdb_get_import_etalage() {
	boekdb_debug( 'get current etalage: ' . var_export( get_transient( 'boekdb_import_etalage' ), true ) );

	return get_transient( 'boekdb_import_etalage' );
}

function boekdb_set_import_etalage( $etalage_id ) {
	boekdb_debug( 'set current etalage to ' . $etalage_id );
	set_transient( 'boekdb_import_etalage', $etalage_id );
}

function boekdb_boek_data( $id ) {
	$data = array();
	$meta = get_post_meta( $id );
	foreach ( $meta as $name => $value ) {
		if ( substr( $name, 0, 7 ) === 'boekdb_' ) {
			$data[ substr( $name, 7 ) ] = $value[0];
		}
	}

	$series = wp_get_object_terms( $id, 'boekdb_serie_tax' );
	if ( ! empty( $series ) ) {
		$data = array_merge( $data, boekdb_serie_data( $series[0]->term_id, $series[0] ) );
	}

	return $data;
}

function boekdb_betrokkenen_data( $id ) {
	$data = array();
	foreach ( wp_get_post_terms( $id, 'boekdb_auteur_tax' ) as $term ) {
		$data['auteurs'][] = array_merge(array('rol' => 'auteur'), boekdb_betrokkene_data( $term->id, $term ));
	}
	foreach ( wp_get_post_terms( $id, 'boekdb_spreker_tax' ) as $term ) {
		$data['sprekers'][] = array_merge(array('rol' => 'spreker'), boekdb_betrokkene_data( $term->id, $term ));
	}
	foreach ( wp_get_post_terms( $id, 'boekdb_illustrator_tax' ) as $term ) {
		$data['illustrators'][] = array_merge(array('rol' => 'illustrator'), boekdb_betrokkene_data( $term->id, $term ));
	}
	return $data;
}

function boekdb_betrokkene_data( $id, $term = null ) {
	if ( is_null( $term ) ) {
		$term = get_term( $id );
	}

	$data = array();
	foreach(get_term_meta( $term->term_id ) as $key => $meta) {
		$data[$key] = $meta[0];
	}
	return $data;
}

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
