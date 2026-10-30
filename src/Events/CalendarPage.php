<?php

namespace abcnorio\CustomFunc\Events;

use abcnorio\CustomFunc\RestApi\CalendarEndpoint;

final class CalendarPage
{
    private const SLUG = 'abcnorio-scheduling';

    private const PALETTE = ['#1d6fa5', '#b5483a', '#2f7d4f', '#8a5a9e', '#b07d12', '#2b7a78', '#a33d6f', '#5b6770'];

    public static function registerHooks(): void
    {
        if (wp_get_environment_type() === 'production') {
            return;
        }

        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_abcnorio_feed_add', [self::class, 'addFeed']);
        add_action('admin_post_abcnorio_feed_delete', [self::class, 'deleteFeed']);
    }

    public static function addMenu(): void
    {
        $hook = add_submenu_page(
            'edit.php?post_type=event',
            __('Scheduling', 'abcnorio-func'),
            __('Calendar', 'abcnorio-func'),
            'edit_events',
            self::SLUG,
            [self::class, 'render']
        );

        add_action('admin_print_scripts-' . $hook, [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(): void
    {
        $build   = plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . 'build/calendar/';
        $scripts = plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . 'resources/js/';

        wp_enqueue_script('abcnorio-ical', $build . 'ical.min.js', [], null, true);
        wp_enqueue_script('abcnorio-fullcalendar', $build . 'fullcalendar.min.js', [], null, true);
        wp_enqueue_script('abcnorio-fullcalendar-ical', $build . 'icalendar.min.js', ['abcnorio-fullcalendar', 'abcnorio-ical'], null, true);
        wp_enqueue_script('abcnorio-scheduling', $scripts . 'scheduling-calendar.js', ['abcnorio-fullcalendar-ical'], null, true);

        $nonce = wp_create_nonce('wp_rest');
        $feeds = [];
        foreach (self::feeds() as $feed) {
            $feeds[] = [
                'id'    => $feed['id'],
                'name'  => $feed['name'],
                'color' => $feed['color'],
                'url'   => add_query_arg('_wpnonce', $nonce, rest_url('abcnorio/v1/calendar/feed/' . $feed['id'])),
            ];
        }

        $venues = [];
        foreach (self::venues() as $index => $venue) {
            $venues[] = [
                'id'    => $venue->ID,
                'name'  => $venue->post_title,
                'color' => self::PALETTE[$index % count(self::PALETTE)],
            ];
        }

        wp_add_inline_script(
            'abcnorio-scheduling',
            'window.abcnorioScheduling = ' . wp_json_encode([
                'eventsUrl' => rest_url('abcnorio/v1/calendar/events'),
                'nonce'     => $nonce,
                'venues'    => $venues,
                'feeds'     => $feeds,
            ]) . ';',
            'before'
        );

        wp_add_inline_style('wp-admin', '
            .abcnorio-cal-layout { display: flex; gap: 24px; align-items: flex-start; }
            .abcnorio-cal-sidebar { flex: 0 0 240px; }
            .abcnorio-cal-sidebar fieldset { margin: 0 0 20px; }
            .abcnorio-cal-sidebar label { display: block; margin: 4px 0; }
            .abcnorio-cal-swatch { display: inline-block; width: 12px; height: 12px; margin-right: 6px; vertical-align: middle; }
            .abcnorio-cal-main { flex: 1; min-width: 0; background: #fff; padding: 12px; }
            .abcnorio-cal-unpublished { opacity: .6; border-style: dashed !important; }
        ');
    }

    public static function render(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Scheduling', 'abcnorio-func'); ?></h1>
            <div class="abcnorio-cal-layout">
                <aside class="abcnorio-cal-sidebar">
                    <fieldset id="abcnorio-cal-venues">
                        <legend><strong><?php esc_html_e('Venues', 'abcnorio-func'); ?></strong></legend>
                    </fieldset>
                    <fieldset id="abcnorio-cal-feeds">
                        <legend><strong><?php esc_html_e('Calendars', 'abcnorio-func'); ?></strong></legend>
                    </fieldset>
                    <?php self::renderFeedForms(); ?>
                </aside>
                <div class="abcnorio-cal-main">
                    <div id="abcnorio-cal"></div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function renderFeedForms(): void
    {
        ?>
        <details>
            <summary><?php esc_html_e('Manage calendars', 'abcnorio-func'); ?></summary>
            <ul>
                <?php foreach (self::feeds() as $feed) : ?>
                    <li>
                        <?php echo esc_html($feed['name']); ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <input type="hidden" name="action" value="abcnorio_feed_delete">
                            <input type="hidden" name="id" value="<?php echo esc_attr($feed['id']); ?>">
                            <?php wp_nonce_field('abcnorio_feed_delete'); ?>
                            <button type="submit" class="button-link-delete">
                                <?php esc_html_e('Remove', 'abcnorio-func'); ?>
                                <span class="screen-reader-text"><?php echo esc_html($feed['name']); ?></span>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="abcnorio_feed_add">
                <?php wp_nonce_field('abcnorio_feed_add'); ?>
                <p>
                    <label for="abcnorio-feed-name"><?php esc_html_e('Name', 'abcnorio-func'); ?></label><br>
                    <input id="abcnorio-feed-name" name="name" type="text" class="widefat" required>
                </p>
                <p>
                    <label for="abcnorio-feed-url"><?php esc_html_e('ICS URL', 'abcnorio-func'); ?></label><br>
                    <input id="abcnorio-feed-url" name="url" type="url" class="widefat" required>
                </p>
                <p>
                    <label for="abcnorio-feed-color"><?php esc_html_e('Color', 'abcnorio-func'); ?></label>
                    <input id="abcnorio-feed-color" name="color" type="color" value="#5b6770">
                </p>
                <p><button type="submit" class="button"><?php esc_html_e('Add calendar', 'abcnorio-func'); ?></button></p>
            </form>
        </details>
        <?php
    }

    public static function addFeed(): void
    {
        check_admin_referer('abcnorio_feed_add');
        self::requireCap();

        $url = esc_url_raw(preg_replace('#^webcal://#i', 'https://', trim((string) $_POST['url'])), ['http', 'https']);

        if ($url !== '') {
            $feeds   = self::feeds();
            $feeds[] = [
                'id'    => bin2hex(random_bytes(4)),
                'name'  => sanitize_text_field(wp_unslash($_POST['name'])),
                'url'   => $url,
                'color' => sanitize_hex_color(wp_unslash($_POST['color'])) ?: '#5b6770',
            ];
            update_option(CalendarEndpoint::FEEDS_OPTION, $feeds, false);
        }

        self::redirectBack();
    }

    public static function deleteFeed(): void
    {
        check_admin_referer('abcnorio_feed_delete');
        self::requireCap();

        $id    = sanitize_key(wp_unslash($_POST['id']));
        $feeds = array_values(array_filter(self::feeds(), fn ($feed) => $feed['id'] !== $id));
        update_option(CalendarEndpoint::FEEDS_OPTION, $feeds, false);
        delete_transient('abcnorio_feed_' . $id);

        self::redirectBack();
    }

    private static function requireCap(): void
    {
        if (!current_user_can('edit_events')) {
            wp_die(esc_html__('Not allowed.', 'abcnorio-func'), '', ['response' => 403]);
        }
    }

    private static function redirectBack(): void
    {
        wp_safe_redirect(admin_url('edit.php?post_type=event&page=' . self::SLUG));
        exit;
    }

    private static function feeds(): array
    {
        return get_option(CalendarEndpoint::FEEDS_OPTION, []);
    }

    private static function venues(): array
    {
        return get_posts([
            'post_type'      => 'venue',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
    }
}
