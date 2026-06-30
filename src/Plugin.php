<?php

namespace abcnorio\CustomFunc;
use abcnorio\CustomFunc\ContentModel\ACFFieldGroups;
use abcnorio\CustomFunc\ContentModel\PostTypeRegistrar;
use abcnorio\CustomFunc\ContentModel\TaxonomyRegistrar;
use abcnorio\CustomFunc\ContentModel\SidebarScopeSaveGuard;
use abcnorio\CustomFunc\AdminExperience\AdminExperience;
use abcnorio\CustomFunc\Deployment\Deployment;
use abcnorio\CustomFunc\ImageStyles\BlockImageAttributeEnricher;
use abcnorio\CustomFunc\ImageStyles\ImageStyleRegistrar;
use abcnorio\CustomFunc\Navigation\MenuRegistrar;
use abcnorio\CustomFunc\RestApi\EventQueryFilters;
use abcnorio\CustomFunc\RestApi\FeaturedImageField;
use abcnorio\CustomFunc\RestApi\ContentListingEndpoint;
use abcnorio\CustomFunc\RestApi\ICalEndpoint;
use abcnorio\CustomFunc\RestApi\SidebarBlocksField;
use abcnorio\CustomFunc\Security\CapabilityManager;
use abcnorio\CustomFunc\Security\LoginAlias;
use abcnorio\CustomFunc\Blocks\Patterns;
use abcnorio\CustomFunc\Blocks\EventListingQuery;
use abcnorio\CustomFunc\Blocks\ContentListingQuery;
use abcnorio\CustomFunc\Blocks\CollectiveListingQuery;
use abcnorio\CustomFunc\Dashboard\Dashboard;
use abcnorio\CustomFunc\Components\ComponentIngestor;

final class Plugin
{
    public static function activate(): void
    {
        self::registerContentModels();
        CapabilityManager::forceMigrateCapabilities();
    }

    public static function boot(): void
    {
        /*  URL redirects for headless wordpress, admin UX bits */
        AdminExperience::registerHooks();
        /*  Removes useless crap */
        Dashboard::registerHooks();
        /*  Assembled blocks */
        Patterns::registerHooks();
        /*  Event listing wordpress block */
        EventListingQuery::registerHooks();
        /*  Content listing wordpress block */
        ContentListingQuery::registerHooks();
        /*  Collective listing wordpress block */
        CollectiveListingQuery::registerHooks();
        /*  Deployment dashboard */
        Deployment::registerHooks();
        /*  Allows advanced querying of Events */
        EventQueryFilters::registerHooks();
        /*  Makes featured image accessible from REST API */
        FeaturedImageField::registerHooks();
        /*  Joing the different post types that get listed in ContentListing */        
        ContentListingEndpoint::registerHooks();
        /*  Outputs all events as an ics for subscribing to from cals */
        ICalEndpoint::registerHooks();
        /*  Login redirection */
        LoginAlias::registerHooks();
        /*  ACF Field Groups — custom post type fields */
        ACFFieldGroups::registerHooks();
        /*  Provides sidebar blocks to associated pages, and makes them accessible to REST API */
        SidebarBlocksField::registerHooks();
        /*  Keeps us from assigning multiple sidebars to a single post
        handles postType -> perInstance cascading */  
        SidebarScopeSaveGuard::registerHooks();
        /*  Image styles */
        ImageStyleRegistrar::registerHooks();
        /*  Responsive image support for block editor api endpoints */
        BlockImageAttributeEnricher::registerHooks();
        /* Registers our menus */
        MenuRegistrar::registerHooks();
        add_action('after_setup_theme', [self::class, 'enableFeaturedImages']);
        add_action('enqueue_block_assets', [self::class, 'enqueueBlockComponentAssets']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueWpComponentOverridesForEditor'], 120);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueWpComponentOverrides'], 120);
        add_action('admin_init', [CapabilityManager::class, 'maybeMigrateCapabilities'], 1);
        add_action('init', [self::class, 'registerContentModels']);
        add_action('init', [self::class, 'disableComments'], 15);
        add_action('init', [self::class, 'ingestAstroComponentLibrary'], 40);
        remove_action( 'enqueue_block_editor_assets', [self::class, 'wp_enqueue_editor_block_directory_assets'], 999);
    }

    public static function ingestAstroComponentLibrary(): void
    {
        try {
            $manifest = ComponentIngestor::load();
            $components = $manifest['components'] ?? [];

            if (!is_array($components)) {
                throw new \RuntimeException('Components System Error: manifest.components must be an object.');
            }

            $skipBlocks = [
                'event-listing',
                'content-listing',
                'collective-listing',
            ];
            
            // Automate asset handling based on compiled artifacts
            foreach (array_keys($components) as $component_name) {
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
        } catch (\Throwable $e) {
            self::handleComponentSystemFailure($e, 'component ingestion');
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
    }

    public static function enqueueBlockComponentAssets(): void
    {
        self::enqueueComponentRuntimeStyles();

        try {
            ComponentIngestor::enqueue_component_deps('event-listing');
            ComponentIngestor::enqueue_component_deps('content-listing');
        } catch (\Throwable $e) {
            self::handleComponentSystemFailure($e, 'block component deps enqueue');
        }

        self::enqueueMatchingComponentOverrideStyles(self::currentRequestPostContent());
    }

    public static function enqueueWpComponentOverrides(): void
    {
        if (is_admin()) {
            return;
        }

        self::enqueueMatchingComponentOverrideStyles(self::currentRequestPostContent());
    }

    public static function enqueueWpComponentOverridesForEditor(): void
    {
        if (!is_admin()) {
            return;
        }

        self::enqueueMatchingComponentOverrideStyles(self::currentRequestPostContent());
    }

    private static function currentRequestPostContent(): string
    {
        if (is_admin()) {
            $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
            if ($postId > 0) {
                $post = get_post($postId);
                if ($post instanceof \WP_Post) {
                    return (string) $post->post_content;
                }
            }
        }

        $queried = get_queried_object();
        if ($queried instanceof \WP_Post) {
            return (string) $queried->post_content;
        }

        global $post;
        if ($post instanceof \WP_Post) {
            return (string) $post->post_content;
        }

        return '';
    }

    private static function enqueueMatchingComponentOverrideStyles(string $postContent): void
    {
        if ($postContent === '') {
            return;
        }

        // only run if the listing is on the page.
        $hasEventListing = $postContent !== '' && has_block('abcnorio/event-listing', $postContent);
        $hasContentListing = $postContent !== '' && has_block('abcnorio/content-listing', $postContent);

        if ($hasEventListing) {
            wp_enqueue_style(
                'abcnorio-event-listing-overrides',
                plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . 'resources/css/event-listing-overrides.css',
                [],
                null
            );
        }

        if ($hasContentListing) {
            wp_enqueue_style(
                'abcnorio-content-listing-overrides',
                plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . 'resources/css/content-listing-overrides.css',
                [],
                null
            );
        }
    }

    public static function enqueueComponentRuntimeStyles(): void
    {
        try {
            ComponentIngestor::enqueueRuntimeStyles();
        } catch (\Throwable $e) {
            self::handleComponentSystemFailure($e, 'runtime styles enqueue');
        }
    }

    private static function shouldFailLoudForComponents(): bool
    {
        return is_admin();
    }

    private static function handleComponentSystemFailure(\Throwable $error, string $context): void
    {
        if (self::shouldFailLoudForComponents()) {
            wp_die($error->getMessage(), 'Components Engine Failure', ['response' => 500]);
        }

        $message = sprintf('[abcnorio-func] Component system skipped in %s: %s', $context, $error->getMessage());
        error_log($message);
    }

}