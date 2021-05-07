<?php
/**
 * Post Types
 *
 * Registers post types and taxonomies.
 *
 * @package BoekDB\Classes\PostTypes
 */

defined('ABSPATH') || exit;

/**
 * Post types Class.
 */
class Boekdb_Post_Types
{
    /**
     * Hook in methods.
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_taxonomies'), 5);
        add_action('init', array(__CLASS__, 'register_post_types'), 5);
        add_action('add_meta_boxes', array(__CLASS__, 'add_metaboxes'), 5);
    }

    /**
     * Register core taxonomies.
     */
    public static function register_taxonomies()
    {
        if (!is_blog_installed()) {
            return;
        }

        self::register_medewerker_taxonomy();
    }

    /**
     * Register core post types.
     */
    public static function register_post_types()
    {
        if (!is_blog_installed()) {
            return;
        }

        self::register_boek_post_type();
        self::register_medewerker_post_type();
    }

    protected static function register_medewerker_taxonomy()
    {
        $labels = array(
            'name'          => 'Medewerkers Taxonomie',
            'singular_name' => 'Medewerker Taxonomie',
        );
        $args   = array(
            'labels'            => $labels,
            'hierarchical'      => false,
            'public'            => false,
            'rewrite'           => false,
            'query_var'         => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => true,
        );

        register_taxonomy('boekdb_medewerker_tax', array('boekdb_boek', 'boekdb_medewerker'), $args);
    }

    protected static function register_medewerker_post_type()
    {
        if (post_type_exists('boekdb_medewerker')) {
            return;
        }

        $labels = array(
            'name'          => 'Medewerkers',
            'singular_name' => 'Medewerker',
        );

        register_post_type(
            'boekdb_medewerker', array(
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
                //'taxonomies'   => 'category',
                'rewrite'         => array('slug' => 'medewerker'),
                'show_in_rest'    => true
            )
        );
    }

    protected static function register_boek_post_type()
    {
        if (post_type_exists('boekdb_boek')) {
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
                //'taxonomies'   => 'category',
                'rewrite'      => array('slug' => 'boek'),
                'show_in_rest' => true
            )
        );
    }

    public static function add_metaboxes()
    {
        add_meta_box(
            'boek_fields',
            'Velden', // Title of metabox
            array(__CLASS__, 'metabox_html'), // Function that prints out the HTML for metabox
            'boekdb_boek',
            'normal',
            'high'
        );
    }

    public static function metabox_html( $boek )
    {
        $meta = get_post_meta($boek->ID);
        echo '<table>';
        foreach($meta as $name => $value) {
            echo '<tr><th style="vertical-align: top; text-align: left;">'.$name.'</th><td>'.$value[0].'</td></tr>';
        }
        echo '</table>';
    }
}

Boekdb_Post_Types::init();