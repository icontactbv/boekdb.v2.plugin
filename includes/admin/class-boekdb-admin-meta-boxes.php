<?php
/**
 * BoekDB Meta Boxes
 *
 * Sets up the write panels used by custom post types
 *
 * @package BoekDB\Admin\Meta Boxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * BoekDB_Admin_Meta_Boxes.
 */
class BoekDB_Admin_Meta_Boxes {
	/**
	 * Is meta boxes saved once?
	 *
	 * @var boolean
	 */
	private static $saved_meta_boxes = false;

	/**
	 * Meta box error messages.
	 *
	 * @var array
	 */
	public static $meta_box_errors = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ), 10 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 30 );
		// add_action('save_post', array($this, 'save_meta_boxes'), 1, 2);

		// Error handling (for showing errors from meta boxes on next page load).
		add_action( 'admin_notices', array( $this, 'output_errors' ) );
		add_action( 'shutdown', array( $this, 'save_errors' ) );
	}

	/**
	 * Add an error message.
	 *
	 * @param string  $text  Error to add.
	 */
	public static function add_error( $text ) {
		self::$meta_box_errors[] = $text;
	}

	/**
	 * Save errors to an option.
	 */
	public function save_errors() {
		update_option( 'boekdb_meta_box_errors', self::$meta_box_errors );
	}

	/**
	 * Show any stored error messages.
	 */
	public function output_errors() {
		$errors = array_filter( (array) get_option( 'boekdb_meta_box_errors' ) );

		if ( ! empty( $errors ) ) {
			echo '<div id="boekdb_errors" class="error notice is-dismissible">';

			foreach ( $errors as $error ) {
				echo '<p>' . wp_kses_post( $error ) . '</p>';
			}

			echo '</div>';

			// Clear.
			delete_option( 'boekdb_meta_box_errors' );
		}
	}

	/**
	 * Add Meta boxes.
	 */
	public function add_meta_boxes() {
		add_meta_box( 'boek_fields', 'Velden', array( __CLASS__, 'meta_boek_fields_html' ), 'boekdb_boek', 'normal',
			'high' );
	}

	/**
	 * Remove bloat.
	 */
	public function remove_meta_boxes() {
		remove_meta_box( 'postexcerpt', 'boek', 'normal' );
		remove_meta_box( 'commentsdiv', 'boek', 'normal' );
		remove_meta_box( 'commentstatusdiv', 'boek', 'side' );
		remove_meta_box( 'commentstatusdiv', 'boek', 'normal' );
	}

	public static function meta_boek_fields_html( $boek ) {
		$meta = get_post_meta( $boek->ID );
		echo '<table>';
		foreach ( $meta as $name => $value ) {
			if ( substr( $name, 0, 7 ) === 'boekdb_' ) {
				$name = substr( $name, 7 );
				echo '<tr><th style="vertical-align: top; text-align: left;">' . $name . '</th><td>' . $value[0] . '</td></tr>';
			}
		}
		echo '</table>';
	}
}

new BoekDB_Admin_Meta_Boxes();