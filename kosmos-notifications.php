<?php
/*
Plugin Name: KosmOS Notifications REST Helper
Description: Exposes notification posts via REST API at /wp-json/kosmos/v1/notifications
Version: 1.1

Copyright: Matteo Piscitelli 2025

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.
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

            //compute query
            $today = current_time('Y-m-d');
            $meta_query = [
                'relation' => 'AND',

                // always: notify_users = 1
                [
                    'key'     => 'notify_users',
                    'value'   => 1,
                    'compare' => '=',
                    'type'    => 'NUMERIC'
                ],

                // START DATE: missing or <= today
                [
                    'relation' => 'OR',
                    [
                        'key'     => 'start_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => 'start_date',
                        'value'   => $today,
                        'compare' => '<=',
                        'type'    => 'DATE',
                    ],
                    [
                        'key'     => 'start_date',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],

                // END DATE: empty or >= today
                [
                    'relation' => 'OR',
                    [
                        'key'     => 'end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => 'end_date',
                        'value'   => $today,
                        'compare' => '>=',
                        'type'    => 'DATE',
                    ],
                    [
                        'key'     => 'end_date',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
            ];

            // Query posts
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 10,
                'category__in'   => $cat_id ? [$cat_id] : [],
                'meta_query'     => $meta_query,
                'orderby' => 'date',
                'order'   => 'DESC',
            ];



            $q = new WP_Query($args);
            $items = [];

            $last_mod = 0;

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

                $t = strtotime($post->post_modified_gmt . ' GMT');
                if ($t > $last_mod) $last_mod = $t;
            }

            $etag = '"' . md5(json_encode($items)) . '"';
            $client_etag = $request->get_header('if-none-match');

            if ($client_etag && $client_etag === $etag) {
                return new WP_REST_Response(null, 304);
            }

            if (!$last_mod) $last_mod = time();

            // Build REST response
            $response = rest_ensure_response($items);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->header('ETag', $etag);
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $last_mod) . ' GMT');
            return $response;
        },
        'permission_callback' => '__return_true',
    ]);
});
