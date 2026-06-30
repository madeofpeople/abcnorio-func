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
        'abcnorio-collective-thumb' => [
            'width' => 353,
            'height' => 199,
            'crop' => true,
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
        add_filter('image_editor_output_format', [self::class, 'useWebP']);
        add_filter('wp_editor_set_quality', [self::class, 'setWebPQuality'], 10, 2);
    }

    public static function useWebP(array $mapping): array
    {
        $mapping['image/jpeg'] = 'image/webp';
        $mapping['image/png']  = 'image/webp';
        return $mapping;
    }

    public static function setWebPQuality(int $quality, string $mime): int
    {
        return $mime === 'image/webp' ? 80 : $quality;
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