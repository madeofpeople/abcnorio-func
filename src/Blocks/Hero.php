<?php

namespace abcnorio\CustomFunc\Blocks;

final class Hero
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/hero')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/hero',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($block);

        $url = esc_url_raw((string) ($attributes['url'] ?? ''));

        $style = self::heroStyle($url);
        $renderedContent = self::ensureContentWrappers($content);

        $html = '';
        $html .= '<div class="abcnorio-hero wp-block-abcnorio-hero">';
        $html .= '<div class="abcnorio-hero__image-wrapper" style="' . esc_attr($style) . '">';
        $html .= $renderedContent;
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function ensureContentWrappers(string $content): string
    {
        if (str_contains($content, 'abcnorio-hero__content-wrapper')) {
            return $content;
        }

        return '<div class="abcnorio-hero__content-wrapper"><div class="abcnorio-hero__content">' . $content . '</div></div>';
    }

    private static function heroStyle(string $url): string
    {
        if ($url === '') {
            return '--cover-bg-xs:none;--cover-bg-sm:none;--cover-bg-md:none;--cover-bg-lg:none;';
        }

        $escapedUrl = esc_url($url);
        $bg = "url('{$escapedUrl}')";

        return "--cover-bg-xs:{$bg};--cover-bg-sm:{$bg};--cover-bg-md:{$bg};--cover-bg-lg:{$bg};";
    }
}
