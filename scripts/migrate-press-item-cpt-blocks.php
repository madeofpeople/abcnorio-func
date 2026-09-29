<?php

// One-time migration: replace published press_item bodies with self-contained press item blocks.
// Default is a dry run; set ABCNORIO_PRESS_MIGRATION_WRITE=1 to commit. ACF fields remain unchanged.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Run with wp eval-file.\n");
    exit(1);
}

$write = getenv('ABCNORIO_PRESS_MIGRATION_WRITE') === '1';

function abcnorio_press_item_migration_title(\WP_Post $pressItem): ?string
{
    $blocks = parse_blocks((string) $pressItem->post_content);
    $paragraphs = [];

    foreach ($blocks as $block) {
        $rendered = trim((string) render_block($block));
        if ($rendered === '') {
            continue;
        }

        preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $rendered, $matches);
        $remaining = trim((string) preg_replace('/<p\b[^>]*>.*?<\/p>/is', '', $rendered));

        if ($matches[0] === [] || $remaining !== '') {
            return null;
        }

        foreach ($matches[0] as $paragraph) {
            if (trim(wp_strip_all_tags($paragraph)) !== '') {
                $paragraphs[] = wp_strip_all_tags($paragraph);
            }
        }
    }

    return implode(' ', $paragraphs);
}

function abcnorio_press_item_migration_date(string $raw): string
{
    $value = trim($raw);

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
        return $matches[0];
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches) === 1) {
        return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
    }

    return '';
}

$pressItems = get_posts([
    'post_type' => 'press_item',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'ID',
    'order' => 'ASC',
]);

$migrated = 0;
$skipped = 0;
$unconvertible = 0;

foreach ($pressItems as $pressItem) {
    if (has_block('abcnorio/press-item', $pressItem)) {
        $skipped++;
        continue;
    }

    $title = abcnorio_press_item_migration_title($pressItem);
    if ($title === null) {
        $unconvertible++;
        WP_CLI::warning(sprintf('Skipped %d (%s): body content is not paragraph-only.', $pressItem->ID, $pressItem->post_title));
        continue;
    }

    $attributes = wp_json_encode([
        'date' => abcnorio_press_item_migration_date((string) get_field('press_item_date', $pressItem->ID)),
        'fallbackDate' => trim((string) get_field('press_item_fallback_date_string', $pressItem->ID)),
        'title' => $title,
        'source' => trim((string) get_field('press_item_source', $pressItem->ID)),
        'sourceUrl' => trim((string) get_field('press_item_url', $pressItem->ID)),
    ]);

    $migrated++;
    WP_CLI::log(sprintf('%s %d (%s)', $write ? 'Migrated' : 'Would migrate', $pressItem->ID, $pressItem->post_title));

    if ($write) {
        wp_update_post([
            'ID' => $pressItem->ID,
            'post_content' => sprintf('<!-- wp:abcnorio/press-item %s /-->', $attributes),
        ]);
    }
}

WP_CLI::success(sprintf(
    '%s complete. %d to migrate, %d already migrated, %d unconvertible.',
    $write ? 'Migration' : 'Dry run',
    $migrated,
    $skipped,
    $unconvertible,
));
