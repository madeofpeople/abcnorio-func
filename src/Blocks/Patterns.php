<?php

namespace abcnorio\CustomFunc\Blocks;

final class Patterns
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerPatterns']);
    }

    public static function registerPatterns(): void
    {
     
    }
}
