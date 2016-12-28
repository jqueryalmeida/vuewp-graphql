<?php

namespace CI\GraphQLWP\Type\Definition;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;
use CI\GraphQLWP\Schema;

class WPQuery extends WPObjectType {

    private static $instance;
    //count variable for pagination
    private static $count = 0;

    static function getInstance($config=[]) {
        return static::$instance ?: static::$instance = new static($config);
    }

    static function getDescription() {
        return 'deals with the intricacies of a post request on a WordPress blog';
    }

    static function getFieldSchema() {
        $schema = [
            'posts' => [
                'type' => new ListOfType(WPPost::getInstance()),
                'args' => static::getWPQueryParams(),
                'resolve' => function($root, $args) {
                    //CI: replaced 'get_posts' by 'query' because we need found_posts for pagination
                    if(!$args) {
                        static::$count = count($root->posts);
                        return $root->posts;
                    }
                    $result = [];
                    $query = new \WP_Query( $args );
                    $result = $query->posts;
                    static::$count = $query->found_posts;
                    wp_reset_postdata();
                    return $result;
                }
            ],
            //CI we need this to access the custom post types dynamically
            'post_types' =>  [
                'type' => new ListOfType(Type::string()),
                'resolve' => function($root, $args) {
                    return get_post_types( array( '_builtin' => false ) );
                }
            ],
            'menu' => [
                'type' => new ListOfType( MenuItem::getInstance() ),
                'args' => [
                    'name' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => "Menu 'id','name' or 'slug'"
                    ]
                ],
                'resolve' => function($root, $args) {
                    return wp_get_nav_menu_items($args['name']) ?: [];
                }
            ],
            'bloginfo' => [
                'type' => BlogInfo::getInstance(),
                'resolve' => function($root, $args){
                    return isset($args['filter']) ? $args['filter'] : 'raw';
                }
            ],
            'home_page' => [
                'type' => WPPost::getInstance(),
                'resolve' => function(){
                    return get_post(get_option('page_on_front'));
                }
            ],
            'author' => [
                'type' => new ListOfType(WPPost::getInstance()),
                'args' => static::extendArgs([
                    'author' => [
                        'type' => Type::int(),
                    ]
                ]),
                'resolve' => function($root, $args) {
                //CI: replaced get_posts by query because we need found_posts for pagination
                    if(!$args) {
                        static::$count = count($root->posts);
                        return $root->posts;
                    }
                    $result = [];
                    $query = new \WP_Query( $args );
                    $result = $query->posts;
                    static::$count = $query->found_posts;
                    wp_reset_postdata();
                    return $result;
                }
            ],
            'header_image' => [
                'type' => Type::string(),
                'description' => 'header image url',
                'resolve' => function($root) {
                    return get_header_image();
                }
            ],
            'terms' => [
                'type' => new ListOfType(WPTerm::getInstance()),
                'description' => 'Retrieve the terms in a given taxonomy or list of taxonomies. ',
                'args' => static::getTermParams(),
                'resolve' => function($root, $args) {

                    $taxonomies = isset($args['taxonomies'])
                    ? $args['taxonomies']
                    : isset($args['taxonomy']) ? $args['taxonomy'] : 'category';

                    return get_terms($taxonomies, $args);
                }
            ],
            'seo' => [
                'type' => Type::string(),
                'description' => 'Get seo metadata for given url',
                'args' => [
                    'seoType' => [ 'type' => Type::string() ],
                    'seoIdentifier' => [ 'type' => Type::string() ]
                ],
                'resolve' => function( $root, $args ) {
                    $result = "";
                    switch( $args['seoType'] ) {
                        case 'single':
                            $result = get_post_meta( $args['seoIdentifier', 'wpseo_prefetch', true );
                            break;
                        case 'category':
                            $result = get_option( 'wpseo_prefetch_category_' . $args['seoIdentifier'] );
                            break;
                        case 'tag': 
                            $result = get_option( 'wpseo_prefetch_post_tag_' . $args['seoIdentifier'] );
                            break;
                        default:
                            $result = get_option( 'wpseo_prefetch_' . $args['seoIdentifier'] );
                    }
                    return $result;
                }
            ],
            //count result for pagination
            'count' => [
                'type' => Type::int(),
                'resolve' => function() {
                    return static::$count;
                }
            ],
        ];

        return $schema;
    }

    static function extendArgs($args) {
        return $args + static::getWPQueryParams();
    }

    static function getWPQueryParams() {
        static $params;
        return $params ?: $params = [
            'posts_per_page' => [
                'description' => 'number of post to show per page',
                'type' => Type::int(),
            ],
            'author' => [
                'description' => 'author of this set of posts',
                'type' => Type::int()
            ],
            'paged' => [
                'description' => 'number of page.',
                'type' => Type::int(),
            ],
            'post_type' => [
                'description' => "Retrieves posts by Post Types, default value is 'post'.",
                'type' => new ListOfType(Type::string())
            ],
            'post_status' => [
                'description' => "Default value is 'publish', but if the user is logged in, 'private' is added",
                'type' => new ListOfType(Type::string()) // choosing to keep this as a string instead of Enum to ensure custom post status aren't extra work here.
            ],
            'name' => [
                'description' => "Retrieves post by name",
                'type' => Type::string(),
            ],
            'order' => [
                'description' => "Designates the ascending or descending order of the 'orderby' parameter. Defaults to 'DESC'. An array can be used for multiple order/orderby sets.",
                'type' => Type::string()
            ],
            'orderby' => [
                'description' => "Sort retrieved posts by parameter. Defaults to 'date (post_date)'. One or more options can be passed.",
                'type' => Type::string()
            ],
            's' => [
                'description' => "Show posts based on a keyword search.",
                'type' => Type::string()
            ],
            'cat' => [
                'description' => "Show in this category id",
                'type' => Type::int()
            ],
            'category_name' => [
                'description' => "Show in this category slug",
                'type' => Type::string()
            ],
            'tag' => [
                'description' => "Show in this tag slug",
                'type' => Type::string()
            ],
            'tag_id' => [
                'description' => "Show in this tag id",
                'type' => Type::int()
            ],
        ];
    }

    static function getTermParams() {
        return [
            'taxonomies' => [
                'description' => 'Array of Taxonomy names. Overides taxonomy argument',
                'type' => new ListOfType(Type::string()),
            ],
            'taxonomy' => [
                'description' => 'The taxonomy for which to retrieve terms. Defaults to category',
                'type' => Type::string(),
            ],
            'orderby' => [
                'description' => "Field(s) to order terms by. Accepts term fields ('name', 'slug', 'term_group', 'term_id', 'id', 'description'), 'count' for term taxonomy count, 'include' to match the 'order' of the include param, or 'none' to skip ORDER BY. Defaults to 'name'",
                'type' => Type::string()
            ],
            'order' => [
                'description' => "Whether to order terms in ascending or descending order. Accepts 'ASC' (ascending) or 'DESC' (descending). Default 'ASC",
                'type' => Type::string()
            ],
            'hide_empty' => [
                'description' => "Whether to order terms in ascending or descending order. Accepts 'ASC' (ascending) or 'DESC' (descending). Default 'ASC'",
                'type' => Type::string()
            ],
            'include' => [
                'description' => "Array of term ids to include. Default empty array",
                'type' => new ListOfType(Type::int()),
            ],
            'exclude' => [
                'description' => "Array of term ids to exclude. Default empty array",
                'type' => new ListOfType(Type::int())
            ],
            'exclude_tree' => [
                'description' => "Term ids to exclude along with all of their descendant terms. If include is non-empty, exclude_tree is ignored",
                'type' => new ListOfType(Type::int())
            ],
            'number' => [
                'description' => "Maximum number of terms to return. Default 0 (all)",
                'type' => Type::int()
            ],
            'offset' => [
                'description' => "The number by which to offset the terms query.",
                'type' => Type::int()
            ],
            'name' => [
                'description' => "Array of names to return terms for",
                'type' => new ListOfType(Type::string())
            ],
            'slug' => [
                'description' => "Array of slugs to return terms for",
                'type' => new ListOfType(Type::string())
            ],
            'hierarchical' => [
                'description' => "Whether to include terms that have non-empty descendants (even if hide_empty is set to true). Default true",
                'type' => new ListOfType(Type::boolean())
            ],
            'search' => [
                'description' => "Search criteria to match terms. Will be SQL-formatted with wildcards before and after.",
                'type' => Type::string()
            ],
            'name__like' => [
                'description' => "Retrieve terms with criteria by which a term is LIKE name__like",
                'type' => Type::string()
            ],
            'description__like' => [
                'description' => "Retrieve terms where the description is LIKE description__like",
                'type' => Type::string()
            ],
            'pad_counts' => [
                'description' => "Whether to pad the quantity of a term's children in the quantity of each term's \"count\" object variable. Default false",
                'type' => Type::boolean()
            ],
            'get' => [
                'description' => "Whether to return terms regardless of ancestry or whether the terms are empty. Accepts 'all' or empty (disabled).",
                'type' => Type::boolean()
            ],
            'child_of' => [
                'description' => "Term ID to retrieve child terms of. If multiple taxonomies are passed, child_of is ignored. Default 0",
                'type' => Type::int()
            ],
            'parent' => [
                'description' => "Parent term ID to retrieve direct-child terms of.",
                'type' => Type::int()
            ],
            'childless' => [
                'description' => "True to limit results to terms that have no children. This parameter has no effect on non-hierarchical taxonomies. Default false.",
                'type' => Type::boolean()
            ],
        ];
    }
}
