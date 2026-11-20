import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, Notice, PanelBody, RangeControl, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import ServerSideRender from '@wordpress/server-side-render';
import {
    parseCommaSeparatedValues,
    stripHtmlTags,
} from './blockHelpers';
import { normalizeUniqueValues, parsePositiveInt, useQueryPostManualOrder } from './query-post-manual-order';
import blockMetadata from '../../src/Blocks/content-listing-query/block.json';
import { listingLayout, ListingLayoutControls } from './listingLayout';

const ORDER_OPTIONS = [
    { label: 'Ascending', value: 'asc' },
    { label: 'Descending', value: 'desc' },
    { label: 'Manual - Filtered Items', value: 'manual' },
];

const MIN_ITEM_COUNT = 6;
const MAX_ITEM_COUNT = 24;

const VARIANT_OPTIONS = [
    { label: 'Grid', value: 'grid' },
    { label: 'Slider', value: 'slider' },
];

const POST_TYPE_OPTIONS = [
    { label: 'Events', value: 'event' },
    { label: 'Articles', value: 'article' },
];

function togglePostType(postTypes, value) {
    const current = Array.isArray(postTypes) ? postTypes : [];
    if (current.includes(value)) {
        const next = current.filter((entry) => entry !== value);
        return next.length > 0 ? next : ['event', 'article'];
    }

    return [...current, value];
}

function getRecordTitle(record) {
    const renderedTitle = record?.title?.rendered;
    if (typeof renderedTitle === 'string' && renderedTitle.trim() !== '') {
        return stripHtmlTags(renderedTitle);
    }

    const rawTitle = record?.title?.raw;
    if (typeof rawTitle === 'string' && rawTitle.trim() !== '') {
        return rawTitle.trim();
    }

    const slug = typeof record?.slug === 'string' ? record.slug.trim() : '';
    return slug !== '' ? slug : `Post ${record?.id ?? ''}`;
}

registerBlockType('abcnorio/content-listing', {
    edit({ attributes, setAttributes }) {
        const layout = listingLayout(attributes, blockMetadata.attributes);
        const blockProps = useBlockProps({ style: layout.style });
        const orderMode = attributes.order === 'asc' || attributes.order === 'manual' ? attributes.order : 'desc';
        const isManualOrder = orderMode === 'manual';
        const selectedPostTypes = Array.isArray(attributes.listingPostTypes)
            ? attributes.listingPostTypes
            : ['event', 'article'];
        const orderIds = isManualOrder ? normalizeUniqueValues(attributes.sortOrderPostIds, parsePositiveInt) : [];
        const listingCount = Math.max(
            MIN_ITEM_COUNT,
            Math.min(MAX_ITEM_COUNT, Number.parseInt(attributes.listingCount, 10) || blockMetadata.attributes.listingCount.default)
        );
        const candidatePath = isManualOrder ? addQueryArgs('/abcnorio/v1/content-listing', {
            post_types: selectedPostTypes,
            tags: attributes.listingTagFilter || [],
            count: listingCount,
            order: 'desc',
            orderIds,
        }) : '';
        const [candidateState, setCandidateState] = useState({ path: '', records: [], error: '' });
        const isLoading = isManualOrder && candidateState.path !== candidatePath;
        const candidateRecords = isLoading ? [] : candidateState.records;
        const candidateError = isLoading ? '' : candidateState.error;

        useEffect(() => {
            if (!candidatePath) {
                return;
            }

            let isMounted = true;
            apiFetch({ path: candidatePath })
                .then((items) => {
                    if (!isMounted) {
                        return;
                    }
                    if (!Array.isArray(items) || items.some((item) =>
                        !Number.isInteger(item?.id) || item.id < 1
                        || !POST_TYPE_OPTIONS.some((option) => option.value === item.post_type)
                    )) {
                        throw new Error('Could not load matching content: invalid response.');
                    }
                    setCandidateState({
                        path: candidatePath,
                        records: items.map((item) => ({
                            id: item.id,
                            postType: item.post_type,
                            title: getRecordTitle(item),
                        })),
                        error: '',
                    });
                })
                .catch((error) => {
                    if (isMounted) {
                        setCandidateState({
                            path: candidatePath,
                            records: [],
                            error: String(error?.message || 'Could not load matching content.'),
                        });
                    }
                });

            return () => {
                isMounted = false;
            };
        }, [candidatePath]);

        const {
            draggableRecords,
            handleDragStart,
            handleDragOver,
            handleDrop,
        } = useQueryPostManualOrder({
            candidateRecords,
            orderedValues: orderIds,
            valueSelector: (record) => record.id,
            limit: listingCount,
            onOrderChange: (nextOrder) => setAttributes({ sortOrderPostIds: nextOrder }),
        });

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Content Listing Options" initialOpen={true}>
                        <SelectControl
                            label="Variant"
                            value={layout.variant}
                            options={VARIANT_OPTIONS}
                            onChange={(value) => setAttributes({ variant: value, gridColumns: layout.gridColumns })}
                        />
                        <ListingLayoutControls layout={layout} setAttributes={setAttributes} />
                        <TextControl
                            label="Title"
                            value={attributes.title || ''}
                            onChange={(value) => setAttributes({ title: value })}
                        />
                        <CheckboxControl
                            label="Show count"
                            checked={Boolean(attributes.showCount)}
                            onChange={(value) => setAttributes({ showCount: Boolean(value) })}
                        />
                        <CheckboxControl
                            label="Has CTA"
                            checked={Boolean(attributes.hasCta)}
                            onChange={(value) => setAttributes({ hasCta: Boolean(value) })}
                        />
                        {Boolean(attributes.hasCta) && (
                            <>
                                <TextControl
                                    label="CTA"
                                    value={attributes.ctaLabel || ''}
                                    onChange={(value) => setAttributes({ ctaLabel: value })}
                                />
                                <TextControl
                                    label="URL"
                                    value={attributes.ctaUrl || ''}
                                    onChange={(value) => setAttributes({ ctaUrl: value })}
                                />
                            </>
                        )}
                        {POST_TYPE_OPTIONS.map((option) => (
                            <CheckboxControl
                                key={option.value}
                                label={option.label}
                                checked={selectedPostTypes.includes(option.value)}
                                onChange={() =>
                                    setAttributes({
                                        listingPostTypes: togglePostType(selectedPostTypes, option.value),
                                    })
                                }
                            />
                        ))}
                        <TextControl
                            label="Tag Slugs (comma separated)"
                            value={(Array.isArray(attributes.listingTagFilter) ? attributes.listingTagFilter : []).join(', ')}
                            onChange={(value) =>
                                setAttributes({
                                    listingTagFilter: parseCommaSeparatedValues(value),
                                })
                            }
                        />
                        <SelectControl
                            label="Order By"
                            value={orderMode}
                            options={ORDER_OPTIONS}
                            onChange={(value) =>
                                setAttributes({
                                    order: value,
                                    sortOrderPostIds: value === 'manual'
                                        ? normalizeUniqueValues(attributes.sortOrderPostIds, parsePositiveInt)
                                        : [],
                                })
                            }
                        />
                        <RangeControl
                            label="Item Count"
                            value={listingCount}
                            min={MIN_ITEM_COUNT}
                            max={MAX_ITEM_COUNT}
                            onChange={(value) => setAttributes({ listingCount: value ?? listingCount })}
                        />
                    </PanelBody>
                    {isManualOrder && (
                        <PanelBody title="Manual Item Order" initialOpen={true}>
                        {isLoading ? <Spinner /> : candidateError ? (
                            <Notice status="error" isDismissible={false}>{candidateError}</Notice>
                        ) : draggableRecords.length === 0 ? (
                            <p className="abcnorio-manual-order__empty">No matching items found</p>
                        ) : (
                            <div>
                                <p className="abcnorio-manual-order__hint">
                                    Shows the current filtered selection, in the same order as the preview.
                                </p>
                                <p className="abcnorio-manual-order__heading">
                                    Drag to reorder (showing first {draggableRecords.length} items)
                                </p>
                                <div className="abcnorio-manual-order__list">
                                    {draggableRecords.map((record, index) => (
                                        <div
                                            key={record.id}
                                            draggable
                                            onDragStart={(e) => handleDragStart(e, record.id)}
                                            onDragOver={handleDragOver}
                                            onDrop={(e) => handleDrop(e, index)}
                                            className="abcnorio-manual-order__item"
                                        >
                                            <span className="abcnorio-manual-order__handle">⋮⋮</span>
                                            <span className="abcnorio-manual-order__title">
                                                {record.title}
                                                <span className="abcnorio-manual-order__meta">
                                                    ({record.postType}) #{record.id}
                                                </span>
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        </PanelBody>
                    )}
                </InspectorControls>
                <ServerSideRender block="abcnorio/content-listing" attributes={attributes} />
            </div>
        );
    },
    save() {
        return null;
    },
});
