<?php

define('ABCNORIO_CUSTOM_FUNC_FILE', dirname(__DIR__) . '/custom-func.php');
$admin = true;
$environment = 'production';
$styles = [];
$queued = [];
$registrations = [];

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'http://wordpress.test/plugin/'; }
function is_admin() { return $GLOBALS['admin']; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_parse_url($url) { return parse_url($url); }
function esc_url($url) { return $url; }
function wp_style_is($handle, $status) { return isset($GLOBALS['styles'][$handle]); }
function wp_register_style($handle, $url, $deps = [], $version = null) {
    $GLOBALS['styles'][$handle] = compact('url', 'deps', 'version');
    $GLOBALS['registrations'][] = $url;
}
function wp_enqueue_style($handle, $url = null, $deps = [], $version = null) {
    if ($url !== null) {
        wp_register_style($handle, $url, $deps, $version);
    }
    $GLOBALS['queued'][$handle] = true;
}

require dirname(__DIR__) . '/src/Components/ComponentIngestor.php';

use abcnorio\CustomFunc\Components\ComponentIngestor;

$verify = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$url = new ReflectionMethod(ComponentIngestor::class, 'styleUrl');
$path = 'styles/hero.css';
$verify($url->invoke(null, $path) === ComponentIngestor::distUrl() . $path, 'Packaged CSS must be the default.');
$environment = 'development';
$origin = 'http://localhost:3133';
putenv('DEV_FRONTEND_URL=' . $origin);
$verify($url->invoke(null, $path) === $origin . '/__abcnorio/styles/hero.css', 'Local editor must use Astro.');
$verify($url->invoke(null, 'styles/editor/hero.css') === $origin . '/__abcnorio/styles/hero-editor.css', 'Editor SCSS must use Astro.');
$verify($url->invoke(null, 'styles/article-listing.css') === ComponentIngestor::distUrl() . 'styles/article-listing.css', 'Unmigrated styles must stay packaged.');
$admin = false;
$verify($url->invoke(null, $path) === ComponentIngestor::distUrl() . $path, 'Public WordPress requests must remain packaged.');
$admin = true;

foreach (['staging', 'production'] as $environment) {
    $verify($url->invoke(null, $path) === ComponentIngestor::distUrl() . $path, 'Staging and production must always use packaged CSS.');
}
$environment = 'development';
foreach (['', 'javascript:bad', 'http://localhost:3133/path', 'http://user:pass@localhost:3133', 'http://localhost:3133/?x=1'] as $origin) {
    putenv('DEV_FRONTEND_URL=' . $origin);
    try {
        $url->invoke(null, $path);
        throw new LogicException('Invalid origin accepted.');
    } catch (RuntimeException $error) {
        $verify(str_contains($error->getMessage(), 'HTTP(S) origin'), 'Unexpected validation error.');
    }
}
$origin = 'http://localhost:3133';
putenv('DEV_FRONTEND_URL=' . $origin);
ComponentIngestor::enqueueRuntimeStyles();
ComponentIngestor::enqueueEditorStyles();
ComponentIngestor::enqueueRuntimeStyles();
ComponentIngestor::enqueueEditorStyles();
$urls = array_column($styles, 'url');
$verify(count(array_filter($urls, static fn ($value) => str_starts_with($value, $origin))) === 11, 'Expected exactly seven portable and four editor live styles.');
$verify(count($urls) === count(array_unique($urls)), 'Styles must not be registered twice.');
$verify(count($registrations) === count(array_unique($registrations)), 'Repeated enqueue hooks must not register the same URL again.');
$verify(isset($styles['abcnorio-components-editor']), 'Editor still needs the combined fixture CSS alongside the base runtime CSS.');
$handle = new ReflectionMethod(ComponentIngestor::class, 'depHandle');
$verify($handle->invoke(null, 'event-listing', 'styles/event-listing.css') === 'abcnorio-components-runtime-5', 'Shared dependencies must reuse runtime handles.');
foreach ($styles as $style) {
    $verify(is_string($style['version']) && $style['version'] !== '', 'Styles need file versions.');
}
echo "Component style loading checks passed.\n";
