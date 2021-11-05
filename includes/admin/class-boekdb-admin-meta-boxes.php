<?php
/**
 * BoekDB Meta Boxes
 *
 * Sets up the write panels used by custom post types
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

/**
 * BoekDB_Admin_Meta_Boxes.
 */
class BoekDB_Admin_Meta_Boxes {
	/**
	 * Meta box error messages.
	 *
	 * @var array
	 */
	public static $meta_box_errors = array();
	/**
	 * Is meta boxes saved once?
	 *
	 * @var boolean
	 */
	private static $saved_meta_boxes = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ), 10 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 30 );
		add_action( 'save_post', array( $this, 'save_meta_boxes' ), 1, 2 );

		// Error handling (for showing errors from meta boxes on next page load).
		add_action( 'admin_notices', array( $this, 'output_errors' ) );
		add_action( 'shutdown', array( $this, 'save_errors' ) );
	}

	/**
	 * Add an error message.
	 *
	 * @param  string  $text  Error to add.
	 */
	public static function add_error( $text ) {
		self::$meta_box_errors[] = $text;
	}

	public static function save_meta_boxes( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		$meta = get_post_meta( $post_id );
		if ( isset( $_POST['boekdb_annotatie'] ) ) {
			$boekdb_annotatie      = $_POST['boekdb_annotatie'];
			$annotatie_overwritten = isset( $meta['boekdb_annotatie_overwritten'][0] ) ? $meta['boekdb_annotatie_overwritten'][0] : '0';
			if(strlen(trim($boekdb_annotatie)) === 0 && $annotatie_overwritten === '1' ) {
					update_post_meta( $post_id, 'boekdb_annotatie', $meta['boekdb_annotatie_org'][0] );
					update_post_meta( $post_id, 'boekdb_annotatie_overwritten', '0' );
			} else {
				if ( $annotatie_overwritten === '1' || $meta['boekdb_annotatie'][0] !== $boekdb_annotatie ) {
					update_post_meta( $post_id, 'boekdb_annotatie', $boekdb_annotatie );
					update_post_meta( $post_id, 'boekdb_annotatie_overwritten', '1' );
				}
			}
		}
		if ( isset( $_POST['boekdb_flaptekst'] ) ) {
			$boekdb_flaptekst      = str_replace("\r\n","\n", $_POST['boekdb_flaptekst']);
			$flaptekst_overwritten = isset( $meta['boekdb_flaptekst_overwritten'][0] ) ? $meta['boekdb_flaptekst_overwritten'][0] : '0';
			if(strlen(trim($boekdb_flaptekst)) === 0 && $flaptekst_overwritten === '1' ) {
				update_post_meta( $post_id, 'boekdb_flaptekst', trim($meta['boekdb_flaptekst_org'][0]) );
				update_post_meta( $post_id, 'boekdb_flaptekst_overwritten', '0' );
			} else {
				if ( $flaptekst_overwritten === '1' || trim($meta['boekdb_flaptekst'][0]) !== $boekdb_flaptekst ) {
					update_post_meta( $post_id, 'boekdb_flaptekst', $boekdb_flaptekst );
					update_post_meta( $post_id, 'boekdb_flaptekst_overwritten', '1' );
				}
			}
		}

		if ( isset ( $_POST['quote'] ) && is_array( $_POST['quote'] ) ) {
			$quotes = get_post_meta( $post_id, 'boekdb_recensiequotes' )[0];
			foreach ( $_POST['quote'] as $hash => $value ) {
				if ( isset( $quotes[ $hash ] ) ) {
					$quotes[ $hash ]['tonen'] = $value === 'on' ? true : false;
				}
			}
			update_post_meta( $post_id, 'boekdb_recensiequotes', $quotes );
		}

		return $post_id;
	}

	public static function meta_boek_fields_html( $boek ) {
		$meta = get_post_meta( $boek->ID );

		$annotatie_overwritten = isset( $meta['boekdb_annotatie_overwritten'][0] ) ? $meta['boekdb_annotatie_overwritten'][0] : '0';
		$flaptekst_overwritten = isset( $meta['boekdb_flaptekst_overwritten'][0] ) ? $meta['boekdb_flaptekst_overwritten'][0] : '0';
		$quotes                = isset( $meta['boekdb_recensiequotes'][0] ) ? unserialize( $meta['boekdb_recensiequotes'][0] ) : null;

		include __DIR__ . '/views/html-admin-meta-boek-fields-html.php';
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
		add_meta_box( 'boek_fields', 'BoekDB velden',
			array( __CLASS__, 'meta_boek_fields_html' ), 'boekdb_boek' );
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
}


new BoekDB_Admin_Meta_Boxes();