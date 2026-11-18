<?php

use abcnorio\CustomFunc\Blocks\Gallery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;
use abcnorio\CustomFunc\Components\ComponentIngestor;

foreach (['default', 'slider', 'captioned', 'paged-grid', 'masonry'] as $variant) {
    $content = '<figure class="wp-block-image"><img src="https://example.test/one.jpg" alt="First"><figcaption>A &amp; B &#8212; caf&#233;</figcaption></figure>';
    $content .= '<figure class="wp-block-image"><img src="https://example.test/two.jpg" alt="Second"></figure>';
    $rendered = Gallery::render(['variant' => $variant, 'navigationMode' => 'item', 'itemsPerPage' => 16], $content);
    $dom = HtmlFragmentSupport::loadHtmlFragment('<?xml encoding="UTF-8">' . $rendered);
    $xpath = new DOMXPath($dom);
    $root = $dom->getElementsByTagName('gallery-listing')->item(0);
    $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " images ")]/*');
    $fixture = $variant === 'default' ? 'gallery.html' : "gallery/{$variant}.html";
    $expected = HtmlFragmentSupport::loadHtmlFragment('<?xml encoding="UTF-8">' . ComponentIngestor::readDistHtml($fixture));
    if ($root->getAttribute('data-variant') !== $variant
        || $root->getAttribute('data-navigation-mode') !== 'page'
        || $root->getAttribute('data-items-per-page') !== '16'
        || $items->length !== 2
        || $items->item(0)->getAttribute('class') !== 'gallery-item'
        || $items->item(0)->getElementsByTagName('img')->item(0)->getAttribute('alt') !== 'First'
        || $items->item(1)->getElementsByTagName('img')->item(0)->getAttribute('alt') !== 'Second'
        || $items->item(0)->getElementsByTagName('figcaption')->item(0)->textContent !== "A & B \u{2014} caf\u{00E9}"
        || str_contains($rendered, 'data-editor-')) {
        throw new RuntimeException("Gallery {$variant} fixture population changed media or contract.");
    }
    while ($items->length) {
        $item = $items->item(0);
        $item->parentNode->removeChild($item);
        $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " images ")]/*');
    }
    $expectedRoot = $expected->getElementsByTagName('gallery-listing')->item(0);
    $root->setAttribute('class', $expectedRoot->getAttribute('class'));
    $root->setAttribute('data-items-per-page', $expectedRoot->getAttribute('data-items-per-page'));
    if ($dom->saveHTML($root) !== $expected->saveHTML($expectedRoot)) {
        throw new RuntimeException("Gallery {$variant} PHP shell differs from the component fixture.");
    }
}
echo "Gallery PHP fixture parity, item-template population, captions, order and page navigation passed for all five variants.\n";
