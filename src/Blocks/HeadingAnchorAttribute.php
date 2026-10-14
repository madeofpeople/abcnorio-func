<?php

namespace abcnorio\CustomFunc\Blocks;

/**
 * The VIP Block Data API does not source the HTML anchor of core/heading,
 * so read it from the saved markup.
 */
final class HeadingAnchorAttribute
{
    public static function registerHooks(): void
    {
        add_filter('vip_block_data_api__sourced_block_result', [self::class, 'addAnchor'], 10, 4);
    }

    public static function addAnchor(array $sourcedBlock, string $blockName, int $postId = 0, array $parsedBlock = []): array
    {
        if ($blockName !== 'core/heading') {
            return $sourcedBlock;
        }

        if (preg_match('/^\s*<h[1-6][^>]*\sid="([^"]+)"/', $parsedBlock['innerHTML'] ?? '', $matches)) {
            $sourcedBlock['attributes']['anchor'] = $matches[1];
        }

        return $sourcedBlock;
    }
}
