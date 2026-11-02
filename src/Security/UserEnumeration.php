<?php

namespace abcnorio\CustomFunc\Security;

/**
 * Hides account slugs from logged-out visitors: REST user routes and author archives.
 * Nothing in the Astro frontend reads users or authors.
 */
final class UserEnumeration
{
    public static function registerHooks(): void
    {
        add_filter('rest_endpoints', [self::class, 'removeUserRoutes']);
        add_action('template_redirect', [self::class, 'blockAuthorArchives'], 0);
    }

    public static function removeUserRoutes(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);

        return $endpoints;
    }

    public static function blockAuthorArchives(): void
    {
        if (is_user_logged_in() || ! is_author()) {
            return;
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}
