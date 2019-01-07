<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Orders
{
    use Singleton;

    const PATH = 'orders';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH, array(
            'methods' => 'GET',
            'callback' => array($this, 'getAll'),
        ));

        register_rest_route(WPMI_NAMESPACE, self::PATH. '/(?P<order_id>[\d]+)/item/remove', array(
            'methods' => 'POST',
            'callback' => array($this, 'removeItem'),
        ));
        
        register_rest_route(WPMI_NAMESPACE, self::PATH. '/(?P<order_id>[\d]+)/cancel', array(
            'methods' => 'POST',
            'callback' => array($this, 'orderCancel'),
        ));
    }

    public function getAll(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            try {
                $customer_orders = wc_get_orders( apply_filters( 'woocommerce_my_account_my_orders_query', array(
                    'customer' => get_current_user_id(),
                    'numberposts' => -1,
                    'paginate' => false,
                )));

                $orders = array();
                foreach ($customer_orders as $customer_order) {
                    $data = $customer_order->get_data();
                    $itemsObj = $customer_order->get_items();

                    $items = array();
                    foreach ($itemsObj as $item) {
                        $items[] = $item->get_data();
                    }
                    $data['line_items'] = $items;
                    $actions = array('view' => true);
                    $actions['pay'] = $customer_order->needs_payment();
                    $actions['cancel'] = in_array($customer_order->get_status(), apply_filters('woocommerce_valid_order_statuses_for_cancel', array('pending', 'failed'), $customer_order));
                    
                    $data['actions'] = $actions;
                    $data['count_items'] = count($customer_order->get_items());
                    $data['date'] = wc_format_datetime($customer_order->get_date_created(), 'M d, Y');
                    $orders[] = $data;
                }

                return rest_ensure_response($orders);
            } catch (Exception $e) {
            }
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function removeItem(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            $order_id = $request->get_param('order_id');
            $order = wc_get_order($order_id);
            if ($order && $order->is_editable()) {
                $valid = $order->remove_item($request->get_param('item_id'));
                if (!$valid) {
                    $order->calculate_totals();
                    return rest_ensure_response(array('order' => $order->get_data()));
                }
            }
        }

        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }
    
    public function orderCancel(WP_REST_Request $request) 
    {
        if (is_user_logged_in()) {
            $order_id = $request->get_param('order_id');
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id() === get_current_user_id()) {
                $cancel = in_array($order->get_status(), apply_filters('woocommerce_valid_order_statuses_for_cancel', array('pending', 'failed'), $order));
                if ($cancel) {
                    $valid = $order->update_status('cancelled', '', true);
                    if ($valid) {
                        $data = $order->get_data();
                        $itemsObj = $order->get_items();

                        $items = array();
                        foreach ($itemsObj as $item) {
                            $items[] = $item->get_data();
                        }
                        $data['line_items'] = $items;
                        $actions = array('view' => true);
                        $actions['pay'] = $order->needs_payment();
                        $actions['cancel'] = in_array($order->get_status(), apply_filters('woocommerce_valid_order_statuses_for_cancel', array('pending', 'failed'), $order));
                        
                        $data['actions'] = $actions;
                        $data['count_items'] = count($order->get_items());
                        $data['date'] = wc_format_datetime($order->get_date_created(), 'M d, Y');
                        return rest_ensure_response($data);
                    }
                }
            }
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }    
}
