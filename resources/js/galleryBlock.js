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
import imageSizes from '../vendor/components/dist/gallery-image-sizes.json';

const ALLOWED_BLOCKS = ['core/image'];
const MIN_ITEMS_PER_PAGE = 8;
const MAX_ITEMS_PER_PAGE = 24;

function normalizeNavigationMode(value) {
    return value === 'page' ? 'page' : 'item';
}

function normalizeVariant(value) {
    return value === 'default' || value === 'paged-grid' || value === 'masonry' ? value : 'slider';
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
            attributes={{ ...props.attributes, url: previewUrl }}
        />
    );
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
        const [editorPage, setEditorPage] = useState(1);
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
            const grid = gallery?.querySelector('.images');
            if (!grid) {
                return;
            }

            const items = Array.from(grid.querySelectorAll(':scope > [data-type="core/image"]'));
            items.forEach((item, index) => {
                const firstVisibleIndex = (editorPage - 1) * itemsPerPage;
                const isVisible = variant !== 'paged-grid'
                    || (index >= firstVisibleIndex && index < firstVisibleIndex + itemsPerPage);
                item.toggleAttribute('hidden', !isVisible);
                item.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
            });

            return () => {
                items.forEach((item) => {
                    item.removeAttribute('hidden');
                    item.removeAttribute('aria-hidden');
                });
            };
        }, [editorPage, imageCount.count, itemsPerPage, variant]);

        useEffect(() => {
            const gallery = galleryRef.current?.querySelector('gallery-listing');
            const grid = gallery?.querySelector('.images');
            if (variant !== 'paged-grid' || !gallery || !grid) {
                return;
            }

            const measurePage = () => {
                const width = gallery.clientWidth;
                if (pageHeightRef.current.width !== width) {
                    pageHeightRef.current = { width, height: 0 };
                    gallery.style.removeProperty('--gallery-paged-grid-min-height');
                }

                pageHeightRef.current.height = Math.max(
                    pageHeightRef.current.height,
                    gallery.getBoundingClientRect().height
                );
                gallery.style.setProperty(
                    '--gallery-paged-grid-min-height',
                    `${pageHeightRef.current.height}px`
                );
            };
            const observer = new ResizeObserver((entries) => {
                const width = Math.round(entries[0]?.contentRect.width || 0);
                if (width && width !== pageHeightRef.current.width) {
                    pageHeightRef.current = { width, height: 0 };
                    gallery.style.removeProperty('--gallery-paged-grid-min-height');
                }
                requestAnimationFrame(measurePage);
            });

            observer.observe(gallery);
            requestAnimationFrame(measurePage);
            return () => observer.disconnect();
        }, [editorPage, imageCount.count, itemsPerPage, variant]);

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
                `abcnorio-gallery-nav-${navigationMode}`,
                `abcnorio-gallery--${variant}`,
                variant === 'paged-grid' || variant === 'masonry' ? null : 'blaze-slider',
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
                    class={`gallery ${variant === 'paged-grid' || variant === 'masonry' ? '' : 'blaze-slider'} gallery--${variant} gallery--nav-${navigationMode}`}
                    data-variant={variant}
                    data-navigation-mode={navigationMode}
                    data-items-per-page={itemsPerPage}
                >
                    <div className={variant === 'paged-grid' || variant === 'masonry' ? undefined : 'blaze-container'}>
                        <div className={variant === 'paged-grid' || variant === 'masonry' ? undefined : 'blaze-track-container'}>
                            <div {...innerBlockContainerProps} className={variant === 'paged-grid' || variant === 'masonry' ? 'images' : 'images blaze-track'}>
                                {innerBlockChildren}
                            </div>
                        </div>

                        {variant !== 'paged-grid' && variant !== 'masonry' && (
                            <>
                                <button type="button" className="blaze-prev"><span>Previous!</span></button>
                                <button type="button" className="blaze-next"><span>Next!</span></button>
                                <div className="blaze-pagination"></div>
                            </>
                        )}
                    </div>

                    {variant === 'paged-grid' && (
                        <nav className="gallery-pagination" aria-label="Gallery pages">
                            <ul>
                                <li>
                                    <button type="button" disabled={editorPage === 1} onClick={() => setEditorPage((page) => page - 1)}>
                                        Previous
                                    </button>
                                </li>
                                {Array.from({ length: pageCount }, (_, index) => {
                                    const page = index + 1;
                                    return (
                                        <li key={page}>
                                            <button
                                                type="button"
                                                className={page === editorPage ? 'is-current' : undefined}
                                                aria-current={page === editorPage ? 'page' : undefined}
                                                aria-label={page === editorPage ? `Page ${page}, current page` : `Go to page ${page}`}
                                                onClick={() => setEditorPage(page)}
                                            >
                                                {page}
                                            </button>
                                        </li>
                                    );
                                })}
                                <li>
                                    <button type="button" disabled={editorPage === pageCount} onClick={() => setEditorPage((page) => page + 1)}>
                                        Next
                                    </button>
                                </li>
                            </ul>
                        </nav>
                    )}

                    {variant !== 'paged-grid' && variant !== 'masonry' && <nav className="blaze-slide-dots" aria-label="Slide navigation"></nav>}
                </gallery-listing>
            </div>
        );
    },
    save() {
        return <InnerBlocks.Content />;
    },
});