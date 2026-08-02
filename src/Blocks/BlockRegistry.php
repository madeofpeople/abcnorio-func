<?php

namespace abcnorio\CustomFunc\Blocks;

final class BlockRegistry
{
    public static function getBlocks(): array
    {
        return [
            'abcnorio/announcement-tout' => [
                'path' => 'src/Blocks/announcement-tout',
            ],
            'abcnorio/hero' => [
                'path' => 'src/Blocks/hero',
            ],
            'abcnorio/sidebar-tout' => [
                'path' => 'src/Blocks/sidebar-tout',
                'renderClass' => SidebarTout::class,
                'renderMethod' => 'render',
            ],
            'abcnorio/newsletter-signup' => [
                'path' => 'src/Blocks/newsletter-signup',
                'renderClass' => NewsletterSignup::class,
                'renderMethod' => 'render',
            ],
            'abcnorio/event-listing' => [
                'path' => 'src/Blocks/event-listing-query',
                'renderClass' => EventListingQuery::class,
                'renderMethod' => 'render',
            ],
            'abcnorio/content-listing' => [
                'path' => 'src/Blocks/content-listing-query',
                'renderClass' => ContentListingQuery::class,
                'renderMethod' => 'render',
            ],
            'abcnorio/collective-listing' => [
                'path' => 'src/Blocks/collective-listing-query',
                'renderClass' => CollectiveListingQuery::class,
                'renderMethod' => 'render',
            ],
        ];
    }
}
