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
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { createBlock } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import imageSizes from '../../src/Blocks/gallery/image-sizes.json';

const ALLOWED_BLOCKS = ['core/image'];
const MIN_ITEMS_PER_PAGE = 8;
const MAX_ITEMS_PER_PAGE = 24;

function normalizeNavigationMode(value) {
    return value === 'page' ? 'page' : 'item';
}

function normalizeVariant(value) {
    return value === 'default' || value === 'paged-grid' ? value : 'slider';
}

// Sizes come from the image style, so drop core/image's drag handles inside the gallery.
const withoutImageResize = createHigherOrderComponent((BlockEdit) => (props) => {
    const parentName = useSelect((select) => {
        const editor = select('core/block-editor');
        const parentId = editor.getBlockRootClientId(props.clientId);
        return parentId ? editor.getBlockName(parentId) : null;
    }, [props.clientId]);

    if (props.name !== 'core/image' || parentName !== 'abcnorio/gallery') {
        return <BlockEdit {...props} />;
    }

    return <BlockEdit {...props} allowResize={false} />;
}, 'withoutImageResize');

addFilter('editor.BlockEdit', 'abcnorio/gallery-image-no-resize', withoutImageResize);

registerBlockType('abcnorio/gallery', {
    edit({ attributes, setAttributes, clientId }) {
        const navigationMode = normalizeNavigationMode(attributes.navigationMode);
        const variant = normalizeVariant(attributes.variant);
        const itemsPerPage = Math.max(
            MIN_ITEMS_PER_PAGE,
            Math.min(MAX_ITEMS_PER_PAGE, Number.parseInt(attributes.itemsPerPage, 10) || MIN_ITEMS_PER_PAGE)
        );
        const imageCount = useSelect(
            (select) => select('core/block-editor').getBlock(clientId)?.innerBlocks?.length || 0,
            [clientId]
        );
        const galleryRef = useRef(null);
        const { insertBlocks } = useDispatch('core/block-editor');

        useEffect(() => {
            const gallery = galleryRef.current;
            const grid = gallery?.querySelector('.images');
            if (!grid) {
                return;
            }

            const items = Array.from(grid.querySelectorAll(':scope > [data-type="core/image"]'));
            items.forEach((item, index) => {
                const isVisible = variant !== 'paged-grid' || index < itemsPerPage;
                item.toggleAttribute('hidden', !isVisible);
                item.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
            });

            return () => {
                items.forEach((item) => {
                    item.removeAttribute('hidden');
                    item.removeAttribute('aria-hidden');
                });
            };
        }, [imageCount, itemsPerPage, variant]);

        function insertSelectedImages(mediaItems) {
            const selected = Array.isArray(mediaItems) ? mediaItems : [mediaItems];
            const blocks = selected
                .filter((item) => item && item.id && item.url)
                .map((item) => {
                    const captionText = typeof item.caption === 'string'
                        ? item.caption
                        : (item.caption && item.caption.raw) || '';

                    const imageSize = imageSizes[variant];

                    return createBlock('core/image', {
                        id: item.id,
                        url: item.sizes?.[imageSize]?.url || item.url,
                        alt: item.alt || '',
                        caption: captionText,
                        sizeSlug: imageSize,
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
                                min={MIN_ITEMS_PER_PAGE}
                                max={MAX_ITEMS_PER_PAGE}
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
                    ref={galleryRef}
                    class={`gallery ${variant === 'paged-grid' ? '' : 'blaze-slider'} gallery--${variant} gallery--nav-${navigationMode}`}
                    data-variant={variant}
                    data-navigation-mode={navigationMode}
                    data-items-per-page={itemsPerPage}
                >
                    <div className={variant === 'paged-grid' ? undefined : 'blaze-container'}>
                        <div className={variant === 'paged-grid' ? undefined : 'blaze-track-container'}>
                            <div {...innerBlocksProps} className={variant === 'paged-grid' ? 'images' : 'images blaze-track'} />
                        </div>

                        {variant !== 'paged-grid' && (
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