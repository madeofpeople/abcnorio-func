<?php

namespace abcnorio\CustomFunc\ContentModel;

final class SidebarScopeSaveGuard
{
    private const POST_TYPES = ['event', 'facility', 'article', 'page', 'sidebar'];
    private const TAXONOMY_KEYS = ['sidebar_scope', 'sidebar-scopes'];

    public static function registerHooks(): void
    {
        add_action('wp_after_insert_post', [self::class, 'assignNewEventScope'], 10, 3);

        foreach (self::POST_TYPES as $postType) {
            add_filter("rest_pre_insert_{$postType}", [self::class, 'enforceSingleSidebarScope'], 10, 2);
        }
    }

    public static function assignNewEventScope(int $postId, \WP_Post $post, bool $update): void
    {
        if ($update || $post->post_type !== 'event') {
            return;
        }

        $scopes = wp_get_object_terms($postId, 'sidebar_scope', ['fields' => 'ids']);
        if (is_wp_error($scopes)) {
            throw new \RuntimeException($scopes->get_error_message());
        }
        if ($scopes !== []) {
            return;
        }

        $eventScope = get_term_by('slug', 'event', 'sidebar_scope');
        if (! $eventScope instanceof \WP_Term) {
            throw new \RuntimeException('The Event sidebar scope (slug: event) is missing.');
        }

        $result = wp_set_object_terms($postId, [$eventScope->term_id], 'sidebar_scope');
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }
    }

    /**
     * @param mixed $preparedPost
     * @param \WP_REST_Request $request
     *
     * @return mixed
     */
    public static function enforceSingleSidebarScope($preparedPost, \WP_REST_Request $request)
    {
        $rawScopes = null;

        foreach (self::TAXONOMY_KEYS as $taxonomyKey) {
            $candidate = $request->get_param($taxonomyKey);

            if ($candidate !== null) {
                $rawScopes = $candidate;
                break;
            }
        }

        if ($rawScopes === null) {
            return $preparedPost;
        }

        if (! is_array($rawScopes)) {
            $rawScopes = [$rawScopes];
        }

        $scopeIds = [];

        foreach ($rawScopes as $scopeId) {
            $intId = (int) $scopeId;

            if ($intId > 0) {
                $scopeIds[] = $intId;
            }
        }

        if (count(array_unique($scopeIds)) <= 1) {
            return $preparedPost;
        }

        return new \WP_Error(
            'abcnorio_sidebar_scope_single_only',
            __('Only one Sidebar Scope can be assigned. Remove extra selections and save again.', 'abcnorio-func'),
            ['status' => 400]
        );
    }
}
