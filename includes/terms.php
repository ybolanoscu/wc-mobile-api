<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Terms
{
    use Singleton;

    const PATH = 'attributes';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH, array(
            'methods' => 'GET',
            'callback' => array($this, 'getAll'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/(?P<attribute_id>[\d]+)/terms', array(
            'methods' => 'GET',
            'callback' => array($this, 'getTermsByAttribute'),
        ));
    }

    public function getAll(WP_REST_Request $request)
    {
        $attributes = wc_get_attribute_taxonomies();
        $data = array();
        foreach ($attributes as $item) {
            $data[] = array(
                'id' => (int)$item->attribute_id,
                'name' => $item->attribute_label,
                'slug' => wc_attribute_taxonomy_name($item->attribute_name),
                'type' => $item->attribute_type,
                'order_by' => $item->attribute_orderby,
                'has_archives' => (bool)$item->attribute_public,
            );
        }

        return rest_ensure_response($data);
    }

    public function getTermsByAttribute(WP_REST_Request $request)
    {
        $taxonomy = null;
        if (!empty($request['attribute_id'])) {
            $taxonomy = wc_attribute_taxonomy_name_by_id((int)$request['attribute_id']);
        }

        $prepared_args = array(
            'exclude'    => $request['exclude'],
            'include'    => $request['include'],
            'order'      => $request['order'],
            'orderby'    => $request['orderby'],
            'product'    => $request['product'],
            'hide_empty' => $request['hide_empty'],
            'number'     => $request['per_page'],
            'search'     => $request['search'],
            'slug'       => $request['slug'],
        );

        if ( ! empty( $request['offset'] ) ) {
            $prepared_args['offset'] = $request['offset'];
        } else {
            $prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['number'];
        }

        $taxonomy_obj = get_taxonomy( $taxonomy );
        if ( $taxonomy_obj->hierarchical && isset( $request['parent'] ) ) {
            if ( 0 === $request['parent'] ) {
                $prepared_args['parent'] = 0;
            } else {
                if ( $request['parent'] ) {
                    $prepared_args['parent'] = $request['parent'];
                }
            }
        }

        $query_result = get_terms( $taxonomy, $prepared_args );

        $count_args = $prepared_args;
        unset( $count_args['number'] );
        unset( $count_args['offset'] );
        $total_terms = wp_count_terms( $taxonomy, $count_args );

        if ( $prepared_args['offset'] >= $total_terms ) {
            $query_result = array();
        }

        return rest_ensure_response($query_result);
    }
}