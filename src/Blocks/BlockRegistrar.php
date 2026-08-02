<?php

namespace abcnorio\CustomFunc\Blocks;

final class BlockRegistrar
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerAll']);
    }

    public static function registerAll(): void
    {
        $blocks = BlockRegistry::getBlocks();

        foreach ($blocks as $blockName => $config) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($blockName)) {
                continue;
            }

            $options = [];

            if (isset($config['renderClass'], $config['renderMethod'])) {
                $options['render_callback'] = [$config['renderClass'], $config['renderMethod']];
            }

            register_block_type(
                plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . $config['path'],
                $options
            );
        }
    }
}
