<?php

namespace abcnorio\CustomFunc\ImageStyles;

/**
 * Orchestrates image attribute enrichment across block-data and REST pipelines.
 */
final class BlockImageAttributeEnricher
{
    private const IMAGE_BLOCK = 'core/image';
    private const DEFAULT_SIZE_SLUG = 'full';
    private const REST_BLOCKS_FIELD = 'abcnorio_blocks';

    /**
     * Register filters/actions used by both block and REST enrichments.
     */
    public static function registerHooks(): void
    {
        add_filter('vip_block_data_api__sourced_block_result', [self::class, 'enrichCoreImageBlock'], 20, 2);
        add_filter('rest_prepare_attachment', [self::class, 'enrichAttachmentResponse'], 20, 3);
        add_action('rest_api_init', [self::class, 'registerParsedBlocksField']);
    }

    /**
     * Register a normalized parsed-block field on all REST-enabled post types.
     *
     * This gives regular WP REST consumers access to a blocks array similar to
     * the VIP Block Data API shape used elsewhere in the project.
     */
    public static function registerParsedBlocksField(): void
    {
        $postTypes = get_post_types(['show_in_rest' => true], 'names');

        foreach ($postTypes as $postType) {
            if ($postType === 'attachment') {
                continue;
            }

            register_rest_field(
                $postType,
                self::REST_BLOCKS_FIELD,
                [
                    'get_callback' => [self::class, 'getParsedBlocksForRestField'],
                    'schema' => [
                        'description' => 'Parsed Gutenberg blocks with enriched core/image attributes.',
                        'type' => 'array',
                        'context' => ['view', 'edit'],
                    ],
                ]
            );
        }
    }

    /**
     * Build parsed block payload for the custom REST field.
     *
     * @param array<string, mixed> $preparedPost Prepared REST post entity.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getParsedBlocksForRestField(array $preparedPost): array
    {
        $postId = isset($preparedPost['id']) ? (int) $preparedPost['id'] : 0;
        if ($postId <= 0) {
            return [];
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return [];
        }

        $parsedBlocks = parse_blocks((string) $post->post_content);

        return self::mapParsedBlocks($parsedBlocks);
    }

    /**
     * Recursively map parse_blocks output to a compact, frontend-friendly shape.
     *
     * @param array<int, mixed> $parsedBlocks Raw parse_blocks output.
     *
     * @return array<int, array{name: string|null, attributes: array<string, mixed>, innerBlocks: array<int, array<string, mixed>>}>
     */
    private static function mapParsedBlocks(array $parsedBlocks): array
    {
        $mapped = [];

        foreach ($parsedBlocks as $parsedBlock) {
            if (! is_array($parsedBlock)) {
                continue;
            }

            $blockName = isset($parsedBlock['blockName']) && is_string($parsedBlock['blockName'])
                ? $parsedBlock['blockName']
                : null;

            $attributes = is_array($parsedBlock['attrs'] ?? null) ? $parsedBlock['attrs'] : [];

            if ($blockName === self::IMAGE_BLOCK) {
                $attachmentId = self::attachmentIdFromAttributes($attributes);
                if ($attachmentId > 0) {
                    $sizeSlug = self::sizeSlugFromAttributes($attributes);
                    $attributes = array_merge($attributes, ImageVariantBuilder::build($attachmentId, $sizeSlug));
                }
            }

            $innerBlocks = is_array($parsedBlock['innerBlocks'] ?? null)
                ? self::mapParsedBlocks($parsedBlock['innerBlocks'])
                : [];

            $mapped[] = [
                'name' => $blockName,
                'attributes' => $attributes,
                'innerBlocks' => $innerBlocks,
            ];
        }

        return $mapped;
    }

    /**
     * Enrich standard wp/v2 attachment responses with normalized image fields.
     *
     * @param mixed $response REST response object from core.
     * @param mixed $post Attachment post object.
     * @param mixed $request REST request object.
     *
     * @return mixed
     */
    public static function enrichAttachmentResponse($response, $post, $request)
    {
        if (! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post) {
            return $response;
        }

        $attachmentId = (int) $post->ID;
        if ($attachmentId <= 0) {
            return $response;
        }

        $data = $response->get_data();
        if (! is_array($data)) {
            return $response;
        }

        $requestedSize = $request instanceof \WP_REST_Request ? $request->get_param('media_size') : null;
        $sizeSlug = is_string($requestedSize) && $requestedSize !== '' ? $requestedSize : self::DEFAULT_SIZE_SLUG;
        $data = array_merge($data, ImageVariantBuilder::build($attachmentId, $sizeSlug));

        $response->set_data($data);

        return $response;
    }

    /**
     * Enrich core/image block attributes inside VIP Block Data API pipeline.
     *
     * @param array<string, mixed> $sourcedBlock Sourced block payload.
     * @param string $blockName Parsed block name.
     *
     * @return array<string, mixed>
     */
    public static function enrichCoreImageBlock(array $sourcedBlock, string $blockName): array
    {
        if (self::IMAGE_BLOCK !== $blockName) {
            return $sourcedBlock;
        }

        $attributes = is_array($sourcedBlock['attributes'] ?? null) ? $sourcedBlock['attributes'] : [];
        $attachmentId = self::attachmentIdFromAttributes($attributes);

        if ($attachmentId <= 0) {
            return $sourcedBlock;
        }

        $sizeSlug = self::sizeSlugFromAttributes($attributes);
        $attributes = array_merge($attributes, ImageVariantBuilder::build($attachmentId, $sizeSlug));

        $sourcedBlock['attributes'] = $attributes;

        return $sourcedBlock;
    }

    /**
     * Extract attachment ID from block attributes.
     *
     * @param array<string, mixed> $attributes
     */
    private static function attachmentIdFromAttributes(array $attributes): int
    {
        return isset($attributes['id']) ? (int) $attributes['id'] : 0;
    }

    /**
     * Resolve image size slug from attributes, with a full-size fallback.
     *
     * @param array<string, mixed> $attributes
     */
    private static function sizeSlugFromAttributes(array $attributes): string
    {
        return isset($attributes['sizeSlug']) && is_string($attributes['sizeSlug']) && $attributes['sizeSlug'] !== ''
            ? $attributes['sizeSlug']
            : self::DEFAULT_SIZE_SLUG;
    }
}