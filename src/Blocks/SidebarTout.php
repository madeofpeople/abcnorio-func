<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

final class SidebarTout
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/sidebar-tout')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/sidebar-tout',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($content, $block);

        self::enqueueComponentAssets();

        $title = sanitize_text_field((string) ($attributes['title'] ?? 'Announcement Title'));
        $details = sanitize_textarea_field((string) ($attributes['details'] ?? 'Add details here.'));
        $buttonLabel = sanitize_text_field((string) ($attributes['buttonLabel'] ?? 'Register Here'));
        $buttonUrl = esc_url_raw((string) ($attributes['buttonUrl'] ?? '#'));
        $imageUrl = esc_url_raw((string) ($attributes['imageUrl'] ?? ''));
        $imageAlt = sanitize_text_field((string) ($attributes['imageAlt'] ?? 'Description of the image'));

        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('sidebar-tout.html'));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('aside')->item(0);
        if (! $root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: sidebar-tout fixture root missing.');
        }

        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-sidebar-tout');

        $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " sidebar-tout__title ")]', $root)->item(0);
        $detailsNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " sidebar-tout__details ")]', $root)->item(0);
        $buttonNode = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " sidebar-tout__button ")]', $root)->item(0);
        $imageNode = $xpath->query('.//img[contains(concat(" ", normalize-space(@class), " "), " sidebar-tout__image ")]', $root)->item(0);

        if ($titleNode instanceof \DOMElement) {
            $titleNode->nodeValue = $title;
        }
        if ($detailsNode instanceof \DOMElement) {
            $detailsNode->nodeValue = $details;
        }
        if ($buttonNode instanceof \DOMElement) {
            $buttonNode->nodeValue = $buttonLabel;
            $buttonNode->setAttribute('href', $buttonUrl !== '' ? $buttonUrl : '#');
        }
        if ($imageNode instanceof \DOMElement) {
            if ($imageUrl !== '') {
                $imageNode->setAttribute('src', $imageUrl);
            }
            $imageNode->setAttribute('alt', $imageAlt);
        }

        return trim((string) $dom->saveHTML($root));
    }

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();
        ComponentIngestor::enqueue_component_deps('sidebar-tout');
    }
}
