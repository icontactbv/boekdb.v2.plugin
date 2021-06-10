<?php
/**
 * Post Types
 *
 * Registers post types and taxonomies.
 *
 * @package BoekDB\Classes\PostTypes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types Class.
 */
class Boekdb_Post_Types {
	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_post_type_templates' ), 5 );

		add_filter( 'posts_join', array( __CLASS__, 'search_join' ), 5 );
		add_filter( 'posts_where', array( __CLASS__, 'search_where' ), 5 );
		add_filter( 'posts_distinct', array( __CLASS__, 'search_distinct' ), 5 );
		add_filter( 'gutenberg_can_edit_post_type', array( __CLASS__, 'gutenberg_can_edit_post_type' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'gutenberg_can_edit_post_type' ), 10, 2 );
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
	}

	/**
	 * Register core post types.
	 */
	public static function register_post_types() {
		if ( ! is_blog_installed() ) {
			return;
		}

		self::register_nstc_post_type();
		self::register_boek_post_type();
		self::register_betrokkene_post_type();
	}

	public static function register_post_type_templates() {
		add_filter( 'single_template', array(__CLASS__, 'load_boek_template'), 50, 1 );
		function load_my_custom_template( $template ) {

			if ( is_singular( 'my_custom_post_type' ) ) {
				$template = plugins_url( 'templates/my_custom_post_type.php', __FILE__ );
			}

			return $template;
		}
	}

	public static function load_boek_template( $template ) {
		if ( is_singular( 'boekdb_boek' ) ) {
			$template = plugin_dir_path(__FILE__) . 'templates/boekdb_boek.php';
		}

		return $template;
	}

	protected static function register_nstc_post_type() {
		if ( post_type_exists( 'boekdb_nstc' ) ) {
			return;
		}

		$labels = array(
			'name'          => 'NSTCs',
			'singular_name' => 'NSTC',
		);

		register_post_type(
			'boekdb_nstc', array(
				'labels'       => $labels,
				'has_archive'  => true,
				'public'       => false,
				'hierarchical' => false,
				'supports'     => array(
					'title',
					'thumbnail',
				),
				'capabilities' => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap' => true,
				//'taxonomies'   => 'category',
				'rewrite'      => array( 'slug' => 'nstc' ),
				'show_in_rest' => true
			)
		);
	}

	protected static function register_onderwerpen_taxonomies() {
		$args = array(
			'hierarchical'      => false,
			'public'            => false,
			'rewrite'           => false,
			'query_var'         => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
		);

		$labels         = array(
			'name'          => 'NUR',
			'singular_name' => 'NUR',
		);
		$args['labels'] = $labels;
		register_taxonomy( 'boekdb_nur_tax', 'boekdb_boek', $args );

		$labels         = array(
			'name'          => 'BISAC',
			'singular_name' => 'BISAC',
		);
		$args['labels'] = $labels;
		register_taxonomy( 'boekdb_bisac_tax', 'boekdb_boek', $args );

		$labels         = array(
			'name'          => 'THEMA',
			'singular_name' => 'THEMA',
		);
		$args['labels'] = $labels;
		register_taxonomy( 'boekdb_thema_tax', 'boekdb_boek', $args );
	}

	protected static function register_betrokkenen_taxonomies() {
		$args = array(
			'hierarchical'      => false,
			'public'            => false,
			'rewrite'           => false,
			'query_var'         => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
		);

		$labels         = array(
			'name'          => 'Auteurs',
			'singular_name' => 'Auteur',
		);
		$args['labels'] = $labels;

		register_taxonomy( 'boekdb_auteur_tax', array( 'boekdb_boek', 'boekdb_betrokkene' ), $args );

		$labels         = array(
			'name'          => 'Illustrators',
			'singular_name' => 'Illustrator',
		);
		$args['labels'] = $labels;

		register_taxonomy( 'boekdb_illustrator_tax', array( 'boekdb_boek', 'boekdb_betrokkene' ), $args );

		$labels         = array(
			'name'          => 'Sprekers',
			'singular_name' => 'Spreker',
		);
		$args['labels'] = $labels;

		register_taxonomy( 'boekdb_spreker_tax', array( 'boekdb_boek', 'boekdb_betrokkene' ), $args );
	}

	protected static function register_betrokkene_post_type() {
		if ( post_type_exists( 'boekdb_betrokkene' ) ) {
			return;
		}

		$labels = array(
			'name'          => 'Betrokkenen',
			'singular_name' => 'Betrokkene',
		);

		register_post_type(
			'boekdb_betrokkene', array(
				'labels'          => $labels,
				'has_archive'     => true,
				'public'          => true,
				'hierarchical'    => false,
				'supports'        => array(
					'title',
					'custom-fields',
					'thumbnail',
				),
				'capability_type' => 'page',
				'capabilities'    => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap'    => true,
				'rewrite'         => array( 'slug' => 'betrokkene' ),
				'show_in_rest'    => true
			)
		);
	}

	protected static function register_boek_post_type() {
		if ( post_type_exists( 'boekdb_boek' ) ) {
			return;
		}

		$labels = array(
			'name'          => 'Boeken',
			'singular_name' => 'Boek',
		);

		register_post_type(
			'boekdb_boek', array(
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
				'show_in_rest' => true
			)
		);
	}

	/**
	 * Disable Gutenberg
	 *
	 * @param bool  $can_edit  Whether the post type can be edited or not.
	 * @param string  $post_type  The post type being checked.
	 *
	 * @return bool
	 */
	public static function gutenberg_can_edit_post_type( $can_edit, $post_type ) {
		$result = 'boekdb_boek' === $post_type || 'boekdb_betrokkene' === $post_type || 'boekdb_nstc' === $post_type;

		return $result ? false : $can_edit;
	}

	/**
	 * Join posts and postmeta tables
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_join
	 */
	public static function search_join( $join ) {
		global $wpdb;

		if ( is_search() ) {
			$join .= ' LEFT JOIN ' . $wpdb->postmeta . ' ON ' . $wpdb->posts . '.ID = ' . $wpdb->postmeta . '.post_id ';
		}

		return $join;
	}

	/**
	 * Modify the search query with posts_where
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_where
	 */
	public static function search_where( $where ) {
		global $pagenow, $wpdb;

		if ( is_search() ) {
			$where = preg_replace(
				"/\(\s*" . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
				"(" . $wpdb->posts . ".post_title LIKE $1) OR (" . $wpdb->postmeta . ".meta_value LIKE $1 AND meta_key LIKE 'boekdb_%')",
				$where );
		}

		return $where;
	}

	/**
	 * Prevent duplicates
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_distinct
	 */
	public static function search_distinct( $where ) {
		global $wpdb;

		if ( is_search() ) {
			return "DISTINCT";
		}

		return $where;
	}

}

Boekdb_Post_Types::init();