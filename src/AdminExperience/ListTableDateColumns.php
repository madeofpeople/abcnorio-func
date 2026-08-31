<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class ListTableDateColumns
{
    /**
     * post_type => meta key => column label
     *
     * @var array<string, array<string, string>>
     */
    private const MAP = [
        'event' => [
            'event_start_date' => 'Start Date',
        ],
        'article' => [
            'article_date' => 'Display Date',
        ],
    ];

    /** @var array<string, string> */
    private const FILTER_LABELS = [
        'event'   => 'Event Start Date',
        'article' => 'Article Dates',
    ];

    public static function registerHooks(): void
    {
        if (! is_admin()) {
            return;
        }

        foreach (self::MAP as $postType => $fields) {
            add_filter("manage_{$postType}_posts_columns", static function (array $columns) use ($fields): array {
                return self::replaceDateColumn($columns, $fields);
            });

            add_action("manage_{$postType}_posts_custom_column", static function (string $column, int $postId) use ($fields): void {
                self::renderColumn($column, $postId, $fields);
            }, 10, 2);

            add_filter("manage_edit-{$postType}_sortable_columns", static function (array $columns) use ($fields): array {
                foreach (array_keys($fields) as $metaKey) {
                    $columns[$metaKey] = $metaKey;
                }

                return $columns;
            });
            add_filter('disable_months_dropdown', static function (bool $disable, string $screenPostType) use ($postType): bool {
                return $disable || $screenPostType === $postType;
            }, 10, 2);
        }

        add_action('restrict_manage_posts', [self::class, 'renderFilterDropdown']);
        add_action('parse_query', [self::class, 'applyFilterQuery']);
        add_action('pre_get_posts', [self::class, 'handleSortQuery']);
    }

    public static function handleSortQuery(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $postType = (string) $query->get('post_type');
        $orderby = (string) $query->get('orderby');

        if (! isset(self::MAP[$postType]) || ! array_key_exists($orderby, self::MAP[$postType])) {
            return;
        }

        $query->set('meta_key', $orderby);
        $query->set('orderby', 'meta_value');
    }

    public static function renderFilterDropdown(string $postType): void
    {
        if (! isset(self::MAP[$postType])) {
            return;
        }

        $fieldKey = array_key_first(self::MAP[$postType]);

        if (! is_string($fieldKey) || $fieldKey === '') {
            return;
        }

        $selected = isset($_GET[$fieldKey]) ? sanitize_text_field(wp_unslash((string) $_GET[$fieldKey])) : '';
        $months = self::availableMonths($postType, $fieldKey);
        $label = self::FILTER_LABELS[$postType] ?? 'All Dates';

        echo '<select name="' . esc_attr($fieldKey) . '" class="postform">';
        echo '<option value="">' . esc_html($label) . '</option>';

        foreach ($months as $month) {
            $value = $month['value'];
            $text = $month['label'];
            $isSelected = selected($selected, $value, false);

            echo '<option value="' . esc_attr($value) . '"' . $isSelected . '>' . esc_html($text) . '</option>';
        }

        echo '</select>';
    }

    public static function applyFilterQuery(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $postType = (string) $query->get('post_type');

        if (! isset(self::MAP[$postType])) {
            return;
        }

        $fieldKey = array_key_first(self::MAP[$postType]);

        if (! is_string($fieldKey) || $fieldKey === '') {
            return;
        }

        $selected = isset($_GET[$fieldKey]) ? sanitize_text_field(wp_unslash((string) $_GET[$fieldKey])) : '';

        if ($selected === '') {
            return;
        }

        $range = self::monthRangeFromSelection($selected);

        if ($range === null) {
            return;
        }

        $metaQuery = (array) $query->get('meta_query');
        $metaQuery[] = [
            'relation' => 'AND',
            [
                'key'     => $fieldKey,
                'value'   => $range['start'],
                'compare' => '>=',
                'type'    => 'CHAR',
            ],
            [
                'key'     => $fieldKey,
                'value'   => $range['end'],
                'compare' => '<',
                'type'    => 'CHAR',
            ],
        ];

        $query->set('meta_query', $metaQuery);
    }

    /**
     * @param array<string, string> $columns
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private static function replaceDateColumn(array $columns, array $fields): array
    {
        if ($fields === []) {
            return $columns;
        }

        unset($columns['date']);

        $newColumns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            if (! $inserted && $key === 'comments') {
                foreach ($fields as $metaKey => $fieldLabel) {
                    $newColumns[$metaKey] = $fieldLabel;
                }

                $inserted = true;
            }

            $newColumns[$key] = $label;
        }

        if (! $inserted) {
            foreach ($fields as $metaKey => $fieldLabel) {
                $newColumns[$metaKey] = $fieldLabel;
            }
        }

        return $newColumns;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function renderColumn(string $column, int $postId, array $fields): void
    {
        if (! array_key_exists($column, $fields)) {
            return;
        }

        $rawValue = (string) get_post_meta($postId, $column, true);

        if ($rawValue === '') {
            echo '&mdash;';
            return;
        }

        $timestamp = self::timestampFromStoredValue($column, $rawValue);

        if ($timestamp === null) {
            echo esc_html($rawValue);
            return;
        }

        $format = $column === 'event_start_date'
            ? get_option('date_format') . ' ' . get_option('time_format')
            : get_option('date_format');

        echo esc_html(wp_date($format, $timestamp));
    }

    private static function timestampFromStoredValue(string $column, string $rawValue): ?int
    {
        $format = $column === 'event_start_date' ? 'Y-m-d H:i:s' : 'Y-m-d';
        $dateTime = \DateTimeImmutable::createFromFormat($format, $rawValue, wp_timezone());

        if ($dateTime instanceof \DateTimeImmutable) {
            return $dateTime->getTimestamp();
        }

        $timestamp = strtotime($rawValue);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private static function availableMonths(string $postType, string $fieldKey): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT DISTINCT LEFT(pm.meta_value, 7) AS month_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_status <> 'auto-draft'
               AND pm.meta_key = %s
               AND pm.meta_value <> ''
             ORDER BY month_value DESC",
            $postType,
            $fieldKey
        );

        $months = $wpdb->get_col($sql);

        if (! is_array($months)) {
            return [];
        }

        $result = [];

        foreach ($months as $month) {
            if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
                continue;
            }

            $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', wp_timezone());

            if (! $dateTime instanceof \DateTimeImmutable) {
                continue;
            }

            $result[] = [
                'value' => $month,
                'label' => wp_date('F Y', $dateTime->getTimestamp()),
            ];
        }

        return $result;
    }

    /**
     * @return array{start:string,end:string}|null
     */
    private static function monthRangeFromSelection(string $selected): ?array
    {
        $dateTime = \DateTimeImmutable::createFromFormat('Y-m', $selected, wp_timezone());

        if (! $dateTime instanceof \DateTimeImmutable) {
            return null;
        }

        $start = $dateTime->setTime(0, 0, 0);
        $end = $start->modify('first day of next month')->setTime(0, 0, 0);

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end'   => $end->format('Y-m-d H:i:s'),
        ];
    }
}