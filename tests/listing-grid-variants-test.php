<?php

namespace abcnorio\CustomFunc\Components {
    final class ComponentIngestor
    {
        public static function readDistHtml(string $fixture): string
        {
            if (str_starts_with($fixture, 'event-listing')) {
                return '<event-listing class="event-listing card-grid"><section id="teaser-list"></section></event-listing>';
            }

            return '<content-listing class="content-listing card-grid"><section id="content-listing-list"></section></content-listing>';
        }
    }
}

namespace {
    require __DIR__ . '/../src/Blocks/HtmlFragmentSupport.php';
    require __DIR__ . '/../src/Blocks/EventListingQuery.php';
    require __DIR__ . '/../src/Blocks/ContentListingQuery.php';

    $slides = ['all' => '1', 'xs' => '2', 'sm' => '3', 'md' => '3', 'lg' => '4'];
    $eventRenderer = new \ReflectionMethod(\abcnorio\CustomFunc\Blocks\EventListingQuery::class, 'renderListingFromDist');
    $contentRenderer = new \ReflectionMethod(\abcnorio\CustomFunc\Blocks\ContentListingQuery::class, 'renderListingFromDist');

    foreach (['grid', 'grid-3', 'grid-4', 'paged-grid'] as $variant) {
        $outputs = [
            $eventRenderer->invoke(null, null, '', false, '', false, '', '', $slides, $variant),
            $contentRenderer->invoke(null, 0, '', false, $slides, $variant),
        ];

        foreach ($outputs as $html) {
            $dom = \abcnorio\CustomFunc\Blocks\HtmlFragmentSupport::loadHtmlFragment($html);
            $root = $dom->getElementsByTagName('event-listing')->item(0)
                ?? $dom->getElementsByTagName('content-listing')->item(0);
            $classes = preg_split('/\s+/', $root->getAttribute('class'));
            foreach (['grid-3', 'grid-4'] as $gridClass) {
                if (in_array($gridClass, $classes, true) !== ($variant === $gridClass)) {
                    throw new \RuntimeException("Incorrect $gridClass class for $variant: $html");
                }
            }
        }
    }

    echo "Listing grid variants rendered correctly.\n";
}
