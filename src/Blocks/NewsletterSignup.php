<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

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

        ComponentIngestor::enqueueRuntimeStyles();

        $header = sanitize_text_field((string) ($attributes['header'] ?? 'Newsletter Signup'));
        $subHeader = sanitize_text_field((string) ($attributes['subHeader'] ?? ''));
        $submitText = sanitize_text_field((string) ($attributes['submitText'] ?? 'Subscribe'));

        if ($header === '') {
            $header = 'Newsletter Signup';
        }

        if ($submitText === '') {
            $submitText = 'Subscribe';
        }

        $dom = HtmlFragmentSupport::loadHtmlFragment(
            '<?xml encoding="UTF-8">' . ComponentIngestor::readDistHtml('newsletter-signup/preview.html')
        );
        $xpath = new \DOMXPath($dom);
        $root = $xpath->query('//*[@data-newsletter-preview]')->item(0);
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: newsletter preview root missing.');
        }
        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-newsletter-signup');
        $showRequired = ($attributes['showRequiredIndicator'] ?? true) !== false;
        $requiredText = sanitize_text_field((string) ($attributes['requiredIndicatorText'] ?? ''));
        $values = [
            'header' => $header,
            'sub-header' => $subHeader === '' ? null : $subHeader,
            'required-star' => $showRequired ? ' *' : null,
            'required-text' => $showRequired ? ($requiredText === '' ? '* = required field' : $requiredText) : null,
            'submit' => $submitText,
        ];
        foreach ($values as $name => $value) {
            $nodes = $xpath->query('.//*[@data-newsletter-bind="' . $name . '"]', $root);
            if ($nodes->length !== 1) {
                throw new \RuntimeException("Components System Error: newsletter preview binding missing or duplicated: {$name}.");
            }
            $node = $nodes->item(0);
            if ($value === null) {
                $node->parentNode->removeChild($node);
            } else {
                $node->textContent = $value;
            }
        }
        return trim((string) $dom->saveHTML($root));
    }

}
