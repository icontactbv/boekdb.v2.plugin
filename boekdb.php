<?php
/**
 * Plugin Name: BoekDB.v2
 * Plugin URI: https://www.boekdbv2.nl/
 * Description: Wordpress plugin for BoekDBv2 data.
 * Version: 0.1.2
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
	if(WP_DEBUG && WP_DEBUG_LOG) {
		// debug
		error_log($message);
	}
}

BoekDB();
function boekdb_set_import_running() {
	boekdb_debug( 'set import running transient' );
	set_transient( 'boekdb_import_running', true, MINUTE_IN_SECONDS * 10 );
}

function boekdb_is_import_running() {
	boekdb_debug( 'is import running transient: '. var_export(get_transient( 'boekdb_import_running' ), true) );
	return get_transient( 'boekdb_import_running' );
}

function boekdb_reset_import_running() {
	boekdb_debug( 'reset import running transient' );
	delete_transient( 'boekdb_import_running' );
	delete_transient( 'boekdb_import_etalage' );
}

function boekdb_get_import_etalage() {
	boekdb_debug( 'get current etalage: '. var_export(get_transient( 'boekdb_import_etalage' ), true) );
	return get_transient( 'boekdb_import_etalage' );
}

function boekdb_set_import_etalage( $etalage_id ) {
	boekdb_debug( 'set current etalage to '. $etalage_id );
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

	return $data;
}
