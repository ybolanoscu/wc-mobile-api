<?php
/**
 * @package Mobile Integration
 * @version 0.1.5
 */
/*
Plugin Name: Mobile Integration
Description: This is not just a plugin, is more than that ;).
Author:
Version: 0.1.5
*/

define('WPMI_NAMESPACE', 'wpmi/v1');

include_once 'helpers/singleton.php';

include_once 'includes/users.php';
include_once 'includes/categories.php';
include_once 'includes/products.php';
include_once 'includes/terms.php';
include_once 'includes/wishlist.php';
include_once 'includes/cart.php';
include_once 'includes/utils.php';
include_once 'includes/stripe.php';
include_once 'includes/orders.php';

add_action('rest_api_init', 'init_mobile_rest_api_calls');
function init_mobile_rest_api_calls()
{
	Users::getInstance()->init();
	Products::getInstance()->init();
	Categories::getInstance()->init();
	Terms::getInstance()->init();
    Wishlist::getInstance()->init();
    Cart::getInstance()->init();
    Utils::getInstance()->init();
    Stripe::getInstance()->init();
    Orders::getInstance()->init();
}
