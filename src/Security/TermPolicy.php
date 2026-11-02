<?php

namespace abcnorio\CustomFunc\Security;

// Editors assign existing terms but never create, edit or delete them.
final class TermPolicy
{
    private const CORE_TAXONOMIES = ['category', 'post_tag'];

    public static function registerHooks(): void
    {
        add_filter('register_taxonomy_args', [self::class, 'restrictCoreTaxonomies'], 10, 2);
        add_filter('pre_insert_term', [self::class, 'blockTermCreation'], 10, 2);
    }

    public static function restrictCoreTaxonomies(array $args, string $taxonomy): array
    {
        if (in_array($taxonomy, self::CORE_TAXONOMIES, true)) {
            $args['capabilities'] = array_merge($args['capabilities'] ?? [], [
                'manage_terms' => 'manage_core_terms',
                'edit_terms'   => 'manage_core_terms',
                'delete_terms' => 'manage_core_terms',
            ]);
        }

        return $args;
    }

    // Covers the classic tag box, REST and ACF. WP-CLI and cron have no current user.
    public static function blockTermCreation($term, string $taxonomy)
    {
        if (get_current_user_id() === 0) {
            return $term;
        }

        $taxonomyObject = get_taxonomy($taxonomy);

        if ($taxonomyObject && !current_user_can($taxonomyObject->cap->edit_terms)) {
            return new \WP_Error('term_creation_restricted', __('Only administrators can add new terms.', 'abcnorio-func'), ['status' => 403]);
        }

        return $term;
    }
}
