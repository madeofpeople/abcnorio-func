<?php

use abcnorio\CustomFunc\Blocks\EventListingQuery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;

$verify = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$render = new ReflectionMethod(EventListingQuery::class, 'renderListingFromDist');

foreach ([false, true] as $slider) {
    foreach ([false, true] as $showAll) {
        $html = $render->invoke(null, null, '<event-teaser>Actual event</event-teaser>', $slider, 'Real title', $showAll, '/events/', 'All events');
        $dom = HtmlFragmentSupport::loadHtmlFragment($html);
        $xpath = new DOMXPath($dom);
        $listing = $dom->getElementsByTagName('event-listing')->item(0);
        $verify($listing instanceof DOMElement, 'Listing root is missing.');
        $scopes = [];
        foreach ($listing->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'data-astro-cid-')) {
                $scopes[] = $attribute->name;
            }
        }
        $verify(count($scopes) === 0, 'Listing must not require generated Astro scope attributes.');
        $verify($dom->getElementsByTagName('event-teaser')->length === 1, 'Sample cards must be replaced.');
        $verify($xpath->query('//*[contains(@class,"blaze-track-container")]')->length === ($slider ? 1 : 0), 'Slider structure is incorrect.');
        $verify($xpath->query('//a[contains(@class,"show-all")]')->length === ($showAll ? 1 : 0), 'View-all link visibility is incorrect.');
        if ($slider) {
            $verify($xpath->query('//button[contains(@class,"blaze-next") and @type="button"]')->length === 1, 'Slider navigation must use a button.');
        }
    }
}

echo "Event listing template checks passed.\n";
