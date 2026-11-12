<?php

// Run in a WordPress environment: wp eval-file path/to/component-ingestion-test.php

use abcnorio\CustomFunc\Components\ComponentIngestor;
use abcnorio\CustomFunc\Blocks\ContentListingQuery;
use abcnorio\CustomFunc\Blocks\HtmlFragmentSupport;

$cases = [
    'gallery' => [['variant' => 'slider'], ['gallery']],
    'event-listing' => [[
        'dateFilter' => 'alltime',
        'variant' => 'slider',
        'slidesPerPage' => 1,
        'slidesPerPageXs' => 2,
        'slidesPerPageSm' => 3,
        'slidesPerPageMd' => 4,
        'slidesPerPageLg' => 5,
    ], ['event-listing', 'event-teaser']],
    'content-listing' => [[
        'variant' => 'slider',
        'slidesPerPage' => 1,
        'slidesPerPageXs' => 2,
        'slidesPerPageSm' => 3,
        'slidesPerPageMd' => 4,
        'slidesPerPageLg' => 5,
    ], ['content-listing', 'event-teaser', 'article-teaser']],
    'collective-listing' => [[], ['collective-listing', 'collective-teaser']],
    'sidebar-tout' => [[], ['sidebar-tout']],
    'newsletter-signup' => [[], []],
];

$manifest = ComponentIngestor::load();
$adminCss = file_get_contents(dirname(__DIR__) . '/resources/css/admin-overrides.css');
if (preg_match('/(?:\.wp-block-buttons|a\.button|(?:editor-styles-wrapper|block-editor-iframe__body)\s+button\b)/', $adminCss)) {
    throw new RuntimeException('Component button styling belongs in component styles, not admin overrides.');
}
if (! in_array('elements/event-listing.js', $manifest['components']['event-listing']['deps'], true)) {
    throw new RuntimeException('Event Listing module missing from declared dependencies.');
}
set_current_screen('post');
$iframeAssets = _wp_get_iframed_editor_assets();
if (! str_contains($iframeAssets['styles'], '/styles/component-fixtures.css?ver=')) {
    throw new RuntimeException('Component fixture styles missing from editor iframe assets.');
}
if (! preg_match('/<script\b(?=[^>]*\btype=["\']module["\'])(?=[^>]*\bsrc=["\'][^"\']*\/elements\/event-listing\.js(?:\?[^"\']*)?["\'])[^>]*>/', $iframeAssets['scripts'])) {
    throw new RuntimeException('Event Listing module missing from editor iframe assets.');
}
echo "ok event-listing editor iframe module\n";
foreach (['announcement-tout', 'gallery', 'hero', 'event-listing', 'content-listing', 'buttons', 'public-form', 'newsletter-signup'] as $component) {
    $path = "styles/editor/{$component}.css";
    if (($manifest['components'][$component]['editorCss'] ?? []) !== [$path]
        || !str_contains($iframeAssets['styles'], "/{$path}?ver=")
    ) {
        throw new RuntimeException("Component editor stylesheet missing from iframe: {$component}");
    }
}
echo "ok component editor styles in iframe\n";

foreach ($cases as $block => [$attributes, $components]) {
    foreach (wp_styles()->queue as $handle) {
        if (str_starts_with($handle, 'abcnorio-')) {
            wp_dequeue_style($handle);
        }
    }
    foreach (wp_scripts()->queue as $handle) {
        if (str_starts_with($handle, 'abcnorio-')) {
            wp_dequeue_script($handle);
        }
    }

    do_action('enqueue_block_assets');

    $html = render_block([
        'blockName' => 'abcnorio/' . $block,
        'attrs' => $attributes,
        'innerBlocks' => [],
        'innerHTML' => '',
        'innerContent' => [],
    ]);

    if (trim($html) === '') {
        throw new RuntimeException("Empty block output: {$block}");
    }
    if (! wp_style_is('abcnorio-components-runtime', 'enqueued')) {
        throw new RuntimeException("Shared styles not enqueued: {$block}");
    }
    if ($block === 'gallery') {
        $galleryRoot = HtmlFragmentSupport::loadHtmlFragment($html)->getElementsByTagName('gallery-listing')->item(0);
        if (! $galleryRoot instanceof DOMElement) {
            throw new RuntimeException('Gallery slider root missing.');
        }
        foreach ([
            'data-slides-per-page',
            'data-slides-per-page-xs',
            'data-slides-per-page-sm',
            'data-slides-per-page-md',
            'data-slides-per-page-lg',
        ] as $attribute) {
            if ($galleryRoot->hasAttribute($attribute)) {
                throw new RuntimeException('Gallery should not render per-breakpoint slide-count controls.');
            }
        }
    }
    if ($block === 'event-listing' || $block === 'content-listing') {
        $rootTag = $block === 'event-listing' ? 'event-listing' : 'content-listing';
        $listingRoot = HtmlFragmentSupport::loadHtmlFragment($html)->getElementsByTagName($rootTag)->item(0);
        if (! $listingRoot instanceof DOMElement) {
            throw new RuntimeException("{$block} slider root missing.");
        }
        foreach ([
            'data-slides-per-page' => '1',
            'data-slides-per-page-xs' => '2',
            'data-slides-per-page-sm' => '3',
            'data-slides-per-page-md' => '4',
            'data-slides-per-page-lg' => '5',
        ] as $attribute => $expected) {
            if ($listingRoot->getAttribute($attribute) !== $expected) {
                throw new RuntimeException("{$block} slider items-per-page setting was not rendered.");
            }
        }
        if (! str_contains($listingRoot->getAttribute('class'), 'blaze-slider')) {
            throw new RuntimeException("{$block} slider variant was not rendered.");
        }
        if ($block === 'content-listing') {
            $xpath = new DOMXPath($listingRoot->ownerDocument);
            if ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " blaze-track ")]', $listingRoot)->length !== 1) {
                throw new RuntimeException('Content listing slider track missing.');
            }
        }
    }
    if ($block === 'gallery') {
        foreach (wp_scripts()->queue as $handle) {
            if (str_starts_with($handle, 'abcnorio-dep-script-gallery-')) {
                throw new RuntimeException('Gallery frontend script must not be enqueued in WordPress.');
            }
        }
    }

    foreach ($components as $component) {
        foreach ($manifest['components'][$component]['deps'] as $path) {
            $registry = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css' ? wp_styles() : wp_scripts();
            $found = false;
            foreach ($registry->queue as $handle) {
                $asset = $registry->registered[$handle] ?? null;
                if ($asset && str_starts_with($handle, "abcnorio-dep-") && str_contains($handle, "-{$component}-") && str_contains($asset->src, $path)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                throw new RuntimeException("Dependency not enqueued for {$block}: {$component}/{$path}");
            }
        }
    }
    echo "ok {$block}\n";
}

$masonryHtml = render_block([
    'blockName' => 'abcnorio/gallery',
    'attrs' => ['variant' => 'masonry'],
    'innerBlocks' => [],
    'innerHTML' => '',
    'innerContent' => [],
]);
$masonry = HtmlFragmentSupport::loadHtmlFragment($masonryHtml);
$masonryRoot = $masonry->getElementsByTagName('gallery-listing')->item(0);
$masonryXpath = new DOMXPath($masonry);
if (! $masonryRoot instanceof DOMElement
    || ! str_contains($masonryRoot->getAttribute('class'), 'gallery--masonry')
    || str_contains($masonryRoot->getAttribute('class'), 'blaze-slider')
    || $masonryXpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " blaze-track ")]')->length !== 0
    || $masonryXpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " blaze-prev ") or contains(concat(" ", normalize-space(@class), " "), " gallery-pagination ")]')->length !== 0
) {
    throw new RuntimeException('Masonry gallery must render without slider or pagination markup.');
}
echo "ok masonry gallery\n";

$captionedHtml = \abcnorio\CustomFunc\Blocks\Gallery::render(
    ['variant' => 'captioned'],
    '<figure class="wp-block-image"><img src="/caption-example.webp" alt="Example" width="300" height="400"><figcaption>Caption with <em>formatting</em>.</figcaption></figure>'
);
if (!str_contains($captionedHtml, 'data-variant="captioned"')
    || !str_contains($captionedHtml, 'images blaze-track')
    || !str_contains($captionedHtml, '<figcaption>Caption with <em>formatting</em>.</figcaption>')
    || str_contains($captionedHtml, 'data-pswp-item')
) {
    throw new RuntimeException('Captioned gallery must preserve figures and captions without lightbox links.');
}
echo "ok captioned gallery\n";

$renderArticle = new ReflectionMethod(ContentListingQuery::class, 'renderArticleItemFromDist');
foreach ([
    '<p>Real <strong>article</strong> excerpt.</p>' => '<p>Real <strong>article</strong> excerpt.</p>',
    '<p>Safe<script>alert(1)</script></p>' => '<p>Safealert(1)</p>',
    '<p>Café &#8211; community.</p>' => '<p>Café – community.</p>',
    '' => '',
] as $input => $expected) {
    $html = $renderArticle->invoke(null, [
        'title' => ['rendered' => 'Article'],
        'excerpt' => ['rendered' => $input],
    ]);
    $dom = HtmlFragmentSupport::loadHtmlFragment('<div id="listing"></div>');
    $listing = $dom->getElementById('listing');
    HtmlFragmentSupport::appendHtmlFragment($dom, $listing, $html, 'test listing');
    $xpath = new DOMXPath($dom);
    $excerpt = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-teaser__excerpt ")]')->item(0);
    if (! $excerpt instanceof DOMElement) {
        throw new RuntimeException('Article teaser excerpt node missing.');
    }
    $actual = '';
    foreach ($excerpt->childNodes as $child) {
        $actual .= $dom->saveHTML($child);
    }
    if ($actual !== $expected) {
        throw new RuntimeException("Article teaser excerpt mismatch: expected {$expected}; got {$actual}.");
    }
}
echo "ok content-listing article excerpts\n";

$manifestProperty = new ReflectionProperty(ComponentIngestor::class, 'manifest');
$testManifest = $manifest;
$testManifest['components']['module-test'] = [
    'fixtures' => [],
    'css' => [],
    'js' => ['elements/wp-blocks/gallery.js'],
    'deps' => ['elements/wp-blocks/gallery.js'],
];
$manifestProperty->setValue(null, $testManifest);
try {
    ComponentIngestor::enqueue_component_deps('module-test');
    $moduleHandle = null;
    foreach (wp_scripts()->queue as $handle) {
        if (str_starts_with($handle, 'abcnorio-dep-script-module-test-')) {
            $moduleHandle = $handle;
            break;
        }
    }
    if ($moduleHandle === null) {
        throw new RuntimeException('Declared module was not enqueued.');
    }
    ob_start();
    wp_scripts()->do_item($moduleHandle);
    $tag = ob_get_clean();
    if (! str_contains($tag, 'type="module"')) {
        throw new RuntimeException('Declared JS was not printed as a module.');
    }
    if (ComponentIngestor::moduleScriptTag('<script src="example.js"></script>', 'unrelated') !== '<script src="example.js"></script>') {
        throw new RuntimeException('Unrelated script tag was modified.');
    }
    wp_dequeue_script($moduleHandle);
} finally {
    $manifestProperty->setValue(null, $manifest);
}
echo "ok declared JS module\n";
