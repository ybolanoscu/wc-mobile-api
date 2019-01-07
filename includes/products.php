<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Products
{
    use Singleton;

    private $post_type = 'product';

    const PATH = 'products';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH, array(
            'methods' => 'GET',
            'callback' => array($this, 'getAll'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/(?P<product_id>[\d]+)/bookings', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_product_booking'),
        ));
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/(?P<product_id>[\d]+)/book', array(
            'methods' => 'POST',
            'callback' => array($this, 'set_product_book'),
        ));
    }

    /**
     * Get object.
     *
     * @since  3.0.0
     * @param  int $id Object ID.
     * @return WC_Data
     */
    protected function get_object($id)
    {
        return wc_get_product($id);
    }

    /**
     * Prepare a single product output for response.
     *
     * @since  3.0.0
     * @param  WC_Data $object Object data.
     * @param  WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function prepare_object_for_response($object, $request)
    {
        $context = !empty($request['context']) ? $request['context'] : 'view';
        $data = $this->get_product_data($object, $context);

        if ($object->is_type('variable') && $object->has_child()) {
            $data['variations'] = $object->get_children();
        }

        if ($object->is_type('grouped') && $object->has_child()) {
            $data['grouped_products'] = $object->get_children();
        }

        $response = rest_ensure_response($data);

        /**
         * Filter the data for a response.
         *
         * The dynamic portion of the hook name, $this->post_type,
         * refers to object type being prepared for the response.
         *
         * @param WP_REST_Response $response The response object.
         * @param WC_Data $object Object data.
         * @param WP_REST_Request $request Request object.
         */
        return apply_filters("woocommerce_rest_prepare_{$this->post_type}_object", $response, $object, $request);
    }

    /**
     * Prepare objects query.
     *
     * @since  3.0.0
     * @param  WP_REST_Request $request Full details about the request.
     * @return array
     */
    protected function base_prepare_objects_query($request)
    {
        $args = array();
        $args['offset'] = $request['offset'];
        $args['order'] = $request['order'];
        $args['orderby'] = $request['orderby'];
        $args['paged'] = $request['page'];
        $args['post__in'] = $request['include'];
        $args['post__not_in'] = $request['exclude'];
        $args['posts_per_page'] = $request['per_page'];
        $args['name'] = $request['slug'];
        $args['post_parent__in'] = $request['parent'];
        $args['post_parent__not_in'] = $request['parent_exclude'];
        $args['s'] = $request['search'];

        if ('date' === $args['orderby']) {
            $args['orderby'] = 'date ID';
        }

        $args['date_query'] = array();
        // Set before into date query. Date query must be specified as an array of an array.
        if (isset($request['before'])) {
            $args['date_query'][0]['before'] = $request['before'];
        }

        // Set after into date query. Date query must be specified as an array of an array.
        if (isset($request['after'])) {
            $args['date_query'][0]['after'] = $request['after'];
        }

        // Force the post_type argument, since it's not a user input variable.
        $args['post_type'] = $this->post_type;

        /**
         * Filter the query arguments for a request.
         *
         * Enables adding extra arguments or setting defaults for a post
         * collection request.
         *
         * @param array $args Key value array of query var to query value.
         * @param WP_REST_Request $request The request used.
         */
        $args = apply_filters("woocommerce_rest_{$this->post_type}_object_query", $args, $request);

        return $this->prepare_items_query($args, $request);
    }

    /**
     * Determine the allowed query_vars for a get_items() response and
     * prepare for WP_Query.
     *
     * @param array $prepared_args
     * @param WP_REST_Request $request
     * @return array          $query_args
     */
    protected function prepare_items_query($prepared_args = array(), $request = null)
    {
        $valid_vars = array_flip($this->get_allowed_query_vars());
        $query_args = array();
        foreach ($valid_vars as $var => $index) {
            if (isset($prepared_args[$var])) {
                /**
                 * Filter the query_vars used in `get_items` for the constructed query.
                 *
                 * The dynamic portion of the hook name, $var, refers to the query_var key.
                 *
                 * @param mixed $prepared_args [ $var ] The query_var value.
                 *
                 */
                $query_args[$var] = apply_filters("woocommerce_rest_query_var-{$var}", $prepared_args[$var]);
            }
        }

        $query_args['ignore_sticky_posts'] = true;

        if ('include' === $query_args['orderby']) {
            $query_args['orderby'] = 'post__in';
        } elseif ('id' === $query_args['orderby']) {
            $query_args['orderby'] = 'ID'; // ID must be capitalized
        }

        return $query_args;
    }

    /**
     * Get all the WP Query vars that are allowed for the API request.
     *
     * @return array
     */
    protected function get_allowed_query_vars()
    {
        global $wp;

        /**
         * Filter the publicly allowed query vars.
         *
         * Allows adjusting of the default query vars that are made public.
         *
         * @param array  Array of allowed WP_Query query vars.
         */
        $valid_vars = apply_filters('query_vars', $wp->public_query_vars);

        $post_type_obj = get_post_type_object($this->post_type);
        if (current_user_can($post_type_obj->cap->edit_posts)) {
            /**
             * Filter the allowed 'private' query vars for authorized users.
             *
             * If the user has the `edit_posts` capability, we also allow use of
             * private query parameters, which are only undesirable on the
             * frontend, but are safe for use in query strings.
             *
             * To disable anyway, use
             * `add_filter( 'woocommerce_rest_private_query_vars', '__return_empty_array' );`
             *
             * @param array $private_query_vars Array of allowed query vars for authorized users.
             * }
             */
            $private = apply_filters('woocommerce_rest_private_query_vars', $wp->private_query_vars);
            $valid_vars = array_merge($valid_vars, $private);
        }
        // Define our own in addition to WP's normal vars.
        $rest_valid = array(
            'date_query',
            'ignore_sticky_posts',
            'offset',
            'post__in',
            'post__not_in',
            'post_parent',
            'post_parent__in',
            'post_parent__not_in',
            'posts_per_page',
            'meta_query',
            'tax_query',
            'meta_key',
            'meta_value',
            'meta_compare',
            'meta_value_num',
        );
        $valid_vars = array_merge($valid_vars, $rest_valid);

        /**
         * Filter allowed query vars for the REST API.
         *
         * This filter allows you to add or remove query vars from the final allowed
         * list for all requests, including unauthenticated ones. To alter the
         * vars for editors only.
         *
         * @param array {
         *    Array of allowed WP_Query query vars.
         *
         * @param string $allowed_query_var The query var to allow.
         * }
         */
        $valid_vars = apply_filters('woocommerce_rest_query_vars', $valid_vars);

        return $valid_vars;
    }

    /**
     * Add meta query.
     *
     * @since 3.0.0
     * @param array $args Query args.
     * @param array $meta_query Meta query.
     * @return array
     */
    protected function add_meta_query($args, $meta_query)
    {
        if (!empty($args['meta_query'])) {
            $args['meta_query'] = array();
        }

        $args['meta_query'][] = $meta_query;

        return $args['meta_query'];
    }

    /**
     * Prepare objects query.
     *
     * @since  3.0.0
     * @param  WP_REST_Request $request Full details about the request.
     * @return array
     */
    protected function prepare_objects_query($request)
    {
        $args = $this->base_prepare_objects_query($request);

        // Set post_status.
        $args['post_status'] = $request['status'];

        // Taxonomy query to filter products by type, category,
        // tag, shipping class, and attribute.
        $tax_query = array();

        // Map between taxonomy name and arg's key.
        $taxonomies = array(
            'product_cat' => 'category',
            'product_tag' => 'tag',
            'product_shipping_class' => 'shipping_class',
        );

        // Set tax_query for each passed arg.
        foreach ($taxonomies as $taxonomy => $key) {
            if (!empty($request[$key])) {
                $tax_query[] = array(
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $request[$key],
                );
            }
        }

        // Filter product type by slug.
        if (!empty($request['type'])) {
            $tax_query[] = array(
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => $request['type'],
            );
        }

        // Filter by attribute and term.
        if (!empty($request['attribute']) && !empty($request['attribute_term'])) {
            if (in_array($request['attribute'], wc_get_attribute_taxonomy_names(), true)) {
                $tax_query[] = array(
                    'taxonomy' => $request['attribute'],
                    'field' => 'term_id',
                    'terms' => $request['attribute_term'],
                );
            }
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query; // WPCS: slow query ok.
        }

        // Filter featured.
        if (is_bool($request['featured'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'product_visibility',
                'field' => 'name',
                'terms' => 'featured',
            );
        }

        // Filter by sku.
        if (!empty($request['sku'])) {
            $skus = explode(',', $request['sku']);
            // Include the current string as a SKU too.
            if (1 < count($skus)) {
                $skus[] = $request['sku'];
            }

            $args['meta_query'] = $this->add_meta_query( // WPCS: slow query ok.
                $args, array(
                    'key' => '_sku',
                    'value' => $skus,
                    'compare' => 'IN',
                )
            );
        }

        // Filter by tax class.
        if (!empty($request['tax_class'])) {
            $args['meta_query'] = $this->add_meta_query( // WPCS: slow query ok.
                $args, array(
                    'key' => '_tax_class',
                    'value' => 'standard' !== $request['tax_class'] ? $request['tax_class'] : '',
                )
            );
        }

        // Price filter.
        if (!empty($request['min_price']) || !empty($request['max_price'])) {
            $args['meta_query'] = $this->add_meta_query($args, wc_get_min_max_price_meta_query($request));  // WPCS: slow query ok.
        }

        // Filter product in stock or out of stock.
        if (is_bool($request['in_stock'])) {
            $args['meta_query'] = $this->add_meta_query( // WPCS: slow query ok.
                $args, array(
                    'key' => '_stock_status',
                    'value' => true === $request['in_stock'] ? 'instock' : 'outofstock',
                )
            );
        }

        // Filter by on sale products.
        if (is_bool($request['on_sale'])) {
            $on_sale_key = $request['on_sale'] ? 'post__in' : 'post__not_in';
            $on_sale_ids = wc_get_product_ids_on_sale();

            // Use 0 when there's no on sale products to avoid return all products.
            $on_sale_ids = empty($on_sale_ids) ? array(0) : $on_sale_ids;

            $args[$on_sale_key] += $on_sale_ids;
        }

        // Force the post_type argument, since it's not a user input variable.
        if (!empty($request['sku'])) {
            $args['post_type'] = array('product', 'product_variation');
        } else {
            $args['post_type'] = $this->post_type;
        }

        return $args;
    }

    /**
     * Get the downloads for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_downloads($product)
    {
        $downloads = array();

        if ($product->is_downloadable()) {
            foreach ($product->get_downloads() as $file_id => $file) {
                $downloads[] = array(
                    'id' => $file_id, // MD5 hash.
                    'name' => $file['name'],
                    'file' => $file['file'],
                );
            }
        }

        return $downloads;
    }

    /**
     * Get taxonomy terms.
     *
     * @param WC_Product $product Product instance.
     * @param string $taxonomy Taxonomy slug.
     * @return array
     */
    protected function get_taxonomy_terms($product, $taxonomy = 'cat')
    {
        $terms = array();

        foreach (wc_get_object_terms($product->get_id(), 'product_' . $taxonomy) as $term) {
            $terms[] = array(
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            );
        }

        return $terms;
    }

    /**
     * Get the images for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_images($product)
    {
        $images = array();
        $attachment_ids = array();

        // Add featured image.
        if (has_post_thumbnail($product->get_id())) {
            $attachment_ids[] = $product->get_image_id();
        }

        // Add gallery images.
        $attachment_ids = array_merge($attachment_ids, $product->get_gallery_image_ids());

        // Build image data.
        foreach ($attachment_ids as $position => $attachment_id) {
            $attachment_post = get_post($attachment_id);
            if (is_null($attachment_post)) {
                continue;
            }

            $attachment = wp_get_attachment_image_src($attachment_id, 'full');
            if (!is_array($attachment)) {
                continue;
            }

            $images[] = array(
                'id' => (int)$attachment_id,
                'date_created' => wc_rest_prepare_date_response($attachment_post->post_date, false),
                'date_created_gmt' => wc_rest_prepare_date_response(strtotime($attachment_post->post_date_gmt)),
                'date_modified' => wc_rest_prepare_date_response($attachment_post->post_modified, false),
                'date_modified_gmt' => wc_rest_prepare_date_response(strtotime($attachment_post->post_modified_gmt)),
                'src' => current($attachment),
                'name' => get_the_title($attachment_id),
                'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                'position' => (int)$position,
            );
        }

        // Set a placeholder image if the product has no images set.
        if (empty($images)) {
            $images[] = array(
                'id' => 0,
                'date_created' => wc_rest_prepare_date_response(current_time('mysql'), false), // Default to now.
                'date_created_gmt' => wc_rest_prepare_date_response(current_time('timestamp', true)), // Default to now.
                'date_modified' => wc_rest_prepare_date_response(current_time('mysql'), false),
                'date_modified_gmt' => wc_rest_prepare_date_response(current_time('timestamp', true)),
                'src' => wc_placeholder_img_src(),
                'name' => __('Placeholder', 'woocommerce'),
                'alt' => __('Placeholder', 'woocommerce'),
                'position' => 0,
            );
        }

        return $images;
    }

    /**
     * Get the thumbnail for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_thumbnail($product)
    {
        $attachment_ids = array();

        // Add featured image.
        if (has_post_thumbnail($product->get_id())) {
//            $attachment_ids[] = $product->get_image_id();
        }

        // Add gallery images.
        $attachment_ids = array_merge($attachment_ids, $product->get_gallery_image_ids());

        // Build image data.
        foreach ($attachment_ids as $position => $attachment_id) {
            $attachment_post = get_post($attachment_id);
            if (is_null($attachment_post)) {
                continue;
            }

            $attachment = wp_get_attachment_image_src($attachment_id, 'woocommerce_thumbnail');
            if (!is_array($attachment)) {
                continue;
            }

            return array(
                'id' => (int)$attachment_id,
                'date_created' => wc_rest_prepare_date_response($attachment_post->post_date, false),
                'date_created_gmt' => wc_rest_prepare_date_response(strtotime($attachment_post->post_date_gmt)),
                'date_modified' => wc_rest_prepare_date_response($attachment_post->post_modified, false),
                'date_modified_gmt' => wc_rest_prepare_date_response(strtotime($attachment_post->post_modified_gmt)),
                'src' => current($attachment),
                'name' => get_the_title($attachment_id),
                'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                'position' => (int)$position,
            );
        }

        // Set a placeholder image if the product has no images set.
        return array(
            'id' => 0,
            'date_created' => wc_rest_prepare_date_response(current_time('mysql'), false), // Default to now.
            'date_created_gmt' => wc_rest_prepare_date_response(current_time('timestamp', true)), // Default to now.
            'date_modified' => wc_rest_prepare_date_response(current_time('mysql'), false),
            'date_modified_gmt' => wc_rest_prepare_date_response(current_time('timestamp', true)),
            'src' => wc_placeholder_img_src(),
            'name' => __('Placeholder', 'woocommerce'),
            'alt' => __('Placeholder', 'woocommerce'),
            'position' => 0,
        );
    }

    /**
     * Get attribute taxonomy label.
     *
     * @deprecated 3.0.0
     *
     * @param  string $name Taxonomy name.
     * @return string
     */
    protected function get_attribute_taxonomy_label($name)
    {
        $tax = get_taxonomy($name);
        $labels = get_taxonomy_labels($tax);

        return $labels->singular_name;
    }

    /**
     * Get product attribute taxonomy name.
     *
     * @since  3.0.0
     * @param  string $slug Taxonomy name.
     * @param  WC_Product $product Product data.
     * @return string
     */
    protected function get_attribute_taxonomy_name($slug, $product)
    {
        $attributes = $product->get_attributes();

        if (!isset($attributes[$slug])) {
            return str_replace('pa_', '', $slug);
        }

        $attribute = $attributes[$slug];

        // Taxonomy attribute name.
        if ($attribute->is_taxonomy()) {
            $taxonomy = $attribute->get_taxonomy_object();
            return $taxonomy->attribute_label;
        }

        // Custom product attribute name.
        return $attribute->get_name();
    }

    /**
     * Get default attributes.
     *
     * @param WC_Product $product Product instance.
     * @return array
     */
    protected function get_default_attributes($product)
    {
        $default = array();

        if ($product->is_type('variable')) {
            foreach (array_filter((array)$product->get_default_attributes(), 'strlen') as $key => $value) {
                if (0 === strpos($key, 'pa_')) {
                    $default[] = array(
                        'id' => wc_attribute_taxonomy_id_by_name($key),
                        'name' => $this->get_attribute_taxonomy_name($key, $product),
                        'option' => $value,
                    );
                } else {
                    $default[] = array(
                        'id' => 0,
                        'name' => $this->get_attribute_taxonomy_name($key, $product),
                        'option' => $value,
                    );
                }
            }
        }

        return $default;
    }

    /**
     * Get attribute options.
     *
     * @param int $product_id Product ID.
     * @param array $attribute Attribute data.
     * @return array
     */
    protected function get_attribute_options($product_id, $attribute)
    {
        if (isset($attribute['is_taxonomy']) && $attribute['is_taxonomy']) {
            return wc_get_product_terms(
                $product_id, $attribute['name'], array(
                    'fields' => 'names',
                )
            );
        } elseif (isset($attribute['value'])) {
            return array_map('trim', explode('|', $attribute['value']));
        }

        return array();
    }

    /**
     * Get the attributes for a product or product variation.
     *
     * @param WC_Product|WC_Product_Variation $product Product instance.
     * @return array
     */
    protected function get_attributes($product)
    {
        $attributes = array();

        if ($product->is_type('variation')) {
            $_product = wc_get_product($product->get_parent_id());
            foreach ($product->get_variation_attributes() as $attribute_name => $attribute) {
                $name = str_replace('attribute_', '', $attribute_name);

                if (!$attribute) {
                    continue;
                }

                // Taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`.
                if (0 === strpos($attribute_name, 'attribute_pa_')) {
                    $option_term = get_term_by('slug', $attribute, $name);
                    $attributes[] = array(
                        'id' => wc_attribute_taxonomy_id_by_name($name),
                        'name' => $this->get_attribute_taxonomy_name($name, $_product),
                        'option' => $option_term && !is_wp_error($option_term) ? $option_term->name : $attribute,
                    );
                } else {
                    $attributes[] = array(
                        'id' => 0,
                        'name' => $this->get_attribute_taxonomy_name($name, $_product),
                        'option' => $attribute,
                    );
                }
            }
        } else {
            foreach ($product->get_attributes() as $attribute) {
                $attributes[] = array(
                    'id' => $attribute['is_taxonomy'] ? wc_attribute_taxonomy_id_by_name($attribute['name']) : 0,
                    'name' => $this->get_attribute_taxonomy_name($attribute['name'], $product),
                    'position' => (int)$attribute['position'],
                    'visible' => (bool)$attribute['is_visible'],
                    'variation' => (bool)$attribute['is_variation'],
                    'options' => $this->get_attribute_options($product->get_id(), $attribute),
                );
            }
        }

        return $attributes;
    }

    /**
     * Get product data.
     *
     * @param WC_Product $product Product instance.
     * @param string $context Request context.
     *                            Options: 'view' and 'edit'.
     * @return array
     */
    protected function get_product_data($product, $context = 'view')
    {
        $data = array(
            'id' => $product->get_id(),
            'name' => $product->get_name($context),
            'slug' => $product->get_slug($context),
            'permalink' => $product->get_permalink(),
            'date_created' => wc_rest_prepare_date_response($product->get_date_created($context), false),
            'date_created_gmt' => wc_rest_prepare_date_response($product->get_date_created($context)),
            'date_modified' => wc_rest_prepare_date_response($product->get_date_modified($context), false),
            'date_modified_gmt' => wc_rest_prepare_date_response($product->get_date_modified($context)),
            'type' => $product->get_type(),
            'status' => $product->get_status($context),
            'featured' => $product->is_featured(),
            'catalog_visibility' => $product->get_catalog_visibility($context),
//            'description' => 'view' === $context ? wpautop(do_shortcode($product->get_description())) : $product->get_description($context),
//            'short_description' => 'view' === $context ? apply_filters('woocommerce_short_description', $product->get_short_description()) : $product->get_short_description($context),
            'sku' => $product->get_sku($context),
            'price' => $product->get_price($context),
            'regular_price' => $product->get_regular_price($context),
            'sale_price' => $product->get_sale_price($context) ? $product->get_sale_price($context) : '',
//            'date_on_sale_from' => wc_rest_prepare_date_response($product->get_date_on_sale_from($context), false),
//            'date_on_sale_from_gmt' => wc_rest_prepare_date_response($product->get_date_on_sale_from($context)),
//            'date_on_sale_to' => wc_rest_prepare_date_response($product->get_date_on_sale_to($context), false),
//            'date_on_sale_to_gmt' => wc_rest_prepare_date_response($product->get_date_on_sale_to($context)),
//            'price_html' => $product->get_price_html(),
            'on_sale' => $product->is_on_sale($context),
            'purchasable' => $product->is_purchasable(),
            'total_sales' => $product->get_total_sales($context),
//            'virtual' => $product->is_virtual(),
//            'downloadable' => $product->is_downloadable(),
//            'downloads' => $this->get_downloads($product),
//            'download_limit' => $product->get_download_limit($context),
//            'download_expiry' => $product->get_download_expiry($context),
//            'external_url' => $product->is_type('external') ? $product->get_product_url($context) : '',
//            'button_text' => $product->is_type('external') ? $product->get_button_text($context) : '',
            'tax_status' => $product->get_tax_status($context),
            'tax_class' => $product->get_tax_class($context),
            'manage_stock' => $product->managing_stock(),
            'stock_quantity' => $product->get_stock_quantity($context),
            'in_stock' => $product->is_in_stock(),
//            'backorders' => $product->get_backorders($context),
//            'backorders_allowed' => $product->backorders_allowed(),
//            'backordered' => $product->is_on_backorder(),
//            'sold_individually' => $product->is_sold_individually(),
            'weight' => $product->get_weight($context),
            'dimensions' => array(
                'length' => $product->get_length($context),
                'width' => $product->get_width($context),
                'height' => $product->get_height($context),
            ),
            'shipping_required' => $product->needs_shipping(),
            'shipping_taxable' => $product->is_shipping_taxable(),
            'shipping_class' => $product->get_shipping_class(),
            'shipping_class_id' => $product->get_shipping_class_id($context),
            'reviews_allowed' => $product->get_reviews_allowed($context),
            'average_rating' => 'view' === $context ? wc_format_decimal($product->get_average_rating(), 2) : $product->get_average_rating($context),
            'rating_count' => $product->get_rating_count(),
            'related_ids' => array_map('absint', array_values(wc_get_related_products($product->get_id()))),
            'upsell_ids' => array_map('absint', $product->get_upsell_ids($context)),
            'cross_sell_ids' => array_map('absint', $product->get_cross_sell_ids($context)),
            'parent_id' => $product->get_parent_id($context),
            'purchase_note' => 'view' === $context ? wpautop(do_shortcode(wp_kses_post($product->get_purchase_note()))) : $product->get_purchase_note($context),
            'categories' => $this->get_taxonomy_terms($product),
            'tags' => $this->get_taxonomy_terms($product, 'tag'),
            'images' => $this->get_images($product),
            'thumbnail' => $this->get_thumbnail($product),
            'attributes' => $this->get_attributes($product),
            'default_attributes' => $this->get_default_attributes($product),
            'variations' => array(),
            'grouped_products' => array(),
        );

        return $data;
    }

    /**
     * Get objects.
     *
     * @since  3.0.0
     * @param  array $query_args Query args.
     * @return array
     */
    protected function get_objects($query_args)
    {
        $query = new WP_Query();
        $result = $query->query($query_args);

        $total_posts = $query->found_posts;
        if ($total_posts < 1) {
            // Out-of-bounds, run the query again without LIMIT for total count.
            unset($query_args['paged']);
            $count_query = new WP_Query();
            $count_query->query($query_args);
            $total_posts = $count_query->found_posts;
        }

        return array(
            'objects' => array_map(array($this, 'get_object'), $result),
            'total' => (int)$total_posts,
            'pages' => (int)ceil($total_posts / (int)$query->query_vars['posts_per_page']),
        );
    }

    /**
     * Prepares a response for insertion into a collection.
     *
     * @since 4.7.0
     *
     * @param WP_REST_Response $response Response object.
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function prepare_response_for_collection($response)
    {
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }

        $data = (array)$response->get_data();
        $server = rest_get_server();

        if (method_exists($server, 'get_compact_response_links')) {
            $links = call_user_func(array($server, 'get_compact_response_links'), $response);
        } else {
            $links = call_user_func(array($server, 'get_response_links'), $response);
        }

        if (!empty($links)) {
            $data['_links'] = $links;
        }

        return $data;
    }

    public function getAll(WP_REST_Request $request)
    {
        $query_args = $this->prepare_objects_query($request);
        $query_results = $this->get_objects($query_args);

        $objects = array();

        foreach ($query_results['objects'] as $object) {
            $data = $this->prepare_object_for_response($object, $request);
            $objects[] = $this->prepare_response_for_collection($data);
        }

        return rest_ensure_response($objects);
    }

    public function getProductsByAttributes(WP_REST_Request $request)
    {
        $data = [];
        return rest_ensure_response($data);
    }

    public function getProductsByCategories(WP_REST_Request $request)
    {
        $data = [];
        return rest_ensure_response($data);
    }

    public function get_product_booking(WP_REST_Request $request)
    {
        $id = $request->get_param('product_id');
        $dress = $request->get_param('dress');
        $max = (is_string($dress) && $dress == "false" xor boolval($dress)) ? 4 : 7;

        $datas = array();
        if (function_exists('fix_get_booked_items_from_orders')) {
            $datas = fix_get_booked_items_from_orders($id);
            $max = 10;
        }
        $array = parsingProducts($id, $datas, false);

        return rest_ensure_response(array('disables' => $array, 'max' => $max));
    }

    public function set_product_book(WP_REST_Request $request)
    {
        $product_id = absint($request->get_param('product_id')); // Product ID
        $variation_id = absint($request->get_param('variation_id')); // Variation ID
        $children = isset($_POST['children']) ? array_map('absint', $_POST['children']) : array(); // Product children for grouped and variable products

        $id = !empty($variation_id) ? $variation_id : $product_id; // Product or variation id

        $options = get_option('easy_booking_settings');
        $calc_mode = $options['easy_booking_calc_mode']; // Calculation mode (Days or Nights)

        $start_date = sanitize_text_field($request->get_param('start')); // Booking start date
        $end_date = sanitize_text_field($request->get_param('end')); // Booking end date

        $start = sanitize_text_field($request->get_param('start_format')); // Booking start date 'yyyy-mm-dd'
        $end = sanitize_text_field($request->get_param('end_format')); // Booking end date 'yyyy-mm-dd'

        $product = wc_get_product($product_id); // Product object
        $_product = ($product_id !== $id) ? wc_get_product($id) : $product; // Product or variation object

        if (!$_product) {
            return $this->reponseErrorMsg();
        }

        // If product is variable and no variation was selected
        if ($product->is_type('variable') && empty($variation_id)) {
            return $this->reponseErrorMsg('Please select a variation.');
        }

        // If product is grouped and no quantity was selected for grouped products
        if ($product->is_type('grouped') && empty($children)) {
            return $this->reponseErrorMsg('Select a quantity for groupe product');
        }

        $number_of_dates = wceb_get_product_booking_dates($_product);

        // If date format is "one", check only one date is set
        if ($number_of_dates === 'one') {

            $dates = 'one_date';
            $duration = 1;

            // If end date is set
            if (!empty($end_date) || !empty($end)) {
                return $this->reponseErrorMsg('Select an end date...');
            }

            // If date is empty
            if (empty($start_date) || empty($start)) {
                return $this->reponseErrorMsg('Select a start date...');
            }

        } else { // "Two" dates check

            $dates = 'two_dates';

            // If one date is empty
            if (empty($start_date) || empty($end_date) || empty($start) || empty($end)) {
                return $this->reponseErrorMsg('Please choose two dates');
            }

            $start_time = strtotime($start);
            $end_time = strtotime($end);

            // If end date is before start date
            if ($end_time < $start_time) {
                return $this->reponseErrorMsg('Please choose valid dates');
            }

            // Get booking duration in days
            $duration = absint(($start_time - $end_time) / 86400);

            if ($duration == 0) {
                $duration = 1;
            }

            // If booking mode is days and calculation mode is set to "Days", add one day
            if ($calc_mode === 'days' && ($start != $end)) {
                $duration += 1;
            }

            $booking_duration = wceb_get_product_booking_duration($_product);

            // If booking mode is weeks and duration is a multiple of 7
            if ($booking_duration === 'weeks') {

                if ($calc_mode === 'nights' && $duration % 7 === 0) { // If in weeks mode, check that the duration is a multiple of 7
                    $duration /= 7;
                } else if ($calc_mode === 'days' && $duration % 6 === 0) { // Or 6 in "Days" mode
                    $duration /= 6;
                } else { // Otherwise throw an error
                    return $this->reponseErrorMsg('Please choose valid dates');
                }

            } else if ($booking_duration === 'custom') {

                $custom_booking_duration = wceb_get_product_custom_booking_duration($_product);

                if ($duration % $custom_booking_duration === 0) {
                    $duration /= $custom_booking_duration;
                } else {
                    return $this->reponseErrorMsg('Please choose valid dates');
                }

            }

            // If number of days is inferior to 0
            if ($duration <= 0) {
                return $this->reponseErrorMsg('Please choose valid dates');
            }

        }

        // Get additional costs (for WooCommerce Product Addons)
        $additional_cost = $this->get_additional_costs($_product);

        // Store data in array
        $data = array(
            'start_date' => $start_date,
            'start' => $start
        );

        if (isset($duration) && !empty($duration)) {
            $data['duration'] = $duration;
        }

        if (isset($end_date) && !empty($end_date)) {
            $data['end_date'] = $end_date;
        }

        if (isset($end) && !empty($end)) {
            $data['end'] = $end;
        }

        $booking_data = array();

        $new_price = 0;
        $new_regular_price = 0;

        // Grouped or Bundle product types
        if ($product->is_type('grouped') || $product->is_type('bundle')) {

            if (!empty($children)) foreach ($children as $child_id => $quantity) {

                if ($quantity <= 0 || ($child_id === $id)) {
                    continue;
                }

                $child = wc_get_product($child_id);

                $children_prices[$child_id] = wceb_get_product_price($product, $child, false, 'array');

                // Multiply price by duration only if children is bookable
                if ($children_prices[$child_id]) {

                    if (wceb_is_bookable($child)) {

                        if ($children_prices[$child_id]) foreach ($children_prices[$child_id] as $price_type => $price) {

                            if ($price === "") {
                                continue;
                            }

                            if ($number_of_dates === 'two') {
                                $price *= $duration;
                            }

                            ${'child_new_' . $price_type} = apply_filters(
                                'easy_booking_' . $dates . '_price',
                                wc_format_decimal($price), // Regular or sale price for x days
                                $product, $child, $data, $price_type
                            );

                        }

                    } else {

                        $child_new_price = wc_format_decimal($children_prices[$child_id]['price']);

                        if (isset($children_prices[$child_id]['regular_price'])) {
                            $child_new_regular_price = wc_format_decimal($children_prices[$child_id]['regular_price']);
                        }

                    }

                } else {

                    // Tweak for not individually sold bundled products
                    $child_new_price = 0;
                    $child_new_regular_price = 0;

                }

                // Maybe add additional costs
                if (isset($additional_cost[$child_id])) {

                    $child_new_price = $this->add_additional_costs($child_new_price, $additional_cost[$child_id], $duration);

                    if (isset($child_new_regular_price)) {
                        $child_new_regular_price = $this->add_additional_costs($child_new_regular_price, $additional_cost[$child_id], $duration);
                    }

                }

                $data['new_price'] = $child_new_price;

                if (isset($child_new_regular_price) && !empty($child_new_regular_price)) {
                    $data['new_regular_price'] = $child_new_regular_price;
                }

                // Store parent produt for bundled items
                if ($product->is_type('bundle')) {
                    $data['grouped_by'] = $product;
                }

                $booking_data[$child_id] = $data;

                if ($product->is_type('grouped')) {
                    $new_price += wc_format_decimal($child_new_price * $quantity);

                    if (isset($child_new_regular_price)) {
                        $new_regular_price += wc_format_decimal($child_new_regular_price * $quantity);
                    }
                }

            }

            if ($product->is_type('bundle')) {

                $prices = (array)wceb_get_product_price($product, false, false, 'array');

                if ($prices) foreach ($prices as $price_type => $price) {

                    if ($price === "") {
                        continue;
                    }

                    if ($number_of_dates === 'two') {
                        $price *= $duration;
                    }

                    ${'new_' . $price_type} = apply_filters(
                        'easy_booking_' . $dates . '_price',
                        wc_format_decimal($price), // Regular or sale price for x days
                        $product, $_product, $data, $price_type
                    );

                    if (isset($additional_cost[$id]) && $additional_cost[$id] > 0) {
                        ${'new_' . $price_type} = $this->add_additional_costs(${'new_' . $price_type}, $additional_cost[$id], $duration);
                    }

                }

            }

        } else {

            // Get product price and (if on sale) regular price
            $prices = (array)wceb_get_product_price($_product, false, false, 'array');

            if ($prices) foreach ($prices as $price_type => $price) {

                if ($price === "") {
                    continue;
                }

                if ($number_of_dates === 'two') {
                    $price *= 1; //$duration;
                }

                ${'new_' . $price_type} = apply_filters(
                    'easy_booking_' . $dates . '_price',
                    wc_format_decimal($price), // Regular or sale price for x days
                    $product, $_product, $data, $price_type
                );

                if (isset($additional_cost[$id]) && $additional_cost[$id] > 0) {
                    ${'new_' . $price_type} = $this->add_additional_costs(${'new_' . $price_type}, $additional_cost[$id], $duration);
                }

            }

        }

        $data['new_price'] = $new_price;

        if (isset($new_regular_price) && !empty($new_regular_price) && ($new_regular_price !== $new_price)) {
            $data['new_regular_price'] = $new_regular_price;
        } else {
            unset($data['new_regular_price']); // Unset value in case it was set for a child product
        }

        $booking_data[$id] = $data;

        try {
            // Update session data
            if (!WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
            WC()->session->set('booking', $booking_data);
            $data['cart_hash'] = WC()->cart->add_to_cart($product->get_id());
        } catch (Exception $e) {
            return $this->reponseErrorMsg($e->getMessage());
        }

        return rest_ensure_response($data);
    }

    private function add_additional_costs($price, $additional_cost, $duration)
    {
        // Pass true to filter to multiply additional costs by booking duration (default: false)
        if (true === apply_filters('easy_booking_multiply_additional_costs', false)) {
            $price += ($additional_cost * $duration);
        } else {
            $price += $additional_cost;
        }
        return $price;
    }

    private function get_additional_costs($_product)
    {
        $additional_cost = isset($_POST['additional_cost']) ? array_map('wc_format_decimal', $_POST['additional_cost']) : array();
        $prices_include_tax = get_option('woocommerce_prices_include_tax');
        $tax_display_mode = get_option('woocommerce_tax_display_shop');

        // Get additional costs including or excluding taxes (for WooCommerce Product Addons)
        if (!empty($additional_cost)) {
            foreach ($additional_cost as $ac_id => $ac_amount) {
                if ($_product->is_taxable()) {
                    $rates = WC_Tax::get_base_tax_rates($_product->get_tax_class());
                    if ($prices_include_tax === 'yes' && $tax_display_mode === 'excl') {
                        $taxes = WC_Tax::calc_exclusive_tax($ac_amount, $rates);
                        if ($taxes) foreach ($taxes as $tax) {
                            $additional_cost[$ac_id] += $tax;
                        }
                    } else if ($prices_include_tax === 'no' && $tax_display_mode === 'incl') {
                        $taxes = WC_Tax::calc_inclusive_tax($ac_amount, $rates);
                        if ($taxes) foreach ($taxes as $tax) {
                            $additional_cost[$ac_id] -= $tax;
                        }
                    }
                }
            }
        }
        return $additional_cost;
    }

    private function reponseErrorMsg($string = '')
    {
        $session = WC()->session->get('booking');
        if (!empty($session)) {
            WC()->session->set('booking', '');
        }
        $error = new WP_Error(400, $string, 400);
        return rest_ensure_response($error);
    }
}