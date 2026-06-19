<?php

namespace abcnorio\CustomFunc\ContentModel;

final class TaxonomyTermSeeder
{
    private const OPTION_KEY = 'custom_func_taxonomy_terms_schema_version';
    private const SCHEMA_VERSION = '5';

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
        self::seedTerms('event_type', [
            'Show',
            'Exhibition',
            'Meeting',
        ]);

        $collectives = [
            'Punk/Hardcore Collective',
            'Visual Arts Collective',
            'Zine Library Collective',
            'Silkscreen PrintShop',
            'Darkroom Collective',
            'Computer Center',
        ];

        self::seedTerms('collective_association', $collectives);

        self::seedTermsWithSlugs('sidebar_scope', [
            ['name' => 'Event', 'slug' => 'event'],
            ['name' => 'Collective', 'slug' => 'collective'],
            ['name' => 'Article', 'slug' => 'article'],
            ['name' => 'Page', 'slug' => 'page'],
        ]);
    }

    private static function seedTerms(string $taxonomy, array $terms): void
    {
        if (! taxonomy_exists($taxonomy)) {
            return;
        }

        foreach ($terms as $termName) {
            $existingTerm = get_term_by('name', $termName, $taxonomy);

            if ($existingTerm) {
                continue;
            }

            wp_insert_term($termName, $taxonomy);
        }
    }

    /**
     * @param array<int, array{name: string, slug: string}> $terms
     */
    private static function seedTermsWithSlugs(string $taxonomy, array $terms): void
    {
        if (! taxonomy_exists($taxonomy)) {
            return;
        }

        foreach ($terms as $term) {
            $slug = (string) ($term['slug'] ?? '');
            $name = (string) ($term['name'] ?? '');

            if ($slug === '' || $name === '') {
                continue;
            }

            $existingTerm = get_term_by('slug', $slug, $taxonomy);
            if ($existingTerm instanceof \WP_Term) {
                continue;
            }

            wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        }
    }
}