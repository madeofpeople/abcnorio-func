<?php

namespace abcnorio\CustomFunc\RestApi;

use abcnorio\CustomFunc\Deployment\Deployment;
use abcnorio\CustomFunc\Support\UrlNormalizer;

/**
 * Called by the orchestrator after a DB sync so environment hosts never leak into content.
 * POST abcnorio/v1/normalize-urls  (Bearer ASTRO_BUILD_TRIGGER_SECRET, apply=1 to write)
 */
final class NormalizeUrlsEndpoint
{
    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('abcnorio normalize-urls', [self::class, 'cli']);
        }
    }

    public static function register(): void
    {
        register_rest_route('abcnorio/v1', '/normalize-urls', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    public static function authorize(\WP_REST_Request $request): bool
    {
        $secret = Deployment::orchestratorSecret();
        $header = (string) $request->get_header('authorization');

        return $secret !== '' && hash_equals('Bearer ' . $secret, $header);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        set_time_limit(300);

        try {
            return new \WP_REST_Response(UrlNormalizer::fromEnv()->run((bool) $request->get_param('apply')));
        } catch (\Throwable $e) {
            return new \WP_Error('normalize_urls_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Rewrite other environments' hosts in content. Dry run unless --apply.
     *
     * [--apply]
     * : Write changes.
     */
    public static function cli(array $args, array $assoc): void
    {
        try {
            $report = UrlNormalizer::fromEnv()->run(isset($assoc['apply']));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }

        foreach ($report['targets'] as $t) {
            \WP_CLI::log(sprintf('%s.%s: %d rows %s', $t['table'], $t['column'], $t['changed'], $report['applied'] ? 'rewritten' : 'would change'));
        }
        \WP_CLI::success($report['applied'] ? "Applied. {$report['rows_changed']} rows rewritten." : "Dry run. {$report['rows_changed']} rows would change. Use --apply.");
    }
}
