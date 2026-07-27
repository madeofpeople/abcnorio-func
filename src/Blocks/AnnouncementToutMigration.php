<?php

namespace abcnorio\CustomFunc\Blocks;

final class AnnouncementToutMigration
{
    public static function registerHooks(): void
    {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('abcnorio announcement-tout-migrate', [self::class, 'run']);
        }
    }

    public static function run(array $args, array $assocArgs): void
    {
        unset($args);

        global $wpdb;

        $dryRun = ! empty($assocArgs['dry-run']);
        $postIds = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
            WHERE post_content LIKE '%wp:abcnorio/announcement-tout%'
              AND post_type <> 'revision'
              AND post_status NOT IN ('auto-draft', 'trash', 'inherit')"
        );

        $updated = 0;
        $scanned = 0;

        foreach ($postIds as $postId) {
            $post = get_post((int) $postId);
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $scanned++;
            $blocks = parse_blocks((string) $post->post_content);
            $didChange = false;
            $nextBlocks = self::transformBlocks($blocks, $didChange);

            if (! $didChange) {
                continue;
            }

            $updated++;
            if ($dryRun) {
                \WP_CLI::log(sprintf('Would migrate post %d (%s)', $post->ID, $post->post_title));
                continue;
            }

            wp_update_post([
                'ID' => $post->ID,
                'post_content' => serialize_blocks($nextBlocks),
            ]);

            \WP_CLI::log(sprintf('Migrated post %d (%s)', $post->ID, $post->post_title));
        }

        \WP_CLI::success(sprintf(
            'Scanned %d posts. %s %d posts.',
            $scanned,
            $dryRun ? 'Would migrate' : 'Migrated',
            $updated
        ));
    }

    private static function transformBlocks(array $blocks, bool &$didChange): array
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = self::transformBlocks($block['innerBlocks'], $didChange);
            }

            if (($block['blockName'] ?? '') !== 'abcnorio/announcement-tout') {
                continue;
            }

            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $details = trim((string) ($attrs['details'] ?? ''));
            $buttonLabel = trim((string) ($attrs['buttonLabel'] ?? ''));
            $buttonUrl = trim((string) ($attrs['buttonUrl'] ?? ''));
            $nextAttrs = $attrs;
            unset($nextAttrs['details'], $nextAttrs['buttonLabel'], $nextAttrs['buttonUrl']);
            $attrsChanged = $nextAttrs !== $attrs;

            if (! empty($block['innerBlocks'])) {
                if ($attrsChanged) {
                    $block['attrs'] = $nextAttrs;
                    $didChange = true;
                }
                continue;
            }

            $innerBlocks = [];
            if ($details !== '') {
                $innerBlocks[] = self::paragraphBlock($details);
            }
            if ($buttonLabel !== '' && $buttonUrl !== '') {
                $innerBlocks[] = self::buttonsBlock($buttonLabel, $buttonUrl);
            }

            if (! $attrsChanged && count($innerBlocks) === 0) {
                continue;
            }

            $block['attrs'] = $nextAttrs;
            $block['innerBlocks'] = $innerBlocks;
            $block['innerHTML'] = '';
            $block['innerContent'] = array_fill(0, count($innerBlocks), null);
            $didChange = true;
        }

        return $blocks;
    }

    private static function paragraphBlock(string $text): array
    {
        $html = '<p>' . esc_html($text) . '</p>';

        return [
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerBlocks' => [],
            'innerHTML' => $html,
            'innerContent' => [$html],
        ];
    }

    private static function buttonsBlock(string $label, string $url): array
    {
        $buttonHtml = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url($url) . '">' . esc_html($label) . '</a></div>';
        $buttonBlock = [
            'blockName' => 'core/button',
            'attrs' => [],
            'innerBlocks' => [],
            'innerHTML' => $buttonHtml,
            'innerContent' => [$buttonHtml],
        ];

        return [
            'blockName' => 'core/buttons',
            'attrs' => ['className' => 'announcement-tout__actions'],
            'innerBlocks' => [$buttonBlock],
            'innerHTML' => '',
            'innerContent' => ['<div class="wp-block-buttons announcement-tout__actions">', null, '</div>'],
        ];
    }
}