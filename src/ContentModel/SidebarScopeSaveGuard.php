<?php

namespace abcnorio\CustomFunc\ContentModel;

final class SidebarScopeSaveGuard
{
    private const POST_TYPES = ['event', 'collective', 'article', 'page', 'sidebar'];
    private const TAXONOMY_KEYS = ['sidebar_scope', 'sidebar-scopes'];

    public static function registerHooks(): void
    {
        foreach (self::POST_TYPES as $postType) {
            add_filter("rest_pre_insert_{$postType}", [self::class, 'enforceSingleSidebarScope'], 10, 2);
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
