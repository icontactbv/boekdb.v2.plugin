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
	 * Nonce action and field of the BoekDB fields meta box.
	 */
	const FIELDS_NONCE = 'boekdb_boek_fields_nonce';

	/**
	 * Nonce action and field of the alternate url meta box.
	 */
	const URL_NONCE = 'boekdb_etalage_url_nonce';

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
	 * Whether this request may save the meta boxes of a book.
	 *
	 * WordPress fires save_post for every post saved anywhere, revisions included. Only a
	 * book, by someone who may edit that book. The nonce of the meta box is checked by the
	 * caller.
	 *
	 * @param int $post_id  The ID of the post being saved.
	 *
	 * @return bool
	 */
	private static function may_save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( 'boekdb_boek' !== get_post_type( $post_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * A text field of the form, unslashed and as it is compared and stored.
	 *
	 * @param string $field  The name of the field.
	 *
	 * @return string
	 */
	private static function posted_text( $field ) {
		return self::as_stored( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- The caller checked the nonce and that the field is a string; as_stored() sanitizes it.
	}

	/**
	 * A text the way this user would store it.
	 *
	 * Left as it was typed for whoever may post unfiltered html, as WordPress does for post
	 * content. Anyone else gets what is allowed in a post. Line endings as a textarea sends
	 * them back are made plain. What BoekDB stored goes through the same, so a text nobody
	 * touched compares equal and is not taken for one that was overwritten.
	 *
	 * @param string $text  The text.
	 *
	 * @return string
	 */
	private static function as_stored( $text ) {
		$text = str_replace( "\r\n", "\n", (string) $text );

		return current_user_can( 'unfiltered_html' ) ? $text : wp_kses_post( $text );
	}

	/**
	 * Saves the meta boxes for a given post ID.
	 *
	 * The form data comes slashed and update_post_meta() unslashes what it stores. Texts are
	 * compared unslashed and handed over slashed, or a text with a quote in it would count
	 * as changed on every save and lose its backslashes.
	 *
	 * @param int $post_id  The ID of the post being saved.
	 *
	 * @return mixed The post ID that was saved.
	 */
	public static function save_meta_boxes( $post_id ) {
		if ( ! isset( $_POST[ self::FIELDS_NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::FIELDS_NONCE ] ) ), self::FIELDS_NONCE ) ) {
			return $post_id;
		}

		if ( ! self::may_save( $post_id ) ) {
			return $post_id;
		}

		$meta = get_post_meta( $post_id );
		if ( isset( $_POST['boekdb_annotatie'] ) && is_string( $_POST['boekdb_annotatie'] ) ) {
			$boekdb_annotatie      = self::posted_text( 'boekdb_annotatie' );
			$annotatie_overwritten = isset( $meta['boekdb_annotatie_overwritten'][0] ) ? $meta['boekdb_annotatie_overwritten'][0] : '0';
			if ( strlen( trim( $boekdb_annotatie ) ) === 0 && '1' === $annotatie_overwritten ) {
				update_post_meta( $post_id, 'boekdb_annotatie', wp_slash( $meta['boekdb_annotatie_org'][0] ) );
				update_post_meta( $post_id, 'boekdb_annotatie_overwritten', '0' );
			} elseif ( '1' === $annotatie_overwritten || self::as_stored( $meta['boekdb_annotatie'][0] ) !== $boekdb_annotatie ) {
					update_post_meta( $post_id, 'boekdb_annotatie', wp_slash( $boekdb_annotatie ) );
					update_post_meta( $post_id, 'boekdb_annotatie_overwritten', '1' );
			}
		}
		if ( isset( $_POST['boekdb_flaptekst'] ) && is_string( $_POST['boekdb_flaptekst'] ) ) {
			$boekdb_flaptekst      = self::posted_text( 'boekdb_flaptekst' );
			$flaptekst_overwritten = isset( $meta['boekdb_flaptekst_overwritten'][0] ) ? $meta['boekdb_flaptekst_overwritten'][0] : '0';
			if ( strlen( trim( $boekdb_flaptekst ) ) === 0 && '1' === $flaptekst_overwritten ) {
				update_post_meta( $post_id, 'boekdb_flaptekst', wp_slash( trim( $meta['boekdb_flaptekst_org'][0] ) ) );
				update_post_meta( $post_id, 'boekdb_flaptekst_overwritten', '0' );
			} elseif ( '1' === $flaptekst_overwritten || trim( self::as_stored( $meta['boekdb_flaptekst'][0] ) ) !== $boekdb_flaptekst ) {
					update_post_meta( $post_id, 'boekdb_flaptekst', wp_slash( $boekdb_flaptekst ) );
					update_post_meta( $post_id, 'boekdb_flaptekst_overwritten', '1' );
			}
		}

		if ( isset( $_POST['quote'] ) && is_array( $_POST['quote'] ) ) {
			$quotes = get_post_meta( $post_id, 'boekdb_recensiequotes', true );
			if ( is_array( $quotes ) ) {
				foreach ( array_map( 'sanitize_key', wp_unslash( $_POST['quote'] ) ) as $hash => $value ) {
					if ( isset( $quotes[ $hash ] ) ) {
						$quotes[ $hash ]['tonen'] = 'on' === $value;
					}
				}
				update_post_meta( $post_id, 'boekdb_recensiequotes', wp_slash( $quotes ) );
			}
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
		$quotes                = isset( $meta['boekdb_recensiequotes'][0] ) ? maybe_unserialize( $meta['boekdb_recensiequotes'][0] ) : null;

		wp_nonce_field( self::FIELDS_NONCE, self::FIELDS_NONCE );

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
	 * @param object $post  The WordPress post object.
	 *
	 * @return void
	 */
	public static function render_etalage_url_meta_box( $post ) {
		$alternate_urls = boekdb_get_alternate_urls( $post->ID );
		$selected_url   = get_post_meta( $post->ID, 'selected_alternate_url', true );

		// If no alternate URL was selected previously
		if ( ! $selected_url ) {
			// Set the selected URL to the current permalink
			$selected_url = get_permalink( $post->ID );
		}

		if ( count( $alternate_urls ) > 1 ) {
			wp_nonce_field( self::URL_NONCE, self::URL_NONCE );

			echo '<ul>';
			foreach ( $alternate_urls as $alternate_url ) {
				echo '<li>';

				echo '<input type="radio" name="selected_alternate_url" value="'
					. esc_attr( $alternate_url['url'] ) . '" '
					. checked( $selected_url, $alternate_url['url'], false ) . '>&nbsp;';

				if ( 'default' === $alternate_url['name'] ) {
					echo '"' . esc_html( $alternate_url['name'] ) . '":&nbsp;';
				} else {
					echo 'Etalage "' . esc_html( $alternate_url['name'] ) . '":&nbsp;';
				}

				echo '<a href="' . esc_url( $alternate_url['url'] )
					. '" target="_blank">' . esc_url( $alternate_url['url'] ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>Er zijn geen alternatieve URLs voor deze etalage.</p>';
		}
	}

	/**
	 * Saves the alternate url chosen for a book.
	 *
	 * The url becomes the permalink of the book, so only one on this site, under /boek/,
	 * as the meta box offers them.
	 *
	 * @param int $post_id  The ID of the post being saved.
	 *
	 * @return void
	 */
	public function save_etalage_url( $post_id ) {
		if ( ! isset( $_POST[ self::URL_NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::URL_NONCE ] ) ), self::URL_NONCE ) ) {
			return;
		}

		if ( ! isset( $_POST['selected_alternate_url'] ) || ! self::may_save( $post_id ) ) {
			return;
		}

		$selected_url = esc_url_raw( wp_unslash( $_POST['selected_alternate_url'] ) );

		if ( ! boekdb_is_book_url( $selected_url ) ) {
			return;
		}

		update_post_meta( $post_id, 'selected_alternate_url', $selected_url );

		// Clear the cache.
		delete_transient( 'boekdb_permalink_' . $post_id );
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
