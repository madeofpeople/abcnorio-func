<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

final class Gallery
{
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

        $variant = strtolower(sanitize_key((string) ($attributes['variant'] ?? 'slider')));
        if (!in_array($variant, ['default', 'slider', 'captioned', 'paged-grid', 'masonry'], true)) {
            $variant = 'slider';
        }

        $itemsPerPage = absint((int) ($attributes['itemsPerPage'] ?? 6));
        $itemsPerPage = min(24, max(8, $itemsPerPage ?: 8));
        $className = trim((string) ($attributes['className'] ?? ''));
        $fixture = $variant === 'default' ? 'gallery.html' : "gallery/{$variant}.html";
        $dom = HtmlFragmentSupport::loadHtmlFragment('<?xml encoding="UTF-8">' . ComponentIngestor::readDistHtml($fixture));

        $root = $dom->getElementsByTagName('gallery-listing')->item(0);
        if (! $root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery root missing.');
        }

        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-gallery');
        foreach (array_filter(['abcnorio-gallery', 'abcnorio-gallery--' . $variant, 'abcnorio-gallery-nav-page', $className]) as $class) {
            HtmlFragmentSupport::addClass($root, $class);
        }
        $root->setAttribute('data-items-per-page', (string) $itemsPerPage);

        $track = (new \DOMXPath($dom))->query('.//*[contains(concat(" ", normalize-space(@class), " "), " images ")]', $root)->item(0);
        if (! $track instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery track missing.');
        }

        $template = (new \DOMXPath($dom))->query('.//template[@data-gallery-template="item"]/*[1]', $root)->item(0);
        if (!$template instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery item template missing.');
        }
        self::appendGalleryItems($dom, $track, $content, self::imageSizeFor($variant), $template);

        return trim((string) $dom->saveHTML($root));
    }

    private static function imageSizeFor(string $variant): string
    {
        $path = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/gallery/image-sizes.json';
        $sizes = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($sizes[$variant])) {
            throw new \RuntimeException("Gallery image size missing for variant '{$variant}' in image-sizes.json.");
        }

        return $sizes[$variant];
    }

    private static function appendGalleryItems(\DOMDocument $dom, \DOMElement $track, string $content, string $imageSize, \DOMElement $template): void
    {
        if (trim($content) === '') {
            return;
        }

        $fragment = HtmlFragmentSupport::loadHtmlFragment('<?xml encoding="UTF-8"><wrapper>' . $content . '</wrapper>');
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
                    self::applyPreferredSliderImageSize($dom, $item, $imageSize);
                }

                $track->appendChild($item);
                continue;
            }

            $item = $template->cloneNode(true);
            $item->appendChild($dom->importNode($child, true));
            self::applyPreferredSliderImageSize($dom, $item, $imageSize);
            $track->appendChild($item);
        }
    }

    private static function applyPreferredSliderImageSize(\DOMDocument $dom, \DOMElement $item, string $imageSize): void
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

            $preferredImage = wp_get_attachment_image($attachmentId, $imageSize, false, $attributes);
            if ($preferredImage === '') {
                continue;
            }

            $replacementDom = HtmlFragmentSupport::loadHtmlFragment('<?xml encoding="UTF-8"><wrapper>' . $preferredImage . '</wrapper>');
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
            self::syncFigureSizeClass($parent, $imageSize);
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

    private static function syncFigureSizeClass(\DOMNode $node, string $imageSize): void
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
            HtmlFragmentSupport::addClass($figure, 'size-' . $imageSize);
            return;
        }

        $updated = preg_replace('/\\bsize-[^\\s]+\\b/', 'size-' . $imageSize, $className, 1);
        $updated = is_string($updated) ? trim($updated) : trim($className);

        if ($updated === $className && ! str_contains(' ' . $className . ' ', ' size-' . $imageSize . ' ')) {
            $updated .= ' size-' . $imageSize;
        }

        $figure->setAttribute('class', trim($updated));
    }
}