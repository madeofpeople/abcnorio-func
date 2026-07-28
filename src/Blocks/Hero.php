<?php

namespace abcnorio\CustomFunc\Blocks;

final class Hero
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerBlock']);
    }

    public static function registerBlock(): void
    {
        if (\WP_Block_Type_Registry::get_instance()->is_registered('abcnorio/hero')) {
            return;
        }

        register_block_type(
            plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'src/Blocks/hero'
        );
    }
}
