<?php
/**
 * Post Types
 *
 * Registers post types and taxonomies.
 *
 * @package BoekDB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types Class.
 */
class BoekDB_Post_Types {
	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );

		add_filter( 'posts_join', array( __CLASS__, 'search_join' ), 5 );
		add_filter( 'posts_where', array( __CLASS__, 'search_where' ), 5 );
		add_filter( 'posts_distinct', array( __CLASS__, 'search_distinct' ), 5 );
		add_filter( 'gutenberg_can_edit_post_type', array( __CLASS__, 'gutenberg_can_edit_post_type' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'gutenberg_can_edit_post_type' ), 10, 2 );

		//add_filter( 'manage_boekdb_boek_posts_columns', array( __CLASS__, 'boekdb_add_touch_product_column' ) );
		// add_action( 'admin_init', array( __CLASS__, 'boekdb_touch_product_action' ) );
		//add_action( 'manage_boekdb_boek_posts_custom_column', array( __CLASS__, 'boekdb_render_touch_product_column' ), 10, 2 );
	}

	/**
	 * Register core taxonomies.
	 */
	public static function register_taxonomies() {
		if ( ! is_blog_installed() ) {
			return;
		}

		self::register_betrokkenen_taxonomies();
		self::register_onderwerpen_taxonomies();
		self::register_serie_taxonomy();
	}

	/**
	 * Register Betrokkenen Taxonomies
	 *
	 * Registers three taxonomies: Auteurs, Illustrators, and Sprekers.
	 *
	 * @access protected
	 * @return void
	 */
	protected static function register_betrokkenen_taxonomies() {
		$args = array(
			'hierarchical'      => false,
			'public'            => true,
			'query_var'         => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
		);

		$labels          = array(
			'name'          => 'Auteurs',
			'singular_name' => 'Auteur',
		);
		$args['labels']  = $labels;
		$args['rewrite'] = array(
			'slug'       => 'auteur',
			'with_front' => true,
		);
		register_taxonomy( 'boekdb_auteur_tax', array( 'boekdb_boek' ), $args );

		$labels          = array(
			'name'          => 'Illustrators',
			'singular_name' => 'Illustrator',
		);
		$args['labels']  = $labels;
		$args['rewrite'] = array(
			'slug'       => 'illustrator',
			'with_front' => true,
		);
		register_taxonomy( 'boekdb_illustrator_tax', array( 'boekdb_boek' ), $args );

		$labels          = array(
			'name'          => 'Sprekers',
			'singular_name' => 'Spreker',
		);
		$args['labels']  = $labels;
		$args['rewrite'] = array(
			'slug'       => 'spreker',
			'with_front' => true,
		);
		register_taxonomy( 'boekdb_spreker_tax', array( 'boekdb_boek' ), $args );
	}

	/**
	 * Register Onderwerpen Taxonomies
	 */
	protected static function register_onderwerpen_taxonomies() {
		$args = array(
			'hierarchical'      => false,
			'public'            => true,
			'query_var'         => false,
			'show_ui'           => false,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
		);

		$labels          = array(
			'name'          => 'NUR',
			'singular_name' => 'NUR',
		);
		$args['labels']  = $labels;
		$args['rewrite'] = array(
			'slug'       => 'nur',
			'with_front' => true,
		);
		register_taxonomy( 'boekdb_nur_tax', 'boekdb_boek', $args );

		$labels          = array(
			'name'          => 'BISAC',
			'singular_name' => 'BISAC',
		);
		$args['labels']  = $labels;
		$args['rewrite'] = array(
			'slug'       => 'bisac',
			'with_front' => true,
		);
		register_taxonomy( 'boekdb_bisac_tax', 'boekdb_boek', $args );

		$labels          = array(
			'name'          => 'THEMA',
			'singular_name' => 'THEMA',
		);
		$args['labels']  = $labels;
		$args['rewrite'] = array(
			'slug'       => 'thema',
			'with_front' => true,
		);
		register_taxonomy( 'boekdb_thema_tax', 'boekdb_boek', $args );
	}

	/**
	 * Register the 'boekdb_serie_tax' taxonomy for the 'boekdb_boek' post type.
	 */
	protected static function register_serie_taxonomy() {
		$args = array(
			'hierarchical'      => false,
			'public'            => true,
			'rewrite'           => array(
				'slug'       => 'series',
				'with_front' => true,
			),
			'query_var'         => false,
			'show_ui'           => false,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
			'labels'            => array(
				'name'          => 'Series',
				'singular_name' => 'Serie',
			),
		);

		register_taxonomy( 'boekdb_serie_tax', 'boekdb_boek', $args );
	}

	/**
	 * Register core post types.
	 */
	public static function register_post_types() {
		if ( ! is_blog_installed() ) {
			return;
		}

		self::register_boek_post_type();
	}

	/**
	 * Register the "boekdb_boek" post type.
	 *
	 * This method registers a custom post type called "boekdb_boek" with the specified labels, settings, and capabilities.
	 *
	 * @return void
	 */
	protected static function register_boek_post_type() {
		if ( post_type_exists( 'boekdb_boek' ) ) {
			return;
		}

		$labels = array(
			'name'          => 'Boeken',
			'singular_name' => 'Boek',
		);

		register_post_type(
			'boekdb_boek',
			array(
				'labels'       => $labels,
				'has_archive'  => true,
				'public'       => true,
				'hierarchical' => false,
				'supports'     => array(
					'title',
					'thumbnail',
				),
				'capabilities' => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap' => true,
				'rewrite'      => array( 'slug' => 'boek' ),
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Disable Gutenberg
	 *
	 * @param bool   $can_edit   Whether the post type can be edited or not.
	 * @param string $post_type  The post type being checked.
	 *
	 * @return bool
	 */
	public static function gutenberg_can_edit_post_type( $can_edit, $post_type ) {
		$result = 'boekdb_boek' === $post_type;

		return $result ? false : $can_edit;
	}

	/**
	 * Add additional join condition for search query
	 *
	 * Joins posts and postmeta tables
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_join
	 *
	 * @param string $join  The join condition for the search query.
	 *
	 * @return string The updated join condition for the search query.
	 */
	public static function search_join( $join ) {
		global $wpdb;

		if ( is_search() ) {
			$join .= ' LEFT JOIN ' . $wpdb->postmeta . ' as boekdb_metadata ON ' . $wpdb->posts . '.ID = boekdb_metadata.post_id ';
		}

		return $join;
	}

	/**
	 * Modify the WHERE clause of a search query.
	 *
	 * Modify the search query with posts_where
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_where
	 *
	 * @param string $where  The original WHERE clause of the search query.
	 *
	 * @return string The modified WHERE clause.
	 */
	public static function search_where( $where ) {
		global $pagenow, $wpdb;

		if ( is_search() ) {
			$where = preg_replace(
				'/\(\s*' . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
				'(' . $wpdb->posts . ".post_title LIKE $1) OR (boekdb_metadata.meta_value LIKE $1 AND boekdb_metadata.meta_key LIKE 'boekdb_%')",
				$where
			);
		}

		return $where;
	}

	/**
	 * Search Distinct, prevent duplicates
	 *
	 * Determines if the search query is being executed and returns 'DISTINCT' if true.
	 * Otherwise, it returns the original $where parameter.
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_distinct
	 *
	 * @param string $where  The original WHERE clause.
	 *
	 * @return string
	 */
	public static function search_distinct( $where ) {
		if ( is_search() ) {
			return 'DISTINCT';
		}

		return $where;
	}

	/**
	 * Add touch_product column to the given columns array.
	 *
	 * @param array $columns  The array of columns.
	 *
	 * @return array The updated array of columns with touch_product column.
	 */
	public static function boekdb_add_touch_product_column( $columns ) {
		$columns['touch_product'] = 'Touch Product';

		return $columns;
	}

	/**
	 * Render the touch product column for the given post.
	 *
	 * @param string $column   The name of the column being rendered.
	 * @param int    $post_id  The post ID.
	 *
	 * @return void
	 */
	public static function boekdb_render_touch_product_column( $column, $post_id ) {
		if ( 'touch_product' === $column ) {
			$url = add_query_arg(
				array(
					'action' => 'touch_product',
					'post'   => $post_id,
					'nonce'  => wp_create_nonce( 'touch_product_' . $post_id ),
				),
				admin_url( 'edit.php' )
			);
			echo '<a href="' . esc_url( $url ) . '" class="button">Touch Product</a>';
		}
	}

	/**
	 * Perform touch action on a product.
	 *
	 * @return void
	 */
	public static function boekdb_touch_product_action() {
		if ( isset( $_GET['action'], $_GET['post'], $_GET['nonce'] )
			&& $_GET['action'] === 'touch_product'
			&& wp_verify_nonce( $_GET['nonce'], 'touch_product_' . $_GET['post'] )
		) {
			$post_id = $_GET['post'];
			if ( BoekDB_Api_Service::touch_product( $post_id ) ) {
				// add a transient to store the admin message
				set_transient( 'boekdb_admin_notice', 'Product touch succesvol!', 5 );
			} else {
				// add a transient to store the error message
				set_transient( 'boekdb_admin_notice', 'Kon product touch niet uitvoeren!', 5 );
			}

			// redirect to prevent refreshing the page from causing a double touch
			wp_safe_redirect( admin_url( 'edit.php?post_type=boekdb_boek' ) );
			exit;
		}
	}
}

BoekDB_Post_Types::init();
