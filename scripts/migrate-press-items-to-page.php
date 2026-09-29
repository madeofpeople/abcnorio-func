<?php

// One-time migration: inject press_item CPT data into the "press" WordPress page.
// Default is a dry run; set ABCNORIO_PRESS_MIGRATION_WRITE=1 to commit. press_item posts are left untouched.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Run with wp eval-file.\n");
    exit(1);
}

$write = getenv('ABCNORIO_PRESS_MIGRATION_WRITE') === '1';

function abcnorio_press_migration_title(\WP_Post $pressItem): ?string
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

function abcnorio_press_migration_date(string $raw): string
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

function abcnorio_press_migration_block(\WP_Post $pressItem): ?string
{
    $title = abcnorio_press_migration_title($pressItem);
    if ($title === null) {
        return null;
    }

    $date = abcnorio_press_migration_date((string) get_field('press_item_date', $pressItem->ID));
    $fallbackDate = trim((string) get_field('press_item_fallback_date_string', $pressItem->ID));
    $source = trim((string) get_field('press_item_source', $pressItem->ID));
    $sourceUrl = trim((string) get_field('press_item_url', $pressItem->ID));

    $attributes = wp_json_encode([
        'date' => $date,
        'fallbackDate' => $fallbackDate,
        'title' => $title,
        'source' => $source,
        'sourceUrl' => $sourceUrl,
    ]);
    return sprintf("<!-- wp:abcnorio/press-item %s /-->", $attributes);
}

$pressItems = get_posts([
    'post_type' => 'press_item',
    'post_status' => 'publish',
    'numberposts' => -1,
    'meta_key' => 'press_item_date',
    'orderby' => 'meta_value',
    'order' => 'DESC',
]);

$articleBlocks = [];
$skipped = [];

foreach ($pressItems as $pressItem) {
    $block = abcnorio_press_migration_block($pressItem);
    if ($block === null) {
        $skipped[] = sprintf('%d (%s)', $pressItem->ID, $pressItem->post_title);
        continue;
    }
    $articleBlocks[] = $block;
}

foreach ($skipped as $entry) {
    WP_CLI::warning(sprintf('Skipped %s: body content is not paragraph-only.', $entry));
}

$pageContent = implode("\n\n", $articleBlocks);

$existingPages = get_posts([
    'post_type' => 'page',
    'name' => 'press',
    'post_status' => 'any',
    'numberposts' => 1,
]);
$existingPage = $existingPages[0] ?? null;

WP_CLI::log(sprintf(
    '%s: %d press items included, %d skipped. Target page: %s.',
    $write ? 'Migrating' : 'Would migrate',
    count($articleBlocks),
    count($skipped),
    $existingPage ? sprintf('existing page ID %d', $existingPage->ID) : 'new page (slug "press")'
));

if ($write) {
    if ($existingPage) {
        wp_update_post([
            'ID' => $existingPage->ID,
            'post_content' => $pageContent,
        ]);
    } else {
        wp_insert_post([
            'post_type' => 'page',
            'post_title' => 'Press',
            'post_name' => 'press',
            'post_status' => 'publish',
            'post_content' => $pageContent,
        ]);
    }
}

WP_CLI::success($write ? 'Migration complete.' : 'Dry run complete.');
