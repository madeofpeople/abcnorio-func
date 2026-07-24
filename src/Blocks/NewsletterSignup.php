<?php

namespace abcnorio\CustomFunc\Blocks;

final class NewsletterSignup
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/newsletter-signup')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/newsletter-signup',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($content, $block);

        $header = sanitize_text_field((string) ($attributes['header'] ?? 'Newsletter Signup'));
        $subHeader = sanitize_text_field((string) ($attributes['subHeader'] ?? ''));
        $submitText = sanitize_text_field((string) ($attributes['submitText'] ?? 'Subscribe'));

        if ($header === '') {
            $header = 'Newsletter Signup';
        }

        if ($submitText === '') {
            $submitText = 'Subscribe';
        }

        $html = '';
        $html .= '<div class="wp-block-abcnorio-newsletter-signup">';
        $html .= '<h3 class="abcnorio-newsletter-signup__preview-title">' . esc_html($header) . '</h3>';
        if ($subHeader !== '') {
            $html .= '<p class="abcnorio-newsletter-signup__preview-subtitle">' . esc_html($subHeader) . '</p>';
        }
        $html .= '<div class="abcnorio-newsletter-signup__preview-field">';
        $html .= '<label class="abcnorio-newsletter-signup__preview-label">Email address *</label>';
        $html .= '<div class="abcnorio-newsletter-signup__preview-input"></div>';
        $html .= '</div>';
        $html .= '<button type="button" class="abcnorio-newsletter-signup__preview-button" disabled>' . esc_html($submitText) . '</button>';
        $html .= '</div>';

        return $html;
    }

}
