<?php
/**
 * BoekDB Core Functions
 *
 * General core functions available on both the front-end and admin.
 *
 * @package BoekDB\Functions
 * @version 0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define a constant if it is not already defined.
 *
 * @param string  $name  Constant name.
 * @param mixed  $value  Value.
 */
function boekdb_maybe_define_constant( $name, $value ) {
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
}