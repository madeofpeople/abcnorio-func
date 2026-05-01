<?php

namespace abcnorio\CustomFunc\RestApi;

/**
 * Short-lived transient cache for the WP REST events collection endpoint.
 *
 * Caches GET responses for 5 minutes, keyed on normalized query params.
 * Time-based params (event_start_after/before) are rounded to the nearest
 * 5-minute bucket so sequential SSR requests share a cache entry instead of
 * each generating a unique key.
 *
 * Invalidated immediately on event save/delete.
 */
final class EventRestCache
{
    private const ROUTE_PREFIX  = '/wp/v2/events';
    private const TTL           = 5 * MINUTE_IN_SECONDS;
    private const BUCKET_SECS   = 5 * MINUTE_IN_SECONDS;
    private const TRANSIENT_KEY = 'abcnorio_event_rest_';
    private const VERSION_KEY   = 'abcnorio_event_rest_version';

    public static function registerHooks(): void
    {
        add_filter('rest_pre_dispatch',  [self::class, 'maybeServeFromCache'], 10, 3);
        add_filter('rest_post_dispatch', [self::class, 'maybeCacheResponse'],  10, 3);
        add_action('save_post_event',    [self::class, 'bustCache']);
        add_action('delete_post',        [self::class, 'bustOnDelete'], 10, 2);
    }

    public static function maybeServeFromCache(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if ($result !== null) {
            return $result;
        }

        if (! self::isCacheable($request)) {
            return $result;
        }

        $cached = get_transient(self::cacheKey($request));

        return $cached !== false ? $cached : $result;
    }

    public static function maybeCacheResponse(mixed $response, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if (! self::isCacheable($request)) {
            return $response;
        }

        if (! ($response instanceof \WP_REST_Response) || $response->get_status() !== 200) {
            return $response;
        }

        set_transient(self::cacheKey($request), $response, self::TTL);

        return $response;
    }

    public static function bustCache(): void
    {
        update_option(self::VERSION_KEY, microtime(true), false);
    }

    public static function bustOnDelete(int $postId, \WP_Post $post): void
    {
        if ($post->post_type === 'event') {
            self::bustCache();
        }
    }

    private static function isCacheable(\WP_REST_Request $request): bool
    {
        if ($request->get_method() !== 'GET') {
            return false;
        }

        if (! str_ends_with($request->get_route(), self::ROUTE_PREFIX)
            && ! str_contains($request->get_route(), self::ROUTE_PREFIX . '?')) {
            // Allow /wp/v2/events (collection only, not single /wp/v2/events/123)
            if (! preg_match('#^/wp/v2/events$#', $request->get_route())) {
                return false;
            }
        }

        // Don't cache authenticated requests (editor previews, drafts).
        if (is_user_logged_in()) {
            return false;
        }

        return true;
    }

    private static function cacheKey(\WP_REST_Request $request): string
    {
        $params = $request->get_query_params();

        foreach (['event_start_after', 'event_start_before'] as $key) {
            if (! empty($params[$key])) {
                $params[$key] = self::bucketDatetime($params[$key]);
            }
        }

        ksort($params);

        $version = get_option(self::VERSION_KEY, '1');

        return self::TRANSIENT_KEY . md5($version . serialize($params));
    }

    private static function bucketDatetime(string $datetime): string
    {
        $ts = strtotime($datetime);

        if ($ts === false) {
            return $datetime;
        }

        return date('Y-m-d H:i:s', (int) (floor($ts / self::BUCKET_SECS) * self::BUCKET_SECS));
    }
}
