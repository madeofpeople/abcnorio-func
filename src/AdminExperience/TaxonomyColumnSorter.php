<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class TaxonomyColumnSorter
{
    /** @var array<string, list<string>> post_type => taxonomy slugs */
    private const MAP = [
        'event'      => ['event_type', 'event_tag', 'collective_association'],
        'collective' => ['collective_association'],
        'press_item' => ['press_flag'],
    ];

    /** @var array<string, list<string>> post_type => meta keys to include in admin search */
    private const SEARCH_META_MAP = [
        'event' => ['event_subtitle', 'event_details'],
    ];

    /** @var array<string, list<string>> post_type => filterable taxonomy slugs */
    private const FILTER_MAP = [
        'event'      => ['event_type', 'event_tag', 'collective_association'],
        'news_item'  => ['collective_association'],
        'press_item' => ['press_flag'],
    ];

    /** @var array<string, string> taxonomy slug => dropdown label */
    private const FILTER_LABELS = [
        'event_type'             => 'All Event Types',
        'event_tag'              => 'All Event Tags',
        'collective_association' => 'All Collectives',
        'press_flag'             => 'All Flags',
    ];

    public static function registerHooks(): void
    {
        foreach (self::MAP as $postType => $taxonomies) {
            add_filter(
                "manage_edit-{$postType}_sortable_columns",
                static function (array $columns) use ($taxonomies): array {
                    foreach ($taxonomies as $taxonomy) {
                        $columns["taxonomy-{$taxonomy}"] = $taxonomy;
                    }
                    return $columns;
                }
            );
        }

        add_action('pre_get_posts', [self::class, 'handleSortQuery']);
        add_action('restrict_manage_posts', [self::class, 'renderFilterDropdowns']);
        add_action('parse_query', [self::class, 'applyNonPublicTaxFilters']);
        add_filter('posts_join', [self::class, 'extendSearchJoin'], 10, 2);
        add_filter('posts_search', [self::class, 'extendSearchWhere'], 10, 2);
        add_filter('posts_distinct', [self::class, 'extendSearchDistinct'], 10, 2);
    }

    public static function handleSortQuery(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $postType = (string) $query->get('post_type');
        $orderby  = (string) $query->get('orderby');

        if (! isset(self::MAP[$postType]) || ! in_array($orderby, self::MAP[$postType], true)) {
            return;
        }

        add_filter('posts_clauses', static function (array $clauses) use ($orderby): array {
            global $wpdb;

            $order = strtoupper((string) (get_query_var('order') ?: 'ASC'));
            $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'ASC';

            $clauses['join'] .= "
                LEFT JOIN {$wpdb->term_relationships} abctr
                    ON ({$wpdb->posts}.ID = abctr.object_id)
                LEFT JOIN {$wpdb->term_taxonomy} abctt
                    ON (abctr.term_taxonomy_id = abctt.term_taxonomy_id AND abctt.taxonomy = '" . esc_sql($orderby) . "')
                LEFT JOIN {$wpdb->terms} abct
                    ON (abctt.term_id = abct.term_id)
            ";
            $clauses['groupby'] = "{$wpdb->posts}.ID";
            $clauses['orderby'] = "abct.name {$order}";

            return $clauses;
        }, 10, 1);
    }

    public static function renderFilterDropdowns(string $postType): void
    {
        $taxonomies = self::FILTER_MAP[$postType] ?? [];

        foreach ($taxonomies as $taxonomy) {
            $selected = (string) ($_GET[$taxonomy] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
            $label    = self::FILTER_LABELS[$taxonomy] ?? "All {$taxonomy}";

            wp_dropdown_categories([
                'show_option_all' => __($label, 'abcnorio-func'),
                'taxonomy'        => $taxonomy,
                'name'            => $taxonomy,
                'value_field'     => 'slug',
                'selected'        => $selected,
                'hide_empty'      => true,
            ]);
        }
    }

    public static function applyNonPublicTaxFilters(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $postType   = (string) $query->get('post_type');
        $taxonomies = self::FILTER_MAP[$postType] ?? [];

        $taxQuery = $query->get('tax_query') ?: [];

        foreach ($taxonomies as $taxonomy) {
            $taxObj = get_taxonomy($taxonomy);

            // Public taxonomies handle their own query_var — skip them
            if ($taxObj && $taxObj->public) {
                continue;
            }

            $slug = (string) ($_GET[$taxonomy] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification

            if ($slug === '' || $slug === '0') {
                continue;
            }

            $taxQuery[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slug,
            ];
        }

        if ($taxQuery !== []) {
            $query->set('tax_query', $taxQuery);
        }
    }

    public static function extendSearchJoin(string $join, \WP_Query $query): string
    {
        if (! is_admin() || ! $query->is_main_query() || ! $query->get('s')) {
            return $join;
        }

        $postType = (string) $query->get('post_type');

        if (! isset(self::SEARCH_META_MAP[$postType])) {
            return $join;
        }

        global $wpdb;
        $join .= " LEFT JOIN {$wpdb->postmeta} abcsm ON ({$wpdb->posts}.ID = abcsm.post_id)";

        return $join;
    }

    public static function extendSearchWhere(string $search, \WP_Query $query): string
    {
        if (! is_admin() || ! $query->is_main_query() || ! $query->get('s')) {
            return $search;
        }

        $postType = (string) $query->get('post_type');
        $metaKeys = self::SEARCH_META_MAP[$postType] ?? [];

        if ($metaKeys === []) {
            return $search;
        }

        global $wpdb;
        $like        = '%' . $wpdb->esc_like((string) $query->get('s')) . '%';
        $keys_in     = implode(',', array_fill(0, count($metaKeys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $search .= $wpdb->prepare(
            " OR (abcsm.meta_key IN ({$keys_in}) AND abcsm.meta_value LIKE %s)",
            array_merge($metaKeys, [$like])
        );

        return $search;
    }

    public static function extendSearchDistinct(string $distinct, \WP_Query $query): string
    {
        if (! is_admin() || ! $query->is_main_query() || ! $query->get('s')) {
            return $distinct;
        }

        $postType = (string) $query->get('post_type');

        if (! isset(self::SEARCH_META_MAP[$postType])) {
            return $distinct;
        }

        return 'DISTINCT';
    }
}
