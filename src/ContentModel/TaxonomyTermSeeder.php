<?php

namespace abcnorio\CustomFunc\ContentModel;

final class TaxonomyTermSeeder
{
    private const OPTION_KEY = 'custom_func_taxonomy_terms_schema_version';
    private const SCHEMA_VERSION = '2';

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

        self::seedTerms('collective_association', [
            'Punk/Hardcore Collective',
            'Visual Arts Collective',
            'Zine Library Collective',
            'Silkscreen PrintShop',
            'Darkroom Collective',
            'Computer Center',
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
}