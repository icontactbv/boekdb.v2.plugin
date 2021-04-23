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
 * @since 3.0.0
 * @param string $name  Constant name.
 * @param mixed  $value Value.
 */
function boekdb_maybe_define_constant( $name, $value ) {
    if ( ! defined( $name ) ) {
        define( $name, $value );
    }
}