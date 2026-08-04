<?php

namespace abcnorio\CustomFunc\Blocks;

final class Gallery
{
    private const SLIDER_IMAGE_SIZE = 'galery-slider';

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/gallery')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/gallery',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($block);

        $navigationMode = strtolower(sanitize_key((string) ($attributes['navigationMode'] ?? 'item')));
        if ($navigationMode !== 'page') {
            $navigationMode = 'item';
        }

        $variant = strtolower(sanitize_key((string) ($attributes['variant'] ?? 'slider')));
        if ($variant === '') {
            $variant = 'slider';
        }

        $className = trim((string) ($attributes['className'] ?? ''));
        $classes = array_filter([
            'gallery',
            'blaze-slider',
            'gallery--' . $variant,
            'gallery--nav-' . $navigationMode,
            'abcnorio-gallery',
            'abcnorio-gallery--' . $variant,
            'abcnorio-gallery-nav-' . $navigationMode,
            $className !== '' ? $className : null,
        ]);

        $dom = HtmlFragmentSupport::loadHtmlFragment(
            '<gallery-listing class="' . esc_attr(implode(' ', $classes)) . '" data-variant="' . esc_attr($variant) . '" data-navigation-mode="' . esc_attr($navigationMode) . '">' .
                '<div class="blaze-container">' .
                    '<div class="blaze-track-container">' .
                        '<div class="images blaze-track"></div>' .
                    '</div>' .
                    '<a href="javascript:void(0)" role="button" class="blaze-prev"><span>Previous!</span></a>' .
                    '<a href="javascript:void(0)" role="button" class="blaze-next"><span>Next!</span></a>' .
                    '<div class="blaze-pagination"></div>' .
                '</div>' .
                '<nav class="blaze-slide-dots" aria-label="Slide navigation"></nav>' .
            '</gallery-listing>'
        );

        $root = $dom->getElementsByTagName('gallery-listing')->item(0);
        if (! $root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery root missing.');
        }

        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-gallery');

        $track = (new \DOMXPath($dom))->query('.//*[contains(concat(" ", normalize-space(@class), " "), " images ") and contains(concat(" ", normalize-space(@class), " "), " blaze-track ")]', $root)->item(0);
        if (! $track instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery track missing.');
        }

        self::appendGalleryItems($dom, $track, $content);

        return trim((string) $dom->saveHTML($root));
    }

    private static function appendGalleryItems(\DOMDocument $dom, \DOMElement $track, string $content): void
    {
        if (trim($content) === '') {
            return;
        }

        $fragment = HtmlFragmentSupport::loadHtmlFragment('<wrapper>' . $content . '</wrapper>');
        $wrapper = $fragment->getElementsByTagName('wrapper')->item(0);
        if (! $wrapper instanceof \DOMElement) {
            return;
        }

        while ($wrapper->firstChild) {
            $child = $wrapper->firstChild;
            $wrapper->removeChild($child);

            if ($child instanceof \DOMText && trim($child->wholeText) === '') {
                continue;
            }

            if ($child instanceof \DOMElement && str_contains(' ' . ($child->getAttribute('class') ?? '') . ' ', ' gallery-item ')) {
                $item = $dom->importNode($child, true);
                if ($item instanceof \DOMElement) {
                    self::applyPreferredSliderImageSize($dom, $item);
                }

                $track->appendChild($item);
                continue;
            }

            $item = $dom->createElement('div');
            HtmlFragmentSupport::addClass($item, 'gallery-item');
            $item->appendChild($dom->importNode($child, true));
            self::applyPreferredSliderImageSize($dom, $item);
            $track->appendChild($item);
        }
    }

    private static function applyPreferredSliderImageSize(\DOMDocument $dom, \DOMElement $item): void
    {
        $xpath = new \DOMXPath($dom);
        $images = $xpath->query('.//img', $item);

        if (! $images instanceof \DOMNodeList || $images->length === 0) {
            return;
        }

        $targets = [];
        foreach ($images as $image) {
            if ($image instanceof \DOMElement) {
                $targets[] = $image;
            }
        }

        foreach ($targets as $image) {
            $attachmentId = self::extractAttachmentId($image);
            if ($attachmentId < 1) {
                continue;
            }

            $className = trim((string) $image->getAttribute('class'));
            $attributes = $className === '' ? [] : ['class' => $className];

            $preferredImage = wp_get_attachment_image($attachmentId, self::SLIDER_IMAGE_SIZE, false, $attributes);
            if ($preferredImage === '') {
                continue;
            }

            $replacementDom = HtmlFragmentSupport::loadHtmlFragment('<wrapper>' . $preferredImage . '</wrapper>');
            $replacementImage = $replacementDom->getElementsByTagName('img')->item(0);
            if (! $replacementImage instanceof \DOMElement) {
                continue;
            }

            $imported = $dom->importNode($replacementImage, true);
            $parent = $image->parentNode;
            if (! $parent instanceof \DOMNode) {
                continue;
            }

            $parent->replaceChild($imported, $image);
            self::syncFigureSizeClass($parent);
        }
    }

    private static function extractAttachmentId(\DOMElement $image): int
    {
        $className = (string) $image->getAttribute('class');
        if (preg_match('/\\bwp-image-(\\d+)\\b/', $className, $matches) === 1) {
            return (int) $matches[1];
        }

        $dataId = (int) $image->getAttribute('data-id');
        if ($dataId > 0) {
            return $dataId;
        }

        return 0;
    }

    private static function syncFigureSizeClass(\DOMNode $node): void
    {
        if (! $node instanceof \DOMElement) {
            return;
        }

        $figure = $node;
        if (strtolower($figure->tagName) !== 'figure') {
            $figure = $node->parentNode instanceof \DOMElement ? $node->parentNode : $node;
        }

        if (! $figure instanceof \DOMElement || strtolower($figure->tagName) !== 'figure') {
            return;
        }

        $className = (string) $figure->getAttribute('class');
        if ($className === '') {
            HtmlFragmentSupport::addClass($figure, 'size-' . self::SLIDER_IMAGE_SIZE);
            return;
        }

        $updated = preg_replace('/\\bsize-[^\\s]+\\b/', 'size-' . self::SLIDER_IMAGE_SIZE, $className, 1);
        $updated = is_string($updated) ? trim($updated) : trim($className);

        if ($updated === $className && ! str_contains(' ' . $className . ' ', ' size-' . self::SLIDER_IMAGE_SIZE . ' ')) {
            $updated .= ' size-' . self::SLIDER_IMAGE_SIZE;
        }

        $figure->setAttribute('class', trim($updated));
    }
}