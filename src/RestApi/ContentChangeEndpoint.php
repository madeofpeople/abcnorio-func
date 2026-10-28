<?php

namespace abcnorio\CustomFunc\RestApi;

/**
 * Exposes a "last content change" timestamp so staging/dev frontends can poll and reload.
 * Any post, term, or nav menu write bumps the value.
 */
final class ContentChangeEndpoint
{
    private const OPTION = 'abcnorio_last_content_change';

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);

        foreach (['save_post', 'deleted_post', 'wp_update_nav_menu', 'created_term', 'edited_term', 'delete_term'] as $hook) {
            add_action($hook, [self::class, 'touch']);
        }
    }

    public static function register(): void
    {
        register_rest_route('abcnorio/v1', '/last-change', [
            'methods' => 'GET',
            'callback' => [self::class, 'getLastChange'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function touch(): void
    {
        update_option(self::OPTION, (int) (microtime(true) * 1000), false);
    }

    public static function getLastChange(): \WP_REST_Response
    {
        $response = new \WP_REST_Response(['changed' => (int) get_option(self::OPTION, 0)]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
