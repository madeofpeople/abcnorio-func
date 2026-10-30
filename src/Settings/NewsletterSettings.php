<?php

namespace abcnorio\CustomFunc\Settings;

final class NewsletterSettings
{
    public const LIST_ID = 'abcnorio_newsletter_list_id';
    public const DOUBLE_OPT_IN = 'abcnorio_newsletter_double_opt_in';
    public const UPDATE_EXISTING = 'abcnorio_newsletter_update_existing';

    private const GROUP = 'abcnorio_newsletter';

    public static function registerHooks(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettings(): void
    {
        register_setting(self::GROUP, self::LIST_ID, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting(self::GROUP, self::DOUBLE_OPT_IN, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);
        register_setting(self::GROUP, self::UPDATE_EXISTING, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);
    }

    public static function renderSection(): void
    {
        $lists = (array) get_option('mailchimp_sf_lists', []);
        $listId = (string) get_option(self::LIST_ID, '');
        ?>
        <section>
            <h2><?php esc_html_e('Newsletter Signup', 'abcnorio-func'); ?></h2>
            <p class="description"><?php esc_html_e('Applies to every Newsletter Signup block.', 'abcnorio-func'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::LIST_ID); ?>"><?php esc_html_e('Mailchimp list', 'abcnorio-func'); ?></label></th>
                        <td>
                            <select id="<?php echo esc_attr(self::LIST_ID); ?>" name="<?php echo esc_attr(self::LIST_ID); ?>">
                                <option value=""><?php esc_html_e('Select a list', 'abcnorio-func'); ?></option>
                                <?php foreach ($lists as $list) : ?>
                                    <option value="<?php echo esc_attr($list['id']); ?>" <?php selected($listId, $list['id']); ?>><?php echo esc_html($list['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Double opt-in', 'abcnorio-func'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::DOUBLE_OPT_IN); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::DOUBLE_OPT_IN); ?>" value="1" <?php checked(get_option(self::DOUBLE_OPT_IN, 1)); ?>> <?php esc_html_e('Send a confirmation email before subscribing', 'abcnorio-func'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Existing subscribers', 'abcnorio-func'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::UPDATE_EXISTING); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::UPDATE_EXISTING); ?>" value="1" <?php checked(get_option(self::UPDATE_EXISTING, 1)); ?>> <?php esc_html_e('Update existing subscribers (unchecked shows "already subscribed")', 'abcnorio-func'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </section>
        <?php
    }
}
