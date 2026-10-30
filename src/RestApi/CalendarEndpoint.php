<?php

namespace abcnorio\CustomFunc\RestApi;

use abcnorio\CustomFunc\Events\VenueOverlapNotice;

final class CalendarEndpoint
{
    public const FEEDS_OPTION = 'abcnorio_external_feeds';

    private const FEED_CACHE_SECONDS = 5 * MINUTE_IN_SECONDS;

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('abcnorio/v1', '/calendar/events', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'events'],
            'permission_callback' => [self::class, 'canView'],
        ]);

        register_rest_route('abcnorio/v1', '/calendar/events/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reschedule'],
            'permission_callback' => [self::class, 'canView'],
        ]);

        register_rest_route('abcnorio/v1', '/calendar/feed/(?P<id>[a-f0-9]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'feed'],
            'permission_callback' => [self::class, 'canView'],
        ]);
    }

    public static function canView(): bool
    {
        return current_user_can('edit_events');
    }

    public static function events(\WP_REST_Request $request): \WP_REST_Response
    {
        $after  = self::toDateTime((string) $request->get_param('start'));
        $before = self::toDateTime((string) $request->get_param('end'));

        $query = new \WP_Query([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'pending', 'future'],
            'posts_per_page' => 500,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                ICalEndpoint::hasStartDateClause(),
                ICalEndpoint::effectiveEndClause('>=', $after),
                [
                    'key'     => 'event_start_date',
                    'value'   => $before,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ],
            ],
        ]);

        $site_tz = get_option('timezone_string') ?: 'UTC';
        $items   = [];

        foreach ($query->posts as $post) {
            $start_raw = get_field('event_start_date', $post->ID);
            $end_raw   = get_field('event_end_date', $post->ID);
            $tz        = new \DateTimeZone(get_field('event_timezone', $post->ID) ?: $site_tz);
            $start     = \DateTime::createFromFormat('Y-m-d H:i:s', $start_raw, $tz);
            $end       = $end_raw
                ? \DateTime::createFromFormat('Y-m-d H:i:s', $end_raw, $tz)
                : (clone $start)->modify('+2 hours');

            $status   = $post->post_status;
            $venue_id = (int) get_field('event_venue_id', $post->ID);
            $title    = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');

            $items[] = [
                'id'            => (string) $post->ID,
                'title'         => $status === 'publish' ? $title : '[' . $status . '] ' . $title,
                'start'         => $start->format('c'),
                'end'           => $end->format('c'),
                'url'           => get_edit_post_link($post->ID, 'raw'),
                'classNames'    => $status === 'publish' ? [] : ['abcnorio-cal-unpublished'],
                'extendedProps' => ['venue_id' => $venue_id, 'status' => $status],
            ];
        }

        return new \WP_REST_Response($items);
    }

    public static function reschedule(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        if (get_post_type($id) !== 'event' || !current_user_can('edit_post', $id)) {
            return new \WP_REST_Response(['message' => 'Not allowed.'], 403);
        }

        $tz    = new \DateTimeZone(get_field('event_timezone', $id) ?: (get_option('timezone_string') ?: 'UTC'));
        $start = (new \DateTime((string) $request->get_param('start')))->setTimezone($tz);
        $end   = (new \DateTime((string) $request->get_param('end')))->setTimezone($tz);

        update_field('event_start_date', $start->format('Y-m-d H:i:s'), $id);
        update_field('event_end_date', $end->format('Y-m-d H:i:s'), $id);

        // update_field does not fire save_post, which SaveTrigger needs to queue the Astro rebuild.
        wp_update_post(['ID' => $id]);

        return new \WP_REST_Response(['warnings' => VenueOverlapNotice::warnings($id)]);
    }

    public static function feed(\WP_REST_Request $request): void
    {
        $id  = (string) $request->get_param('id');
        $url = '';
        foreach (get_option(self::FEEDS_OPTION, []) as $feed) {
            if ($feed['id'] === $id) {
                $url = $feed['url'];
            }
        }

        if ($url === '') {
            status_header(404);
            exit;
        }

        $cache_key = 'abcnorio_feed_' . $id;
        $body      = get_transient($cache_key);

        if ($body === false) {
            $response = wp_safe_remote_get($url, ['timeout' => 10]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                status_header(502);
                exit;
            }
            $body = wp_remote_retrieve_body($response);
            set_transient($cache_key, $body, self::FEED_CACHE_SECONDS);
        }

        header('Content-Type: text/calendar; charset=utf-8');
        echo $body;
        exit;
    }

    // FullCalendar sends ISO strings with an offset; meta compares 'Y-m-d H:i:s'.
    private static function toDateTime(string $iso): string
    {
        return str_replace('T', ' ', substr($iso, 0, 19));
    }
}
