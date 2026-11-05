<?php

namespace abcnorio\CustomFunc\Dashboard;

final class Dashboard
{
    public static function registerHooks(): void
    {
        add_filter('default_hidden_meta_boxes', [self::class, 'setDefaultHiddenWidgets'], 10, 2);
        add_action('wp_dashboard_setup', [self::class, 'addRecentContentWidgets']);
    }

    public static function addRecentContentWidgets(): void
    {
        if (current_user_can('edit_events')) {
            wp_add_dashboard_widget(
                'abcnorio_recent_events',
                __('Recently added events', 'abcnorio-func'),
                [self::class, 'renderRecentEvents']
            );
        }

        if (current_user_can('edit_articles')) {
            wp_add_dashboard_widget(
                'abcnorio_recent_articles',
                __('Recently added articles', 'abcnorio-func'),
                [self::class, 'renderRecentArticles']
            );
        }
    }

    public static function renderRecentEvents(): void
    {
        ?>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=event')); ?>">
                <?php esc_html_e('Add event', 'abcnorio-func'); ?>
            </a>
            <?php if (wp_get_environment_type() !== 'production') : ?>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=event&page=abcnorio-scheduling')); ?>">
                    <?php esc_html_e('Open scheduling calendar', 'abcnorio-func'); ?>
                </a>
            <?php endif; ?>
        </p>
        <?php

        self::renderRecentContent('event', __('events', 'abcnorio-func'), 'event_start_date', __('Start date', 'abcnorio-func'));
    }

    public static function renderRecentArticles(): void
    {
        ?>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=article')); ?>">
                <?php esc_html_e('Add article', 'abcnorio-func'); ?>
            </a>
        </p>
        <?php

        self::renderRecentContent('article', __('articles', 'abcnorio-func'), 'article_date', __('Display date', 'abcnorio-func'));
    }

    private static function formatMetaDate(string $value): string
    {
        $date = date_create($value, wp_timezone());

        return $date ? $date->format('d/m/Y') : $value;
    }

    // Sorts the 16 recently added posts; sort state lives in the query string so it works without JS.
    private static function renderRecentContent(string $postType, string $label, string $metaKey, string $metaLabel): void
    {
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => 16,
            'orderby' => 'date',
            'order' => 'DESC',
            'perm' => 'editable',
        ]);
        $posts = array_values(array_filter(
            $posts,
            static fn(\WP_Post $post): bool => current_user_can('edit_post', $post->ID)
        ));

        if ($posts === []) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(__('No %s found.', 'abcnorio-func'), $label))
            );
            return;
        }

        $orderbyParam = $postType . '_orderby';
        $orderParam = $postType . '_order';
        $orderby = (isset($_GET[$orderbyParam]) && $_GET[$orderbyParam] === 'meta') ? 'meta' : 'added';
        $order = (isset($_GET[$orderParam]) && $_GET[$orderParam] === 'asc') ? 'asc' : 'desc';

        usort($posts, static function (\WP_Post $a, \WP_Post $b) use ($orderby, $order, $metaKey): int {
            $result = $orderby === 'meta'
                ? strcmp((string) get_post_meta($a->ID, $metaKey, true), (string) get_post_meta($b->ID, $metaKey, true))
                : strcmp($a->post_date, $b->post_date);

            return $order === 'asc' ? $result : -$result;
        });

        $columns = ['added' => __('Date added', 'abcnorio-func'), 'meta' => $metaLabel];

        printf(
            '<div role="region" aria-label="%s" tabindex="0" style="max-height: 20rem; overflow-y: auto;">',
            esc_attr(sprintf(__('Recently added %s', 'abcnorio-func'), $label))
        );
        echo '<table class="widefat striped"><thead><tr>';
        printf('<th scope="col">%s</th>', esc_html__('Title', 'abcnorio-func'));

        foreach ($columns as $column => $columnLabel) {
            $isCurrent = $orderby === $column;
            $nextOrder = ($isCurrent && $order === 'desc') ? 'asc' : 'desc';
            $sortUrl = add_query_arg([$orderbyParam => $column, $orderParam => $nextOrder], admin_url('index.php')) . '#abcnorio_recent_' . $postType . 's';
            $ariaSort = $isCurrent ? ($order === 'asc' ? 'ascending' : 'descending') : 'none';
            $thClass = $isCurrent ? 'sorted ' . $order : 'sortable desc';

            printf(
                '<th scope="col" class="%s" aria-sort="%s"><a href="%s"><span>%s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>',
                esc_attr($thClass),
                esc_attr($ariaSort),
                esc_url($sortUrl),
                esc_html($columnLabel)
            );
        }

        echo '</tr></thead><tbody>';

        foreach ($posts as $post) {
            $editLink = get_edit_post_link($post->ID);
            if (! $editLink) {
                continue;
            }

            $metaValue = (string) get_post_meta($post->ID, $metaKey, true);

            printf(
                '<tr><td><a href="%s">%s</a></td><td><time datetime="%s">%s</time></td><td>%s</td></tr>',
                esc_url($editLink),
                esc_html(get_the_title($post)),
                esc_attr(get_the_date(DATE_W3C, $post)),
                esc_html(get_the_date('d/m/Y', $post)),
                esc_html($metaValue === '' ? '' : self::formatMetaDate($metaValue))
            );
        }

        echo '</tbody></table></div>';
    }

    /**
     * Hide all dashboard widgets by default except Site Health Status.
     * Users can re-enable widgets via Screen Options.
     */
    public static function setDefaultHiddenWidgets(array $hidden, \WP_Screen $screen): array
    {
        if ($screen->id !== 'dashboard') {
            return $hidden;
        }

        $hide = [
            'dashboard_right_now',
            'dashboard_activity',
            'dashboard_quick_press',
            'dashboard_primary',
        ];

        return array_unique(array_merge($hidden, $hide));
    }
}
