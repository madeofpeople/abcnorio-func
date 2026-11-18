<?php

namespace abcnorio\CustomFunc\Blocks;

use abcnorio\CustomFunc\Components\ComponentIngestor;

final class EventTeaser
{
    public static function render(array $data): string
    {
        $dom = HtmlFragmentSupport::loadHtmlFragment(ComponentIngestor::readDistHtml('event-teaser.html'));
        $xpath = new \DOMXPath($dom);
        $root = $dom->getElementsByTagName('event-teaser')->item(0);
        $link = $dom->getElementsByTagName('a')->item(0);
        $title = $dom->getElementsByTagName('h3')->item(0);

        if (! $root instanceof \DOMElement || ! $link instanceof \DOMElement || ! $title instanceof \DOMElement) {
            throw new \RuntimeException('Components System Error: event-teaser fixture structure missing required nodes.');
        }

        $link->setAttribute('href', esc_url($data['href']));
        $title->nodeValue = wp_strip_all_tags((string) $data['title']);
        if (! empty($data['isPastEvent'])) {
            HtmlFragmentSupport::addClass($root, 'event-teaser--past');
        } else {
            HtmlFragmentSupport::removeClass($root, 'event-teaser--past');
        }

        $time = $dom->getElementsByTagName('time')->item(0);
        if ($time instanceof \DOMElement) {
            if ($data['dateLabel'] !== '' || $data['timeLabel'] !== '') {
                $time->setAttribute('datetime', (string) $data['dateTime']);
                $parts = $xpath->query('./span', $time);
                if ($parts->length !== 2) {
                    throw new \RuntimeException('Components System Error: event-teaser fixture date/time regions missing.');
                }
                foreach ([' ' . $data['dateLabel'] . ' ', ' @ ' . $data['timeLabel']] as $index => $label) {
                    $parts->item($index)->textContent = $label;
                }
            } else {
                $time->parentNode?->removeChild($time);
            }
        }

        HtmlFragmentSupport::syncCardImage(
            $dom, $xpath, $root, $data['featured_image'] ?? null,
            'event-teaser__content', 'event-teaser__image', 'event-teaser'
        );
        foreach ($data['classes'] ?? [] as $class) {
            HtmlFragmentSupport::addClass($root, $class);
        }

        return trim((string) $dom->saveHTML($root));
    }
}
