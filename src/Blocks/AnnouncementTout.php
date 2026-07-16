<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

final class AnnouncementTout
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/announcement-tout')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/announcement-tout',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($content, $block);

        self::enqueueComponentAssets();

        $toast = sanitize_text_field((string) ($attributes['toast'] ?? 'toast'));
        $title = sanitize_text_field((string) ($attributes['title'] ?? 'Title'));
        $details = sanitize_textarea_field((string) ($attributes['details'] ?? 'Add details here.'));
        $buttonLabel = sanitize_text_field((string) ($attributes['buttonLabel'] ?? 'Register Here'));
        $buttonUrl = esc_url_raw((string) ($attributes['buttonUrl'] ?? '#'));

        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('announcement-tout.html'));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('section')->item(0);
        if (! $root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: announcement-tout fixture root missing.');
        }

        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-announcement-tout');

        $toastNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__toast ")]', $root)->item(0);
        $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__title ")]', $root)->item(0);
        $detailsNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__details ")]', $root)->item(0);
        $buttonNode = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__button ")]', $root)->item(0);

        if ($toastNode instanceof \DOMElement) {
            $toastNode->nodeValue = $toast;
        }
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

        return trim((string) $dom->saveHTML($root));
    }

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();
        ComponentIngestor::enqueue_component_deps('announcement-tout');
    }
}
