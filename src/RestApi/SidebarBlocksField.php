<?php

namespace abcnorio\CustomFunc\RestApi;

use abcnorio\CustomFunc\ImageStyles\BlockImageAttributeEnricher;

final class SidebarBlocksField
{
    private const POST_TYPES = ['event', 'collective', 'article', 'page'];

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        foreach (self::POST_TYPES as $postType) {
            register_rest_field($postType, 'sidebar_post_id', [
                'get_callback' => [self::class, 'getSidebarPostIdField'],
                'schema' => [
                    'description' => 'Resolved sidebar post ID from sidebar_scope assignment.',
                    'type' => 'integer',
                    'context' => ['view', 'edit'],
                ],
            ]);

            register_rest_field($postType, 'sidebar_blocks', [
                'get_callback' => [self::class, 'getField'],
                'schema' => [
                    'description' => 'Parsed sidebar blocks resolved from sidebar_scope assignment.',
                    'type' => 'array',
                    'context' => ['view', 'edit'],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getField(array $post): array
    {
        $sidebarId = self::resolveSidebarPostId($post);

        if ($sidebarId <= 0) {
            return [];
        }

        return BlockImageAttributeEnricher::getParsedBlocksForRestField(['id' => $sidebarId]);
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function getSidebarPostIdField(array $post): int
    {
        return self::resolveSidebarPostId($post);
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function resolveSidebarPostId(array $post): int
    {
        $postId = isset($post['id']) ? (int) $post['id'] : 0;

        if ($postId <= 0) {
            return 0;
        }

        $scopeTerms = wp_get_object_terms($postId, 'sidebar_scope', [
            'fields' => 'ids',
            'orderby' => 'term_id',
            'order' => 'ASC',
        ]);

        if (! is_array($scopeTerms) || $scopeTerms === []) {
            return 0;
        }

        $scopeTermId = (int) $scopeTerms[0];

        if ($scopeTermId <= 0) {
            return 0;
        }

        $sidebarIds = get_posts([
            'post_type' => 'sidebar',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'tax_query' => [
                [
                    'taxonomy' => 'sidebar_scope',
                    'field' => 'term_id',
                    'terms' => [$scopeTermId],
                ],
            ],
            'orderby' => ['menu_order' => 'ASC', 'date' => 'ASC'],
        ]);

        if (! is_array($sidebarIds) || $sidebarIds === []) {
            return 0;
        }

        return (int) $sidebarIds[0];
    }
}
