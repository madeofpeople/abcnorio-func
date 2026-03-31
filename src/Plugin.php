<?php

namespace abcnorio\CustomFunc;
use abcnorio\CustomFunc\ContentModel\ACFFieldGroups;
use abcnorio\CustomFunc\ContentModel\PostTypeRegistrar;
use abcnorio\CustomFunc\ContentModel\TaxonomyRegistrar;
use abcnorio\CustomFunc\ContentModel\CollectivePostSeeder;
use abcnorio\CustomFunc\ContentModel\TaxonomyTermSeeder;
use abcnorio\CustomFunc\Headless\AdminExperience;
use abcnorio\CustomFunc\Security\CapabilityManager;

final class Plugin
{
    public static function activate(): void
    {
        self::registerContentModels();
        CapabilityManager::forceMigrateCapabilities();
        TaxonomyTermSeeder::forceSeedDefaults();
        CollectivePostSeeder::forceSeedDefaults();
    }

    public static function boot(): void
    {
        AdminExperience::registerHooks();
        ACFFieldGroups::registerHooks();
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
        add_action('admin_init', [CapabilityManager::class, 'maybeMigrateCapabilities'], 1);
        add_action('init', [self::class, 'registerContentModels']);
        add_action('init', [TaxonomyTermSeeder::class, 'maybeSeedDefaults'], 20);
        add_action('init', [CollectivePostSeeder::class, 'maybeSeedDefaults'], 30);

    }

    public static function registerContentModels(): void
    {
        $postTypes = require __DIR__ . '/ContentModel/post-types.php';
        $taxonomies = require __DIR__ . '/ContentModel/taxonomies.php';
        PostTypeRegistrar::registerMany($postTypes);
        TaxonomyRegistrar::registerMany($taxonomies);
    }

    public static function enqueueEditorAssets(): void
    {
        $buildDir = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'build/';
        $assetFile = $buildDir . 'index.asset.php';

        if (! file_exists($assetFile)) {
            return;
        }

        $asset = require $assetFile;

        wp_enqueue_script(
            'abcnorio-custom-func-editor',
            plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
    }
}