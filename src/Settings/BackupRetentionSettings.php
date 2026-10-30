<?php

namespace abcnorio\CustomFunc\Settings;

/**
 * Option names and defaults are mirrored in the deploy orchestrator (dev-tools.mjs, RETENTION_OPTIONS),
 * which reads these options from the staging database when it prunes backups.
 */
final class BackupRetentionSettings
{
    public const PRODUCTION_ARCHIVES = 'abcnorio_retention_production_archives';
    public const ROLLBACK_SNAPSHOTS = 'abcnorio_retention_rollback_snapshots';
    public const MEDIA_BACKUPS = 'abcnorio_retention_media_backups';
    public const DATABASE_BACKUPS = 'abcnorio_retention_database_backups';

    private const GROUP = 'abcnorio_backup_retention';

    private const FIELDS = [
        self::PRODUCTION_ARCHIVES => [3, 'Static build archives', 'Release archives kept per target (production and preview). Production shows these in the deploy dashboard.'],
        self::ROLLBACK_SNAPSHOTS => [3, 'Production rollback snapshots', 'Copies of the live site taken before each production deploy.'],
        self::MEDIA_BACKUPS => [3, 'Media backups', 'Kept separately for Dev and Staging.'],
        self::DATABASE_BACKUPS => [3, 'Database backups', 'Kept separately for Dev and Staging.'],
    ];

    public static function registerHooks(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettings(): void
    {
        foreach (self::FIELDS as $option => [$default]) {
            register_setting(self::GROUP, $option, [
                'type' => 'integer',
                'sanitize_callback' => static fn($value): int => max(1, (int) $value),
                'default' => $default,
            ]);
        }
    }

    public static function productionArchives(): int
    {
        return self::value(self::PRODUCTION_ARCHIVES);
    }

    public static function rollbackSnapshots(): int
    {
        return self::value(self::ROLLBACK_SNAPSHOTS);
    }

    public static function mediaBackups(): int
    {
        return self::value(self::MEDIA_BACKUPS);
    }

    private static function value(string $option): int
    {
        return max(1, (int) get_option($option, self::FIELDS[$option][0]));
    }

    public static function renderSection(): void
    {
        ?>
        <section>
            <h2><?php esc_html_e('Backup retention', 'abcnorio-func'); ?></h2>
            <p class="description"><?php esc_html_e('Number of backups kept. Older ones are deleted when a new backup is created.', 'abcnorio-func'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <?php foreach (self::FIELDS as $option => [, $label, $help]) : ?>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($option); ?>"><?php echo esc_html($label); ?></label></th>
                        <td>
                            <input type="number" min="1" id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>" value="<?php echo esc_attr(self::value($option)); ?>" class="small-text">
                            <p class="description"><?php echo esc_html($help); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </section>
        <?php
    }
}
