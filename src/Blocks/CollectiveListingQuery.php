<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

final class CollectiveListingQuery
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/collective-listing')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/collective-listing-query',
            [
                'render_callback' => [self::class, 'render'],
            ]
        );
    }

    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        unset($content, $block);

        self::enqueueComponentAssets();

        $order = strtoupper(sanitize_key((string) ($attributes['order'] ?? 'desc')));

        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }

        $queryArgs = [
            'post_type'      => 'collective',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => $order,
            'ignore_sticky_posts' => true,
            'no_found_rows'  => true,
        ];

        $query = new \WP_Query($queryArgs);
        $sortOrderSlugs = self::normalizeSortOrderSlugs($attributes['sortOrderSlugs'] ?? []);
        $posts = self::orderPostsBySlug($query->posts, $sortOrderSlugs);

        $itemsHtml = '';

        if ($posts !== []) {
            foreach ($posts as $post) {
                $itemsHtml .= self::renderCollectiveCard($post);
            }
        }

        wp_reset_postdata();

        return self::renderListingFromDist(null, $itemsHtml);
    }

    private static function normalizeSortOrderSlugs($rawSlugs): array
    {
        if (! is_array($rawSlugs)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($rawSlugs as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $value = sanitize_title($slug);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    private static function orderPostsBySlug(array $posts, array $sortOrderSlugs): array
    {
        $normalizedPosts = [];

        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $normalizedPosts[] = $post;
            }
        }

        if ($sortOrderSlugs === []) {
            return $normalizedPosts;
        }

        $postsBySlug = [];
        foreach ($normalizedPosts as $post) {
            $slug = (string) $post->post_name;
            if ($slug !== '' && ! isset($postsBySlug[$slug])) {
                $postsBySlug[$slug] = $post;
            }
        }

        $ordered = [];
        $usedIds = [];

        foreach ($sortOrderSlugs as $slug) {
            $post = $postsBySlug[$slug] ?? null;
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $ordered[] = $post;
            $usedIds[$post->ID] = true;
        }

        foreach ($normalizedPosts as $post) {
            if (isset($usedIds[$post->ID])) {
                continue;
            }

            $ordered[] = $post;
        }

        return $ordered;
    }

    private static function renderCollectiveCard(\WP_Post $post): string
    {
        $postId = (int) $post->ID;
        $href = get_permalink($postId);
        $title = get_the_title($postId);

        return self::renderCollectiveCardFromDist([
            'href'        => (string) $href,
            'title'       => (string) $title,
            'featured_image' => self::getCardImageData($postId),
        ]);
    }

    private static function renderListingFromDist(?string $countLabel, string $itemsHtml): string
    {
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('collective-listing.html'));
        $xpath = new \DOMXPath($dom);

        $listing = $dom->getElementsByTagName('collective-listing')->item(0);
        if (! $listing instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: collective-listing fixture root missing.');
        }

        $listing->setAttribute(
            'class',
            trim($listing->getAttribute('class') . ' wp-block-abcnorio-collective-listing')
        );

        $countNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " collective__count ") or contains(concat(" ", normalize-space(@class), " "), " collective-listing__count ")]')->item(0);
        if ($countLabel !== null) {
            if (! $countNode instanceof \DOMElement) {
                $countNode = $dom->createElement('p');
                $countNode->setAttribute('class', 'collective-listing__count collective__count');

                $teaserListNode = $dom->getElementById('collective-list');
                if ($teaserListNode instanceof \DOMElement && $teaserListNode->parentNode === $listing) {
                    $listing->insertBefore($countNode, $teaserListNode);
                } else {
                    $listing->appendChild($countNode);
                }
            }
            $countNode->nodeValue = $countLabel;
        } elseif ($countNode instanceof \DOMElement) {
            $countNode->parentNode?->removeChild($countNode);
        }

        $teaserList = $dom->getElementById('collective-list');
        if (! $teaserList instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: collective-list node missing.');
        }

        HtmlFragmentSupport::appendHtmlFragment($dom, $teaserList, $itemsHtml, 'collective listing');

        return trim((string) $dom->saveHTML($listing));
    }

    private static function renderCollectiveCardFromDist(array $data): string
    {
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('collective-teaser.html'));
        $xpath = new \DOMXPath($dom);

        $root = $dom->getElementsByTagName('collective-teaser')->item(0);
        $link = $dom->getElementsByTagName('a')->item(0);
        $title = $dom->getElementsByTagName('h3')->item(0);

        if (! $root instanceof \DOMElement || ! $link instanceof \DOMElement || ! $title instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: collective-teaser fixture structure missing required nodes.');
        }

        $link->setAttribute('href', esc_url($data['href']));
        $title->nodeValue = wp_strip_all_tags((string) $data['title']);

        if (! empty($data['isPastCollective'])) {
            HtmlFragmentSupport::addClass($root, 'past');
        } else {
            HtmlFragmentSupport::removeClass($root, 'past');
        }

        $time = $dom->getElementsByTagName('time')->item(0);
        if ($time instanceof \DOMElement) {
            if ($data['dateLabel'] !== '' || $data['timeLabel'] !== '') {
                $time->setAttribute('datetime', (string) $data['dateTime']);
                while ($time->firstChild) {
                    $time->removeChild($time->firstChild);
                }

                $dateSpan = $dom->createElement('span');
                $dateSpan->appendChild($dom->createTextNode(' ' . (string) $data['dateLabel'] . ' '));
                $time->appendChild($dateSpan);

                $timeSpan = $dom->createElement('span');
                $timeSpan->appendChild($dom->createTextNode(' @ ' . (string) $data['timeLabel']));
                $time->appendChild($timeSpan);
            } else {
                $time->parentNode?->removeChild($time);
            }
        }

        HtmlFragmentSupport::syncCardImage(
            $dom,
            $xpath,
            $root,
            isset($data['featured_image']) && is_array($data['featured_image']) ? $data['featured_image'] : null,
            'collective-teaser__content',
            'collective-teaser__image',
            'collective-teaser'
        );

        return trim((string) $dom->saveHTML($root));
    }

    private static function getCardImageData(int $postId): ?array
    {
        $thumbnailId = (int) get_post_thumbnail_id($postId);
        if ($thumbnailId < 1) {
            return null;
        }

        $image = wp_get_attachment_image_src($thumbnailId, 'abcnorio-collective-thumb');
        if (! is_array($image) || empty($image[0])) {
            return null;
        }

        return [
            'src'    => (string) $image[0],
            'width'  => (int) ($image[1] ?? 0),
            'height' => (int) ($image[2] ?? 0),
            'alt'    => (string) get_post_meta($thumbnailId, '_wp_attachment_image_alt', true),
            'srcset' => (string) wp_get_attachment_image_srcset($thumbnailId, 'abcnorio-collective-thumb'),
            'sizes'  => (string) wp_get_attachment_image_sizes($thumbnailId, 'abcnorio-collective-thumb'),
        ];
    }

    private static function enqueueComponentAssets(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();

        ComponentIngestor::enqueue_component_deps('collective-listing');
        ComponentIngestor::enqueue_component_deps('collective-teaser');
    }
}
