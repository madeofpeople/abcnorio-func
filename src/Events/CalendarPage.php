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
        wp_enqueue_script('abcnorio-scheduling', $scripts . 'scheduling-calendar.js', ['abcnorio-fullcalendar-ical', 'postbox'], (string) filemtime(plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'resources/js/scheduling-calendar.js'), true);

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
                'name'  => preg_replace('/^ABC No Rio\s*-\s*/', '', $venue->post_title),
                'color' => self::PALETTE[$index % count(self::PALETTE)],
                'list'  => str_starts_with($venue->post_title, 'ABC No Rio') ? 'abcnorio-cal-venues-abc' : 'abcnorio-cal-venues-other',
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

        wp_add_inline_script('abcnorio-scheduling', "jQuery(function () { postboxes.add_postbox_toggles('" . self::SLUG . "'); });");

        wp_add_inline_style('wp-admin', '
            #post-body-content { background: #fff; padding: 12px; }
            .abcnorio-cal-box ul { list-style: none; margin: 0; padding: 0; }
            .abcnorio-cal-box li { margin: 0; padding: 2px 0; }
            .abcnorio-cal-box label { display: block; }
            .abcnorio-cal-swatch { display: inline-block; width: 12px; height: 12px; margin-right: 6px; vertical-align: middle; }
            .abcnorio-cal-unpublished { opacity: .6; border-style: dashed !important; }
            #abcnorio-cal-detail[hidden], #abcnorio-detail-edit[hidden] { display: none; }
            #abcnorio-cal-detail dt { font-weight: 600; }
            #abcnorio-cal-detail dd { margin: 0 0 8px; }
            #abcnorio-feed-dialog { max-width: 360px; width: 100%; }
            #abcnorio-feed-dialog::backdrop { background: rgba(0, 0, 0, .5); }
        ');
    }

    public static function render(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Scheduling', 'abcnorio-func'); ?></h1>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <div id="abcnorio-cal"></div>
                    </div>
                    <div id="postbox-container-1" class="postbox-container">
                        <div id="side-sortables" class="meta-box-sortables">
                            <?php self::openBox('abcnorio-cal-detail', __('Event', 'abcnorio-func'), true); ?>
                                <dl id="abcnorio-detail-fields"></dl>
                                <p>
                                    <a class="button button-primary" id="abcnorio-detail-edit" href="#"><?php esc_html_e('Edit event', 'abcnorio-func'); ?></a>
                                    <button type="button" class="button" id="abcnorio-detail-close"><?php esc_html_e('Close', 'abcnorio-func'); ?></button>
                                </p>
                            <?php self::closeBox(); ?>

                            <?php self::openBox('abcnorio-cal-venues-abc-box', __('ABC No Rio spaces', 'abcnorio-func')); ?>
                                <ul id="abcnorio-cal-venues-abc"></ul>
                            <?php self::closeBox(); ?>

                            <?php self::openBox('abcnorio-cal-venues-other-box', __('Other venues', 'abcnorio-func')); ?>
                                <ul id="abcnorio-cal-venues-other"></ul>
                            <?php self::closeBox(); ?>

                            <?php self::openBox('abcnorio-cal-feeds-box', __('Manage calendars', 'abcnorio-func')); ?>
                                <ul id="abcnorio-cal-feeds"></ul>
                                <ul>
                                    <?php foreach (self::feeds() as $feed) : ?>
                                        <li>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <input type="hidden" name="action" value="abcnorio_feed_delete">
                                                <input type="hidden" name="id" value="<?php echo esc_attr($feed['id']); ?>">
                                                <?php wp_nonce_field('abcnorio_feed_delete'); ?>
                                                <?php echo esc_html($feed['name']); ?>
                                                <button type="submit" class="button-link button-link-delete">
                                                    <?php esc_html_e('Remove', 'abcnorio-func'); ?>
                                                    <span class="screen-reader-text"><?php echo esc_html($feed['name']); ?></span>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                    <li>
                                        <button type="button" class="button" id="abcnorio-feed-open" aria-haspopup="dialog">
                                            <?php esc_html_e('Add calendar', 'abcnorio-func'); ?>
                                        </button>
                                    </li>
                                </ul>
                            <?php self::closeBox(); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php self::renderAddDialog(); ?>
        </div>
        <?php
    }

    private static function openBox(string $id, string $title, bool $hidden = false): void
    {
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="postbox abcnorio-cal-box"<?php echo $hidden ? ' hidden' : ''; ?>>
            <div class="postbox-header">
                <h2 class="hndle" id="<?php echo esc_attr($id); ?>-title" tabindex="-1"><?php echo esc_html($title); ?></h2>
                <div class="handle-actions hide-if-no-js">
                    <button type="button" class="handlediv" aria-expanded="true">
                        <span class="screen-reader-text">
                            <?php
                            /* translators: %s: panel title */
                            echo esc_html(sprintf(__('Toggle panel: %s', 'abcnorio-func'), $title));
                            ?>
                        </span>
                        <span class="toggle-indicator" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
            <div class="inside">
        <?php
    }

    private static function closeBox(): void
    {
        echo '</div></div>';
    }

    private static function renderAddDialog(): void
    {
        ?>
        <dialog id="abcnorio-feed-dialog" aria-labelledby="abcnorio-feed-title">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <h2 id="abcnorio-feed-title"><?php esc_html_e('Add calendar', 'abcnorio-func'); ?></h2>
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
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Add calendar', 'abcnorio-func'); ?></button>
                    <button type="button" class="button" id="abcnorio-feed-cancel"><?php esc_html_e('Cancel', 'abcnorio-func'); ?></button>
                </p>
            </form>
        </dialog>
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
