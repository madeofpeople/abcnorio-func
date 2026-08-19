<?php

namespace abcnorio\CustomFunc\ContentModel;

final class PublicFormGroups
{
    private const META_ENABLED = '_abcnorio_public_form_enabled';
    private const META_SLUG = '_abcnorio_public_form_slug';
    private const META_CIVI_ENDPOINT_URL = '_abcnorio_public_form_civi_endpoint_url';
    private const META_TO_EMAIL = '_abcnorio_public_form_to_email';
    private const LEGACY_META_RECIPIENT_EMAIL = '_abcnorio_public_form_recipient_email';
    private const NONCE_ACTION = 'abcnorio_public_form_group';
    private const NONCE_NAME = 'abcnorio_public_form_group_nonce';

    public static function registerHooks(): void
    {
        add_action('add_meta_boxes_acf-field-group', [self::class, 'registerMetaBox']);
        add_action('save_post_acf-field-group', [self::class, 'saveMetaBox']);
        add_action('admin_notices', [self::class, 'renderIncompleteConfigNotice']);
        add_filter('redirect_post_location', [self::class, 'addIncompleteConfigNoticeQueryArg']);
    }

    public static function registerMetaBox(): void
    {
        add_meta_box(
            'abcnorio-public-form-group',
            __('Public Form', 'abcnorio-func'),
            [self::class, 'renderMetaBox'],
            'acf-field-group',
            'side',
            'default'
        );
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        $enabled = (bool) get_post_meta($post->ID, self::META_ENABLED, true);
        $slug = (string) get_post_meta($post->ID, self::META_SLUG, true);
        $civiEndpointUrl = self::civiEndpointUrl($post->ID);
        $toEmail = self::toEmail($post->ID);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        echo '<p>';
        echo '<label for="abcnorio-public-form-enabled">';
        echo '<input type="checkbox" id="abcnorio-public-form-enabled" name="abcnorio_public_form_enabled" value="1" ' . checked($enabled, true, false) . ' /> ';
        echo esc_html__('Expose this ACF group as a public form', 'abcnorio-func');
        echo '</label>';
        echo '</p>';

        echo '<p>';
        echo '<label for="abcnorio-public-form-civi-endpoint-url"><strong>' . esc_html__('Civi Endpoint URL', 'abcnorio-func') . '</strong></label><br />';
        echo '<input type="url" id="abcnorio-public-form-civi-endpoint-url" name="abcnorio_public_form_civi_endpoint_url" value="' . esc_attr($civiEndpointUrl) . '" class="widefat" placeholder="https://crm.example.org/forms/submit" />';
        echo '</p>';

        echo '<p style="margin-top:0;color:#666;font-size:12px;">';
        echo esc_html__('Optional. If present, public submissions are sent to Civi and TO Email is ignored.', 'abcnorio-func');
        echo '</p>';

        echo '<p>';
        echo '<label for="abcnorio-public-form-slug"><strong>' . esc_html__('Form slug', 'abcnorio-func') . '</strong></label><br />';
        echo '<input type="text" id="abcnorio-public-form-slug" name="abcnorio_public_form_slug" value="' . esc_attr($slug) . '" class="widefat" placeholder="volunteer" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="abcnorio-public-form-to-email"><strong>' . esc_html__('To Email', 'abcnorio-func') . '</strong></label><br />';
        echo '<input type="email" id="abcnorio-public-form-to-email" name="abcnorio_public_form_to_email" value="' . esc_attr($toEmail) . '" class="widefat" placeholder="mailbox@example.org" />';
        echo '</p>';

        echo '<p style="margin-top:0;color:#666;font-size:12px;">';
        echo esc_html__('Used only when Civi Endpoint URL is empty.', 'abcnorio-func');
        echo '</p>';

        echo '<p style="margin-top:0;color:#666;font-size:12px;">';
        echo esc_html__('Used by API and frontend as form_slug (letters, numbers, hyphens).', 'abcnorio-func');
        echo '</p>';
    }

    public static function saveMetaBox(int $postId): void
    {
        if (! isset($_POST[self::NONCE_NAME]) || ! wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        $enabled = isset($_POST['abcnorio_public_form_enabled']) ? '1' : '0';
        $slug = sanitize_title((string) ($_POST['abcnorio_public_form_slug'] ?? ''));
        $civiEndpointUrl = self::sanitizeCiviEndpointUrl((string) ($_POST['abcnorio_public_form_civi_endpoint_url'] ?? ''));
        $toEmail = sanitize_email((string) ($_POST['abcnorio_public_form_to_email'] ?? ''));

        update_post_meta($postId, self::META_ENABLED, $enabled);

        if ($civiEndpointUrl === '') {
            delete_post_meta($postId, self::META_CIVI_ENDPOINT_URL);
        } else {
            update_post_meta($postId, self::META_CIVI_ENDPOINT_URL, $civiEndpointUrl);
        }

        if ($toEmail === '') {
            delete_post_meta($postId, self::META_TO_EMAIL);
        } else {
            update_post_meta($postId, self::META_TO_EMAIL, $toEmail);
        }

        delete_post_meta($postId, self::LEGACY_META_RECIPIENT_EMAIL);

        if ($slug === '') {
            delete_post_meta($postId, self::META_SLUG);
            return;
        }

        update_post_meta($postId, self::META_SLUG, $slug);
    }

    public static function renderIncompleteConfigNotice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->post_type !== 'acf-field-group') {
            return;
        }

        if (empty($_GET['abcnorio_form_notice']) || $_GET['abcnorio_form_notice'] !== 'incomplete-config') {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo esc_html__('Public form is enabled but not fully configured yet. Add a Civi Endpoint URL or a To Email address.', 'abcnorio-func');
        echo '</p></div>';
    }

    public static function addIncompleteConfigNoticeQueryArg(string $location): string
    {
        if (! isset($_POST[self::NONCE_NAME]) || ! wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            return $location;
        }

        $enabled = isset($_POST['abcnorio_public_form_enabled']) ? '1' : '0';
        $civiEndpointUrl = self::sanitizeCiviEndpointUrl((string) ($_POST['abcnorio_public_form_civi_endpoint_url'] ?? ''));
        $toEmail = sanitize_email((string) ($_POST['abcnorio_public_form_to_email'] ?? ''));

        if ($enabled !== '1' || ($civiEndpointUrl !== '' || $toEmail !== '')) {
            return $location;
        }

        return add_query_arg('abcnorio_form_notice', 'incomplete-config', $location);
    }

    public static function isEnabled(int $postId): bool
    {
        return get_post_meta($postId, self::META_ENABLED, true) === '1';
    }

    public static function slug(int $postId): string
    {
        return (string) get_post_meta($postId, self::META_SLUG, true);
    }

    public static function civiEndpointUrl(int $postId): string
    {
        $url = (string) get_post_meta($postId, self::META_CIVI_ENDPOINT_URL, true);
        return self::sanitizeCiviEndpointUrl($url);
    }

    public static function toEmail(int $postId): string
    {
        $email = (string) get_post_meta($postId, self::META_TO_EMAIL, true);
        if (! is_email($email)) {
            $email = (string) get_post_meta($postId, self::LEGACY_META_RECIPIENT_EMAIL, true);
        }

        return is_email($email) ? $email : '';
    }

    public static function hasDeliveryTarget(int $postId): bool
    {
        return self::hasDeliveryTargetValues(self::civiEndpointUrl($postId), self::toEmail($postId));
    }

    private static function hasDeliveryTargetValues(string $civiEndpointUrl, string $toEmail): bool
    {
        return $civiEndpointUrl !== '' || $toEmail !== '';
    }

    private static function sanitizeCiviEndpointUrl(string $url): string
    {
        $sanitized = esc_url_raw(trim($url));
        if ($sanitized === '') {
            return '';
        }

        return wp_http_validate_url($sanitized) ? $sanitized : '';
    }
}
