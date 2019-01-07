<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Wishlist
{
    use Singleton;

    const PATH = 'wishlists';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH, array(
            'methods' => 'GET',
            'callback' => array($this, 'getProducts'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/add', array(
            'methods' => 'POST',
            'callback' => array($this, 'addProduct'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/remove', array(
            'methods' => 'POST',
            'callback' => array($this, 'removeProduct'),
        ));
    }

    public function getProducts(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            $wishlist = YITH_WCWL()->get_products(array('wishlist_id' => 'all'));
            $result = array();
            foreach ($wishlist as $item) {
                $result[] = Products::getInstance()->prepare_object_for_response(wc_get_product($item['ID']), [])->get_data();
            }
            return rest_ensure_response($result);
        } else {
            return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
        }
    }

    public function addProduct(WP_REST_Request $request)
    {
        if (is_user_logged_in() && is_numeric($request['add_to_wishlist'])) {
            $product = get_post($request['add_to_wishlist']);
            if ($product !== null) {
                YITH_WCWL()->details = $request->get_params();
                YITH_WCWL()->details['user_id'] = get_current_user_id();
                return rest_ensure_response(array('status' => YITH_WCWL()->add()));
            } else
                return rest_ensure_response(new WP_REST_Response("Faild, sorry... :(", 404));
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function removeProduct(WP_REST_Request $request)
    {
        if (is_user_logged_in() && is_numeric($request['remove_from_wishlist'])) {
            $product = get_post($request['remove_from_wishlist']);
            if ($product !== null) {
                YITH_WCWL()->details = $request->get_params();
                YITH_WCWL()->details['user_id'] = get_current_user_id();
                return rest_ensure_response(array('status' => YITH_WCWL()->remove()));
            } else
                return rest_ensure_response(new WP_REST_Response("Faild, sorry... :(", 404));
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }
}
