<?php

namespace abcnorio\CustomFunc\Settings;

use abcnorio\CustomFunc\Navigation\SocialLinks;

final class SettingsPage
{
    public const SLUG = 'abcnorio-settings';
    public const HOOK = 'settings_page_abcnorio-settings';

    public static function registerHooks(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
    }

    public static function addPage(): void
    {
        add_options_page(
            __('ABC No Rio', 'abcnorio-func'),
            __('ABC No Rio', 'abcnorio-func'),
            'manage_options',
            self::SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ABC No Rio', 'abcnorio-func'); ?></h1>
            <?php
            EventScheduleSettings::renderSection();
            BackupRetentionSettings::renderSection();
            NewsletterSettings::renderSection();
            SocialLinks::renderSection();
            ?>
        </div>
        <?php
    }
}
