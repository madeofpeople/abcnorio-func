<?php

namespace abcnorio\CustomFunc\RestApi;

final class DeploymentVersionEndpoint
{
    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        register_rest_route('abcnorio/v1', '/deployment/version', [
            'methods' => 'GET',
            'callback' => [self::class, 'getVersion'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function getVersion(): array
    {
        $pluginData = get_file_data(ABCNORIO_CUSTOM_FUNC_FILE, ['Version' => 'Version']);

        return ['version' => (string) ($pluginData['Version'] ?? '')];
    }
}