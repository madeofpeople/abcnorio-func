<?php

namespace abcnorio\CustomFunc\Headless;

final class AdminExperience
{
    private const BLOCKED_THEME_CAPS = [
        'switch_themes' => true,
        'edit_theme_options' => true,
        'customize' => true,
        'install_themes' => true,
        'update_themes' => true,
        'delete_themes' => true,
        'edit_themes' => true,
    ];

    public static function registerHooks(): void
    {
        add_action('admin_menu', [self::class, 'hideThemeMenu'], 999);
        add_action('rest_api_init', [self::class, 'registerRestLinkRewrites']);

        add_filter('map_meta_cap', [self::class, 'blockThemeAdministrationCaps'], 10, 4);

        add_filter('post_link', [self::class, 'rewritePublicLink'], 10, 2);
        add_filter('page_link', [self::class, 'rewritePageLink'], 10, 3);
        add_filter('post_type_link', [self::class, 'rewritePostTypeLink'], 10, 4);
        add_filter('preview_post_link', [self::class, 'rewritePreviewLink'], 10, 2);
    }

    public static function registerRestLinkRewrites(): void
    {
        foreach (get_post_types(['show_in_rest' => true], 'names') as $postType) {
            add_filter("rest_prepare_{$postType}", [self::class, 'rewriteRestPreparedEntity'], 10, 3);
        }
    }

    public static function hideThemeMenu(): void
    {
        if (! self::shouldBlockThemeAdministration()) {
            return;
        }

        remove_menu_page('themes.php');
    }

    public static function blockThemeAdministrationCaps(array $caps, string $cap, int $userId, array $args): array
    {
        if (! is_admin()) {
            return $caps;
        }

        // Avoid recursion when shouldBlockThemeAdministration() checks manage_options.
        if ($cap === 'manage_options') {
            return $caps;
        }

        if (! isset(self::BLOCKED_THEME_CAPS[$cap])) {
            return $caps;
        }

        if (! self::shouldBlockThemeAdministration($userId)) {
            return $caps;
        }

        return ['do_not_allow'];
    }

    public static function rewritePreviewLink(string $previewLink, \WP_Post $post): string
    {
        return self::rewriteToFrontend($previewLink);
    }

    public static function rewritePostTypeLink(string $postLink, \WP_Post $post, bool $leavename, bool $sample): string
    {
        return self::rewriteToFrontend($postLink);
    }

    public static function rewritePageLink(string $link, int $postId, bool $sample): string
    {
        return self::rewriteToFrontend($link);
    }

    public static function rewritePublicLink(string $link, \WP_Post $post): string
    {
        return self::rewriteToFrontend($link);
    }

    public static function rewriteRestPreparedEntity($response, \WP_Post $post, \WP_REST_Request $request)
    {
        if (! $response instanceof \WP_REST_Response) {
            return $response;
        }

        $data = $response->get_data();

        if (! is_array($data)) {
            return $response;
        }

        foreach (['link', 'preview_link', 'permalink_template'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = self::rewriteToFrontend($data[$key]);
            }
        }

        $response->set_data($data);

        return $response;
    }

    private static function rewriteToFrontend(string $url): string
    {
        $baseUrl = self::frontendBaseUrl();

        if ($baseUrl === '') {
            return $url;
        }

        $targetParts = wp_parse_url($baseUrl);
        $sourceParts = wp_parse_url($url);

        if (! is_array($targetParts) || ! is_array($sourceParts)) {
            return $url;
        }

        if (empty($targetParts['scheme']) || empty($targetParts['host'])) {
            return $url;
        }

        $scheme = $targetParts['scheme'];
        $host = $targetParts['host'];
        $port = isset($targetParts['port']) ? ':' . (string) $targetParts['port'] : '';

        $targetBasePath = isset($targetParts['path']) ? rtrim($targetParts['path'], '/') : '';
        $sourcePath = $sourceParts['path'] ?? '/';
        $finalPath = $targetBasePath . $sourcePath;

        if ($finalPath === '') {
            $finalPath = '/';
        }

        $query = isset($sourceParts['query']) ? '?' . $sourceParts['query'] : '';
        $fragment = isset($sourceParts['fragment']) ? '#' . $sourceParts['fragment'] : '';

        return $scheme . '://' . $host . $port . $finalPath . $query . $fragment;
    }

    private static function frontendBaseUrl(): string
    {
        $env = getenv('HEADLESS_FRONTEND_URL');

        return is_string($env) ? rtrim($env, '/') : '';
    }

    private static function allowThemeAdministration(): bool
    {
        static $allowThemeAdmin = null;

        if ($allowThemeAdmin !== null) {
            return $allowThemeAdmin;
        }

        $env = getenv('HEADLESS_ALLOW_THEME_ADMIN');

        if (! is_string($env)) {
            $allowThemeAdmin = false;

            return $allowThemeAdmin;
        }

        $allowThemeAdmin = filter_var($env, FILTER_VALIDATE_BOOLEAN);

        return $allowThemeAdmin;
    }

    private static function shouldBlockThemeAdministration(?int $userId = null): bool
    {
        if (self::allowThemeAdministration()) {
            return false;
        }

        if ($userId !== null) {
            return ! user_can($userId, 'manage_options');
        }

        return ! current_user_can('manage_options');
    }
}
