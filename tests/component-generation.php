<?php
$root = sys_get_temp_dir() . '/component-generation-' . bin2hex(random_bytes(6));
$base = $root . '/resources/vendor/components';
mkdir($base, 0777, true);
define('ABCNORIO_CUSTOM_FUNC_FILE', $root . '/plugin.php');
$environment = 'development';
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'http://wordpress.test/plugin/'; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
require dirname(__DIR__) . '/src/Components/ComponentIngestor.php';
use abcnorio\CustomFunc\Components\ComponentIngestor;
$first = 'dev-' . str_repeat('a', 24);
$second = 'dev-' . str_repeat('b', 24);
$pointer = $base . '/dev-current.json';
$verify = static function ($condition) {
    if (!$condition) throw new RuntimeException('Generation selection failed.');
};
try {
    foreach ([$first, $second, 'dist'] as $name) mkdir($base . '/' . $name);
    file_put_contents($pointer, json_encode($first));
    $verify(str_ends_with(ComponentIngestor::distDir(), $first));
    file_put_contents($pointer, json_encode($second));
    $verify(str_ends_with(ComponentIngestor::distUrl(), $first . '/'));
    $property = new ReflectionProperty(ComponentIngestor::class, 'activeRelativePath');
    $property->setValue(null, null);
    $verify(str_ends_with(ComponentIngestor::distDir(), $second));
    foreach (['staging', 'production'] as $environment) {
        $property->setValue(null, null);
        $verify(str_ends_with(ComponentIngestor::distDir(), '/dist'));
    }
    $environment = 'development';
    $property->setValue(null, null);
    file_put_contents($pointer, '"../../unsafe"');
    try {
        ComponentIngestor::distDir();
        throw new LogicException('Invalid pointer accepted.');
    } catch (RuntimeException $error) {
        $verify(str_contains($error->getMessage(), 'Invalid development'));
    }
    echo "Component generation checks passed.\n";
} finally {
    unlink($pointer);
    foreach ([$first, $second, 'dist'] as $name) rmdir($base . '/' . $name);
    rmdir($base);
    rmdir(dirname($base));
    rmdir($root . '/resources');
    rmdir($root);
}
