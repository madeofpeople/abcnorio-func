import { registerBlockType } from '@wordpress/blocks';
import {
    InspectorControls,
    InnerBlocks,
    MediaUpload,
    MediaUploadCheck,
    useBlockProps,
    useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { QUERY_BLOCK_MAX_ITEM_COUNT, QUERY_BLOCK_MIN_ITEM_COUNT } from './blockHelpers';

const ALLOWED_BLOCKS = ['core/image'];

function normalizeNavigationMode(value) {
    return value === 'page' ? 'page' : 'item';
}

function normalizeVariant(value) {
    return value === 'default' || value === 'paged-grid' ? value : 'slider';
}

registerBlockType('abcnorio/gallery', {
    edit({ attributes, setAttributes, clientId }) {
        const navigationMode = normalizeNavigationMode(attributes.navigationMode);
        const variant = normalizeVariant(attributes.variant);
        const itemsPerPage = Math.max(
            QUERY_BLOCK_MIN_ITEM_COUNT,
            Math.min(QUERY_BLOCK_MAX_ITEM_COUNT, Number.parseInt(attributes.itemsPerPage, 10) || 6)
        );
        const { insertBlocks } = useDispatch('core/block-editor');

        function insertSelectedImages(mediaItems) {
            const selected = Array.isArray(mediaItems) ? mediaItems : [mediaItems];
            const blocks = selected
                .filter((item) => item && item.id && item.url)
                .map((item) => {
                    const captionText = typeof item.caption === 'string'
                        ? item.caption
                        : (item.caption && item.caption.raw) || '';

                    return createBlock('core/image', {
                        id: item.id,
                        url: item.url,
                        alt: item.alt || '',
                        caption: captionText,
                        sizeSlug: 'galery-slider',
                    });
                });

            if (blocks.length === 0) {
                return;
            }

            insertBlocks(blocks, undefined, clientId);
        }

        const blockProps = useBlockProps({
            className: [
                'wp-block-abcnorio-gallery',
                'abcnorio-gallery-editor',
                `abcnorio-gallery-nav-${navigationMode}`,
                `abcnorio-gallery--${variant}`,
                variant === 'paged-grid' ? null : 'blaze-slider',
            ].join(' '),
        });

        const innerBlocksProps = useInnerBlocksProps(
            { className: 'images blaze-track' },
            {
                allowedBlocks: ALLOWED_BLOCKS,
                renderAppender: InnerBlocks.ButtonBlockAppender,
            }
        );

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Gallery Controls" initialOpen={true}>
                        <SelectControl
                            label="Variant"
                            value={variant}
                            options={[
                                { label: 'Default', value: 'default' },
                                { label: 'Slider', value: 'slider' },
                                { label: 'Paged Grid', value: 'paged-grid' },
                            ]}
                            onChange={(value) => setAttributes({ variant: normalizeVariant(value) })}
                        />
                        {variant === 'paged-grid' && (
                            <RangeControl
                                label="Items Per Page"
                                value={itemsPerPage}
                                min={QUERY_BLOCK_MIN_ITEM_COUNT}
                                max={QUERY_BLOCK_MAX_ITEM_COUNT}
                                onChange={(value) => setAttributes({ itemsPerPage: value ?? itemsPerPage })}
                            />
                        )}
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={insertSelectedImages}
                                allowedTypes={['image']}
                                multiple
                                gallery
                                render={({ open }) => (
                                    <Button variant="secondary" onClick={open}>
                                        Select Images
                                    </Button>
                                )}
                            />
                        </MediaUploadCheck>
                    </PanelBody>
                </InspectorControls>

                <gallery-listing
                    className={`gallery ${variant === 'paged-grid' ? '' : 'blaze-slider'} gallery--${variant} gallery--nav-${navigationMode}`}
                    data-variant={variant}
                    data-navigation-mode={navigationMode}
                    data-items-per-page={itemsPerPage}
                >
                    <div className="blaze-container">
                        <div className="blaze-track-container">
                            <div {...innerBlocksProps} />
                        </div>

                        {variant === 'paged-grid' ? (
                            <nav className="gallery-pagination" aria-label="Gallery pagination" hidden />
                        ) : (
                            <>
                                <a href="javascript:void(0)" role="button" className="blaze-prev"><span>Previous!</span></a>
                                <a href="javascript:void(0)" role="button" className="blaze-next"><span>Next!</span></a>
                                <div className="blaze-pagination"></div>
                            </>
                        )}
                    </div>

                    {variant !== 'paged-grid' && <nav className="blaze-slide-dots" aria-label="Slide navigation"></nav>}
                </gallery-listing>
            </div>
        );
    },
    save() {
        return <InnerBlocks.Content />;
    },
});