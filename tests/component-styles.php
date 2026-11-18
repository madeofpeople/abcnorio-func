<?php
define('ABCNORIO_CUSTOM_FUNC_FILE', dirname(__DIR__) . '/custom-func.php');
$styles = [];
$registrations = [];
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'http://wordpress.test/plugin/'; }
function is_admin() { return true; }
function esc_url($url) { return $url; }
function wp_get_environment_type() { return 'development'; }
function wp_style_is($handle, $status) { return isset($GLOBALS['styles'][$handle]); }
function wp_register_style($handle, $url, $deps = [], $version = null) {
    $GLOBALS['styles'][$handle] = compact('url', 'deps', 'version');
    $GLOBALS['registrations'][] = $url;
}
function wp_enqueue_style($handle) {}
require dirname(__DIR__) . '/src/Components/ComponentIngestor.php';
use abcnorio\CustomFunc\Components\ComponentIngestor;
$verify = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
putenv('DEV_FRONTEND_URL');
ComponentIngestor::enqueueRuntimeStyles();
ComponentIngestor::enqueueEditorStyles();
ComponentIngestor::enqueueRuntimeStyles();
ComponentIngestor::enqueueEditorStyles();
$urls = array_column($styles, 'url');
foreach ($styles as $style) {
    $verify(str_starts_with($style['url'], ComponentIngestor::distUrl()), 'All styles must use plugin-local assets.');
    $verify(is_string($style['version']) && $style['version'] !== '', 'Styles need file versions.');
}
$verify(count($urls) === count(array_unique($urls)), 'Styles must not be registered twice.');
$verify(count($registrations) === count(array_unique($registrations)), 'Repeated hooks must not register the same URL again.');
$verify(isset($styles['abcnorio-components-editor']), 'Editor needs combined fixture CSS.');
$handle = new ReflectionMethod(ComponentIngestor::class, 'depHandle');
$verify($handle->invoke(null, 'event-listing', 'styles/event-listing.css') === 'abcnorio-components-runtime-5', 'Shared dependencies must reuse runtime handles.');
echo "Component style loading checks passed.\n";
