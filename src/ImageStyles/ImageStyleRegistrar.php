<?php

namespace abcnorio\CustomFunc\ImageStyles;

final class ImageStyleRegistrar
{
    private const IMAGE_STYLES = [
        'abcnorio-card' => [
            'width' => 640,
            'height' => 360,
            'crop' => true,
            'label' => 'ABC No Rio Card (640x360)',
        ],
        'abcnorio-hero' => [
            'width' => 1600,
            'height' => 900,
            'crop' => true,
            'label' => 'ABC No Rio Hero (1600x900)',
        ],
    ];

    public static function registerHooks(): void
    {
        add_action('after_setup_theme', [self::class, 'registerImageSizes']);
        add_filter('image_size_names_choose', [self::class, 'addSizeLabels']);
    }

    public static function registerImageSizes(): void
    {
        foreach (self::IMAGE_STYLES as $name => $style) {
            add_image_size($name, $style['width'], $style['height'], $style['crop']);
        }
    }

    public static function addSizeLabels(array $sizes): array
    {
        foreach (self::IMAGE_STYLES as $name => $style) {
            $sizes[$name] = $style['label'];
        }

        return $sizes;
    }
}