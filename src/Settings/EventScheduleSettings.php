<?php

namespace abcnorio\CustomFunc\Settings;

final class EventScheduleSettings
{
    public const DEFAULT_DURATION = 'abcnorio_event_default_duration_minutes';
    public const LEAD_TIME = 'abcnorio_event_lead_time_minutes';
    public const MAX_PER_DAY = 'abcnorio_event_max_per_day';

    private const GROUP = 'abcnorio_event_schedule';

    public static function registerHooks(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettings(): void
    {
        register_setting(self::GROUP, self::DEFAULT_DURATION, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 120,
        ]);
        register_setting(self::GROUP, self::LEAD_TIME, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 30,
        ]);
        register_setting(self::GROUP, self::MAX_PER_DAY, [
            'type' => 'integer',
            'sanitize_callback' => 'intval',
            'default' => -1,
        ]);
    }

    public static function defaultDurationMinutes(): int
    {
        return (int) get_option(self::DEFAULT_DURATION, 120);
    }

    public static function leadTimeMinutes(): int
    {
        return (int) get_option(self::LEAD_TIME, 30);
    }

    public static function maxPerDay(): int
    {
        return (int) get_option(self::MAX_PER_DAY, -1);
    }

    public static function renderSection(): void
    {
        ?>
        <section>
            <h2><?php esc_html_e('Event scheduling', 'abcnorio-func'); ?></h2>
            <p class="description"><?php esc_html_e('Used to warn when events overlap in the same venue. Each venue is checked only against itself.', 'abcnorio-func'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::DEFAULT_DURATION); ?>"><?php esc_html_e('Default duration (minutes)', 'abcnorio-func'); ?></label></th>
                        <td>
                            <input type="number" min="1" id="<?php echo esc_attr(self::DEFAULT_DURATION); ?>" name="<?php echo esc_attr(self::DEFAULT_DURATION); ?>" value="<?php echo esc_attr(self::defaultDurationMinutes()); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Assumed length for events with no end time.', 'abcnorio-func'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::LEAD_TIME); ?>"><?php esc_html_e('Lead time (minutes)', 'abcnorio-func'); ?></label></th>
                        <td>
                            <input type="number" min="0" id="<?php echo esc_attr(self::LEAD_TIME); ?>" name="<?php echo esc_attr(self::LEAD_TIME); ?>" value="<?php echo esc_attr(self::leadTimeMinutes()); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Minimum gap required between events in the same venue.', 'abcnorio-func'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::MAX_PER_DAY); ?>"><?php esc_html_e('Max events per day', 'abcnorio-func'); ?></label></th>
                        <td>
                            <input type="number" min="-1" id="<?php echo esc_attr(self::MAX_PER_DAY); ?>" name="<?php echo esc_attr(self::MAX_PER_DAY); ?>" value="<?php echo esc_attr(self::maxPerDay()); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Total across all venues, to guard against too much concurrency. -1 turns this warning off.', 'abcnorio-func'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </section>
        <?php
    }
}
