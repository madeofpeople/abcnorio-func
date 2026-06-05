<?php

namespace abcnorio\CustomFunc\Security;

final class LoginAlias
{
    private const LOGIN_ALIAS_PATH = '/abcnorio-login';
    private const BLOCKED_ADMIN_PREFIXES = ['/wp-admin', '/wp/wp-admin'];

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'blockDirectAdminAccess'], 0);
        add_filter('login_url', [self::class, 'filterLoginUrl'], 10, 3);
    }

    public static function blockDirectAdminAccess(): void
    {
        if (is_user_logged_in() || wp_doing_ajax()) {
            return;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestPath = wp_parse_url($requestUri, PHP_URL_PATH);

        if (! is_string($requestPath) || $requestPath === '') {
            return;
        }

        foreach (self::BLOCKED_ADMIN_PREFIXES as $prefix) {
            if ($requestPath === $prefix || str_starts_with($requestPath, $prefix . '/')) {
                status_header(404);
                nocache_headers();
                exit('Not Found');
            }
        }
    }

    public static function filterLoginUrl(string $loginUrl, string $redirect, bool $forceReauth): string
    {
        $aliasUrl = home_url(self::LOGIN_ALIAS_PATH);
        $queryArgs = [];

        if ($redirect !== '') {
            $queryArgs['redirect_to'] = $redirect;
        }

        if ($forceReauth) {
            $queryArgs['reauth'] = '1';
        }

        if ($queryArgs === []) {
            return $aliasUrl;
        }

        return add_query_arg($queryArgs, $aliasUrl);
    }
}