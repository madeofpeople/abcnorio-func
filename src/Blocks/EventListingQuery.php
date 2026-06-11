<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

final class EventListingQuery
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/event-listing')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/event-listing-query',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($content, $block);

        self::enqueueComponentAssets();

        $dateFilter = sanitize_key((string) ($attributes['dateFilter'] ?? 'upcoming'));
        $order = strtoupper(sanitize_key((string) ($attributes['order'] ?? 'desc')));
        $itemCount = (int) ($attributes['itemCount'] ?? 6);
        $eventType = sanitize_title((string) ($attributes['eventType'] ?? ''));
        $collectiveAssociation = sanitize_title((string) ($attributes['collectiveAssociation'] ?? ''));

        if ($itemCount < 1) {
            $itemCount = 1;
        }
        if ($itemCount > 50) {
            $itemCount = 50;
        }

        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }

        $metaQuery = [];
        $now = wp_date('Y-m-d H:i:s');

        if ($dateFilter === 'upcoming') {
            $metaQuery[] = [
                'key'     => 'event_effective_end',
                'value'   => $now,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ];
        } elseif ($dateFilter === 'past') {
            $metaQuery[] = [
                'key'     => 'event_effective_end',
                'value'   => $now,
                'compare' => '<',
                'type'    => 'DATETIME',
            ];
        }

        $taxQuery = [];

        if ($eventType !== '') {
            $taxQuery[] = [
                'taxonomy' => 'event_type',
                'field'    => 'slug',
                'terms'    => [$eventType],
            ];
        }

        if ($collectiveAssociation !== '') {
            $taxQuery[] = [
                'taxonomy' => 'collective_association',
                'field'    => 'slug',
                'terms'    => [$collectiveAssociation],
            ];
        }

        $queryArgs = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $itemCount,
            'meta_key'       => 'event_start_date',
            'orderby'        => 'meta_value',
            'meta_type'      => 'DATETIME',
            'order'          => $order,
        ];

        if (!empty($metaQuery)) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        if (!empty($taxQuery)) {
            if (count($taxQuery) > 1) {
                $taxQuery['relation'] = 'AND';
            }
            $queryArgs['tax_query'] = $taxQuery;
        }

        $query = new \WP_Query($queryArgs);

        $itemsHtml = '';

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $itemsHtml .= self::renderEventCard($post);
            }
        }

        wp_reset_postdata();

        $count = (int) $query->found_posts;
        $countLabel = sprintf(
            'The current filters are displaying %d %s.',
            $count,
            $count === 1 ? 'event' : 'events'
        );

        return self::renderListingFromDist($countLabel, $itemsHtml);
    }

    private static function renderEventCard(\WP_Post $post): string
    {
        $postId = (int) $post->ID;
        $href = get_permalink($postId);
        $title = get_the_title($postId);

        $startRaw = (string) get_post_meta($postId, 'event_start_date', true);
        $dateLabel = '';
        $timeLabel = '';
        $dateTime = '';

        if ($startRaw !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, wp_timezone());
            if ($dt instanceof \DateTimeImmutable) {
                $dateLabel = $dt->format('M j, Y');
                $timeLabel = $dt->format('g:i A');
                $dateTime = $dt->format(DATE_ATOM);
            }
        }

        $effectiveEnd = (string) get_post_meta($postId, 'event_effective_end', true);
        $isPast = $effectiveEnd !== '' && strtotime($effectiveEnd) < current_time('timestamp');

        return self::renderEventCardFromDist([
            'href'        => (string) $href,
            'title'       => (string) $title,
            'dateLabel'   => $dateLabel,
            'timeLabel'   => $timeLabel,
            'dateTime'    => $dateTime,
            'isPastEvent' => $isPast,
            'image'       => self::getCardImageData($postId),
        ]);
    }

    private static function renderListingFromDist(string $countLabel, string $itemsHtml): string
    {
        $dom = self::loadHtmlFragment(ComponentIngestor::readDistHtml('event-listing/empty.html'));
        $xpath = new \DOMXPath($dom);

        $listing = $dom->getElementsByTagName('event-listing')->item(0);
        if (! $listing instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: event-listing fixture root missing.');
        }

        $listing->setAttribute(
            'class',
            trim($listing->getAttribute('class') . ' wp-block-abcnorio-event-listing')
        );

        $countNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " events__count ")]')->item(0);
        if (! $countNode instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: event-listing count node missing.');
        }
        $countNode->nodeValue = $countLabel;

        $teaserList = $dom->getElementById('teaser-list');
        if (! $teaserList instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: teaser-list node missing.');
        }

        self::appendHtmlFragment($dom, $teaserList, $itemsHtml);

        return trim((string) $dom->saveHTML($listing));
    }

    private static function renderEventCardFromDist(array $data): string
    {
        $dom = self::loadHtmlFragment(ComponentIngestor::readDistHtml('event-teaser.html'));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('event-teaser')->item(0);
        $article = $dom->getElementsByTagName('article')->item(0);
        $link = $dom->getElementsByTagName('a')->item(0);
        $title = $dom->getElementsByTagName('h3')->item(0);

        if (! $root instanceof \DOMElement || ! $article instanceof \DOMElement || ! $link instanceof \DOMElement || ! $title instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: event-teaser fixture structure missing required nodes.');
        }

        $link->setAttribute('href', esc_url($data['href']));
        $title->nodeValue = wp_strip_all_tags((string) $data['title']);

        if (! empty($data['isPastEvent'])) {
            self::addClass($article, 'past');
        } else {
            self::removeClass($article, 'past');
        }

        $time = $dom->getElementsByTagName('time')->item(0);
        if ($time instanceof \DOMElement) {
            if ($data['dateLabel'] !== '' || $data['timeLabel'] !== '') {
                $time->setAttribute('datetime', (string) $data['dateTime']);
                $span = $time->getElementsByTagName('span')->item(0);
                if ($span instanceof \DOMElement) {
                    while ($span->firstChild) {
                        $span->removeChild($span->firstChild);
                    }

                    $span->appendChild($dom->createTextNode((string) $data['dateLabel']));
                    $span->appendChild($dom->createElement('br'));
                    $span->appendChild($dom->createTextNode('@ ' . (string) $data['timeLabel']));
                }
            } else {
                $time->parentNode?->removeChild($time);
            }
        }

        self::syncCardImage($dom, $xpath, $article, $data['image']);

        return trim((string) $dom->saveHTML($root));
    }

    private static function getCardImageData(int $postId): ?array
    {
        $thumbnailId = (int) get_post_thumbnail_id($postId);
        if ($thumbnailId < 1) {
            return null;
        }

        $image = wp_get_attachment_image_src($thumbnailId, 'abcnorio-card');
        if (! is_array($image) || empty($image[0])) {
            return null;
        }

        return [
            'src'    => (string) $image[0],
            'width'  => (int) ($image[1] ?? 0),
            'height' => (int) ($image[2] ?? 0),
            'alt'    => (string) get_post_meta($thumbnailId, '_wp_attachment_image_alt', true),
            'srcset' => (string) wp_get_attachment_image_srcset($thumbnailId, 'abcnorio-card'),
            'sizes'  => (string) wp_get_attachment_image_sizes($thumbnailId, 'abcnorio-card'),
        ];
    }

    private static function syncCardImage(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $article, ?array $image): void
    {
        $existing = $xpath->query('.//img', $article)->item(0);

        if ($image === null) {
            if ($existing instanceof \DOMElement) {
                $existing->parentNode?->removeChild($existing);
            }
            return;
        }

        $link = $xpath->query('.//a', $article)->item(0);
        $content = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " content ")]', $article)->item(0);

        if (! $link instanceof \DOMElement || ! $content instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: event-teaser fixture missing link/content nodes.');
        }

        $img = $existing instanceof \DOMElement ? $existing : $dom->createElement('img');
        $img->setAttribute('src', esc_url((string) $image['src']));
        $img->setAttribute('alt', (string) $image['alt']);
        $img->setAttribute('width', (string) $image['width']);
        $img->setAttribute('height', (string) $image['height']);
        $img->setAttribute('decoding', 'async');
        $img->setAttribute('loading', 'lazy');

        if (! empty($image['srcset'])) {
            $img->setAttribute('srcset', (string) $image['srcset']);
        }
        if (! empty($image['sizes'])) {
            $img->setAttribute('sizes', (string) $image['sizes']);
        }

        if (! $existing instanceof \DOMElement) {
            $link->insertBefore($img, $content);
        }
    }

    private static function loadHtmlFragment(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private static function appendHtmlFragment(\DOMDocument $dom, \DOMElement $parent, string $html): void
    {
        if ($html === '') {
            return;
        }

        $fragmentDom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $fragmentDom->loadHTML(
            '<wrapper>' . $html . '</wrapper>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new \RuntimeException('Components System Error: Failed appending rendered event listing items.');
        }

        $wrapper = $fragmentDom->getElementsByTagName('wrapper')->item(0);
        if (! $wrapper instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: Missing wrapper node while appending rendered items.');
        }

        while ($wrapper->firstChild) {
            $parent->appendChild($dom->importNode($wrapper->firstChild, true));
            $wrapper->removeChild($wrapper->firstChild);
        }
    }

    private static function addClass(\DOMElement $element, string $className): void
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (! in_array($className, $classes, true)) {
            $classes[] = $className;
        }
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function removeClass(\DOMElement $element, string $className): void
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter($classes, static fn (string $class): bool => $class !== $className));
        $element->setAttribute('class', trim(implode(' ', $classes)));
    }

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();
    }
}
