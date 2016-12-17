<?php

namespace Mohiohio\GraphQLWP\Type\Definition;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;
use GraphQLRelay\Relay;

class Product extends PostType {

    static function getDescription() {
        return 'The WooCommerce product class handles individual product data.';
    }

    static function getPostType() {
        return 'product';
    }

    static function toProduct($post) {

        static $products = [];

        if($post instanceof \WC_Product ) {
            //Already a product, leave as is
            return $post;
        }

        return isset($products[$post->ID]) ? $products[$post->ID] : $products[$post->ID] = wc_get_product($post);
    }

    static function getFieldSchema() {
        return  [
            'ID' => [
                'type' => Type::nonNull(Type::string()),
                'resolve' => function($post) {
                    $product = static::toProduct($post);
                    return (int) $product->is_type( 'variation' ) ? $product->get_variation_id() : $product->id;
                }
            ],
            'name' => [
                'type' => Type::string(),
                'resolve' => function($post) {
                    $product = static::toProduct($post);
                    return $product->get_title();
                }
            ],
            'price' => [
                'type' => Type::string(),
                'resolve' => function($post) {
                    $product = static::toProduct($post);
                    return $product->get_price();
                }
            ],
            'description' => [
              'type' => Type::string(),
              'resolve' => function($post) {
                  $product = static::toProduct($post);
                  return wpautop( do_shortcode( $product->get_post_data()->post_content ) );
              }
            ],
            'terms' => [
                'type' => new ListOfType(WPTerm::getInstance()),
                'description' => 'Terms ( Categories, Tags etc ) or this product',
                'args' => [
                    'taxonomy' => [
                        'description' => 'The taxonomy for which to retrieve terms. Defaults to cat.',
                        'type' => Type::string(),
                    ],
                    'orderby' => [
                        'description' => "Defaults to name",
                        'type' => Type::string(),
                    ],
                    'order' => [
                        'description' => "Defaults to ASC",
                        'type' => Type::string(),
                    ]
                ],
                'resolve' => function($post, $args) {

                    $args += ['taxonomy' => 'cat'];
                    extract($args);

                    $res = wp_get_post_terms($post->ID, 'product_'.$taxonomy);

                    return is_wp_error($res) ? [] : $res;
                }
            ],
            'attributes' => [
                'type' => new ListOfType(ProductAttribute::getInstance()),
                'resolve' => function($post) {

                    $product = static::toProduct($post);
                    $attributes = [];

                    if ( $product->is_type( 'variation' ) ) {
                        // Variation attributes.
                        foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {
                            $name = str_replace( 'attribute_', '', $attribute_name );

                            // Taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`.
                            if ( 0 === strpos( $attribute_name, 'attribute_pa_' ) ) {
                                $attributes[] = [
                                    'id'     => wc_attribute_taxonomy_id_by_name( $name ),
                                    'name'   => static::get_attribute_taxonomy_label( $name ),
                                    'option' => $attribute,
                                ];
                            } else {
                                $attributes[] = [
                                    'id'     => 0,
                                    'name'   => str_replace( 'pa_', '', $name ),
                                    'option' => $attribute,
                                ];
                            }
                        }
                    }
                    /* else {
                        foreach ( $product->get_attributes() as $attribute ) {
                            if ( $attribute['is_taxonomy'] ) {
                                $attributes[] = array(
                                    'id'        => wc_attribute_taxonomy_id_by_name( $attribute['name'] ),
                                    'name'      => static::get_attribute_taxonomy_label( $attribute['name'] ),
                                    'position'  => (int) $attribute['position'],
                                    'visible'   => (bool) $attribute['is_visible'],
                                    'variation' => (bool) $attribute['is_variation'],
                                    'options'   => static::get_attribute_options( $product->id, $attribute ),
                                );
                            } else {
                                $attributes[] = array(
                                    'id'        => 0,
                                    'name'      => str_replace( 'pa_', '', $attribute['name'] ),
                                    'position'  => (int) $attribute['position'],
                                    'visible'   => (bool) $attribute['is_visible'],
                                    'variation' => (bool) $attribute['is_variation'],
                                    'options'   => static::get_attribute_options( $product->id, $attribute ),
                                );
                            }
                        }
                    }*/

                    return $attributes;
                }
            ],
            'variations' => [
                'type' => function() {
                    //return new ListOfType(Product::getInstance());
                    return new ListOfType(Product::getInstance());
                },
                'resolve' => function($post) {

                    $product = static::toProduct($post);

                    if ( $product->is_type( 'variable' ) && $product->has_child() ) {
                        return array_filter(array_map(function($child_id) use ($product) {
                            $variation = $product->get_child( $child_id );
                            return $variation->exists() ? $variation : null;
                        },$product->get_children()));
                    }
                    return [];
                },
            ],
            'images' => [
                'type' => function(){
                    return new ListOfType(Attachment::getInstance());
                },
                'resolve' => function($post) {

                    $product = static::toProduct($post);
                    $attachment_ids = [];

                    if ( $product->is_type( 'variation' ) ) {
                        if ( has_post_thumbnail( $product->get_variation_id() ) ) {
                            // Add variation image if set.
                            $attachment_ids[] = get_post_thumbnail_id( $product->get_variation_id() );
                        } elseif ( has_post_thumbnail( $product->id ) ) {
                            // Otherwise use the parent product featured image if set.
                            $attachment_ids[] = get_post_thumbnail_id( $product->id );
                        }
                    } else {
                        // Add featured image.
                        if ( has_post_thumbnail( $product->id ) ) {
                            $attachment_ids[] = get_post_thumbnail_id( $product->id );
                        }
                        // Add gallery images.
                        $attachment_ids = array_merge( $attachment_ids, $product->get_gallery_attachment_ids() );
                    }

                    return get_posts(['post__in'=>$attachment_ids,'post_type'=>'attachment']);
                }
            ],
            'type' => [
                'type'=> Type::string(),
                'resolve'=> function($post) {
                    $product = static::toProduct($post);
                    return $product->get_type();
                }
            ]
		] + parent::getFieldSchema();
    }

    protected static function get_attribute_taxonomy_label( $name ) {
		$tax    = get_taxonomy( $name );
		$labels = get_taxonomy_labels( $tax );

		return $labels->singular_name;
	}

    protected static function get_attribute_options( $product_id, $attribute ) {
		if ( isset( $attribute['is_taxonomy'] ) && $attribute['is_taxonomy'] ) {
			return wc_get_product_terms( $product_id, $attribute['name'], ['fields' => 'names'] );
		} elseif ( isset( $attribute['value'] ) ) {
			return array_map( 'trim', explode( '|', $attribute['value'] ) );
		}

		return [];
	}
}
