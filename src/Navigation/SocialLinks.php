<?php

namespace abcnorio\CustomFunc\Navigation;

use abcnorio\CustomFunc\Settings\SettingsPage;

final class SocialLinks
{
    private const OPTION = 'abcnorio_social_links';
    private const ACTION = 'abcnorio_save_social_links';

    public static function registerHooks(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        add_filter('upload_mimes', [self::class, 'allowSvgUploads']);
        add_filter('wp_check_filetype_and_ext', [self::class, 'fixSvgFiletype'], 10, 3);
    }

    public static function allowSvgUploads(array $mimes): array
    {
        if (current_user_can('manage_options')) {
            $mimes['svg'] = 'image/svg+xml';
        }
        return $mimes;
    }

    public static function fixSvgFiletype(array $data, string $file, string $filename): array
    {
        if (str_ends_with(strtolower($filename), '.svg') && current_user_can('manage_options')) {
            $data['ext'] = 'svg';
            $data['type'] = 'image/svg+xml';
        }
        return $data;
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== SettingsPage::HOOK) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'abcnorio-social-links',
            plugins_url('resources/js/socialLinks.js', ABCNORIO_CUSTOM_FUNC_FILE),
            [],
            null,
            true
        );
    }

    public static function handleSave(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'abcnorio-func'));
        }

        $names = $_POST['name'] ?? [];
        $icons = $_POST['icon_id'] ?? [];
        $urls = $_POST['url'] ?? [];
        $links = [];

        foreach ($names as $i => $name) {
            $name = sanitize_text_field(wp_unslash($name));
            $url = esc_url_raw(wp_unslash($urls[$i] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }
            $links[] = [
                'name' => $name,
                'icon_id' => absint($icons[$i] ?? 0),
                'url' => $url,
            ];
        }

        update_option(self::OPTION, $links);
        wp_safe_redirect(add_query_arg(['page' => SettingsPage::SLUG, 'social-links-updated' => 1], admin_url('options-general.php')));
        exit;
    }

    public static function renderSection(): void
    {
        $links = get_option(self::OPTION, []);
        ?>
        <section>
            <h2><?php esc_html_e('Social Links', 'abcnorio-func'); ?></h2>
            <?php if (isset($_GET['social-links-updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'abcnorio-func'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION); ?>
                <p class="description"><?php esc_html_e('Drag rows to reorder.', 'abcnorio-func'); ?></p>
                <ul id="social-links-list" style="max-width:800px">
                    <?php foreach ($links as $link) : ?>
                        <?php self::renderRow($link); ?>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <button type="button" class="button" id="social-links-add"><?php esc_html_e('Add link', 'abcnorio-func'); ?></button>
                    <?php submit_button(__('Save', 'abcnorio-func'), 'primary', 'submit', false); ?>
                </p>
            </form>
            <template id="social-links-template">
                <?php self::renderRow(['name' => '', 'icon_id' => 0, 'url' => '']); ?>
            </template>
        </section>
        <?php
    }

    private static function renderRow(array $link): void
    {
        $iconUrl = $link['icon_id'] ? wp_get_attachment_url($link['icon_id']) : '';
        ?>
        <li class="social-link-row" draggable="true" style="display:flex;gap:8px;align-items:center;padding:8px;background:#fff;border:1px solid #ccd0d4;margin-bottom:4px;cursor:move">
            <span class="dashicons dashicons-menu" aria-hidden="true"></span>
            <input type="text" name="name[]" value="<?php echo esc_attr($link['name']); ?>" placeholder="<?php esc_attr_e('Name', 'abcnorio-func'); ?>" aria-label="<?php esc_attr_e('Name', 'abcnorio-func'); ?>">
            <input type="hidden" name="icon_id[]" value="<?php echo esc_attr($link['icon_id']); ?>">
            <img class="social-link-icon" src="<?php echo esc_url($iconUrl); ?>" alt="" width="24" height="24" <?php echo $iconUrl ? '' : 'hidden'; ?>>
            <button type="button" class="button social-link-upload"><?php esc_html_e('SVG icon', 'abcnorio-func'); ?></button>
            <input type="url" name="url[]" value="<?php echo esc_attr($link['url']); ?>" placeholder="https://" aria-label="<?php esc_attr_e('URL', 'abcnorio-func'); ?>" class="regular-text">
            <button type="button" class="button social-link-remove"><?php esc_html_e('Remove', 'abcnorio-func'); ?></button>
        </li>
        <?php
    }

    public static function registerRestRoutes(): void
    {
        register_rest_route('abcnorio/v1', '/social-links', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'getLinks'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function getLinks(): array
    {
        return array_map(
            static fn ($link) => [
                'name' => $link['name'],
                'icon' => $link['icon_id'] ? wp_get_attachment_url($link['icon_id']) : '',
                'url' => $link['url'],
            ],
            get_option(self::OPTION, [])
        );
    }
}
