<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;
use abcnorio\CustomFunc\RestApi\ContentListingEndpoint;

final class ContentListingQuery
{
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

        $count = max(6, min(24, (int) ($attributes['listingCount'] ?? 6)));
        $order = strtolower(sanitize_key((string) ($attributes['order'] ?? 'desc')));

        $request = new \WP_REST_Request('GET', '/abcnorio/v1/content-listing');
        $request->set_param('post_types', $attributes['listingPostTypes'] ?? []);
        $request->set_param('tags', $attributes['listingTagFilter'] ?? []);
        $request->set_param('count', $count);
        $request->set_param('order', $order === 'asc' ? 'asc' : 'desc');
        if ($order === 'manual') {
            $request->set_param('orderIds', $attributes['sortOrderPostIds'] ?? []);
        }

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

        return self::renderListingFromDist(count($items), $itemsHtml, $attributes);
    }

    private static function renderListingFromDist(int $totalItems, string $itemsHtml, array $attributes): string
    {
        $fixture = ($attributes['variant'] ?? 'grid') === 'slider' ? 'content-listing/slider.html' : 'content-listing.html';
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml($fixture));
        $xpath = new \DOMXPath($dom);

        $listing = $dom->getElementsByTagName('content-listing')->item(0);
        if (! $listing instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: content-listing fixture root missing.');
        }

        HtmlFragmentSupport::addClass($listing, 'wp-block-abcnorio-content-listing');
        ListingLayout::apply($listing, $attributes);

        $titleNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " content-listing__title ")]')->item(0);
        $countNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " content-listing__count ")]')->item(0);
        $ctaNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " content-listing__cta ")]')->item(0);
        if (! $titleNode instanceof \DOMElement || ! $countNode instanceof \DOMElement || ! $ctaNode instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: content-listing fixture missing title/count/CTA slots.');
        }

        $title = sanitize_text_field((string) ($attributes['title'] ?? ''));
        if ($title !== '') {
            $titleNode->nodeValue = $title;
        } else {
            $titleNode->parentNode->removeChild($titleNode);
        }
        if (! empty($attributes['showCount'])) {
            $countNode->nodeValue = sprintf('Displaying %d %s.', $totalItems, $totalItems === 1 ? 'item' : 'items');
        } else {
            $countNode->parentNode->removeChild($countNode);
        }
        $ctaLabel = sanitize_text_field((string) ($attributes['ctaLabel'] ?? ''));
        $ctaUrl = esc_url((string) ($attributes['ctaUrl'] ?? ''));
        if (! empty($attributes['hasCta']) && $ctaLabel !== '' && $ctaUrl !== '') {
            $ctaNode->nodeValue = $ctaLabel;
            $ctaNode->setAttribute('href', $ctaUrl);
        } else {
            $ctaNode->parentNode->removeChild($ctaNode);
        }

        $itemsNode = $dom->getElementById('content-listing-list');
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
        $eventStart = (string) ($item['acf']['event_start_date'] ?? '');
        $date = $eventStart !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $eventStart, wp_timezone()) : false;
        return EventTeaser::render([
            'href' => self::resolveItemHref($item, '/events'),
            'title' => (string) ($item['title']['rendered'] ?? 'Untitled event'),
            'dateLabel' => $date ? $date->format('M j, Y') : '',
            'timeLabel' => $date ? $date->format('g:i A') : '',
            'dateTime' => $date ? $date->format(DATE_ATOM) : '',
            'featured_image' => $item['featured_image'] ?? null,
            'classes' => ['content-listing__item', 'content-listing__item--event'],
        ]);
    }

    private static function renderArticleItemFromDist(array $item): string
    {
        $image = $item['featured_image'] ?? null;
        $fixture = !empty($image['url']) || !empty($image['src']) ? 'article-teaser.html' : 'article-teaser/no-image.html';
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml($fixture));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('article-teaser')->item(0);
        $link = $dom->getElementsByTagName('a')->item(0);
        $title = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-teaser__title ")]')->item(0);

        if (! $root instanceof \DOMElement || ! $link instanceof \DOMElement || ! $title instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: article-teaser fixture structure missing required nodes.');
        }

        HtmlFragmentSupport::addClass($root, 'content-listing__item');
        HtmlFragmentSupport::addClass($root, 'content-listing__item--article');

        $link->setAttribute('href', esc_url(self::resolveItemHref($item, '/articles')));
        $title->nodeValue = wp_strip_all_tags((string) ($item['title']['rendered'] ?? 'Untitled article'));

        $excerpt = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-teaser__excerpt ")]')->item(0);
        if (! $excerpt instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: article-teaser fixture missing excerpt slot.');
        }
        while ($excerpt->firstChild) {
            $excerpt->removeChild($excerpt->firstChild);
        }
        HtmlFragmentSupport::appendHtmlFragment($dom, $excerpt, wp_kses_post((string) ($item['excerpt']['rendered'] ?? '')), 'article excerpt');

        $rawDate = (string) (($item['acf']['article_date'] ?? '') ?: ($item['date'] ?? ''));
        $time = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-teaser__date ")]')->item(0);
        if ($time instanceof \DOMElement) {
            if ($rawDate === '') {
                $time->parentNode?->removeChild($time);
            } else {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($rawDate, 0, 10), wp_timezone());
                $datetime = $date ? $date->format('Y-m-d') : $rawDate;
                $label = $date ? $date->format('M j, Y') : $rawDate;
                $time->setAttribute('datetime', $datetime);
                $time->nodeValue = $label;
            }
        }

        HtmlFragmentSupport::syncCardImage(
            $dom,
            $xpath,
            $root,
            $image,
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

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();

        ComponentIngestor::enqueue_component_deps('content-listing');
        ComponentIngestor::enqueue_component_deps('event-teaser');
        ComponentIngestor::enqueue_component_deps('article-teaser');
    }
}
