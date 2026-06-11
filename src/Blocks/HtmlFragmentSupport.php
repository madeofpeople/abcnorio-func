<?php

namespace abcnorio\CustomFunc\Blocks;

final class HtmlFragmentSupport
{
    public static function loadHtmlFragment(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    public static function appendHtmlFragment(\DOMDocument $dom, \DOMElement $parent, string $html, string $context): void
    {
        if ($html === '') {
            return;
        }

        $fragmentDom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $fragmentDom->loadHTML(
            '<wrapper>' . $html . '</wrapper>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new \RuntimeException(sprintf('Components System Error: Failed appending rendered %s items.', $context));
        }

        $wrapper = $fragmentDom->getElementsByTagName('wrapper')->item(0);
        if (! $wrapper instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: Missing wrapper node while appending rendered items.');
        }

        while ($wrapper->firstChild) {
            $parent->appendChild($dom->importNode($wrapper->firstChild, true));
            $wrapper->removeChild($wrapper->firstChild);
        }
    }

    public static function addClass(\DOMElement $element, string $className): void
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (! in_array($className, $classes, true)) {
            $classes[] = $className;
        }
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    public static function removeClass(\DOMElement $element, string $className): void
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter($classes, static fn (string $class): bool => $class !== $className));
        $element->setAttribute('class', trim(implode(' ', $classes)));
    }

    public static function syncCardImage(
        \DOMDocument $dom,
        \DOMXPath $xpath,
        \DOMElement $article,
        ?array $image,
        string $contentClass,
        ?string $imageClass,
        string $context
    ): void {
        $existing = $xpath->query('.//img', $article)->item(0);

        if ($image === null) {
            if ($existing instanceof \DOMElement) {
                $existing->parentNode?->removeChild($existing);
            }
            return;
        }

        $link = $xpath->query('.//a', $article)->item(0);
        $content = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $contentClass . ' ")]',
            $article
        )->item(0);

        if (! $link instanceof \DOMElement || ! $content instanceof \DOMElement) {
            throw new \RuntimeException(sprintf('Components System Error: %s fixture missing link/content nodes.', $context));
        }

        $img = $existing instanceof \DOMElement ? $existing : $dom->createElement('img');
        if ($imageClass !== null) {
            $img->setAttribute('class', $imageClass);
        }

        $img->setAttribute('src', esc_url((string) ($image['src'] ?? $image['url'] ?? '')));
        $img->setAttribute('alt', (string) ($image['alt'] ?? ''));
        $img->setAttribute('width', (string) ((int) ($image['width'] ?? 0)));
        $img->setAttribute('height', (string) ((int) ($image['height'] ?? 0)));
        $img->setAttribute('decoding', 'async');
        $img->setAttribute('loading', 'lazy');

        if (! empty($image['srcset'])) {
            $img->setAttribute('srcset', (string) $image['srcset']);
        }
        if (! empty($image['sizes'])) {
            $img->setAttribute('sizes', (string) $image['sizes']);
        }

        if (! $existing instanceof \DOMElement) {
            $link->insertBefore($img, $content);
        }
    }
}