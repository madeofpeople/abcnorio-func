<?php

namespace abcnorio\CustomFunc\ContentModel;

final class InstagramMentionLinker
{
    private const POST_TYPES = ['event'];
    private const PROFILE_BASE_URL = 'https://www.instagram.com/';
    private const MENTION_PATTERN = '/(^|[^A-Za-z0-9_@])@([A-Za-z0-9._-]+)/';
    private const ANCHOR_PATTERN = '#(<a\b[^>]*>.*?</a>)#is';
    private const TAG_PATTERN = '/(<[^>]+>)/';

    public static function registerHooks(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'linkMentions'], 10, 1);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function linkMentions(array $data): array
    {
        if (! in_array($data['post_type'], self::POST_TYPES, true) || ! str_contains($data['post_content'], '@')) {
            return $data;
        }

        $content = wp_unslash($data['post_content']);
        $segments = preg_split(self::ANCHOR_PATTERN, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($segments as $i => $segment) {
            if (stripos($segment, '<a') === 0) {
                continue;
            }
            $parts = preg_split(self::TAG_PATTERN, $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $j => $part) {
                if (! str_starts_with($part, '<')) {
                    $parts[$j] = preg_replace_callback(self::MENTION_PATTERN, [self::class, 'anchor'], $part);
                }
            }
            $segments[$i] = implode('', $parts);
        }

        $data['post_content'] = wp_slash(implode('', $segments));

        return $data;
    }

    /**
     * @param array<int, string> $match
     */
    private static function anchor(array $match): string
    {
        return $match[1] . '<a href="' . self::PROFILE_BASE_URL . $match[2] . '" target="_blank" rel="noopener noreferrer">@' . $match[2] . '</a>';
    }
}
