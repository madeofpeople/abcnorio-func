<?php

namespace abcnorio\CustomFunc\RestApi;

final class FeaturedImageField
{
    private const POST_TYPES = ['event'];

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        foreach (self::POST_TYPES as $postType) {
            register_rest_field($postType, 'featured_image', [
                'get_callback'    => [self::class, 'getField'],
                'update_callback' => null,
                'schema'          => [
                    'description' => 'Featured image URL and alt text.',
                    'type'        => 'object',
                    'context'     => ['view'],
                    'properties'  => [
                        'url' => ['type' => 'string'],
                        'alt' => ['type' => 'string'],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array{url: string, alt: string}|null
     */
    public static function getField(array $post): ?array
    {
        $thumbnailId = (int) get_post_thumbnail_id($post['id']);

        if ($thumbnailId === 0) {
            return null;
        }

        $src = wp_get_attachment_image_src($thumbnailId, 'full');

        if ($src === false) {
            return null;
        }

        return [
            'url' => $src[0],
            'alt' => (string) get_post_meta($thumbnailId, '_wp_attachment_image_alt', true),
        ];
    }
}
