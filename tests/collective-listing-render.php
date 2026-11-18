<?php

use abcnorio\CustomFunc\Blocks\CollectiveListingQuery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;

$render = new ReflectionMethod(CollectiveListingQuery::class, 'renderListingFromDist');
$card = new ReflectionMethod(CollectiveListingQuery::class, 'renderCollectiveCardFromDist');
foreach ([null, ['url' => 'https://example.test/collective.jpg', 'alt' => 'Collective', 'width' => 320, 'height' => 240]] as $image) {
    $html = $card->invoke(null, [
        'href' => '/facilities/example/', 'title' => 'Actual collective',
        'excerpt' => '<p>Actual <strong>details</strong></p>', 'featured_image' => $image,
    ]);
    $dom = HtmlFragmentSupport::loadHtmlFragment($html);
    if ($dom->getElementsByTagName('h3')->item(0)->textContent !== 'Actual collective'
        || $dom->getElementsByTagName('time')->length !== 0
        || $dom->getElementsByTagName('img')->length !== ($image === null ? 0 : 1)
        || !str_contains($html, '<strong>details</strong>')) {
        throw new RuntimeException('Collective teaser must populate component markup without event/date presentation.');
    }
    foreach ([null, 'Displaying 1 item.'] as $label) {
        $listing = HtmlFragmentSupport::loadHtmlFragment($render->invoke(null, $label, $html));
        $counts = (new DOMXPath($listing))->query('//*[contains(concat(" ", normalize-space(@class), " "), " collective-listing__count ")]');
        if ($counts->length !== ($label === null ? 0 : 1)
            || ($label !== null && $counts->item(0)->textContent !== $label)
            || $listing->getElementsByTagName('collective-teaser')->length !== 1) {
            throw new RuntimeException('Collective listing must populate the fixture count and actual teaser.');
        }
    }
}
echo "Collective listing/teaser fixture population, counts, rich excerpts and optional images passed.\n";
