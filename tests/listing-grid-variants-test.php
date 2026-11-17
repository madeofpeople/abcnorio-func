<?php

// Run in WordPress: wp eval-file path/to/listing-grid-variants-test.php

use abcnorio\CustomFunc\Blocks\ContentListingQuery;
use abcnorio\CustomFunc\Blocks\EventListingQuery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;

$eventRenderer = new ReflectionMethod(EventListingQuery::class, 'renderListingFromDist');
$contentRenderer = new ReflectionMethod(ContentListingQuery::class, 'renderListingFromDist');

foreach (['grid', 'grid-3', 'grid-4', 'paged-grid'] as $variant) {
    foreach (['', 'grid-3', 'grid-4'] as $columns) {
        $attributes = ['variant' => $variant, 'gridColumns' => $columns];
        $outputs = [
            $eventRenderer->invoke(null, null, '', false, '', false, '', '', $attributes),
            $contentRenderer->invoke(null, 0, '', $attributes),
        ];
        $expected = $columns ?: (in_array($variant, ['grid-3', 'grid-4'], true) ? $variant : 'grid-4');
        foreach ($outputs as $html) {
            $dom = HtmlFragmentSupport::loadHtmlFragment($html);
            $root = $dom->getElementsByTagName('event-listing')->item(0)
                ?? $dom->getElementsByTagName('content-listing')->item(0);
            $classes = preg_split('/\s+/', $root->getAttribute('class'));
            foreach (['grid-3', 'grid-4'] as $gridClass) {
                if (in_array($gridClass, $classes, true) !== ($expected === $gridClass)) {
                    throw new RuntimeException("Incorrect $gridClass class for $variant / $columns: $html");
                }
            }
        }
    }
}

echo "Listing grid variants rendered correctly.\n";
