<?php
/**
 * Voorbeeld voor een archive-pagina met op NSTC ontdubbelde titels
 *
 * @package BoekDB
 */

// argumenten
$boekdb_args = array(
	'post_type'      => 'boekdb_boek',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'title',
	'order'          => 'ASC',

	// import bepaald welke verschijningsvorm binnen NSTC primair is
	'meta_key'       => 'boekdb_primair',
	'meta_value'     => '1',
);

$boekdb_loop = new WP_Query( $boekdb_args );

// etc
