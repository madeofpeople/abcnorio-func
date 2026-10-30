<?php

namespace abcnorio\CustomFunc\RestApi;

// wp_trim_excerpt() strips tags without adding whitespace, so "a<br>b" becomes "ab".
// Keep a newline after each <br> while an excerpt is generated so words stay separated.
final class ExcerptBreakSpacing
{
    public static function registerHooks(): void
    {
        add_filter('the_content', [self::class, 'spaceBreaks'], 20);
    }

    public static function spaceBreaks(string $content): string
    {
        if (! doing_filter('get_the_excerpt')) {
            return $content;
        }

        return preg_replace('#<br\s*/?>#i', "$0\n", $content);
    }
}
