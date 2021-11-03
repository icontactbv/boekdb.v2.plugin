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
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $post_id;

		$boekdb_annotatie = $_POST['boekdb_annotatie'];
		$boekdb_flaptekst = $_POST['boekdb_flaptekst'];

		$meta = get_post_meta( $post_id );
		$annotatie_overwritten = isset( $meta['boekdb_annotatie_overwritten'][0] ) ? $meta['boekdb_annotatie_overwritten'][0] : '0';
		$flaptekst_overwritten = isset( $meta['boekdb_flaptekst_overwritten'][0] ) ? $meta['boekdb_flaptekst_overwritten'][0] : '0';
		if($annotatie_overwritten === '1' || $meta['boekdb_annotatie'][0] !== $boekdb_annotatie) {
			update_post_meta($post_id, 'boekdb_annotatie', $boekdb_annotatie);
			update_post_meta($post_id, 'boekdb_annotatie_overwritten', '1');
		}

		if($flaptekst_overwritten === '1' || $meta['boekdb_flaptekst'][0] !== $boekdb_flaptekst) {
			update_post_meta($post_id, 'boekdb_flaptekst', $boekdb_flaptekst);
			update_post_meta($post_id, 'boekdb_flaptekst_overwritten', '1');
		}
		return $post_id;
	}

	public static function meta_boek_fields_html( $boek ) {
		$meta = get_post_meta( $boek->ID );

		$annotatie_overwritten = isset( $meta['boekdb_annotatie_overwritten'][0] ) ? $meta['boekdb_annotatie_overwritten'][0] : '0';
		$flaptekst_overwritten = isset( $meta['boekdb_flaptekst_overwritten'][0] ) ? $meta['boekdb_flaptekst_overwritten'][0] : '0';
		$quotes                = isset( $meta['recensiequotes'][0] ) ? $meta['recensiequotes'][0] : array();

		echo '<h4>Annotatie</h4>';
		echo '<textarea name="boekdb_annotatie" id="boekdb_annotatie" cols="80" rows="4">' . $meta['boekdb_annotatie'][0] . '</textarea>';
		if ( $annotatie_overwritten === '1' ) {
			echo '<br /><em>Originele annotatie uit BoekDB:</em>';
			echo '<p>' . $meta['boekdb_annotatie_org'][0] . '</p>';
		}
		echo '<hr />';

		echo '<h4>Flaptekst</h4>';
		echo '<textarea name="boekdb_flaptekst" id="boekdb_flaptekst" cols="80" rows="8">' . $meta['boekdb_flaptekst'][0] . '</textarea>';
		if ( $flaptekst_overwritten === '1' ) {
			echo '<br /><em>Originele flaptekst uit BoekDB:</em>';
			echo '<p>' . htmlspecialchars($meta['boekdb_flaptekst_org'][0]) . '</p>';
		}
		echo '<hr />';

		echo '<h4>Recensiequotes</h4>';
		echo '<ul>';
		foreach ( $quotes as $quote ) {
			echo '<li>' . $quote . '</li>';
		}
		echo '</ul>';
		echo '<hr />';

		$skip = array(
			'flaptekst',
			'annotatie',
			'flaptekst_org',
			'annotatie_org',
			'recensiequotes',
			'recensiequotes_tonen',
			'file_voorbeeld_id',
			'0',
			'primair'
		);
		echo '<table>';
		foreach ( $meta as $name => $value ) {
			if ( wp_startswith( $name, 'boekdb_' ) ) {
				$name = substr( $name, 7 );
				if ( in_array( $name, $skip ) ) {
					continue;
				}


				echo '<tr><th style="vertical-align: top; text-align: left;">' . $name . '</th><td>' . $value[0] . '</td></tr>';
			}
		}
		echo '</table>';
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