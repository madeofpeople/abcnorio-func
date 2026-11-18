<?php
$scripts = wp_scripts();
$seen = [];
$rows = [];
$visit = static function (string $id) use (&$visit, &$seen, &$rows, $scripts): void {
    if (isset($seen[$id])) return;
    $seen[$id] = true;
    $script = $scripts->registered[$id] ?? null;
    if ($script === null) throw new RuntimeException("Unknown public editor script: {$id}");
    foreach ($script->deps as $dependency) $visit($dependency);
    if (!$script->src) return;
    $source = $script->src;
    if (str_starts_with($source, '/')) $source = rtrim($scripts->base_url, '/') . $source;
    $rows[] = ['id' => $id, 'src' => $source];
};
foreach (['wp-block-library', 'wp-block-editor', 'wp-components'] as $handle) $visit($handle);
echo wp_json_encode($rows, JSON_THROW_ON_ERROR);
