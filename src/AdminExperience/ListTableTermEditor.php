<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class ListTableTermEditor
{
    /**
     * post_type => taxonomies editable from list table.
     *
     * @var array<string, list<string>>
     */
    private const MAP = [
        'post'  => ['post_tag'],
        'event' => ['collective_association', 'event_type', 'event_tag'],
    ];

    /** @var array<string, list<\WP_Term>> */
    private static array $termsByTaxonomy = [];

    private const LOCAL_SELECTWOO_SCRIPT_HANDLE = 'abcnorio-selectwoo';
    private const LOCAL_SELECTWOO_STYLE_HANDLE = 'abcnorio-selectwoo';
    private const COLUMN_PREFIX = 'abcnorio-term-';

    public static function registerHooks(): void
    {
        if (! is_admin()) {
            return;
        }

        foreach (array_keys(self::MAP) as $postType) {
            add_filter("manage_{$postType}_posts_columns", [self::class, 'replaceColumns'], 999);
            add_filter("manage_edit-{$postType}_columns", [self::class, 'replaceColumns'], 999);
            add_action("manage_{$postType}_posts_custom_column", [self::class, 'renderColumn'], 10, 2);
        }

        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_abcnorio_set_post_terms', [self::class, 'ajaxSetPostTerms']);
    }

    public static function replaceColumns(array $columns): array
    {
        $postType = self::currentPostType();

        if ($postType === '' || ! isset(self::MAP[$postType])) {
            return $columns;
        }

        foreach (self::MAP[$postType] as $taxonomy) {
            unset($columns["taxonomy-{$taxonomy}"]);
        }

        foreach (self::MAP[$postType] as $taxonomy) {
            $columnKey = self::columnKey($taxonomy);
            $taxonomyObj = get_taxonomy($taxonomy);

            if (! $taxonomyObj) {
                continue;
            }

            $columns[$columnKey] = $taxonomyObj->labels->name ?: $taxonomyObj->label ?: $columnKey;
        }

        return $columns;
    }

    public static function renderColumn(string $column, int $postId): void
    {
        if (! self::canManage()) {
            return;
        }

        $postType = get_post_type($postId);

        if (! is_string($postType) || ! isset(self::MAP[$postType])) {
            return;
        }

        $taxonomy = self::taxonomyFromColumn($column, $postType);

        if ($taxonomy === null) {
            return;
        }

        $taxonomyObj = get_taxonomy($taxonomy);

        if (! $taxonomyObj || ! current_user_can($taxonomyObj->cap->assign_terms)) {
            return;
        }

        $terms = self::getTermsForTaxonomy($taxonomy);

        if ($terms === []) {
            return;
        }

        $canCreateTerms = current_user_can($taxonomyObj->cap->manage_terms);

        $selectedLookup = array_fill_keys(self::getSelectedTermIds($postId, $taxonomy), true);

        echo '<div class="abcnorio-term-editor">';
        echo '<select';
        echo ' id="abcnorio-term-editor-' . esc_attr((string) $postId) . '-' . esc_attr($taxonomy) . '"';
        echo ' class="abcnorio-term-editor__select"';
        echo ' data-post-id="' . esc_attr((string) $postId) . '"';
        echo ' data-taxonomy="' . esc_attr($taxonomy) . '"';
        echo ' data-can-create-terms="' . ($canCreateTerms ? '1' : '0') . '"';
        echo ' multiple';
        echo ' aria-label="' . esc_attr($taxonomyObj->labels->singular_name ?: $taxonomyObj->label ?: $taxonomy) . '"';
        echo '>';

        foreach ($terms as $term) {
            $selected = isset($selectedLookup[(int) $term->term_id]) ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $term->term_id) . '"' . $selected . '>' . esc_html($term->name) . '</option>';
        }

        echo '</select>';
        echo '<p class="abcnorio-term-editor__status" aria-live="polite"></p>';
        echo '</div>';
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'edit.php' || ! self::canManage()) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen instanceof \WP_Screen) {
            return;
        }

        $postType = (string) $screen->post_type;

        if (! isset(self::MAP[$postType])) {
            return;
        }

        $enhancerDependency = self::enqueueSelectEnhancer();

        $relativePath = 'resources/js/admin-term-editor.js';
        $absolutePath = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . $relativePath;

        if (! file_exists($absolutePath)) {
            return;
        }

        wp_enqueue_script(
            'abcnorio-custom-func-term-editor',
            plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . $relativePath,
            $enhancerDependency !== null ? ['jquery', $enhancerDependency] : ['jquery'],
            (string) filemtime($absolutePath),
            true
        );

        wp_localize_script('abcnorio-custom-func-term-editor', 'abcnorioTermEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('abcnorio-term-editor'),
            'messages' => [
                'saving' => __('Saving…', 'abcnorio-func'),
                'saved'  => __('Saved', 'abcnorio-func'),
                'error'  => __('Save failed', 'abcnorio-func'),
            ],
        ]);
    }

    private static function enqueueSelectEnhancer(): ?string
    {
        $pluginDirPath = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE);
        $pluginDirUrl = plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE);

        $localScriptRelativePath = 'node_modules/select2/dist/js/selectWoo.full.min.js';
        $localStyleRelativePath = 'node_modules/select2/dist/css/selectWoo.min.css';
        $localScriptAbsolutePath = $pluginDirPath . $localScriptRelativePath;
        $localStyleAbsolutePath = $pluginDirPath . $localStyleRelativePath;

        $scriptIsAvailable = file_exists($localScriptAbsolutePath);
        $styleIsAvailable = file_exists($localStyleAbsolutePath);

        if ($scriptIsAvailable) {
            wp_register_script(
                self::LOCAL_SELECTWOO_SCRIPT_HANDLE,
                $pluginDirUrl . $localScriptRelativePath,
                ['jquery'],
                (string) filemtime($localScriptAbsolutePath),
                true
            );
            wp_enqueue_script(self::LOCAL_SELECTWOO_SCRIPT_HANDLE);
        }

        if ($styleIsAvailable) {
            wp_register_style(
                self::LOCAL_SELECTWOO_STYLE_HANDLE,
                $pluginDirUrl . $localStyleRelativePath,
                [],
                (string) filemtime($localStyleAbsolutePath)
            );
            wp_enqueue_style(self::LOCAL_SELECTWOO_STYLE_HANDLE);
        }

        return $scriptIsAvailable ? self::LOCAL_SELECTWOO_SCRIPT_HANDLE : null;
    }

    public static function ajaxSetPostTerms(): void
    {
        if (! self::canManage()) {
            wp_send_json_error(['message' => __('Forbidden', 'abcnorio-func')], 403);
        }

        check_ajax_referer('abcnorio-term-editor', 'nonce');

        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key((string) $_POST['taxonomy']) : '';
        $termIdsRaw = isset($_POST['term_ids']) && is_array($_POST['term_ids']) ? $_POST['term_ids'] : [];
        $termIds = array_values(array_filter(array_map('intval', $termIdsRaw)));
        $newTermsRaw = isset($_POST['new_terms']) && is_array($_POST['new_terms']) ? $_POST['new_terms'] : [];

        if ($postId <= 0 || $taxonomy === '') {
            wp_send_json_error(['message' => __('Missing required params', 'abcnorio-func')], 400);
        }

        $postType = get_post_type($postId);

        if (! is_string($postType) || ! isset(self::MAP[$postType]) || ! in_array($taxonomy, self::MAP[$postType], true)) {
            wp_send_json_error(['message' => __('Taxonomy not allowed for this post type', 'abcnorio-func')], 400);
        }

        if (! current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('Cannot edit this post', 'abcnorio-func')], 403);
        }

        $taxonomyObj = get_taxonomy($taxonomy);

        if (! $taxonomyObj || ! current_user_can($taxonomyObj->cap->assign_terms)) {
            wp_send_json_error(['message' => __('Cannot assign terms for this taxonomy', 'abcnorio-func')], 403);
        }

        $newTerms = self::sanitizeNewTerms($newTermsRaw);

        if ($newTerms !== [] && ! current_user_can($taxonomyObj->cap->manage_terms)) {
            wp_send_json_error(['message' => __('Cannot create terms for this taxonomy', 'abcnorio-func')], 403);
        }

        if ($newTerms !== []) {
            foreach ($newTerms as $newTermName) {
                $existing = term_exists($newTermName, $taxonomy);

                if (is_array($existing) && isset($existing['term_id'])) {
                    $termIds[] = (int) $existing['term_id'];
                    continue;
                }

                if (is_int($existing) || ctype_digit((string) $existing)) {
                    $termIds[] = (int) $existing;
                    continue;
                }

                $created = wp_insert_term($newTermName, $taxonomy);

                if (is_wp_error($created)) {
                    wp_send_json_error(['message' => $created->get_error_message()], 400);
                }

                if (is_array($created) && isset($created['term_id'])) {
                    $termIds[] = (int) $created['term_id'];
                }
            }
        }

        $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds))));

        $result = wp_set_object_terms($postId, $termIds, $taxonomy, false);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        wp_send_json_success([
            'post_id'   => $postId,
            'taxonomy'  => $taxonomy,
            'term_ids'  => array_map('intval', $result),
        ]);
    }

    /**
     * @param array<mixed> $newTermsRaw
     *
     * @return list<string>
     */
    private static function sanitizeNewTerms(array $newTermsRaw): array
    {
        $sanitized = [];

        foreach ($newTermsRaw as $candidate) {
            $value = sanitize_text_field((string) $candidate);
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $sanitized[$value] = true;
        }

        return array_keys($sanitized);
    }

    /**
     * @return list<\WP_Term>
     */
    private static function getTermsForTaxonomy(string $taxonomy): array
    {
        if (isset(self::$termsByTaxonomy[$taxonomy])) {
            return self::$termsByTaxonomy[$taxonomy];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms) || ! is_array($terms)) {
            self::$termsByTaxonomy[$taxonomy] = [];
            return [];
        }

        self::$termsByTaxonomy[$taxonomy] = array_values(array_filter($terms, static fn ($term) => $term instanceof \WP_Term));

        return self::$termsByTaxonomy[$taxonomy];
    }

    private static function canManage(): bool
    {
        return current_user_can('edit_posts');
    }

    private static function currentPostType(): string
    {
        $hook = current_filter();

        if (is_string($hook) && preg_match('/^manage_(.+)_posts_columns$/', $hook, $matches) === 1) {
            return sanitize_key((string) $matches[1]);
        }

        if (is_string($hook) && preg_match('/^manage_edit-(.+)_columns$/', $hook, $matches) === 1) {
            return sanitize_key((string) $matches[1]);
        }

        $requestPostType = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($requestPostType !== '') {
            return $requestPostType;
        }

        $screen = get_current_screen();

        if ($screen instanceof \WP_Screen && is_string($screen->post_type)) {
            return $screen->post_type;
        }

        return '';
    }

    private static function columnKey(string $taxonomy): string
    {
        return self::COLUMN_PREFIX . $taxonomy;
    }

    private static function taxonomyFromColumn(string $column, string $postType): ?string
    {
        if (! str_starts_with($column, self::COLUMN_PREFIX)) {
            return null;
        }

        $taxonomy = sanitize_key(substr($column, strlen(self::COLUMN_PREFIX)) ?: '');

        if ($taxonomy === '') {
            return null;
        }

        if (in_array($taxonomy, self::MAP[$postType], true)) {
            return $taxonomy;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function getSelectedTermIds(int $postId, string $taxonomy): array
    {
        $selectedTerms = get_the_terms($postId, $taxonomy);

        if (! is_array($selectedTerms)) {
            return [];
        }

        return array_values(array_map(static fn (\WP_Term $term): int => (int) $term->term_id, $selectedTerms));
    }
}
