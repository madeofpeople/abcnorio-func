<?php

namespace abcnorio\CustomFunc\ImageStyles;

/**
 * Builds a normalized responsive-image payload from a WordPress attachment.
 *
 * Data contract used by both VIP block enrichment and standard REST enrichment:
 *
 * [
 *   'srcset' => '(string) responsive candidates list',
 *   'sizes' => '(string) sizes attribute value',
 *   'url' => '(string) URL for requested size slug',
 *   'sizes_data' => [
 *     'thumbnail' => [
 *       'source_url' => '(string)',
 *       'width' => 150,
 *       'height' => 150,
 *     ],
 *     'full' => [
 *       'source_url' => '(string)',
 *       'width' => 1707,
 *       'height' => 2560,
 *     ],
 *   ],
 * ]
 */
final class ImageVariantBuilder
{
    private const DEFAULT_SIZE_SLUG = 'full';

    /**
     * Build normalized image attributes for API responses.
     *
     * Output keys are intentionally stable across consumers:
     * - srcset
     * - sizes
     * - url
     * - sizes_data (map of variant name => source_url, width, height)
     *
     * @param int $attachmentId WordPress attachment ID.
     * @param string $sizeSlug Requested image size slug.
     *
     * @return array<string, mixed>
     */
    public static function build(int $attachmentId, string $sizeSlug = self::DEFAULT_SIZE_SLUG): array
    {
        if ($attachmentId <= 0) {
            return [];
        }

        $normalizedSizeSlug = $sizeSlug !== '' ? $sizeSlug : self::DEFAULT_SIZE_SLUG;
        $enriched = [];

        $srcset = wp_get_attachment_image_srcset($attachmentId, $normalizedSizeSlug);
        if (is_string($srcset) && $srcset !== '') {
            $enriched['srcset'] = $srcset;
        }

        $sizes = wp_get_attachment_image_sizes($attachmentId, $normalizedSizeSlug);
        if (is_string($sizes) && $sizes !== '') {
            $enriched['sizes'] = $sizes;
        }

        $sizedUrl = wp_get_attachment_image_url($attachmentId, $normalizedSizeSlug);
        if (is_string($sizedUrl) && $sizedUrl !== '') {
            $enriched['url'] = $sizedUrl;
        }

        $variantMap = self::buildVariantMap($attachmentId);
        if (! empty($variantMap)) {
            $enriched['sizes_data'] = $variantMap;
        }

        return $enriched;
    }

    /**
     * Build a map of available image variants from attachment metadata.
     *
     * Includes generated intermediate sizes and a synthetic full-size entry
     * when width/height metadata is available.
     *
     * @param int $attachmentId WordPress attachment ID.
     *
     * @return array<string, array<string, int|string>>
     */
    private static function buildVariantMap(int $attachmentId): array
    {
        $metadata = wp_get_attachment_metadata($attachmentId);
        $allSizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];

        $variants = [];

        foreach ($allSizes as $name => $sizeData) {
            if (! is_array($sizeData)) {
                continue;
            }

            $sourceUrl = wp_get_attachment_image_url($attachmentId, $name);
            if (! is_string($sourceUrl) || $sourceUrl === '') {
                continue;
            }

            $width = isset($sizeData['width']) ? (int) $sizeData['width'] : 0;
            $height = isset($sizeData['height']) ? (int) $sizeData['height'] : 0;

            if ($width <= 0 || $height <= 0) {
                continue;
            }

            $variants[$name] = [
                'source_url' => $sourceUrl,
                'width' => $width,
                'height' => $height,
            ];
        }

        $fullUrl = wp_get_attachment_image_url($attachmentId, self::DEFAULT_SIZE_SLUG);

        if (is_string($fullUrl) && $fullUrl !== '' && is_array($metadata)) {
            $fullWidth = isset($metadata['width']) ? (int) $metadata['width'] : 0;
            $fullHeight = isset($metadata['height']) ? (int) $metadata['height'] : 0;

            if ($fullWidth > 0 && $fullHeight > 0) {
                $variants[self::DEFAULT_SIZE_SLUG] = [
                    'source_url' => $fullUrl,
                    'width' => $fullWidth,
                    'height' => $fullHeight,
                ];
            }
        }

        return $variants;
    }
}