<?php

namespace abcnorio\CustomFunc\Blocks;

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

        $navigationMode = strtolower(sanitize_key((string) ($attributes['navigationMode'] ?? 'item')));
        if ($navigationMode !== 'page') {
            $navigationMode = 'item';
        }

        $variant = strtolower(sanitize_key((string) ($attributes['variant'] ?? 'slider')));
        if ($variant === '') {
            $variant = 'slider';
        }

        $itemsPerPage = absint((int) ($attributes['itemsPerPage'] ?? 6));
        $itemsPerPage = min(24, max(8, $itemsPerPage ?: 8));

        $className = trim((string) ($attributes['className'] ?? ''));
        $isPagedGrid = $variant === 'paged-grid';
        $isMasonry = $variant === 'masonry';
        $usesBlaze = ! $isPagedGrid && ! $isMasonry;
        $classes = array_filter([
            'gallery',
            $usesBlaze ? 'blaze-slider' : null,
            'gallery--' . $variant,
            'gallery--nav-' . $navigationMode,
            'abcnorio-gallery',
            'abcnorio-gallery--' . $variant,
            'abcnorio-gallery-nav-' . $navigationMode,
            $className !== '' ? $className : null,
        ]);

        $containerClass = $usesBlaze ? 'blaze-container' : '';
        $trackContainerClass = $usesBlaze ? 'blaze-track-container' : '';
        $trackClass = $usesBlaze ? 'images blaze-track' : 'images';

        $dom = HtmlFragmentSupport::loadHtmlFragment(
            '<gallery-listing class="' . esc_attr(implode(' ', $classes)) . '" data-variant="' . esc_attr($variant) . '" data-navigation-mode="' . esc_attr($navigationMode) . '" data-items-per-page="' . esc_attr((string) $itemsPerPage) . '">' .
                '<div' . ($containerClass !== '' ? ' class="' . esc_attr($containerClass) . '"' : '') . '>' .
                    '<div' . ($trackContainerClass !== '' ? ' class="' . esc_attr($trackContainerClass) . '"' : '') . '>' .
                        '<div class="' . esc_attr($trackClass) . '"></div>' .
                    '</div>' .
                    ($usesBlaze ?
                        '<a href="javascript:void(0)" role="button" class="blaze-prev"><span>Previous!</span></a>' .
                        '<a href="javascript:void(0)" role="button" class="blaze-next"><span>Next!</span></a>' .
                        '<div class="blaze-pagination"></div>'
                    : '') .
                '</div>' .
                ($isPagedGrid ? '<nav class="gallery-pagination" aria-label="Gallery pagination" hidden></nav>' : '') .
                ($usesBlaze ? '<nav class="blaze-slide-dots" aria-label="Slide navigation"></nav>' : '') .
            '</gallery-listing>'
        );

        $root = $dom->getElementsByTagName('gallery-listing')->item(0);
        if (! $root instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery root missing.');
        }

        HtmlFragmentSupport::addClass($root, 'wp-block-abcnorio-gallery');

        $track = (new \DOMXPath($dom))->query('.//*[contains(concat(" ", normalize-space(@class), " "), " images ")]', $root)->item(0);
        if (! $track instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: gallery track missing.');
        }

        self::appendGalleryItems($dom, $track, $content, self::imageSizeFor($variant));

        return trim((string) $dom->saveHTML($root));
    }

    private static function imageSizeFor(string $variant): string
    {
        $path = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'resources/vendor/components/dist/gallery-image-sizes.json';
        $sizes = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($sizes[$variant])) {
            throw new \RuntimeException("Gallery image size missing for variant '{$variant}' in gallery-image-sizes.json.");
        }

        return $sizes[$variant];
    }

    private static function appendGalleryItems(\DOMDocument $dom, \DOMElement $track, string $content, string $imageSize): void
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
                    self::applyImageSize($dom, $item, $imageSize);
                }
                $track->appendChild($item);
                continue;
            }

            $item = $dom->createElement('div');
            HtmlFragmentSupport::addClass($item, 'gallery-item');
            $item->appendChild($dom->importNode($child, true));
            self::applyImageSize($dom, $item, $imageSize);
            $track->appendChild($item);
        }
    }

    private static function applyImageSize(\DOMDocument $dom, \DOMElement $item, string $imageSize): void
    {
        $xpath = new \DOMXPath($dom);
        $images = $xpath->query('.//img', $item);
        if (! $images instanceof \DOMNodeList) {
            return;
        }

        foreach (iterator_to_array($images) as $image) {
            if (! $image instanceof \DOMElement) {
                continue;
            }

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

            $replacementDom = HtmlFragmentSupport::loadHtmlFragment('<wrapper>' . $preferredImage . '</wrapper>');
            $replacementImage = $replacementDom->getElementsByTagName('img')->item(0);
            $parent = $image->parentNode;
            if (! $replacementImage instanceof \DOMElement || ! $parent instanceof \DOMNode) {
                continue;
            }

            $parent->replaceChild($dom->importNode($replacementImage, true), $image);
            self::syncFigureSizeClass($parent, $imageSize);
        }
    }

    private static function extractAttachmentId(\DOMElement $image): int
    {
        $className = (string) $image->getAttribute('class');
        if (preg_match('/\\bwp-image-(\\d+)\\b/', $className, $matches) === 1) {
            return (int) $matches[1];
        }

        return (int) $image->getAttribute('data-id');
    }

    private static function syncFigureSizeClass(\DOMNode $node, string $imageSize): void
    {
        $figure = $node instanceof \DOMElement && strtolower($node->tagName) === 'figure'
            ? $node
            : ($node->parentNode instanceof \DOMElement ? $node->parentNode : null);
        if (! $figure instanceof \DOMElement || strtolower($figure->tagName) !== 'figure') {
            return;
        }

        $className = (string) $figure->getAttribute('class');
        $updated = preg_replace('/\\bsize-[^\\s]+\\b/', 'size-' . $imageSize, $className, 1);
        $updated = is_string($updated) ? trim($updated) : trim($className);
        if (! str_contains(' ' . $updated . ' ', ' size-' . $imageSize . ' ')) {
            $updated = trim($updated . ' size-' . $imageSize);
        }
        $figure->setAttribute('class', $updated);
    }
}