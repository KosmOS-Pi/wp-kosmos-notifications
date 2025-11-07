<?php
/*
Plugin Name: Kosmos Notifications REST Helper
Description: Exposes notification posts via REST API at /wp-json/kosmos/v1/notifications
Version: 1.0
Author: Kosmos Project
*/

if (!defined('ABSPATH')) exit;

/**
 * REST endpoint: /wp-json/kosmos/v1/notifications
 * 
 * Returns published posts in the "news" category (or specified via ?category=slug)
 * where the custom field "notify_users" is true.
 * 
 * Expected custom fields (managed by ACF, shown in REST API):
 *  - notify_users (true/false)
 *  - notification_text (short text shown in popup)
 *  - notification_link (optional URL)
 *  - start_date (YYYY-MM-DD)
 *  - end_date (YYYY-MM-DD)
 *  - priority (low / normal / high)
 */

add_action('rest_api_init', function() {
    register_rest_route('kosmos/v1', '/notifications', [
        'methods'  => 'GET',
        'callback' => function($request) {

            // Determine which category to use (default: "news")
            $params   = $request->get_params();
            $cat_slug = isset($params['category']) ? sanitize_text_field($params['category']) : 'news';
            $cat      = get_category_by_slug($cat_slug);
            $cat_id   = $cat ? $cat->term_id : 0;

            // Query posts
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 10,
                'category__in'   => $cat_id ? [$cat_id] : [],
                'meta_query'     => [
                    [
                        'key'     => 'notify_users',
                        'value'   => '1',
                        'compare' => '=',
                    ],
                ],
                'orderby' => 'date',
                'order'   => 'DESC',
            ];

            $q = new WP_Query($args);
            $items = [];

            foreach ($q->posts as $post) {
                $id = $post->ID;

                // Retrieve ACF meta
                $notif_text = get_post_meta($id, 'notification_text', true);
                $notif_link = get_post_meta($id, 'notification_link', true);
                $start_date = get_post_meta($id, 'start_date', true) ?: null;
                $end_date   = get_post_meta($id, 'end_date', true) ?: null;
                $priority   = get_post_meta($id, 'priority', true) ?: 'normal';

                // Fallback for notification text
                if (empty($notif_text)) {
                    if (has_excerpt($id)) {
                        $notif_text = wp_strip_all_tags(get_the_excerpt($id));
                    } else {
                        $notif_text = wp_strip_all_tags(wp_trim_words($post->post_content, 40, '...'));
                    }
                }

                // Build item
                $items[] = [
                    'id'         => 'post-' . $id,
                    'post_id'    => (int) $id,
                    'title'      => html_entity_decode(get_the_title($id)),
                    'message'    => $notif_text,
                    'link'       => $notif_link ?: get_permalink($id),
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                    'priority'   => $priority,
                    'date'       => get_the_date('c', $id),
                ];
            }

            // Compute last modified time for caching
            $last_mod = 0;
            foreach ($q->posts as $p) {
                $t = strtotime($p->post_modified_gmt . ' GMT');
                if ($t > $last_mod) $last_mod = $t;
            }
            if (!$last_mod) $last_mod = time();

            // Build REST response
            $response = rest_ensure_response($items);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $last_mod) . ' GMT');
            return $response;
        },
        'permission_callback' => '__return_true',
    ]);
});
