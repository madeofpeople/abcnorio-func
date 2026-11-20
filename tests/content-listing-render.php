<?php

use abcnorio\CustomFunc\Blocks\ContentListingQuery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;
use abcnorio\CustomFunc\RestApi\ContentListingEndpoint;

$verify = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$render = new ReflectionMethod(ContentListingQuery::class, 'renderListingFromDist');
$event = new ReflectionMethod(ContentListingQuery::class, 'renderEventItemFromDist');
$article = new ReflectionMethod(ContentListingQuery::class, 'renderArticleItemFromDist');
$item = [
    'slug' => 'actual-item',
    'title' => ['rendered' => 'Actual title'],
    'excerpt' => ['rendered' => "<p>Actual <strong>excerpt</strong> caf\u{00e9} \u{2026}</p><script>bad()</script>"],
    'date' => '2025-01-01 10:00:00',
    'acf' => ['event_start_date' => '2026-07-04 19:30:00', 'article_date' => '2026-06-10'],
];
$eventHtml = $event->invoke(null, $item);
$articleHtml = $article->invoke(null, $item);
$eventDom = HtmlFragmentSupport::loadHtmlFragment($eventHtml);
$verify($eventDom->getElementsByTagName('time')->item(0)->getElementsByTagName('span')->length === 2, 'Event teaser must use the shared date/time structure.');
$verify(str_contains($eventHtml, 'Jul 4, 2026') && !str_contains($eventHtml, 'Fixture'), 'Event date must replace fixture text.');
$verify(str_contains($articleHtml, 'Jun 10, 2026') && !str_contains($articleHtml, 'Jan 1, 2025'), 'Article date must use article_date before publication date.');
$verify(str_contains($articleHtml, '<strong>excerpt</strong>') && !str_contains($articleHtml, '<script>'), 'Article excerpt must replace sample HTML and be sanitized.');
$verify(str_contains($articleHtml, "caf\u{00e9} \u{2026}"), 'Article excerpts must preserve UTF-8 characters.');
$verify(str_contains($articleHtml, 'no-img_placeholder') && !str_contains($articleHtml, '<img'), 'Image-less article must use the placeholder fixture.');
$withImage = $item;
$withImage['featured_image'] = ['url' => 'https://example.test/actual.webp', 'alt' => 'Actual alt', 'width' => 320, 'height' => 240];
$imageHtml = $article->invoke(null, $withImage);
$verify(str_contains($imageHtml, 'actual.webp') && !str_contains($imageHtml, 'no-img_placeholder'), 'Article image must replace sample image, without a placeholder.');
$item['acf'] = [];
$item['date'] = '';
$item['excerpt'] = ['rendered' => ''];
$withoutDate = $article->invoke(null, $item);
$verify(!str_contains($withoutDate, '<time') && !str_contains($withoutDate, 'Sample excerpt'), 'Empty fields must not retain fixture content.');
$verify(!str_contains($event->invoke(null, $item), '<time'), 'Undated event must not retain fixture time.');

foreach (['grid', 'slider'] as $variant) {
    foreach ([false, true] as $enabled) {
        foreach ([0, 1, 2] as $count) {
            $attributes = ['variant' => $variant, 'title' => $enabled ? 'Actual listing' : '', 'showCount' => $enabled,
                'hasCta' => $enabled, 'ctaLabel' => 'Read more', 'ctaUrl' => '/actual/'];
            $cards = $count === 0 ? '' : $eventHtml . ($count === 2 ? $articleHtml : '');
            $html = $render->invoke(null, $count, $cards, $attributes);
            $dom = HtmlFragmentSupport::loadHtmlFragment($html);
            $xpath = new DOMXPath($dom);
            $verify($dom->getElementsByTagName('event-teaser')->length + $dom->getElementsByTagName('article-teaser')->length === $count, 'Actual cards must replace all fixture cards.');
            $verify($xpath->query('//h2')->length === (int) $enabled, 'Title visibility must follow saved attributes.');
            $verify($xpath->query('//*[contains(@class,"content-listing__count")]')->length === (int) $enabled, 'Count visibility must follow saved attributes.');
            $verify($xpath->query('//a[contains(@class,"content-listing__cta") and @href="/actual/"]')->length === (int) $enabled, 'CTA must follow saved attributes.');
            $verify($xpath->query('//*[contains(@class,"blaze-track-container")]')->length === (int) ($variant === 'slider'), 'Variant must select the matching fixture structure.');
            if ($variant === 'slider') {
                $verify($xpath->query('//button[contains(@class,"blaze-next") and @type="button"]')->length === 1, 'Slider controls must be fixture-owned buttons.');
            }
            $verify(!str_contains($html, 'data-astro-cid-') && !str_contains($html, '<link'), 'Rendered root must not require Astro scope attributes or contain stylesheet links.');
        }
    }
}

$request = new WP_REST_Request('GET', '/abcnorio/v1/content-listing');
$request->set_param('count', 6);
$request->set_param('post_types', ['event', 'article']);
$request->set_param('order', 'asc');
$items = ContentListingEndpoint::serve($request)->get_data();
$verify(count($items) >= 2, 'Integration checks require at least two published items.');
$ids = array_reverse(array_column($items, 'id'));
foreach (['asc', 'desc', 'manual'] as $order) {
    $request->set_param('order', $order === 'asc' ? 'asc' : 'desc');
    $request->set_param('orderIds', $order === 'manual' ? $ids : []);
    $expected = ContentListingEndpoint::serve($request)->get_data();
    $html = ContentListingQuery::render(['listingCount' => 6, 'listingPostTypes' => ['event', 'article'], 'order' => $order,
        'sortOrderPostIds' => $ids, 'variant' => 'slider']);
    $dom = HtmlFragmentSupport::loadHtmlFragment($html);
    $xpath = new DOMXPath($dom);
    $actual = [];
    foreach ($xpath->query('//event-teaser/a | //article-teaser/a') as $link) {
        $actual[] = $link->getAttribute('href');
    }
    $verify($actual === array_map(static fn($entry) => get_permalink($entry['id']), $expected), 'Block must preserve endpoint item ordering for ' . $order . '.');
}

$created = [];
$tagSlug = 'content-listing-order-' . wp_generate_uuid4();
$tag = wp_insert_term($tagSlug, 'post_tag');
$verify(!is_wp_error($tag), 'Regression tag must be created.');
$create = static function (string $postType, string $date, bool $tagged = true) use (&$created, $tag, $verify): int {
    $id = wp_insert_post([
        'post_type' => $postType, 'post_status' => 'publish',
        'post_title' => 'Content listing order regression', 'post_date' => $date,
    ], true);
    $verify(!is_wp_error($id), 'Regression content must be created.');
    $created[] = $id;
    if ($tagged) {
        $result = wp_set_object_terms($id, [(int) $tag['term_id']], 'post_tag');
        $verify(!is_wp_error($result), 'Regression tag must be assigned.');
    }
    return $id;
};
try {
    for ($day = 1; $day <= 25; $day++) {
        $id = $create('article', sprintf('2000-01-%02d 10:00:00', $day));
        if ($day === 1) {
            $oldArticle = $id;
        }
    }
    $eventId = $create('event', '2001-01-01 10:00:00');
    update_post_meta($eventId, 'event_start_date', '2029-07-04 19:30:00');
    $excludedId = $create('article', '2000-01-01 10:00:00', false);
    $request->set_param('post_types', ['event', 'article']);
    $request->set_param('tags', [$tagSlug]);
    $request->set_param('count', 6);
    $request->set_param('order', 'desc');
    $request->set_param('orderIds', []);
    $recentIds = array_column(ContentListingEndpoint::serve($request)->get_data(), 'id');
    $verify(!in_array($oldArticle, $recentIds, true), 'Old article must be outside the default limited query.');
    $request->set_param('orderIds', [$excludedId, $oldArticle, $eventId]);
    $manualItems = ContentListingEndpoint::serve($request)->get_data();
    $manualIds = array_column($manualItems, 'id');
    $verify(array_slice($manualIds, 0, 2) === [$oldArticle, $eventId], 'Matching saved IDs must be prioritized even outside the latest query window.');
    $verify(count($manualIds) === 6 && !in_array($excludedId, $manualIds, true), 'Tag filters must apply before manual ordering and unranked items must fill remaining slots.');
    $request->set_param('post_types', ['article']);
    $articleIds = array_column(ContentListingEndpoint::serve($request)->get_data(), 'id');
    $verify($articleIds[0] === $oldArticle && !in_array($eventId, $articleIds, true), 'Post-type filters must exclude manually ranked events.');

    foreach ([1 => 6, 24 => 24, 99 => 24] as $savedCount => $expectedCount) {
        $request->set_param('count', $expectedCount);
        $expected = ContentListingEndpoint::serve($request)->get_data();
        $html = ContentListingQuery::render([
            'listingCount' => $savedCount, 'listingPostTypes' => ['article'],
            'listingTagFilter' => [$tagSlug], 'order' => 'manual',
            'sortOrderPostIds' => [$excludedId, $oldArticle, $eventId], 'variant' => 'grid',
        ]);
        $xpath = new DOMXPath(HtmlFragmentSupport::loadHtmlFragment($html));
        $links = [];
        foreach ($xpath->query('//article-teaser/a') as $link) {
            $links[] = $link->getAttribute('href');
        }
        $verify(count($links) === $expectedCount, 'Block count must respect the 6-24 bounds.');
        $verify($links === array_map(static fn($entry) => get_permalink($entry['id']), $expected), 'Manual preview must match the actual filtered endpoint selection and order.');
    }
    $request->set_param('tags', [$tagSlug . '-missing']);
    $verify(ContentListingEndpoint::serve($request)->get_data() === [], 'Saved manual IDs must not bypass an unmatched tag filter.');
} finally {
    foreach ($created as $id) {
        wp_delete_post($id, true);
    }
    wp_delete_term($tag['term_id'], 'post_tag');
}

echo "Content listing render checks passed (variants, fields, filtered manual selection/order and 6-24 count bounds).\n";
