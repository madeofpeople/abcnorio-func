<?php

namespace abcnorio\CustomFunc;
use abcnorio\CustomFunc\ContentModel\ACFFieldGroups;
use abcnorio\CustomFunc\ContentModel\PostTypeRegistrar;
use abcnorio\CustomFunc\ContentModel\TaxonomyRegistrar;
use abcnorio\CustomFunc\ContentModel\CollectivePostSeeder;
use abcnorio\CustomFunc\ContentModel\MinimalContentSeeder;
use abcnorio\CustomFunc\ContentModel\TaxonomyTermSeeder;
use abcnorio\CustomFunc\AdminExperience\AdminExperience;
use abcnorio\CustomFunc\Deployment\Deployment;
use abcnorio\CustomFunc\ImageStyles\BlockImageAttributeEnricher;
use abcnorio\CustomFunc\ImageStyles\ImageStyleRegistrar;
use abcnorio\CustomFunc\Navigation\MenuRegistrar;
use abcnorio\CustomFunc\RestApi\EventQueryFilters;
use abcnorio\CustomFunc\RestApi\FeaturedImageField;
use abcnorio\CustomFunc\RestApi\ContentListingEndpoint;
use abcnorio\CustomFunc\RestApi\ICalEndpoint;
use abcnorio\CustomFunc\Security\CapabilityManager;
use abcnorio\CustomFunc\Security\LoginAlias;
use abcnorio\CustomFunc\Blocks\Patterns;
use abcnorio\CustomFunc\Blocks\EventListingQuery;
use abcnorio\CustomFunc\Dashboard\Dashboard;
use abcnorio\CustomFunc\Components\ComponentIngestor;

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
        MinimalContentSeeder::registerCliCommand();
        AdminExperience::registerHooks();
        Dashboard::registerHooks();
        Patterns::registerHooks();
        EventListingQuery::registerHooks();
        Deployment::registerHooks();
        EventQueryFilters::registerHooks();
        FeaturedImageField::registerHooks();
        ContentListingEndpoint::registerHooks();
        ICalEndpoint::registerHooks();
        LoginAlias::registerHooks();
        ACFFieldGroups::registerHooks();
        ImageStyleRegistrar::registerHooks();
        BlockImageAttributeEnricher::registerHooks();
        MenuRegistrar::registerHooks();
        add_action('after_setup_theme', [self::class, 'enableFeaturedImages']);
        add_action('enqueue_block_assets', [self::class, 'enqueueComponentRuntimeStyles']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
        add_action('admin_init', [CapabilityManager::class, 'maybeMigrateCapabilities'], 1);
        add_action('init', [self::class, 'registerContentModels']);
        add_action('init', [self::class, 'disableComments'], 15);
        add_action('init', [TaxonomyTermSeeder::class, 'maybeSeedDefaults'], 20);
        add_action('init', [CollectivePostSeeder::class, 'maybeSeedDefaults'], 30);
        add_action('save_post_collective', [CollectivePostSeeder::class, 'maybeAssignTermOnSave'], 10, 1);
        add_action('init', [self::class, 'ingestAstroComponentLibrary'], 40);
        add_action('init', [self::class, 'unregisterSeedPostType'], 999);
        remove_action( 'enqueue_block_editor_assets', [self::class, 'wp_enqueue_editor_block_directory_assets'], 999);
    }

    public static function ingestAstroComponentLibrary(): void
    {
        try {
            $manifest = ComponentIngestor::load();
            $skipBlocks = [
                'event-listing',
            ];
            
            // Automate asset handling based on compiled artifacts
            foreach (array_keys($manifest) as $component_name) {
                if (in_array($component_name, $skipBlocks, true)) {
                    continue;
                }

                ComponentIngestor::register_block_assets($component_name);
                
                // Dynamically create the Gutenberg block type registration hook
                register_block_type("abcnorio/{$component_name}", [
                    'render_callback' => function($attributes, $content) use ($component_name) {
                        return ComponentIngestor::render($component_name, $content);
                    }
                ]);
            }
        } catch (\Exception $e) {
            // Fail loudly if the file infrastructure or manifest contract breaks
            wp_die($e->getMessage(), 'Components Engine Failure', ['response' => 500]);
        }
    }
    
    public static function registerContentModels(): void
    {
        $postTypes = require __DIR__ . '/ContentModel/post-types.php';
        $taxonomies = require __DIR__ . '/ContentModel/taxonomies.php';
        PostTypeRegistrar::registerMany($postTypes);
        TaxonomyRegistrar::registerMany($taxonomies);
    }

    public static function enableFeaturedImages(): void
    {
        add_theme_support('post-thumbnails', ['post', 'page', 'event', 'collective', 'article']);
    }

    public static function unregisterSeedPostType(): void
    {
        if (post_type_exists('seed')) {
            unregister_post_type('seed');
        }
    }

    public static function disableComments (): void
    {
        add_action('admin_init', function () {
            // Redirect any user trying to access comments page
            global $pagenow;

            if ($pagenow === 'edit-comments.php') {
                wp_redirect(admin_url());
                exit;
            }

            // Remove comments metabox from dashboard
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

            // Disable support for comments and trackbacks in post types
            foreach (get_post_types() as $post_type) {
                if (post_type_supports($post_type, 'comments')) {
                    remove_post_type_support($post_type, 'comments');
                    remove_post_type_support($post_type, 'trackbacks');
                }
            }
        });
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);
        add_filter('comments_array', '__return_empty_array', 10, 2);
        add_action('admin_menu', function () {
            remove_menu_page('edit-comments.php');
        });
        add_action('init', function () {
            if (is_admin_bar_showing()) {
                remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
            }
        });        
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
            false
        );

        self::enqueueComponentRuntimeStyles();
    }

    public static function enqueueComponentRuntimeStyles(): void
    {
        ComponentIngestor::enqueueRuntimeStyles();
    }

}