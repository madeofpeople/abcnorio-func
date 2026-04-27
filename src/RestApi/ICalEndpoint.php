<?php

namespace abcnorio\CustomFunc\RestApi;

final class ICalEndpoint
{
    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('abcnorio/v1', '/events/ical', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'serve'],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_start_after'  => ['type' => 'string', 'required' => false],
                'event_start_before' => ['type' => 'string', 'required' => false],
                'per_page'           => ['type' => 'integer', 'required' => false, 'default' => 100],
            ],
        ]);
    }

    public static function serve(\WP_REST_Request $request): void
    {
        $after    = $request->get_param('event_start_after');
        $before   = $request->get_param('event_start_before');
        $per_page = min((int) $request->get_param('per_page'), 200);

        $meta_query = ['relation' => 'AND', self::hasStartDateClause()];

        if ($after) {
            $meta_query[] = self::effectiveEndClause('>=', sanitize_text_field($after));
        }
        if ($before) {
            $meta_query[] = self::effectiveEndClause('<=', sanitize_text_field($before));
        }

        $query = new \WP_Query([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'meta_key'       => 'event_start_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => $meta_query,
        ]);

        $site_tz  = get_option('timezone_string') ?: 'UTC';
        $prod_id  = '-//ABC No Rio//Events//EN';
        $cal_name = get_bloginfo('name') . ' Events';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="events.ics"');
        header('Cache-Control: no-cache');

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:" . self::escapeText($prod_id) . "\r\n";
        echo "X-WR-CALNAME:" . self::escapeText($cal_name) . "\r\n";
        echo "X-WR-TIMEZONE:" . self::escapeText($site_tz) . "\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:PUBLISH\r\n";

        foreach ($query->posts as $post) {
            $start_raw = get_field('event_start_date', $post->ID);
            $end_raw   = get_field('event_end_date', $post->ID);
            $tz_id     = get_field('event_timezone', $post->ID) ?: $site_tz;
            $venue     = get_field('event_venue_name', $post->ID) ?: '';
            $address   = get_field('event_venue_address', $post->ID) ?: '';
            $tickets   = get_field('event_tickets_url', $post->ID) ?: '';

            if (!$start_raw) {
                continue;
            }

            $tz      = new \DateTimeZone($tz_id);
            $dtstart = \DateTime::createFromFormat('Y-m-d H:i:s', $start_raw, $tz);
            $dtstamp = new \DateTime($post->post_modified, new \DateTimeZone('UTC'));

            if ($end_raw) {
                $dtend = \DateTime::createFromFormat('Y-m-d H:i:s', $end_raw, $tz);
            } else {
                $dtend = clone $dtstart;
                $dtend->modify('+2 hours');
            }

            $location = trim(implode(', ', array_filter([$venue, $address])));
            $url      = $tickets ?: get_permalink($post->ID);
            $excerpt  = wp_strip_all_tags($post->post_excerpt);
            if (!$excerpt) {
                $excerpt = self::firstParagraphText($post->post_content);
            }

            echo "BEGIN:VEVENT\r\n";
            echo self::fold("UID:" . $post->ID . "@" . parse_url(home_url(), PHP_URL_HOST)) . "\r\n";
            echo self::fold("DTSTAMP:" . $dtstamp->format('Ymd\THis\Z')) . "\r\n";
            echo self::fold("LAST-MODIFIED:" . $dtstamp->format('Ymd\THis\Z')) . "\r\n";
            echo self::fold("DTSTART;TZID=" . $tz_id . ":" . $dtstart->format('Ymd\THis')) . "\r\n";
            echo self::fold("DTEND;TZID=" . $tz_id . ":" . $dtend->format('Ymd\THis')) . "\r\n";
            echo self::fold("SUMMARY:" . self::escapeText(get_the_title($post))) . "\r\n";
            if ($excerpt) {
                echo self::fold("DESCRIPTION:" . self::escapeText($excerpt)) . "\r\n";
            }
            if ($location) {
                echo self::fold("LOCATION:" . self::escapeText($location)) . "\r\n";
            }
            echo self::fold("URL:" . esc_url_raw($url)) . "\r\n";
            echo "END:VEVENT\r\n";
        }

        echo "END:VCALENDAR\r\n";
        exit;
    }

    private static function firstParagraphText(string $content): string
    {
        foreach (parse_blocks($content) as $block) {
            if ($block['blockName'] === 'core/paragraph' && !empty($block['innerHTML'])) {
                return wp_strip_all_tags($block['innerHTML']);
            }
        }
        return '';
    }

    private static function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace("\n", '\n', $value);
        return $value;
    }

    // Fold long lines at 75 octets per RFC 5545 §3.1
    private static function fold(string $line): string
    {
        $out    = '';
        $len    = strlen($line);
        $offset = 0;
        $first  = true;

        while ($offset < $len) {
            $chunk  = $first ? 75 : 74;
            $out   .= ($first ? '' : "\r\n ") . substr($line, $offset, $chunk);
            $offset += $chunk;
            $first  = false;
        }

        return $out;
    }

    private static function hasStartDateClause(): array
    {
        return [
            'relation' => 'AND',
            ['key' => 'event_start_date', 'compare' => 'EXISTS'],
            ['key' => 'event_start_date', 'value' => '', 'compare' => '!='],
        ];
    }

    private static function effectiveEndClause(string $compare, string $value): array
    {
        return [
            'relation' => 'OR',
            [
                'key'     => 'event_end_date',
                'value'   => $value,
                'compare' => $compare,
                'type'    => 'DATETIME',
            ],
            [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => 'event_end_date', 'compare' => 'NOT EXISTS'],
                    ['key' => 'event_end_date', 'value' => '', 'compare' => '='],
                ],
                [
                    'key'     => 'event_start_date',
                    'value'   => $value,
                    'compare' => $compare,
                    'type'    => 'DATETIME',
                ],
            ],
        ];
    }
}
