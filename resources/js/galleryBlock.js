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
import { useEffect, useRef, useState } from '@wordpress/element';
import { createBlock } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import imageSizes from '../../src/Blocks/gallery/image-sizes.json';
import { createGallerySliderPreview, showGalleryPage } from './galleryPreview';
import ComponentShell from './componentShell';

const ALLOWED_BLOCKS = ['core/image'];
const MIN_ITEMS_PER_PAGE = 8;
const MAX_ITEMS_PER_PAGE = 24;

function normalizeVariant(value) {
    return value === 'default' || value === 'paged-grid' || value === 'masonry' || value === 'captioned' ? value : 'slider';
}

// Show gallery images at the variant size without changing their saved attributes.
const withoutImageResize = createHigherOrderComponent((BlockEdit) => (props) => {
    const { parentName, previewUrl } = useSelect((select) => {
        const editor = select('core/block-editor');
        const parentId = editor.getBlockRootClientId(props.clientId);
        if (!parentId || editor.getBlockName(parentId) !== 'abcnorio/gallery') {
            return { parentName: null, previewUrl: null };
        }

        const galleryBlock = editor.getBlock(parentId);
        const parentAttributes = editor.getBlockAttributes(parentId);
        const imageSize = imageSizes[normalizeVariant(parentAttributes.variant)];
        const attachmentId = props.attributes.id;
        const core = select('core');
        const attachmentIds = [...new Set((galleryBlock?.innerBlocks || [])
            .map((block) => Number(block.attributes.id))
            .filter((id) => Number.isInteger(id) && id > 0))];
        const query = {
            include: attachmentIds,
            per_page: attachmentIds.length,
            _fields: 'id,media_details',
        };
        const attachments = attachmentIds.length
            ? core.getEntityRecords('postType', 'attachment', query)
            : [];
        const attachment = attachments?.find((record) => Number(record.id) === Number(attachmentId));
        const resolutionFinished = attachmentIds.length
            ? core.hasFinishedResolution('getEntityRecords', ['postType', 'attachment', query])
            : false;
        const previewUrl = attachment?.media_details?.sizes?.[imageSize]?.source_url
            || (resolutionFinished || !attachmentId ? props.attributes.url : '');

        return { parentName: 'abcnorio/gallery', previewUrl };
    }, [props.clientId, props.attributes.id]);
    const isGalleryImage = props.name === 'core/image' && parentName === 'abcnorio/gallery';

    useEffect(() => {
        if (!isGalleryImage || !props.isSelected) {
            return;
        }

        document.body.classList.add('abcnorio-gallery-image-selected');
        return () => document.body.classList.remove('abcnorio-gallery-image-selected');
    }, [isGalleryImage, props.isSelected]);

    if (!isGalleryImage) {
        return <BlockEdit {...props} />;
    }

    return (
        <BlockEdit
            {...props}
            allowResize={false}
            attributes={{ ...props.attributes, url: previewUrl }}
        />
    );
}, 'withoutImageResize');

addFilter('editor.BlockEdit', 'abcnorio/gallery-image-no-resize', withoutImageResize);

registerBlockType('abcnorio/gallery', {
    edit({ attributes, setAttributes, clientId }) {
        const variant = normalizeVariant(attributes.variant);
        const isSlider = variant !== 'paged-grid' && variant !== 'masonry';
        const itemsPerPage = Math.max(
            MIN_ITEMS_PER_PAGE,
            Math.min(MAX_ITEMS_PER_PAGE, Number.parseInt(attributes.itemsPerPage, 10) || MIN_ITEMS_PER_PAGE)
        );
        const imageCount = useSelect(
            (select) => {
                const editor = select('core/block-editor');
                const selectedClientId = editor.getSelectedBlockClientId();
                const selectedRootClientId = selectedClientId
                    ? editor.getBlockRootClientId(selectedClientId)
                    : null;
                return {
                    count: editor.getBlock(clientId)?.innerBlocks?.length || 0,
                    selectedClientId: selectedRootClientId === clientId ? selectedClientId : null,
                    selectedIndex: selectedRootClientId === clientId
                        ? editor.getBlockIndex(selectedClientId, clientId)
                        : -1,
                };
            },
            [clientId]
        );
        const galleryRef = useRef(null);
        const sliderRef = useRef(null);
        const previousPageRef = useRef(1);
        const [editorPage, setEditorPage] = useState(1);
        const [sliderNavigation, setSliderNavigation] = useState({ index: 0, count: 1 });
        const pageHeightRef = useRef({ width: 0, height: 0 });
        const { insertBlocks } = useDispatch('core/block-editor');
        const pageCount = Math.max(1, Math.ceil(imageCount.count / itemsPerPage));

        useEffect(() => {
            if (variant !== 'paged-grid') {
                setEditorPage(1);
                return;
            }

            setEditorPage((page) => Math.min(page, pageCount));
            if (imageCount.selectedIndex >= 0) {
                setEditorPage(Math.floor(imageCount.selectedIndex / itemsPerPage) + 1);
            }
        }, [imageCount.selectedIndex, itemsPerPage, pageCount, variant]);

        useEffect(() => {
            const gallery = galleryRef.current;
            if (!gallery) {
                return;
            }
            const cleanup = showGalleryPage(
                gallery,
                variant === 'paged-grid' ? editorPage : null,
                itemsPerPage,
                previousPageRef.current,
                imageCount.selectedIndex < 0
            );
            previousPageRef.current = editorPage;
            return cleanup;
        }, [editorPage, imageCount.count, imageCount.selectedIndex, itemsPerPage, variant]);

        useEffect(() => {
            if (variant === 'paged-grid' || variant === 'masonry') {
                return;
            }
            const preview = createGallerySliderPreview(galleryRef.current, setSliderNavigation);
            sliderRef.current = preview;
            return () => {
                preview.destroy();
                sliderRef.current = null;
            };
        }, [imageCount.count, variant]);

        useEffect(() => {
            if (imageCount.selectedClientId) {
                sliderRef.current?.reveal(imageCount.selectedClientId);
            }
        }, [imageCount.selectedClientId]);

        useEffect(() => {
            const gallery = galleryRef.current;
            const grid = gallery?.querySelector('.images');
            if (variant !== 'paged-grid' || !gallery || !grid) {
                return;
            }

            const measurePage = () => {
                const width = grid.clientWidth;
                if (pageHeightRef.current.width !== width) {
                    pageHeightRef.current = { width, height: 0 };
                    grid.style.removeProperty('--gallery-paged-grid-min-height');
                }

                pageHeightRef.current.height = Math.max(
                    pageHeightRef.current.height,
                    grid.getBoundingClientRect().height
                );
                grid.style.setProperty(
                    '--gallery-paged-grid-min-height',
                    `${pageHeightRef.current.height}px`
                );
            };
            let frame;
            const observer = new ResizeObserver(() => {
                const width = grid.clientWidth;
                if (width && width !== pageHeightRef.current.width) {
                    pageHeightRef.current = { width, height: 0 };
                    grid.style.removeProperty('--gallery-paged-grid-min-height');
                }
                cancelAnimationFrame(frame);
                frame = requestAnimationFrame(measurePage);
            });

            observer.observe(grid);
            frame = requestAnimationFrame(measurePage);
            return () => {
                observer.disconnect();
                cancelAnimationFrame(frame);
                grid.style.removeProperty('--gallery-paged-grid-min-height');
                pageHeightRef.current = { width: 0, height: 0 };
            };
        }, [imageCount.count, itemsPerPage, variant]);

        useEffect(() => {
            if (variant !== 'paged-grid' || imageCount.selectedIndex < 0) {
                return;
            }

            const grid = galleryRef.current?.querySelector('.images');
            const selectedItem = Array.from(grid?.children || []).find(
                (item) => item.getAttribute('data-block') === imageCount.selectedClientId
            );
            selectedItem?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }, [editorPage, imageCount.selectedClientId, imageCount.selectedIndex, variant]);

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
                'abcnorio-gallery-nav-page',
                `abcnorio-gallery--${variant}`,
                isSlider ? 'blaze-slider' : null,
            ].join(' '),
        });

        const innerBlocksProps = useInnerBlocksProps(
            { className: 'images blaze-track' },
            {
                allowedBlocks: ALLOWED_BLOCKS,
                renderAppender: InnerBlocks.ButtonBlockAppender,
            }
        );
        const { children: innerBlockChildren, ...innerBlockContainerProps } = innerBlocksProps;

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
                                { label: 'Captioned Gallery', value: 'captioned' },
                                { label: 'Paged Grid', value: 'paged-grid' },
                                { label: 'Masonry', value: 'masonry' },
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

                <ComponentShell
                    component="gallery"
                    blockProps={{
                        ref: galleryRef,
                        className: `gallery ${isSlider ? 'blaze-slider' : ''} gallery--${variant} gallery--nav-page`,
                        'data-variant': variant,
                        'data-navigation-mode': 'page',
                        'data-items-per-page': itemsPerPage,
                    }}
                    slots={{ content: innerBlockChildren }}
                    bindings={{
                        container: { props: { className: isSlider ? 'blaze-container' : undefined } },
                        viewport: { props: { className: isSlider ? 'blaze-track-container' : undefined } },
                        track: { props: { ...innerBlockContainerProps, className: isSlider ? 'images blaze-track' : 'images' } },
                        previous: { hidden: !isSlider, props: { disabled: sliderNavigation.index === 0, onClick: () => sliderRef.current?.goTo(sliderNavigation.index - 1) } },
                        next: { hidden: !isSlider, props: { disabled: sliderNavigation.index === sliderNavigation.count - 1, onClick: () => sliderRef.current?.goTo(sliderNavigation.index + 1) } },
                        pagination: { hidden: !isSlider },
                        dots: { hidden: !isSlider },
                        pages: { hidden: variant !== 'paged-grid' || pageCount <= 1 },
                        'previous-page': { props: { disabled: editorPage === 1, onClick: () => setEditorPage((page) => page - 1) } },
                        'next-page': { props: { disabled: editorPage === pageCount, onClick: () => setEditorPage((page) => page + 1) } },
                    }}
                    repeats={{
                        pages: Array.from({ length: pageCount }, (_, index) => ({
                            key: index + 1,
                            bindings: { button: {
                                children: index + 1,
                                props: {
                                    className: index + 1 === editorPage ? 'is-current' : undefined,
                                    'aria-current': index + 1 === editorPage ? 'page' : undefined,
                                    'aria-label': index + 1 === editorPage ? `Page ${index + 1}, current page` : `Go to page ${index + 1}`,
                                    onClick: () => setEditorPage(index + 1),
                                },
                            } },
                        })),
                        dots: sliderNavigation.count <= 1 ? [] : Array.from({ length: sliderNavigation.count }, (_, index) => ({
                            key: index,
                            bindings: { button: { props: {
                                className: `blaze-slide-dot${index === sliderNavigation.index ? ' is-active' : ''}`,
                                'aria-current': index === sliderNavigation.index ? 'true' : undefined,
                                'aria-label': index === sliderNavigation.index ? `Current page ${index + 1}` : `Go to page ${index + 1}`,
                                onClick: () => sliderRef.current?.goTo(index),
                            } } },
                        })),
                    }}
                />
            </div>
        );
    },
    save() {
        return <InnerBlocks.Content />;
    },
});