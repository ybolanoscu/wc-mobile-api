<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Categories
{
    use Singleton;

    const PATH = 'categories';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH, array(
            'methods' => 'GET',
            'callback' => array($this, 'getAll'),
        ));
    }

    public function getAll(WP_REST_Request $request)
    {
        $data = get_terms(array(
            'taxonomy' => "product_cat",
            'number' => 1000
        ));

        if ( is_wp_error( $data ) ) {
            return $data;
        }
        
        $response = array();
        foreach ($data as $item) {
            $var = array(
                'term_id'     => (int) $item->term_id,
                'name'        => $item->name,
                'slug'        => $item->slug,
                'parent'      => (int) $item->parent,
                'description' => $item->description,
                'image'       => null,
                'count'       => (int) $item->count,
            );
            // Get category image.
            $image_id = get_woocommerce_term_meta( $item->term_id, 'thumbnail_id' );
            if ( $image_id ) {
                $attachment = get_post( $image_id );
                $var['image'] = array(
                    'id'                => (int) $image_id,
                    'date_created'      => wc_rest_prepare_date_response( $attachment->post_date ),
                    'date_created_gmt'  => wc_rest_prepare_date_response( $attachment->post_date_gmt ),
                    'date_modified'     => wc_rest_prepare_date_response( $attachment->post_modified ),
                    'date_modified_gmt' => wc_rest_prepare_date_response( $attachment->post_modified_gmt ),
                    'src'               => wp_get_attachment_url( $image_id ),
                    'title'             => get_the_title( $attachment ),
                    'alt'               => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
                );
            }
            $response[] = $var;
        }

        return rest_ensure_response(array_merge(array(), $response));
    }
}
