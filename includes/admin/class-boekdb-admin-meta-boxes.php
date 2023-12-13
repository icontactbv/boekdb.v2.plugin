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
		add_action( 'save_post', array( self::class, 'save_meta_boxes' ), 1, 2 );
		add_action( 'save_post', array( $this, 'save_etalage_url' ), 10, 1 );

		// Error handling (for showing errors from meta boxes on next page load).
		add_action( 'admin_notices', array( $this, 'output_errors' ) );
		add_action( 'shutdown', array( $this, 'save_errors' ) );
	}

	/**
	 * Add an error message.
	 *
	 * @param string $text  Error to add.
	 */
	public static function add_error( $text ) {
		self::$meta_box_errors[] = $text;
	}

	/**
	 * Saves the meta boxes for a given post ID.
	 *
	 * @param int $post_id  The ID of the post being saved.
	 *
	 * @return mixed The post ID that was saved.
	 */
	public static function save_meta_boxes( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		$meta = get_post_meta( $post_id );
		if ( isset( $_POST['boekdb_annotatie'] ) ) {
			$boekdb_annotatie      = $_POST['boekdb_annotatie'];
			$annotatie_overwritten = isset( $meta['boekdb_annotatie_overwritten'][0] ) ? $meta['boekdb_annotatie_overwritten'][0] : '0';
			if ( strlen( trim( $boekdb_annotatie ) ) === 0 && $annotatie_overwritten === '1' ) {
				update_post_meta( $post_id, 'boekdb_annotatie', $meta['boekdb_annotatie_org'][0] );
				update_post_meta( $post_id, 'boekdb_annotatie_overwritten', '0' );
			} elseif ( $annotatie_overwritten === '1' || $meta['boekdb_annotatie'][0] !== $boekdb_annotatie ) {
					update_post_meta( $post_id, 'boekdb_annotatie', $boekdb_annotatie );
					update_post_meta( $post_id, 'boekdb_annotatie_overwritten', '1' );
			}
		}
		if ( isset( $_POST['boekdb_flaptekst'] ) ) {
			$boekdb_flaptekst      = str_replace( "\r\n", "\n", $_POST['boekdb_flaptekst'] );
			$flaptekst_overwritten = isset( $meta['boekdb_flaptekst_overwritten'][0] ) ? $meta['boekdb_flaptekst_overwritten'][0] : '0';
			if ( strlen( trim( $boekdb_flaptekst ) ) === 0 && $flaptekst_overwritten === '1' ) {
				update_post_meta( $post_id, 'boekdb_flaptekst', trim( $meta['boekdb_flaptekst_org'][0] ) );
				update_post_meta( $post_id, 'boekdb_flaptekst_overwritten', '0' );
			} elseif ( $flaptekst_overwritten === '1' || trim( $meta['boekdb_flaptekst'][0] ) !== $boekdb_flaptekst ) {
					update_post_meta( $post_id, 'boekdb_flaptekst', $boekdb_flaptekst );
					update_post_meta( $post_id, 'boekdb_flaptekst_overwritten', '1' );
			}
		}

		if ( isset( $_POST['quote'] ) && is_array( $_POST['quote'] ) ) {
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

	/**
	 * Generate HTML for displaying the meta fields of a book.
	 *
	 * @param WP_Post $boek  The book post object.
	 *
	 * @return void
	 */
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
		add_meta_box(
			'boek_fields',
			'BoekDB velden',
			array( self::class, 'meta_boek_fields_html' ),
			'boekdb_boek'
		);

		// Add meta box for Etalage URL
		add_meta_box(
			'etalage_url_meta_box',
			'Alternatieve URL\'s voor etalages:',
			array( self::class, 'render_etalage_url_meta_box' ),
			'boekdb_boek',
			'normal',
			'high'
		);
	}

	/**
	 * Renders the etalage URL meta box on the post edit screen.
	 *
	 * @param object  $post  The WordPress post object.
	 *
	 * @return void
	 */
	public static function render_etalage_url_meta_box($post) {
		$alternate_urls = boekdb_get_alternate_urls($post->ID);
		$selected_url = get_post_meta($post->ID, 'selected_alternate_url', true);

		// Default URL without prefix
		$default_url = array(
			'name' => 'Default',
			'url' => get_permalink($post)
		);

		array_push($alternate_urls, $default_url);

		if (count($alternate_urls) > 1) {
			echo '<ul>';
			foreach($alternate_urls as $alternate_url) {
				echo '<li>';
				echo '<input type="radio" name="selected_alternate_url" value="' . esc_attr($alternate_url['url']) . '" ' . checked($selected_url, $alternate_url['url'], false) . '>&nbsp;';

				if($alternate_url['name'] == 'Default') {
					echo $alternate_url['name'].':&nbsp;';
				} else {
					echo 'Etalage "'. $alternate_url['name'] .'":&nbsp;';
				}

				echo '<a href="' . esc_url($alternate_url['url']) . '" target="_blank">' . esc_url($alternate_url['url']) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>Er zijn geen alternatieve URLs voor deze etalage.</p>';
		}
	}

	function save_etalage_url($post_id) {
		if (array_key_exists('selected_alternate_url', $_POST)) {
			update_post_meta(
				$post_id,
				'selected_alternate_url',
				$_POST['selected_alternate_url']
			);
		}
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
