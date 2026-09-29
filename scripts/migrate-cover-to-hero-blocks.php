<?php

// One-time migration: rewrite core/cover blocks (any post type/status) to abcnorio/hero.
// Default is a dry run; set ABCNORIO_HERO_MIGRATION_WRITE=1 to commit.
// abcnorio/hero has no video support, so cover blocks using backgroundType=video are skipped.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Run with wp eval-file.\n");
    exit(1);
}

$write = getenv('ABCNORIO_HERO_MIGRATION_WRITE') === '1';

function abcnorio_hero_migration_attrs(array $coverAttrs): array
{
    return [
        'id' => (int) ($coverAttrs['id'] ?? 0),
        'url' => (string) ($coverAttrs['url'] ?? ''),
        'alt' => (string) ($coverAttrs['alt'] ?? ''),
        'sizeSlug' => (string) ($coverAttrs['sizeSlug'] ?? 'full'),
        'variant' => 'default',
    ];
}

// Matches abcnorio/hero's save() output: no wp-block wrapper, InnerBlocks.Content
// rendered directly with no separators, same shape core/group uses for innerContent.
function abcnorio_hero_migration_block(array $coverBlock): array
{
    $innerBlocks = array_map('abcnorio_hero_migration_transform', $coverBlock['innerBlocks'] ?? []);
    $wrapperOpen = '<div class="abcnorio-hero__content-wrapper"><div class="abcnorio-hero__content">';
    $wrapperClose = '</div></div>';

    return [
        'blockName' => 'abcnorio/hero',
        'attrs' => abcnorio_hero_migration_attrs($coverBlock['attrs'] ?? []),
        'innerBlocks' => $innerBlocks,
        'innerHTML' => $wrapperOpen . $wrapperClose,
        'innerContent' => array_merge(
            [$wrapperOpen],
            array_fill(0, count($innerBlocks), null),
            [$wrapperClose]
        ),
    ];
}

function abcnorio_hero_migration_transform(array $block): array
{
    if (($block['blockName'] ?? null) === 'core/cover') {
        $backgroundType = (string) ($block['attrs']['backgroundType'] ?? 'image');
        if ($backgroundType === 'video') {
            $GLOBALS['abcnorio_hero_migration_unconvertible']++;
            return $block;
        }

        $GLOBALS['abcnorio_hero_migration_converted']++;
        return abcnorio_hero_migration_block($block);
    }

    if (!empty($block['innerBlocks'])) {
        $block['innerBlocks'] = array_map('abcnorio_hero_migration_transform', $block['innerBlocks']);
    }

    return $block;
}

$posts = get_posts([
    'post_type' => 'any',
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => 'ID',
    'order' => 'ASC',
]);

$migratedPosts = 0;
$skippedPosts = 0;

foreach ($posts as $post) {
    if (!has_block('core/cover', $post)) {
        continue;
    }

    $GLOBALS['abcnorio_hero_migration_converted'] = 0;
    $GLOBALS['abcnorio_hero_migration_unconvertible'] = 0;

    $blocks = array_map('abcnorio_hero_migration_transform', parse_blocks((string) $post->post_content));

    if ($GLOBALS['abcnorio_hero_migration_unconvertible'] > 0) {
        WP_CLI::warning(sprintf(
            'Skipped %d cover block(s) in post %d (%s): video background not supported by abcnorio/hero.',
            $GLOBALS['abcnorio_hero_migration_unconvertible'],
            $post->ID,
            $post->post_title
        ));
    }

    if ($GLOBALS['abcnorio_hero_migration_converted'] === 0) {
        $skippedPosts++;
        continue;
    }

    $migratedPosts++;
    WP_CLI::log(sprintf(
        '%s %d cover block(s) in post %d (%s)',
        $write ? 'Migrated' : 'Would migrate',
        $GLOBALS['abcnorio_hero_migration_converted'],
        $post->ID,
        $post->post_title
    ));

    if ($write) {
        wp_update_post([
            'ID' => $post->ID,
            'post_content' => serialize_blocks($blocks),
        ]);
    }
}

WP_CLI::success(sprintf(
    '%s complete. %d post(s) to migrate, %d unaffected.',
    $write ? 'Migration' : 'Dry run',
    $migratedPosts,
    $skippedPosts
));
