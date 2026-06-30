<?php
namespace abcnorio\CustomFunc\Components;

class ComponentIngestor {
    private const COMPONENT_DIST_RELATIVE_PATH = 'resources/vendor/components/dist';

    private static ?array $manifest = null;

    /**
     * Load manifest from plugin-local dist artifacts. Fail loudly if missing.
     */
    public static function load(): array {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $component_dist_dir = self::distDir();

        $manifest_path = $component_dist_dir . '/manifest.json';

        if (!file_exists($manifest_path)) {
            throw new \RuntimeException("Components System Error: Manifest missing at '{$manifest_path}'. Run plugin build to ingest component dist artifacts.");
        }

        self::$manifest = json_decode(file_get_contents($manifest_path), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Components System Error: Malformed JSON syntax inside '{$manifest_path}'. Code: " . json_last_error_msg());
        }

        return self::$manifest;
    }

    public static function distDir(): string {
        $component_dist_dir = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . self::COMPONENT_DIST_RELATIVE_PATH;

        if (!is_dir($component_dist_dir)) {
            throw new \RuntimeException("Components System Error: Dist directory missing at '{$component_dist_dir}'.");
        }

        return $component_dist_dir;
    }

    public static function distUrl(): string {
        return plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . self::COMPONENT_DIST_RELATIVE_PATH . '/';
    }

    public static function runtimeCssUrl(): string {
        $shared_css = self::load()['shared']['css'] ?? [];

        if (!is_array($shared_css)) {
            throw new \RuntimeException("Components System Error: shared.css missing in manifest.");
        }

        if (empty($shared_css)) {
            $shared_css = ['styles/component-fixtures.css'];
        }

        if (!is_string($shared_css[0]) || $shared_css[0] === '') {
            throw new \RuntimeException("Components System Error: shared.css entry is invalid.");
        }

        $runtime_css_path = self::distDir() . '/' . ltrim($shared_css[0], '/');
        if (!file_exists($runtime_css_path)) {
            throw new \RuntimeException("Components System Error: Runtime CSS missing at '{$runtime_css_path}'.");
        }

        return self::distUrl() . ltrim($shared_css[0], '/');
    }

    public static function enqueueRuntimeStyles(): void {
        $shared_css = self::load()['shared']['css'] ?? [];

        if (!is_array($shared_css)) {
            throw new \RuntimeException("Components System Error: shared.css must be an array.");
        }

        if (empty($shared_css)) {
            $shared_css = ['styles/component-fixtures.css'];
        }

        foreach ($shared_css as $index => $relative_path) {
            if (!is_string($relative_path) || $relative_path === '') {
                throw new \RuntimeException("Components System Error: shared.css entry is invalid.");
            }

            $handle = $index === 0 ? 'abcnorio-components-runtime' : "abcnorio-components-runtime-{$index}";
            $absolute_path = self::distDir() . '/' . ltrim($relative_path, '/');

            if (!file_exists($absolute_path)) {
                throw new \RuntimeException("Components System Error: Runtime CSS missing at '{$absolute_path}'.");
            }

            if (!wp_style_is($handle, 'registered')) {
                wp_register_style(
                    $handle,
                    self::distUrl() . ltrim($relative_path, '/'),
                    [],
                    null
                );
            }

            wp_enqueue_style($handle);
        }
    }

    public static function readDistHtml(string $relative_path): string {
        $dist_dir = self::distDir();
        $normalized_path = ltrim($relative_path, '/');
        $absolute_path = $dist_dir . '/' . $normalized_path;

        if (!file_exists($absolute_path)) {
            throw new \RuntimeException("Components System Error: Fixture missing at '{$absolute_path}'.");
        }

        return trim((string) file_get_contents($absolute_path));
    }

    private static function sanitizeComponentHandle(string $component_name): string {
        $normalized = strtolower(str_replace(['/', '_'], '-', $component_name));
        return preg_replace('/[^a-z0-9-]+/', '-', $normalized) ?? $normalized;
    }

    private static function componentMeta(string $component_name): array {
        $manifest = self::load();
        $components = $manifest['components'] ?? null;

        if (!is_array($components) || !isset($components[$component_name]) || !is_array($components[$component_name])) {
            throw new \InvalidArgumentException("Components System Error: Component '{$component_name}' is missing from the active build manifest.");
        }

        $meta = $components[$component_name];
        if (!isset($meta['fixtures']) || !is_array($meta['fixtures'])) {
            throw new \RuntimeException("Components System Error: Component '{$component_name}' fixtures list is invalid.");
        }
        if (!isset($meta['js']) || !is_array($meta['js'])) {
            throw new \RuntimeException("Components System Error: Component '{$component_name}' js list is invalid.");
        }
        if (!isset($meta['css']) || !is_array($meta['css'])) {
            throw new \RuntimeException("Components System Error: Component '{$component_name}' css list is invalid.");
        }
        if (!isset($meta['deps']) || !is_array($meta['deps'])) {
            throw new \RuntimeException("Components System Error: Component '{$component_name}' deps list is invalid.");
        }

        return $meta;
    }

    private static function componentDeps(string $component_name): array {
        $meta = self::componentMeta($component_name);
        return $meta['deps'];
    }

    private static function primaryFixtureMeta(string $component_name): array {
        $manifest = self::load();
        $component_meta = self::componentMeta($component_name);
        $fixture_ids = $component_meta['fixtures'];

        if (empty($fixture_ids) || !is_string($fixture_ids[0])) {
            throw new \RuntimeException("Components System Error: Component '{$component_name}' has no renderable fixtures.");
        }

        $fixture_id = $fixture_ids[0];
        $fixtures = $manifest['fixtures'] ?? null;

        if (!is_array($fixtures) || !isset($fixtures[$fixture_id]) || !is_array($fixtures[$fixture_id])) {
            throw new \RuntimeException("Components System Error: Fixture '{$fixture_id}' missing for component '{$component_name}'.");
        }

        $fixture = $fixtures[$fixture_id];
        if (!isset($fixture['html']) || !is_string($fixture['html'])) {
            throw new \RuntimeException("Components System Error: Fixture '{$fixture_id}' html payload is invalid.");
        }

        return $fixture;
    }

    private static function registerAssetsByType(string $component_name, array $paths, string $type): void {
        $base_url = self::distUrl();
        $base_path = self::distDir();
        $component_slug = self::sanitizeComponentHandle($component_name);

        foreach ($paths as $index => $relative_path) {
            if (!is_string($relative_path) || $relative_path === '') {
                throw new \RuntimeException("Components System Error: Invalid {$type} path for component '{$component_name}'.");
            }

            $absolute_path = $base_path . '/' . ltrim($relative_path, '/');
            if (!file_exists($absolute_path)) {
                throw new \RuntimeException("Components System Error: Missing {$type} asset '{$absolute_path}' for component '{$component_name}'.");
            }

            $handle = "abc-{$type}-{$component_slug}-{$index}";
            $asset_url = esc_url($base_url . ltrim($relative_path, '/'));

            if ($type === 'script') {
                if (!wp_script_is($handle, 'registered')) {
                    wp_register_script($handle, $asset_url, [], null, true);
                }
                continue;
            }

            if (!wp_style_is($handle, 'registered')) {
                wp_register_style($handle, $asset_url, [], null);
            }
        }
    }

    private static function depAssetType(string $relative_path): string {
        $extension = strtolower((string) pathinfo($relative_path, PATHINFO_EXTENSION));

        if ($extension === 'css') {
            return 'style';
        }

        if ($extension === 'js' || $extension === 'mjs') {
            return 'script';
        }

        throw new \RuntimeException("Components System Error: Unsupported dep asset type for '{$relative_path}'.");
    }

    private static function depHandle(string $component_name, string $relative_path): string {
        $component_slug = self::sanitizeComponentHandle($component_name);
        $asset_slug = self::sanitizeComponentHandle(pathinfo($relative_path, PATHINFO_FILENAME));
        $asset_type = self::depAssetType($relative_path);
        $hash = substr(md5($relative_path), 0, 8);

        return "abcnorio-dep-{$asset_type}-{$component_slug}-{$asset_slug}-{$hash}";
    }

    private static function registerDepAsset(string $component_name, string $relative_path): void {
        if ($relative_path === '') {
            throw new \RuntimeException("Components System Error: Empty dep path for component '{$component_name}'.");
        }

        $absolute_path = self::distDir() . '/' . ltrim($relative_path, '/');
        if (!file_exists($absolute_path)) {
            throw new \RuntimeException("Components System Error: Missing dep asset '{$absolute_path}' for component '{$component_name}'.");
        }

        $handle = self::depHandle($component_name, $relative_path);
        $asset_url = esc_url(self::distUrl() . ltrim($relative_path, '/'));
        $asset_type = self::depAssetType($relative_path);

        if ($asset_type === 'script') {
            if (!wp_script_is($handle, 'registered')) {
                wp_register_script($handle, $asset_url, [], null, true);
            }
            return;
        }

        if (!wp_style_is($handle, 'registered')) {
            wp_register_style($handle, $asset_url, [], null);
        }
    }

    private static function enqueueAssetsByType(string $component_name, array $paths, string $type): void {
        $component_slug = self::sanitizeComponentHandle($component_name);

        foreach ($paths as $index => $relative_path) {
            $handle = "abc-{$type}-{$component_slug}-{$index}";
            if ($type === 'script') {
                wp_enqueue_script($handle);
                continue;
            }

            wp_enqueue_style($handle);
        }
    }

    public static function register_component_deps(string $component_name): void {
        $deps = self::componentDeps($component_name);

        foreach ($deps as $relative_path) {
            if (!is_string($relative_path)) {
                throw new \RuntimeException("Components System Error: Invalid dep path for component '{$component_name}'.");
            }

            self::registerDepAsset($component_name, $relative_path);
        }
    }

    public static function enqueue_component_deps(string $component_name): void {
        $deps = self::componentDeps($component_name);

        self::register_component_deps($component_name);

        foreach ($deps as $relative_path) {
            if (!is_string($relative_path)) {
                throw new \RuntimeException("Components System Error: Invalid dep path for component '{$component_name}'.");
            }

            $handle = self::depHandle($component_name, $relative_path);
            $asset_type = self::depAssetType($relative_path);

            if ($asset_type === 'script') {
                wp_enqueue_script($handle);
                continue;
            }

            wp_enqueue_style($handle);
        }
    }

    /**
     * Register component assets from plugin-local dist URLs.
     */
    public static function register_block_assets(string $component_name): void {
        $meta = self::componentMeta($component_name);

        self::registerAssetsByType($component_name, $meta['js'], 'script');
        self::registerAssetsByType($component_name, $meta['css'], 'style');
    }

    /**
     * Render prebuilt fixture HTML and enqueue only required component assets.
     */
    public static function render(string $component_name, string $content = ''): string {
        $meta = self::componentMeta($component_name);
        $fixture = self::primaryFixtureMeta($component_name);
        $raw_html = $fixture['html'];

        self::enqueueRuntimeStyles();
        self::register_block_assets($component_name);
        self::enqueue_component_deps($component_name);
        self::enqueueAssetsByType($component_name, $meta['js'], 'script');
        self::enqueueAssetsByType($component_name, $meta['css'], 'style');

        // If the component contains an inner slot placeholder, swap it natively
        if (!empty($content)) {
            return str_replace('<slot name="card-body" />', $content, $raw_html);
        }

        return $raw_html;
    }
}