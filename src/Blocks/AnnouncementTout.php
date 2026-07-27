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
        unset($block);

        self::enqueueComponentAssets();

        $toast = sanitize_text_field((string) ($attributes['toast'] ?? 'toast'));
        $toastHeadingLevel = self::normalizeToastHeadingLevel($attributes['toastHeadingLevel'] ?? 2);
        $toastTag = 'h' . $toastHeadingLevel;
        $title = sanitize_text_field((string) ($attributes['title'] ?? ''));
        $details = sanitize_textarea_field((string) ($attributes['details'] ?? 'Add details here.'));
        $buttonLabel = sanitize_text_field((string) ($attributes['buttonLabel'] ?? 'Register Here'));
        $buttonUrl = esc_url_raw((string) ($attributes['buttonUrl'] ?? '#'));
        $variant = sanitize_key((string) ($attributes['variant'] ?? 'default'));

        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('announcement-tout.html'));
        $xpath = new \DOMXPath($dom);

        $root = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout ")]')->item(0);

        if (! $root instanceof \DOMElement) {
            $root = $dom->getElementsByTagName('section')->item(0);
        }

        if (! $root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: announcement-tout fixture root missing.');
        }

        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-announcement-tout');
        if ($variant === 'secondary') {
            HtmlFragmentSupport::addClass($root, 'announcement-tout--secondary');
        }

        $toastNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__toast ")]', $root)->item(0);
        $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__title ")]', $root)->item(0);
        $detailsNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__details ")]', $root)->item(0);
        $buttonNode = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__button ")]', $root)->item(0);

        if ($toastNode instanceof \DOMElement) {
            $toastNode = self::retagNode($dom, $toastNode, $toastTag);
            $toastNode->nodeValue = $toast;
        }
        if ($titleNode instanceof \DOMElement) {
            $titleNode->nodeValue = $title;
        }

        $contentNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " announcement-tout__content ")]', $root)->item(0);

        if ($contentNode instanceof \DOMElement) {
            while ($contentNode->firstChild) {
                $contentNode->removeChild($contentNode->firstChild);
            }

            HtmlFragmentSupport::appendHtmlFragment(
                $dom,
                $contentNode,
                self::resolvedContentMarkup($content, $details, $buttonLabel, $buttonUrl),
                'announcement tout content'
            );
        }

        return trim((string) $dom->saveHTML($root));
    }

    private static function resolvedContentMarkup(string $content, string $details, string $buttonLabel, string $buttonUrl): string
    {
        if (trim($content) !== '') {
            return $content;
        }

        $html = '';
        if ($details !== '') {
            $html .= '<div class="announcement-tout__details">' . esc_html($details) . '</div>';
        }

        if ($buttonLabel !== '' && $buttonUrl !== '') {
            $html .= '<div class="announcement-tout__actions">';
            $html .= '<a class="button reversed announcement-tout__button" href="' . esc_url($buttonUrl) . '">';
            $html .= esc_html($buttonLabel);
            $html .= '</a>';
            $html .= '</div>';
        }

        return $html;
    }

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();
        ComponentIngestor::enqueue_component_deps('announcement-tout');
    }

    private static function normalizeToastHeadingLevel($rawLevel): int
    {
        $level = (int) $rawLevel;
        if ($level < 1) {
            return 1;
        }

        if ($level > 6) {
            return 6;
        }

        return $level;
    }

    private static function retagNode(\DOMDocument $dom, \DOMElement $node, string $tagName): \DOMElement
    {
        if (strtolower($node->tagName) === strtolower($tagName)) {
            return $node;
        }

        $replacement = $dom->createElement($tagName);
        foreach ($node->attributes as $attribute) {
            if ($attribute instanceof \DOMAttr) {
                $replacement->setAttribute($attribute->name, $attribute->value);
            }
        }

        while ($node->firstChild) {
            $replacement->appendChild($node->firstChild);
        }

        $node->parentNode?->replaceChild($replacement, $node);

        return $replacement;
    }
}
