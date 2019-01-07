<?php
/**
 * Created by PhpStorm.
 * Date: 10/1/18
 * Time: 4:24 PM
 */

class Utils
{
    use Singleton;

    const PATH = 'utils';

    public function init()
    {
        register_rest_route(WPMI_NAMESPACE, self::PATH . '/press/(?P<attribute_id>[\d]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'mobile_wp_get_page_content'),
        ));
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return false|int|WP_Error|WP_REST_Response
     */
    public function mobile_wp_get_page_content(WP_REST_Request $request)
    {
        $response = array();
        $page_id = $request->get_param('attribute_id');

        $post = WP_Post::get_instance($page_id);
        if (empty($post)) {
            $error = new WP_Error();
            $error->add(400, __("Not page found.", 'mobile-integration'), array('status' => 400));
            return $error;
        }

        // TODO: Mejorar
        $matches = array();
        preg_match_all('/image="(?<ids>\d+)"[\s\w"_=]+ link="(?<links>[^"]+)/is', $post->post_content, $matches);

        $result = array();
        foreach ($matches['ids'] as $key => $id) {
            $result[$id] = $matches['links'][$key];
        }
        $matches = array();
        preg_match_all('/image="(?<ids>\d+)"/i', $post->post_content, $matches);
        $medias = get_posts(array('post_type' => 'attachment', 'include' => $matches['ids']));

        foreach ($medias as $key => $media) {
            $medias[$key]->url_extern = @$result[$media->ID];
            $medias[$key]->guid = str_replace('localhost', '192.168.1.200', $medias[$key]->guid);
        }

        return rest_ensure_response($medias);
    }
}
