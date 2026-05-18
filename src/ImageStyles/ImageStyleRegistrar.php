<?php

namespace abcnorio\CustomFunc\ImageStyles;

final class ImageStyleRegistrar
{
    private const IMAGE_STYLES = [
        'abcnorio-card' => [
            'width' => 353,
            'height' => 9999,
            'crop' => false,
            'label' => 'ABC No Rio Card (353 wide)',
        ],
        'abcnorio-large' => [
            'width' => 738,
            'height' => 9999,
            'crop' => false,
            'label' => 'ABC No Rio Poster Large (738 wide)',
        ],
        'abcnorio-hero' => [
            'width' => 1080,
            'height' => 9999,
            'crop' => false,
            'label' => 'ABC No Rio Hero (1080 wide)',
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