<?php
$root = sys_get_temp_dir() . '/hero-shell-' . bin2hex(random_bytes(8));
$dist = $root . '/resources/vendor/components/dist';
mkdir($root . '/build', 0777, true);
mkdir($dist . '/hero', 0777, true);
mkdir($dist . '/announcement-tout', 0777, true);
mkdir($dist . '/gallery', 0777, true);
mkdir($dist . '/newsletter-signup', 0777, true);
mkdir($dist . '/public-form', 0777, true);
mkdir($dist . '/press-item', 0777, true);
define('ABCNORIO_CUSTOM_FUNC_FILE', $root . '/custom-func.php');
file_put_contents($root . '/build/index.asset.php', '<?php return ["dependencies" => [], "version" => "test"];');
$html = '<div class="abcnorio-hero"><div>__EDITOR_SLOT_IMAGE__</div><div>__EDITOR_SLOT_CONTENT__</div></div>';
file_put_contents($dist . '/hero/editor.html', $html);
file_put_contents($dist . '/announcement-tout/editor.html', $html);
file_put_contents($dist . '/gallery/editor.html', $html);
file_put_contents($dist . '/newsletter-signup/editor.html', $html);
file_put_contents($dist . '/public-form/editor.html', $html);
file_put_contents($dist . '/press-item/editor.html', $html);
file_put_contents($dist . '/manifest.json', json_encode(['components' => [
    'hero' => ['fixtures' => ['hero', 'hero/editor']],
    'announcement-tout' => ['fixtures' => ['announcement-tout', 'announcement-tout/editor']],
    'gallery' => ['fixtures' => ['gallery', 'gallery/editor']],
    'newsletter-signup' => ['fixtures' => ['newsletter-signup', 'newsletter-signup/editor']],
    'public-form' => ['fixtures' => ['public-form', 'public-form/editor']],
    'press-item' => ['fixtures' => ['press-item', 'press-item/editor']],
]]));
register_shutdown_function(static function () use ($root, $dist): void {
    foreach ([$root . '/build/index.asset.php', $dist . '/hero/editor.html', $dist . '/announcement-tout/editor.html', $dist . '/gallery/editor.html', $dist . '/newsletter-signup/editor.html', $dist . '/public-form/editor.html', $dist . '/press-item/editor.html', $dist . '/manifest.json'] as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach ([$root . '/build', $dist . '/hero', $dist . '/announcement-tout', $dist . '/gallery', $dist . '/newsletter-signup', $dist . '/public-form', $dist . '/press-item', $dist, dirname($dist), dirname($dist, 2), dirname($dist, 3), $root] as $dir) {
        rmdir($dir);
    }
});
$inline = [];
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'http://wordpress.test/plugin/'; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_enqueue_script(...$args) {}
function wp_add_inline_script($handle, $data, $position) {
    $GLOBALS['inline'][] = compact('handle', 'data', 'position');
}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
require dirname(__DIR__) . '/src/Components/ComponentIngestor.php';
require dirname(__DIR__) . '/src/Plugin.php';
use abcnorio\CustomFunc\Plugin;

foreach (['local', 'development', 'staging', 'production'] as $environment) {
    $inline = [];
    $_GET = [];
    Plugin::enqueueEditorAssets();
    if (count($inline) !== 1
        || $inline[0]['handle'] !== 'abcnorio-custom-func-editor'
        || $inline[0]['position'] !== 'before'
        || $inline[0]['data'] !== 'window.abcnorioEditorShells = ' . wp_json_encode(
            ['hero' => $html, 'announcement-tout' => $html, 'gallery' => $html, 'newsletter-signup' => $html, 'public-form' => $html, 'press-item' => $html],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';'
        || str_contains($inline[0]['data'], '<')) {
        throw new RuntimeException("Hero shell must load safely before the editor bundle in {$environment}.");
    }
}
unlink($dist . '/hero/editor.html');
try {
    Plugin::enqueueEditorAssets();
    throw new LogicException('Missing hero fixture must not silently fall back.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'Fixture missing')) throw $error;
}
echo "Hero shell loading checks passed in all environments; missing fixture fails explicitly.\n";
