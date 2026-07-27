<?php

namespace abcnorio\CustomFunc\Blocks;

final class HeroMigration
{
    public static function registerHooks(): void
    {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('abcnorio hero-migrate', [self::class, 'run']);
        }
    }

    public static function run(array $args, array $assocArgs): void
    {
        unset($args);

        global $wpdb;

        $dryRun = ! empty($assocArgs['dry-run']);
        $postIdFilter = isset($assocArgs['post-id']) ? (int) $assocArgs['post-id'] : 0;

        if ($postIdFilter > 0) {
            $postIds = [$postIdFilter];
        } else {
            $postIds = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_content LIKE '%wp:abcnorio/hero%'
                  AND post_type = 'page'
                  AND post_status NOT IN ('auto-draft', 'trash', 'inherit')"
            );
        }

        $updated = 0;
        $scanned = 0;

        foreach ($postIds as $postId) {
            $post = get_post((int) $postId);
            if (! $post instanceof \WP_Post) {
                continue;
            }

            if ($post->post_type !== 'page') {
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
            'Scanned %d page posts. %s %d posts.',
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

            if (($block['blockName'] ?? '') !== 'abcnorio/hero') {
                continue;
            }

            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $nextAttrs = $attrs;

            $className = trim((string) ($nextAttrs['className'] ?? ''));
            $classTokens = preg_split('/\s+/', $className) ?: [];
            $classTokens = array_values(array_filter($classTokens, static fn ($token): bool => is_string($token) && $token !== ''));

            $hasShortClass = in_array('short', $classTokens, true);

            if ($hasShortClass) {
                $nextAttrs['variant'] = 'short';
            }

            if (isset($nextAttrs['variant'])) {
                $variant = strtolower(sanitize_key((string) $nextAttrs['variant']));
                $nextAttrs['variant'] = $variant === 'short' ? 'short' : 'default';
            }

            if (! empty($classTokens)) {
                $classTokens = array_values(array_filter(
                    $classTokens,
                    static fn (string $token): bool => $token !== 'short' && $token !== 'default'
                ));

                if (! empty($classTokens)) {
                    $nextAttrs['className'] = implode(' ', $classTokens);
                } else {
                    unset($nextAttrs['className']);
                }
            } elseif (isset($nextAttrs['className'])) {
                unset($nextAttrs['className']);
            }

            if ($nextAttrs !== $attrs) {
                $block['attrs'] = $nextAttrs;
                $didChange = true;
            }
        }

        return $blocks;
    }
}
