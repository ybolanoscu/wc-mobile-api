<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Users
{
    use Singleton;

    const PATH = 'users';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, 'users/me', array(
            'methods' => 'GET',
            'callback' => array($this, 'mobile_wp_get_user'),
        ));
        register_rest_route(WPMI_NAMESPACE, 'users/register', array(
            'methods' => 'POST',
            'callback' => array($this, 'mobile_wp_register_user'),
        ));
        register_rest_route(WPMI_NAMESPACE, 'users/password', array(
            'methods' => 'POST',
            'callback' => array($this, 'mobile_wp_password_user'),
        ));
        register_rest_route(WPMI_NAMESPACE, 'users/reset', array(
            'methods' => 'POST',
            'callback' => array($this, 'mobile_wp_reset_user'),
        ));

        register_rest_route(WPMI_NAMESPACE, 'users/default/billing', array(
            'methods' => 'POST',
            'callback' => array($this, 'mobile_wp_default_billing_user'),
        ));
        register_rest_route(WPMI_NAMESPACE, 'users/default/shipping', array(
            'methods' => 'POST',
            'callback' => array($this, 'mobile_wp_default_shipping_user'),
        ));
    }

    public function mobile_wp_get_user(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            $rest = new WC_REST_Customers_Controller();
            $request->set_param('id', get_current_user_id());
            /** @var WP_REST_Response $response */
            $response = $rest->get_item($request);
            if (!($response instanceof WP_Error)) {
                $response->data['full_shipping'] = str_replace('<br/>', ', ', WC()->countries->get_formatted_address($response->data['shipping']));
                $response->data['full_billing'] = str_replace('<br/>', ', ', WC()->countries->get_formatted_address($response->data['billing']));
                return $response;
            }
        }

        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    /**
     * @param null $request
     *
     * @return false|int|WP_Error|WP_REST_Response
     */
    public function mobile_wp_register_user($request = null)
    {
        $response = array('success' => false);
        $parameters = $request->get_json_params();

        $username = sanitize_text_field($parameters['username']);
        $email = sanitize_text_field($parameters['email']);

        $password = sanitize_text_field($parameters['password']);
        $repassword = sanitize_text_field($parameters['repassword']);

        $first_name = sanitize_text_field($parameters['first_name']);
        $last_name = sanitize_text_field($parameters['last_name']);

        $error = new WP_Error();
        if (empty($username)) {
            $error->add(400, __("Username field 'username' is required.", 'mobile-integration'), array('status' => 400));
            return $error;
        }
        if (empty($email)) {
            $error->add(401, __("Email field 'email' is required.", 'mobile-integration'), array('status' => 400));
            return $error;
        }
        if (empty($password)) {
            $error->add(404, __("Password field 'password' is required.", 'mobile-integration'), array('status' => 400));
            return $error;
        }

        $user_id = username_exists($username);
        if (!$user_id && email_exists($email) == false) {
            $login = explode('@', $email);
            $userdata = array(
                'user_pass' => $password,
                'user_login' => $username,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'user_email' => $email,
            );

            $user_id = wp_insert_user($userdata);

            if (!is_wp_error($user_id)) {
                $user = get_user_by('id', $user_id);
                $user->set_role('subscriber');
                if (class_exists('WooCommerce')) {
                    $user->set_role('customer');
                }
                $response['success'] = true;
                $response['code'] = 200;
                $response['message'] = __("User '" . $username . "' Registration was Successful", "mobile-integration");
            } else {
                return $user_id;
            }
        } else {
            $error->add(406, __("Email already exists, please try 'Reset Password'", 'mobile-integration'), array('status' => 400));
            return $error;
        }

        return rest_ensure_response($response);
    }

    /**
     * Handles sending password retrieval email to user.
     *
     * @uses $wpdb WordPress Database object
     *
     * @param null $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function mobile_wp_reset_user($request = null)
    {
        $response = array('success' => false);
        $parameters = $request->get_json_params();

        $user_login = sanitize_text_field($parameters['email']);

        global $wpdb, $current_site;
        $error = new WP_Error();
        if (empty($user_login)) {
            $error->add(401, __("Email field 'email' is required.", 'mobile-integration'), array('status' => 400));
            return $error;
        } else if (strpos($user_login, '@')) {
            $user_data = get_user_by('email', trim($user_login));
            if (empty($user_data)) {
                $error->add(406, __("Email don't exists, please try 'Register'", 'mobile-integration'), array('status' => 400));
                return $error;
            }
        } else {
            $login = trim($user_login);
            $user_data = get_user_by('login', $login);
        }

        do_action('lostpassword_post');

        if (!$user_data) {
            $error->add(406, __("Email don't exists, please try 'Register'", 'mobile-integration'), array('status' => 400));
            return $error;
        };

        $user_login = $user_data->user_login;
        $user_email = $user_data->user_email;
        do_action('retrieve_password', $user_login);

        $allow = apply_filters('allow_password_reset', true, $user_data->ID);
        if (!$allow) {
            $error->add(406, __("You can't reset the password", 'mobile-integration'), array('status' => 403));
            return $error;
        } else if (is_wp_error($allow)) {
            return $allow;
        }

        $key = $wpdb->get_var($wpdb->prepare("SELECT user_activation_key FROM $wpdb->users WHERE user_login = %s", $user_login));
        if (empty($key)) {
            // Generate something random for a key...
            $key = bin2hex(random_bytes(20));
            do_action('retrieve_password_key', $user_login, $key);
            // Now insert the new md5 key into the db
            $wpdb->update($wpdb->users, array('user_activation_key' => $key), array('user_login' => $user_login));
        }
        $message = __('Someone requested that the password be reset for the following account:') . "\r\n\r\n";
        $message .= network_home_url('/') . "\r\n\r\n";
        $message .= sprintf(__('Username: %s'), $user_login) . "\r\n\r\n";
        $message .= __('If this was a mistake, just ignore this email and nothing will happen.') . "\r\n\r\n";
        $message .= __('To reset your password, visit the following address:') . "\r\n\r\n";
        $message .= '<' . network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login') . ">\r\n";

        if (is_multisite()) {
            $blogname = $GLOBALS['current_site']->site_name;
        } else
            // The blogname option is escaped with esc_html on the way into the database in sanitize_option
            // we want to reverse this for the plain text arena of emails.
        {
            $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        }

        $title = sprintf(__('[%s] Password Reset'), $blogname);

        $title = apply_filters('retrieve_password_title', $title);
        $message = apply_filters('retrieve_password_message', $message, $key);

        if ($message && !wp_mail($user_email, $title, $message)) {
            wp_die(__('The e-mail could not be sent.') . "<br />\n" . __('Possible reason: your host may have disabled the mail() function...'));
        }

        $response['success'] = true;
        $response['code'] = 200;
        $response['message'] = __("Reset Password was Successful. Please check you email.", "mobile-integration");

        return rest_ensure_response($response);
    }

    public function mobile_wp_default_billing_user(WP_REST_Request $request) {
        try {
            if (is_user_logged_in()) {
                $params = $request->get_params();
                $customer = new WC_Customer(get_current_user_id());
                $customer->set_billing_country('US');
                foreach ($params as $key => $value) {
                    $method = 'set_billing_' . $key;
                    if (method_exists($customer, $method)) {
                        $customer->$method($value);
                    }
                }
                $customer->save();
                $response = array('full_billing' => str_replace('<br/>', ', ', WC()->countries->get_formatted_address($params)));
                return rest_ensure_response($response);
            }
        } catch (Exception $e) {
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function mobile_wp_default_shipping_user(WP_REST_Request $request) {
        try {
            if (is_user_logged_in()) {
                $params = $request->get_params();
                $customer = new WC_Customer(get_current_user_id());
                $customer->set_shipping_country('US');
                foreach ($params as $key => $value) {
                    $method = 'set_shipping_' . $key;
                    if (method_exists($customer, $method)) {
                        $customer->$method($value);
                    }
                }
                $customer->save();
                $response = array('full_shipping' => str_replace('<br/>', ', ', WC()->countries->get_formatted_address($params)));
                return rest_ensure_response($response);
            }
        } catch (Exception $e) {
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }

    public function mobile_wp_password_user(WP_REST_Request $request)
    {
        try {
            if (is_user_logged_in()) {
                wp_set_password($request->get_param('password'), get_current_user_id());
                return rest_ensure_response(true);
            }
        } catch (Exception $e) {
        }
        return rest_ensure_response(new WP_REST_Response("Faild, sorry", 403));
    }
}


