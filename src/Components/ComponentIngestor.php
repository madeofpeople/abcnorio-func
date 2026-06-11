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

        $manifest_path = $component_dist_dir . '/fixtures-manifest.json';

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
        $runtime_css_path = self::distDir() . '/styles/components.css';

        if (!file_exists($runtime_css_path)) {
            throw new \RuntimeException("Components System Error: Runtime CSS missing at '{$runtime_css_path}'.");
        }

        return self::distUrl() . 'styles/components.css';
    }

    public static function enqueueRuntimeStyles(): void {
        if (! wp_style_is('abcnorio-components-runtime', 'registered')) {
            wp_register_style(
                'abcnorio-components-runtime',
                self::runtimeCssUrl(),
                [],
                null
            );
        }

        wp_enqueue_style('abcnorio-components-runtime');
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

    /**
     * Register component block assets from plugin-local dist URLs.
     */
    public static function register_block_assets(string $component_name): void {
        $manifest = self::load();

        if (!isset($manifest[$component_name])) {
            throw new \InvalidArgumentException("Components System Error: Component '{$component_name}' is missing from the active build manifest.");
        }

        $meta = $manifest[$component_name];
        
        $base_url = self::distUrl();

        if (!empty($meta['scriptPath'])) {
            wp_register_script(
                "abc-script-{$component_name}",
                esc_url($base_url . $meta['scriptPath']),
                [],
                null,
                true // Forces execution in the footer for optimal hydration
            );
        }

        if (!empty($meta['stylePath'])) {
            wp_register_style(
                "abc-style-{$component_name}",
                esc_url($base_url . $meta['stylePath']),
                [],
                null
            );
        }
    }

    /**
     * Render prebuilt fixture HTML and enqueue only required component assets.
     */
    public static function render(string $component_name, string $content = ''): string {
        $manifest = self::load();
        
        if (!isset($manifest[$component_name])) {
            throw new \InvalidArgumentException("Components System Error: Unable to render '{$component_name}'. Element data key is absent from the manifest.");
        }

        $raw_html = $manifest[$component_name]['html'];

        // Enqueue only the specific component assets needed on this page load (Strategy B)
        wp_enqueue_script("abc-script-{$component_name}");
        wp_enqueue_style("abc-style-{$component_name}");

        // If the component contains an inner slot placeholder, swap it natively
        if (!empty($content)) {
            return str_replace('<slot name="card-body" />', $content, $raw_html);
        }

        return $raw_html;
    }
}