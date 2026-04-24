<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class Deployment
{
    public static function registerHooks(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_abcnorio_trigger_build', [self::class, 'ajaxTriggerBuild']);
        add_action('wp_ajax_abcnorio_poll_build_status', [self::class, 'ajaxPollBuildStatus']);
    }

    public static function addMenuPage(): void
    {
        add_menu_page(
            __('Deployment', 'abcnorio-func'),
            __('Deployment', 'abcnorio-func'),
            'manage_options',
            'abcnorio-deployment',
            [self::class, 'renderPage'],
            'dashicons-controls-repeat',
            3
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_abcnorio-deployment') {
            return;
        }

        $jsUrl = plugins_url('resources/js/deployment.js', ABCNORIO_CUSTOM_FUNC_FILE);
        wp_enqueue_script('abcnorio-deployment', $jsUrl, [], '1.0.0', true);
        wp_localize_script('abcnorio-deployment', 'abcnorioDeployment', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'triggerNonce' => wp_create_nonce('abcnorio_trigger_build'),
            'pollNonce'    => wp_create_nonce('abcnorio_poll_build_status'),
            'previewUrls'  => [
                'dev'        => getenv('DEV_FRONTEND_URL') ?: '',
                'staging'    => getenv('STAGING_FRONTEND_URL') ?: '',
                'production' => getenv('PRODUCTION_FRONTEND_URL') ?: '',
            ],
            'buildExists'  => [
                'dev'        => self::buildExists('dev'),
                'staging'    => self::buildExists('staging'),
                'production' => self::buildExists('production'),
            ],
        ]);
    }

    private static function buildExists(string $env): bool
    {
        $buildPath = rtrim(getenv('STATIC_SERVER_SITE_DIR') ?: '', '/') . '/' . $env . '/client';
        if (!is_dir($buildPath)) {
            return false;
        }

        $entries = scandir($buildPath);
        return is_array($entries) && count(array_diff($entries, ['.', '..'])) > 0;
    }

    public static function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Deployment', 'abcnorio-func'); ?></h1>

            <nav class="nav-tab-wrapper" id="deployment-tabs">
                <a href="#tab-dev" class="nav-tab nav-tab-active" data-tab="dev">
                    <?php esc_html_e('Dev', 'abcnorio-func'); ?>
                </a>
                <a href="#tab-staging" class="nav-tab" data-tab="staging">
                    <?php esc_html_e('Staging', 'abcnorio-func'); ?>
                </a>
                <a href="#tab-production" class="nav-tab" data-tab="production">
                    <?php esc_html_e('Production', 'abcnorio-func'); ?>
                </a>
            </nav>

            <?php foreach (['dev', 'staging'] as $env) : ?>
            <div
                id="tab-<?php echo esc_attr($env); ?>"
                class="deployment-tab<?php echo $env !== 'dev' ? ' hidden' : ''; ?>"
            >
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <button
                        class="button button-primary js-trigger-build"
                        data-target="<?php echo esc_attr($env); ?>"
                        data-label="<?php esc_attr_e('Run Static Build', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Run Static Build', 'abcnorio-func'); ?>
                    </button>
                    <a
                        href="#"
                        class="button js-preview-link hidden"
                        data-env="<?php echo esc_attr($env); ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <?php esc_html_e('Preview', 'abcnorio-func'); ?>
                    </a>
                    <span class="js-build-status" style="color: #666;"></span>
                </div>
            </div>
            <?php endforeach; ?>

            <div id="tab-production" class="deployment-tab hidden">
                <div style="margin-top: 1.5rem;">
                    <p style="color: #666; margin-bottom: 1rem;">
                        <?php esc_html_e('Backs up the current production build, then deploys the latest build to production.', 'abcnorio-func'); ?>
                    </p>
                    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <button
                            class="button button-primary js-trigger-build"
                            data-target="production"
                            data-label="<?php esc_attr_e('Deploy to Production', 'abcnorio-func'); ?>"
                            data-confirm="<?php esc_attr_e('Deploy to production? This will replace the live site. Continue?', 'abcnorio-func'); ?>"
                        >
                            <?php esc_html_e('Deploy to Production', 'abcnorio-func'); ?>
                        </button>
                        <span class="js-build-status" style="color: #666;"></span>
                        <a
                            href="#"
                            class="button js-preview-link hidden"
                            data-env="production"
                            target="_blank"
                            rel="noopener"
                        >
                            <?php esc_html_e('Preview', 'abcnorio-func'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajaxTriggerBuild(): void
    {
        check_ajax_referer('abcnorio_trigger_build', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $target = sanitize_key($_POST['target'] ?? '');
        if (!in_array($target, ['dev', 'staging', 'production'], true)) {
            wp_send_json_error(['message' => 'Invalid target'], 400);
        }

        $triggerUrl = rtrim(getenv('ASTRO_BUILD_TRIGGER_URL') ?: 'http://astro:3034', '/');
        $secret     = getenv('ASTRO_BUILD_TRIGGER_SECRET') ?: '';

        $response = wp_remote_post("{$triggerUrl}/trigger", [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => "Bearer {$secret}",
            ],
            'body'    => wp_json_encode(['target' => $target]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 409) {
            wp_send_json_error(['message' => 'A build is already running'], 409);
        }

        if ($code !== 202) {
            wp_send_json_error(['message' => 'Trigger failed', 'detail' => $body], $code);
        }

        wp_send_json_success($body);
    }

    public static function ajaxPollBuildStatus(): void
    {
        check_ajax_referer('abcnorio_poll_build_status', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $triggerUrl = rtrim(getenv('ASTRO_BUILD_TRIGGER_URL') ?: 'http://astro:3034', '/');
        $secret     = getenv('ASTRO_BUILD_TRIGGER_SECRET') ?: '';

        $response = wp_remote_get("{$triggerUrl}/status", [
            'headers' => ['Authorization' => "Bearer {$secret}"],
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
    }
}
