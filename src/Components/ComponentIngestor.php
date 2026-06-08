<?php
namespace abcnorio\CustomFunc\Components;

class ComponentIngestor {
    private const COMPONENT_DIST_DIR_ENV = 'COMPONENT_DIST_DIR';

    private static ?array $manifest = null;

    /**
     * Load the manifest straight from the local bind mount. Fail loudly if missing.
     */
    public static function load(): array {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $component_dist_dir = trim((string) getenv(self::COMPONENT_DIST_DIR_ENV));
        if ($component_dist_dir === '') {
            throw new \RuntimeException("Components System Error: Missing required env var '" . self::COMPONENT_DIST_DIR_ENV . "'.");
        }

        $manifest_path = $component_dist_dir . '/fixtures-manifest.json';

        if (!file_exists($manifest_path)) {
            throw new \RuntimeException("Components System Error: Manifest missing at '{$manifest_path}'. Ensure the Docker bind mount is active and 'npm run build' has run.");
        }

        self::$manifest = json_decode(file_get_contents($manifest_path), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Components System Error: Malformed JSON syntax inside '{$manifest_path}'. Code: " . json_last_error_msg());
        }

        return self::$manifest;
    }

    /**
     * Registers individual block assets natively under the current WordPress domain
     */
    public static function register_block_assets(string $component_name): void {
        $manifest = self::load();

        if (!isset($manifest[$component_name])) {
            throw new \InvalidArgumentException("Components System Error: Component '{$component_name}' is missing from the active build manifest.");
        }

        $meta = $manifest[$component_name];
        
        // Convert the local bind mount directory path into a native local asset URL
        $base_url = '/mu-plugins/abcnorio-components/dist/';

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
     * Injects the raw pre-rendered HTML fixture string directly into the WordPress engine
     */
    public static function render(string $component_name, string $content = ''): string {
        $manifest = self::load();
        
        if (!isset($manifest[$component_name])) {
            throw new \InvalidArgumentException("❌ Components System Error: Unable to render '{$component_name}'. Element data key is absent from the manifest.");
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