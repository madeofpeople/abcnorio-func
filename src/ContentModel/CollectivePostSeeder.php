<?php

namespace abcnorio\CustomFunc\ContentModel;

final class CollectivePostSeeder {
    private const OPTION_KEY = 'custom_func_collective_posts_schema_version';
    private const SCHEMA_VERSION = '1';

    private static array $COLLECTIVES = [
        'Punk/Hardcore Collective',
        'Visual Arts Collective',
        'Zine Library Collective',
        'Silkscreen PrintShop',
        'Darkroom Collective',
        'Computer Center',
    ];

    public static function maybeSeedDefaults(): void {
        $storedVersion = (string) get_option(self::OPTION_KEY, '0');

        if ($storedVersion === self::SCHEMA_VERSION) {
            return;
        }

        self::seedDefaults();
        update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }

    public static function forceSeedDefaults(): void {
        self::seedDefaults();
        update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }

    private static function seedDefaults(): void {
        if (! post_type_exists('collective')) {
            return;
        }

        foreach (self::$COLLECTIVES as $collectiveName) {
            self::seedCollectivePost($collectiveName);
        }
    }

    private static function seedCollectivePost(string $collectiveName): void {
        // Check if a collective post with this title already exists
        $existingPost = get_page_by_title($collectiveName, OBJECT, 'collective');

        if ($existingPost) {
            return;
        }

        // Create the collective post
        $postId = wp_insert_post([
            'post_type' => 'collective',
            'post_title' => $collectiveName,
            'post_status' => 'publish',
        ]);

        if (! $postId || is_wp_error($postId)) {
            return;
        }

        // Assign the matching collective_association term
        if (! taxonomy_exists('collective_association')) {
            return;
        }

        $term = get_term_by('name', $collectiveName, 'collective_association');

        if (! $term) {
            return;
        }

        wp_set_object_terms((int) $postId, (int) $term->term_id, 'collective_association');
    }
}
