<?php
/**
 * Plugin Name: BoekDB.v2
 * Plugin URI: https://www.boekdbv2.nl/
 * Description: Wordpress plugin for BoekDBv2 data.
 * Version: 0.0.1-dev
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

BoekDB();

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
