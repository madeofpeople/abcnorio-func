<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;
use abcnorio\CustomFunc\RestApi\ContentListingEndpoint;

final class ContentListingQuery
{
    private const ALLOWED_POST_TYPES = ['event', 'article'];

    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/content-listing')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/content-listing-query',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($content, $block);

        self::enqueueComponentAssets();

        $postTypes = self::normalizePostTypes($attributes['listingPostTypes'] ?? []);
        $timeFilter = sanitize_key((string) ($attributes['listingTimeFilter'] ?? 'all'));
        $count = max(1, min(50, (int) ($attributes['listingCount'] ?? 5)));
        $order = strtolower(sanitize_key((string) ($attributes['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $tags = self::normalizeTags($attributes['listingTagFilter'] ?? []);

        $request = new \WP_REST_Request('GET', '/abcnorio/v1/content-listing');
        $request->set_param('post_types', $postTypes);
        $request->set_param('tags', $tags);
        $request->set_param('time_filter', $timeFilter);
        $request->set_param('count', $count);
        $request->set_param('order', $order);

        $response = ContentListingEndpoint::serve($request);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Components System Error: Content listing endpoint returned WP_Error.');
        }

        $items = $response instanceof \WP_REST_Response
            ? $response->get_data()
            : (array) $response;

        $itemsHtml = '';

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $postType = (string) ($item['post_type'] ?? '');
            if ($postType === 'event') {
                $itemsHtml .= self::renderEventItemFromDist($item);
                continue;
            }

            if ($postType === 'article') {
                $itemsHtml .= self::renderArticleItemFromDist($item);
            }
        }

        return self::renderListingFromDist(count($items), $itemsHtml);
    }

    private static function renderListingFromDist(int $totalItems, string $itemsHtml): string
    {
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('content-listing.html'));
        $xpath = new \DOMXPath($dom);

        $listing = $dom->getElementsByTagName('content-listing')->item(0);
        if (! $listing instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: content-listing fixture root missing.');
        }

        HtmlFragmentSupport::addClass($listing, 'wp-block-abcnorio-content-listing');

        $countNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " content-listing__count ")]')->item(0);
        if (! $countNode instanceof \DOMElement) {
            $countNode = $dom->createElement('p');
            $countNode->setAttribute('class', 'content-listing__count');
            $listing->insertBefore($countNode, $listing->firstChild);
        }

        $countNode->nodeValue = sprintf(
            'Displaying %d %s.',
            $totalItems,
            $totalItems === 1 ? 'item' : 'items'
        );

        $itemsNode = $dom->getElementById('content-listing-list');
        if (! $itemsNode instanceof \DOMElement) {
            $itemsNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " content-listing__items ")]')->item(0);
        }

        if (! $itemsNode instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: content-listing items node missing.');
        }

        while ($itemsNode->firstChild) {
            $itemsNode->removeChild($itemsNode->firstChild);
        }

        HtmlFragmentSupport::appendHtmlFragment($dom, $itemsNode, $itemsHtml, 'content listing');

        return trim((string) $dom->saveHTML($listing));
    }

    private static function renderEventItemFromDist(array $item): string
    {
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('event-teaser.html'));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('event-teaser')->item(0);
        $article = $dom->getElementsByTagName('article')->item(0);
        $link = $dom->getElementsByTagName('a')->item(0);
        $title = $dom->getElementsByTagName('h3')->item(0);

        if (! $root instanceof \DOMElement || ! $article instanceof \DOMElement || ! $link instanceof \DOMElement || ! $title instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: event-teaser fixture structure missing required nodes.');
        }

        HtmlFragmentSupport::addClass($root, 'content-listing__item');
        HtmlFragmentSupport::addClass($root, 'content-listing__item--event');

        $link->setAttribute('href', esc_url(self::resolveItemHref($item, '/events')));
        $title->nodeValue = wp_strip_all_tags((string) ($item['title']['rendered'] ?? 'Untitled event'));

        $eventStart = (string) ($item['acf']['event_start_date'] ?? '');
        $time = $dom->getElementsByTagName('time')->item(0);
        if ($time instanceof \DOMElement) {
            if ($eventStart !== '') {
                $timestamp = strtotime($eventStart);
                if ($timestamp !== false) {
                    $dateTime = wp_date(DATE_ATOM, $timestamp);
                    $dateLabel = wp_date('M j, Y', $timestamp);
                    $timeLabel = wp_date('g:i A', $timestamp);

                    $time->setAttribute('datetime', $dateTime);
                    $span = $time->getElementsByTagName('span')->item(0);
                    if ($span instanceof \DOMElement) {
                        while ($span->firstChild) {
                            $span->removeChild($span->firstChild);
                        }
                        $span->appendChild($dom->createTextNode($dateLabel));
                        $span->appendChild($dom->createElement('br'));
                        $span->appendChild($dom->createTextNode('@ ' . $timeLabel));
                    }
                }
            } else {
                $time->parentNode?->removeChild($time);
            }
        }

        HtmlFragmentSupport::syncCardImage(
            $dom,
            $xpath,
            $article,
            isset($item['featured_image']) && is_array($item['featured_image']) ? $item['featured_image'] : null,
            'content',
            null,
            'event-teaser'
        );

        return trim((string) $dom->saveHTML($root));
    }

    private static function renderArticleItemFromDist(array $item): string
    {
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('article-teaser.html'));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('article-teaser')->item(0);
        $article = $dom->getElementsByTagName('article')->item(0);
        $link = $dom->getElementsByTagName('a')->item(0);
        $title = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-teaser__title ")]')->item(0);

        if (! $root instanceof \DOMElement || ! $article instanceof \DOMElement || ! $link instanceof \DOMElement || ! $title instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: article-teaser fixture structure missing required nodes.');
        }

        HtmlFragmentSupport::addClass($root, 'content-listing__item');
        HtmlFragmentSupport::addClass($root, 'content-listing__item--article');

        $link->setAttribute('href', esc_url(self::resolveItemHref($item, '/articles')));
        $title->nodeValue = wp_strip_all_tags((string) ($item['title']['rendered'] ?? 'Untitled article'));

        $rawDate = (string) ($item['acf']['item_date'] ?? $item['date'] ?? '');
        $time = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-teaser__date ")]')->item(0);
        if ($time instanceof \DOMElement) {
            if ($rawDate === '') {
                $time->parentNode?->removeChild($time);
            } else {
                $timestamp = strtotime($rawDate);
                $datetime = $timestamp !== false ? wp_date('Y-m-d', $timestamp) : $rawDate;
                $label = $timestamp !== false ? wp_date('M j, Y', $timestamp) : $rawDate;
                $time->setAttribute('datetime', $datetime);
                $time->nodeValue = $label;
            }
        }

        HtmlFragmentSupport::syncCardImage(
            $dom,
            $xpath,
            $article,
            isset($item['featured_image']) && is_array($item['featured_image']) ? $item['featured_image'] : null,
            'article-teaser__content',
            'article-teaser__image',
            'article-teaser'
        );

        return trim((string) $dom->saveHTML($root));
    }

    private static function resolveItemHref(array $item, string $basePath): string
    {
        if (! empty($item['id'])) {
            $permalink = get_permalink((int) $item['id']);
            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        $slug = sanitize_title((string) ($item['slug'] ?? ''));

        return $slug === '' ? $basePath : trailingslashit($basePath) . $slug;
    }

    private static function normalizeTags($rawTags): array
    {
        $tags = is_array($rawTags)
            ? array_values(array_filter(array_map(static fn ($value): string => sanitize_key((string) $value), $rawTags)))
            : [];

        return $tags;
    }

    private static function normalizePostTypes($rawPostTypes): array
    {
        $postTypes = is_array($rawPostTypes)
            ? array_values(array_filter(array_map(static fn ($value): string => sanitize_key((string) $value), $rawPostTypes)))
            : [];

        $allowed = array_values(array_filter($postTypes, static fn (string $postType): bool => in_array($postType, self::ALLOWED_POST_TYPES, true)));

        return $allowed === [] ? self::ALLOWED_POST_TYPES : $allowed;
    }

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();

        ComponentIngestor::enqueue_component_deps('content-listing');
        ComponentIngestor::enqueue_component_deps('event-teaser');
        ComponentIngestor::enqueue_component_deps('article-teaser');
    }
}
