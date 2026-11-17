<?php

use abcnorio\CustomFunc\Blocks\ContentListingQuery;
use abcnorio\CustomFunc\Blocks\EventListingQuery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;

$verify = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$keys = ['slidesPerPage', 'slidesPerPageXs', 'slidesPerPageSm', 'slidesPerPageMd', 'slidesPerPageLg'];
$names = ['data-slides-per-page', 'data-slides-per-page-xs', 'data-slides-per-page-sm', 'data-slides-per-page-md', 'data-slides-per-page-lg'];
foreach (['event-listing', 'content-listing'] as $component) {
    $render = new ReflectionMethod($component === 'event-listing' ? EventListingQuery::class : ContentListingQuery::class, 'renderListingFromDist');
    $type = WP_Block_Type_Registry::get_instance()->get_registered('abcnorio/' . $component);
    foreach ($keys as $key) {
        $verify(($type->attributes[$key]['type'] ?? '') === 'number', 'Responsive attribute must be registered: ' . $key);
    }
    $renderRoot = static function (array $attrs) use ($component, $render): DOMElement {
        $html = $component === 'event-listing'
            ? $render->invoke(null, null, '<event-teaser>Actual event</event-teaser>', ($attrs['variant'] ?? '') === 'slider', '', false, '', '', $attrs)
            : $render->invoke(null, 1, '<event-teaser>Actual event</event-teaser>', $attrs);
        return HtmlFragmentSupport::loadHtmlFragment($html)->getElementsByTagName($component)->item(0);
    };
    $root = $renderRoot([]);
    foreach ($keys as $index => $key) {
        $verify((float) $root->getAttribute($names[$index]) === (float) $type->attributes[$key]['default'], 'PHP defaults must match registered schema: ' . $key);
    }
    $counts = [1.333, 2.333, 3.333, 4.333, 3.5];
    $attrs = array_combine($keys, $counts) + ['variant' => 'slider', 'gridColumns' => 'grid-3'];
    $saved = serialize_block(['blockName' => 'abcnorio/' . $component, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
    $parsed = parse_blocks($saved)[0]['attrs'];
    $prepared = $type->prepare_attributes_for_render($parsed);
    $root = $renderRoot($prepared);
    foreach ($names as $index => $name) {
        $verify((float) $root->getAttribute($name) === $counts[$index], 'Fractional value lost between saved attributes and PHP output: ' . $name);
    }
    $verify(!str_contains($root->getAttribute('class'), 'grid-3'), 'Slider must not carry grid-column classes.');
    foreach (['grid-3', 'grid-4'] as $columns) {
        $root = $renderRoot(['variant' => 'grid', 'gridColumns' => $columns]);
        $verify(str_contains($root->getAttribute('class'), $columns), 'Grid choice must reach the rendered root.');
        $legacy = $renderRoot($type->prepare_attributes_for_render(['variant' => $columns]));
        $verify(str_contains($legacy->getAttribute('class'), $columns), 'Legacy grid variants must survive schema defaults.');
    }
    $root = $renderRoot(['slidesPerPage' => -5, 'slidesPerPageXs' => 100, 'slidesPerPageSm' => 'invalid']);
    $verify($root->getAttribute($names[0]) === '1' && $root->getAttribute($names[1]) === '6' && $root->getAttribute($names[2]) === '3', 'PHP must clamp extremes and default invalid input.');
    $verify(!str_contains($saved, 'itemCount') && !str_contains($saved, 'listingCount'), 'Visible items must remain separate from total query count.');
}
echo "Listing layout checks passed: schema, saved values, fractional forwarding, defaults, grid choices and legacy variants.\n";
