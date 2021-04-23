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
        //add_action('init', array(__CLASS__, 'register_taxonomies'), 5);
        add_action('init', array(__CLASS__, 'register_post_types'), 5);
    }

    /**
     * Register core taxonomies.
     */
    public static function register_taxonomies()
    {
        if (!is_blog_installed()) {
            return;
        }
    }

    /**
     * Register core post types.
     */
    public static function register_post_types()
    {
        if (!is_blog_installed() || post_type_exists('book')) {
            return;
        }

        $labels = array(
            'name'          => 'Boeken',
            'singular_name' => 'Boek',
        );

        register_post_type(
            'book', array(
                'labels'       => $labels,
                'has_archive'  => true,
                'public'       => true,
                'hierarchical' => false,
                'supports'     => array(
                    'title',
                    'editor',
                    'excerpt',
                    'custom-fields',
                    'thumbnail',
                    'page-attributes'
                ),
                'taxonomies'   => 'category',
                'rewrite'      => array('slug' => 'book'),
                'show_in_rest' => true
            )
        );
    }
}

Boekdb_Post_Types::init();