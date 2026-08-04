import { registerBlockType } from '@wordpress/blocks';
import {
    InspectorControls,
    InnerBlocks,
    MediaUpload,
    MediaUploadCheck,
    useBlockProps,
    useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';

const ALLOWED_BLOCKS = ['core/image'];

function normalizeNavigationMode(value) {
    return value === 'page' ? 'page' : 'item';
}

function normalizeVariant(value) {
    return value === 'default' ? 'default' : 'slider';
}

registerBlockType('abcnorio/gallery', {
    edit({ attributes, setAttributes, clientId }) {
        const navigationMode = normalizeNavigationMode(attributes.navigationMode);
        const variant = normalizeVariant(attributes.variant);
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
                            ]}
                            onChange={(value) => setAttributes({ variant: normalizeVariant(value) })}
                        />
                        <SelectControl
                            label="Navigation"
                            value={navigationMode}
                            options={[
                                { label: 'Item based', value: 'item' },
                                { label: 'Page based', value: 'page' },
                            ]}
                            onChange={(value) => setAttributes({ navigationMode: normalizeNavigationMode(value) })}
                        />
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
                    className={`gallery blaze-slider gallery--${variant} gallery--nav-${navigationMode}`}
                    data-variant={variant}
                    data-navigation-mode={navigationMode}
                >
                    <div className="blaze-container">
                        <div className="blaze-track-container">
                            <div {...innerBlocksProps} />
                        </div>

                        <a href="javascript:void(0)" role="button" className="blaze-prev"><span>Previous!</span></a>
                        <a href="javascript:void(0)" role="button" className="blaze-next"><span>Next!</span></a>
                        <div className="blaze-pagination"></div>
                    </div>

                    <nav className="blaze-slide-dots" aria-label="Slide navigation"></nav>
                </gallery-listing>
            </div>
        );
    },
    save() {
        return <InnerBlocks.Content />;
    },
});