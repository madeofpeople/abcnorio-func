<?php

namespace abcnorio\CustomFunc\RestApi;

final class ContentListingEndpoint
{
    private const ALLOWED_POST_TYPES = ['event', 'article'];
    private const DEFAULT_POST_TYPES = ['event', 'article'];
    private const DEFAULT_COUNT = 5;
    private const MAX_COUNT = 50;

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('abcnorio/v1', '/content-listing', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'serve'],
            'permission_callback' => '__return_true',
            'args'                => [
                'post_types' => ['required' => false],
                'tags'       => ['required' => false],
                'count'      => ['required' => false, 'default' => self::DEFAULT_COUNT],
                'order'      => ['required' => false, 'default' => 'desc'],
            ],
        ]);
    }

    public static function serve(\WP_REST_Request $request)
    {
        $postTypes = self::resolvePostTypes($request->get_param('post_types'));
        $tags = self::parseList($request->get_param('tags'));
        $count = self::resolveCount((int) $request->get_param('count'));
        $order = self::resolveOrder((string) $request->get_param('order'));

        $items = [];

        foreach ($postTypes as $postType) {
            $queryArgs = [
                'post_type'           => $postType,
                'post_status'         => 'publish',
                'posts_per_page'      => $count,
                'orderby'             => 'date',
                'order'               => strtoupper($order),
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
            ];

            $taxQuery = self::buildTagTaxQuery($postType, $tags);
            if ($taxQuery !== []) {
                $queryArgs['tax_query'] = $taxQuery;
            }

            $query = new \WP_Query($queryArgs);

            if (! $query->have_posts()) {
                continue;
            }

            foreach ($query->posts as $post) {
                $items[] = self::mapItem($post);
            }
        }

        usort($items, static function (array $left, array $right) use ($order): int {
            if ($left['_sort_ts'] === $right['_sort_ts']) {
                return 0;
            }

            if ($order === 'asc') {
                return $left['_sort_ts'] <=> $right['_sort_ts'];
            }

            return $right['_sort_ts'] <=> $left['_sort_ts'];
        });

        $items = array_slice($items, 0, $count);

        $response = array_map(static function (array $item): array {
            unset($item['_sort_ts']);
            return $item;
        }, $items);

        return rest_ensure_response($response);
    }

    private static function resolvePostTypes($rawPostTypes): array
    {
        $requested = self::parseList($rawPostTypes);

        if (empty($requested)) {
            return self::DEFAULT_POST_TYPES;
        }

        $allowed = array_values(array_filter($requested, static fn (string $postType): bool => in_array($postType, self::ALLOWED_POST_TYPES, true)));

        return empty($allowed) ? self::DEFAULT_POST_TYPES : $allowed;
    }

    private static function parseList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(static fn ($entry): string => sanitize_key((string) $entry), $raw)));
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(static fn (string $entry): string => sanitize_key($entry), explode(',', $raw))));
    }

    private static function resolveCount(int $rawCount): int
    {
        if ($rawCount < 1) {
            return self::DEFAULT_COUNT;
        }

        return min($rawCount, self::MAX_COUNT);
    }

    private static function resolveOrder(string $rawOrder): string
    {
        return strtolower(sanitize_key($rawOrder)) === 'asc' ? 'asc' : 'desc';
    }

    private static function buildTagTaxQuery(string $postType, array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        if ($postType === 'event') {
            return [[
                'taxonomy' => 'event_tag',
                'field'    => 'slug',
                'terms'    => $tags,
            ]];
        }

        if ($postType === 'article' && taxonomy_exists('post_tag')) {
            return [[
                'taxonomy' => 'post_tag',
                'field'    => 'slug',
                'terms'    => $tags,
            ]];
        }

        return [];
    }

    private static function mapItem(\WP_Post $post): array
    {
        $item = [
            'id'        => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'slug'      => (string) $post->post_name,
            'title'     => ['rendered' => get_the_title($post)],
            'excerpt'   => ['rendered' => get_the_excerpt($post)],
            'date'      => (string) get_post_time('Y-m-d H:i:s', false, $post),
            'acf'       => [],
        ];

        if ($post->post_type === 'event') {
            $item['acf'] = [
                'event_start_date' => (string) get_post_meta($post->ID, 'event_start_date', true),
                'event_end_date'   => (string) get_post_meta($post->ID, 'event_end_date', true),
            ];
            $item['_sort_ts'] = self::toTimestamp((string) $item['acf']['event_start_date'])
                ?? self::toTimestamp((string) $item['date'])
                ?? 0;
        } else {
            $item['acf'] = [
                'item_date' => (string) get_post_meta($post->ID, 'item_date', true),
            ];
            $item['_sort_ts'] = self::toTimestamp((string) $item['acf']['item_date'])
                ?? self::toTimestamp((string) $item['date'])
                ?? 0;
        }

        $image = self::getCardImageData($post->ID);
        if ($image !== null) {
            $item['featured_image'] = $image;
        }

        return $item;
    }

    private static function getCardImageData(int $postId): ?array
    {
        $thumbnailId = (int) get_post_thumbnail_id($postId);
        if ($thumbnailId < 1) {
            return null;
        }

        $image = wp_get_attachment_image_src($thumbnailId, 'abcnorio-card');
        if (! is_array($image) || empty($image[0])) {
            return null;
        }

        return [
            'url'    => (string) $image[0],
            'width'  => (int) ($image[1] ?? 0),
            'height' => (int) ($image[2] ?? 0),
            'alt'    => (string) get_post_meta($thumbnailId, '_wp_attachment_image_alt', true),
            'srcset' => (string) wp_get_attachment_image_srcset($thumbnailId, 'abcnorio-card'),
            'sizes'  => (string) wp_get_attachment_image_sizes($thumbnailId, 'abcnorio-card'),
        ];
    }

    private static function toTimestamp(string $raw): ?int
    {
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? null : $timestamp;
    }
}