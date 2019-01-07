<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Cart
{
    use Singleton;

    const PATH = 'cart';

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
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/clear', array(
            'methods' => 'POST',
            'callback' => array($this, 'cartClear'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/checkout', array(
            'methods' => 'POST',
            'callback' => array($this, 'cartCheckout'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/pay', array(
            'methods' => 'POST',
            'callback' => array($this, 'cartPay'),
        ));
    }

    public function getProducts(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            $cart_items = array();
            $cart = WC()->cart->get_cart();
            foreach ($cart as $item) {
                $product = Products::getInstance()->prepare_object_for_response(wc_get_product($item['product_id']), [])->get_data();
                $product['cart_hash'] = $item['key'];
                $product['booking'] = $item['_ebs_start'];
                $cart_items[] = $product;
            }
            return rest_ensure_response($cart_items);
        } else {
            return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
        }
    }

    public function addProduct(WP_REST_Request $request)
    {
        if (is_user_logged_in() && is_numeric($request['add_to_cart'])) {
            $product = get_post($request['add_to_cart']);
            if ($product !== null)
                try {
                    return rest_ensure_response(Easy_booking::instance()->easy_booking_is_bookable($product->ID));
//                    return rest_ensure_response(WC()->cart->add_to_cart($product->ID));
                } catch (Exception $e) {
                    return rest_ensure_response(new WP_REST_Response($e, 403));
                }
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function removeProduct(WP_REST_Request $request)
    {
        try {
            return rest_ensure_response(WC()->cart->remove_cart_item($request['remove_from_cart']));
        } catch (Exception $e) {
            return rest_ensure_response(new WP_REST_Response($e, 403));
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function cartCheckout(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            try {
                $data = array();
                $billing = $request->get_param('billing');
                foreach ($billing as $key => $value) {
                    $data['billing_' . $key] = $value;
                }
                $shipping = $request->get_param('shipping');
                foreach ($shipping as $key => $value) {
                    $data['shipping_' . $key] = $value;
                }

                $order_id = WC_Checkout::instance()->create_order($data);
                if (!$order_id instanceof WP_Error) {
                    $order = wc_get_order($order_id);
                    $order->set_created_via('mobile_integration');
                    $order->save();
                    
                    $data = $order->get_data();
                    $itemsObj = $order->get_items();

                    $items = array();
                    foreach ($itemsObj as $item) {
                        $items[] = $item->get_data();
                    }
                    $data['line_items'] = $items;
                    
                    return rest_ensure_response(array('order_id' => $order_id, 'order' => $data));
                }
            } catch (Exception $e) {
            }
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function cartPay(WP_REST_Request $request)
    {
        try {
            if (is_user_logged_in()) {
                $parameters = $request->get_params();
                $payment_method = sanitize_text_field($parameters['payment_method']);
                $order_id = sanitize_text_field($parameters['order_id']);
                $payment_token = sanitize_text_field($parameters['payment_token']);
                $error = new WP_Error();

                if (empty($payment_method)) {
                    $error->add(400, __("Payment Method 'payment_method' is required.", 'wc-rest-payment'), array('status' => 400));
                    return $error;
                }
                if (empty($order_id)) {
                    $error->add(401, __("Order ID 'order_id' is required.", 'wc-rest-payment'), array('status' => 400));
                    return $error;
                } else if (wc_get_order($order_id) == false) {
                    $error->add(402, __("Order ID 'order_id' is invalid. Order does not exist.", 'wc-rest-payment'), array('status' => 400));
                    return $error;
                } else if (wc_get_order($order_id)->get_status() !== 'pending') {
                    $error->add(403, __("Order status is NOT 'pending', meaning order had already received payment. Multiple payment to the same order is not allowed. ", 'wc-rest-payment'), array('status' => 400));
                    return $error;
                }
                if (empty($payment_token)) {
                    $error->add(404, __("Payment Token 'payment_token' is required.", 'wc-rest-payment'), array('status' => 400));
                    return $error;
                }

                if ($payment_method === "stripe") {
                    $wc_gateway_stripe = new WC_Gateway_Stripe();
                    $_POST['stripe_token'] = $payment_token;
                    $payment_result = $wc_gateway_stripe->process_payment($order_id);
                    if ($payment_result['result'] === "success") {
                        $response['code'] = 200;
                        $response['message'] = __("Your Payment was Successful", "wc-rest-payment");
                        $order = wc_get_order($order_id);
                        if ($order->get_status() == 'processing') {
                            $order->set_payment_method_title($order->get_payment_method_title() . ' - Mobile');
                            $order->set_created_via('Mobile Integration');
                            $order->update_status('completed');
                            WC()->cart->empty_cart();
                        }
                    } else {
                        $response['code'] = 401;
                        $response['message'] = __("Please enter valid card details", "wc-rest-payment");
                    }
                } else {
                    $response['code'] = 405;
                    $response['message'] = __("Please select an available payment method. Supported payment method can be found at https://wordpress.org/plugins/wc-rest-payment/#description", "wc-rest-payment");
                }
                return new WP_REST_Response($response, $response['code']);
            }
        } catch (Exception $e) {
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 401));
    }

    public function cartClear(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            WC()->cart->empty_cart();
            return rest_ensure_response(true);
        } else {
            WC()->cart->empty_cart(false);
            return rest_ensure_response(true);
        }
    }
}
