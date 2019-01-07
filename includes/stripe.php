<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Stripe
{
    use Singleton;

    const PATH = 'stripe';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'mobile_wp_get_stripe_config'),
        ));
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return false|int|WP_Error|WP_REST_Response
     * @throws WC_Data_Exception
     * @throws Exception
     */
    public function mobile_wp_get_stripe_config(WP_REST_Request $request)
    {
        /** @var WC_Order $order */
//        $order = wc_create_order();
//        $order->set_address()

//        $gategay = new WC_Gateway_Stripe();
//        $order->set_payment_method($gategay);


//        wc_get_is_paid_statuses()
        return wc_get_order_statuses();

        $token = new WC_Payment_Token_CC();
        $token->set_card_type('brand');

        $customer = new WC_Customer(get_current_user_id());

        $request->get_param('order_id');

        $order_statuses = array('wc-pending');

        $customer_orders = wc_get_orders( array(
            'meta_key' => '_customer_user',
            'meta_value' => $customer->get_id(),
            'post_status' => $order_statuses,
            'numberposts' => -1
        ));

//        $order = wc_create_order(array(
//            'status'        => 'processing',
//            'customer_id'   => $customer->get_id(),
//            'customer_note' => '',
//            'created_via'   => 'mobile_integration',
////            'cart_hash'     => ,
////            'order_id'      => 0,
//        ));
//        $ords->create_item()

//        $order

//        WC()->cart->checkout()
//        $token->
//        $order->get_order_item_totals()
//        $order->save();
//        return rest_ensure_response([WC_Stripe_Helper::get_settings(), $order->get_id()]);
        return rest_ensure_response($customer_orders[0]->get_data());
//        return rest_ensure_response(WC_Stripe_Helper::get_settings(null, 'test_publishable_key'));
//        return rest_ensure_response(WC_Stripe_Helper::get_settings(null, 'live_publishable_key'));
    }
}
