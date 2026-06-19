<?php

namespace abcnorio\CustomFunc\ContentModel;

final class SidebarPostSeeder
{
    private const OPTION_KEY = 'custom_func_sidebar_posts_schema_version';
    private const SCHEMA_VERSION = '2';

    /**
     * @var array<int, array{title: string, slug: string}>
     */
    private const SIDEBARS = [
        ['title' => 'Event Sidebar', 'slug' => 'event'],
        ['title' => 'Collective Sidebar', 'slug' => 'collective'],
        ['title' => 'Article Sidebar', 'slug' => 'article'],
        ['title' => 'Page Sidebar', 'slug' => 'page'],
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULT_SCOPE_BY_POST_TYPE = [
        'event' => 'event',
        'collective' => 'collective',
        'article' => 'article',
        'page' => 'page',
    ];

    public static function maybeSeedDefaults(): void
    {
        $storedVersion = (string) get_option(self::OPTION_KEY, '0');

        if ($storedVersion === self::SCHEMA_VERSION) {
            return;
        }

        self::seedDefaults();
        update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }

    public static function forceSeedDefaults(): void
    {
        self::seedDefaults();
        update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }

    private static function seedDefaults(): void
    {
        if (! post_type_exists('sidebar') || ! taxonomy_exists('sidebar_scope')) {
            return;
        }

        foreach (self::SIDEBARS as $sidebar) {
            self::ensureSidebarPost($sidebar['title'], $sidebar['slug']);
        }

        self::backfillDefaultScopeAssignments();
    }

    public static function maybeAssignDefaultScopeOnSave(int $postId): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        $postType = (string) get_post_type($postId);
        $defaultScope = self::DEFAULT_SCOPE_BY_POST_TYPE[$postType] ?? '';

        if ($defaultScope === '') {
            return;
        }

        self::assignScopeIfMissing($postId, $defaultScope);
    }

    private static function backfillDefaultScopeAssignments(): void
    {
        foreach (self::DEFAULT_SCOPE_BY_POST_TYPE as $postType => $scopeSlug) {
            $postIds = get_posts([
                'post_type' => $postType,
                'post_status' => ['publish', 'future', 'draft', 'private', 'pending'],
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
            ]);

            foreach ($postIds as $postId) {
                self::assignScopeIfMissing((int) $postId, $scopeSlug);
            }
        }
    }

    private static function ensureSidebarPost(string $title, string $slug): void
    {
        $existing = get_page_by_path($slug, OBJECT, 'sidebar');
        $content = sprintf('<h2>%s</h2>', esc_html($slug));

        if ($existing instanceof \WP_Post) {
            self::assignScopeIfMissing((int) $existing->ID, $slug);

            return;
        }

        $postId = wp_insert_post([
            'post_type' => 'sidebar',
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_content' => $content,
        ]);

        if (is_wp_error($postId) || (int) $postId <= 0) {
            return;
        }

        self::assignScopeIfMissing((int) $postId, $slug);
    }

    private static function assignScopeIfMissing(int $postId, string $scopeSlug): void
    {
        if ($postId <= 0 || $scopeSlug === '' || ! taxonomy_exists('sidebar_scope')) {
            return;
        }

        $existing = wp_get_object_terms($postId, 'sidebar_scope', ['fields' => 'ids']);

        if (is_array($existing) && $existing !== []) {
            return;
        }

        $term = get_term_by('slug', $scopeSlug, 'sidebar_scope');

        if (! $term instanceof \WP_Term) {
            return;
        }

        wp_set_object_terms($postId, [(int) $term->term_id], 'sidebar_scope', false);
    }
}
