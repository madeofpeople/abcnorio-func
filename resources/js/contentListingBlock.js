import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';
import {
    QUERY_BLOCK_MAX_ITEM_COUNT,
    QUERY_BLOCK_MIN_ITEM_COUNT,
    parseCommaSeparatedValues,
    stripHtmlTags,
} from './blockHelpers';
import { normalizeUniqueValues, parsePositiveInt, useQueryPostManualOrder } from './query-post-manual-order';
import blockMetadata from '../../src/Blocks/content-listing-query/block.json';
import { listingLayout, ListingLayoutControls } from './listingLayout';

const ORDER_OPTIONS = [
    { label: 'Ascending', value: 'asc' },
    { label: 'Descending', value: 'desc' },
    { label: 'Manual - Upcoming', value: 'manual' },
];

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

function isUpcomingForDayOrBeyond(dateValue) {
    if (typeof dateValue !== 'string' || dateValue.trim() === '') {
        return false;
    }

    const parsedDate = Date.parse(dateValue);
    if (Number.isNaN(parsedDate)) {
        return false;
    }

    const todayStart = new Date();
    todayStart.setHours(0, 0, 0, 0);

    return parsedDate >= todayStart.getTime();
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
            QUERY_BLOCK_MIN_ITEM_COUNT,
            Math.min(QUERY_BLOCK_MAX_ITEM_COUNT, Number.parseInt(attributes.listingCount, 10) || 5)
        );

        const candidateRecords = useSelect(
            (select) => {
                const coreStore = select('core');
                if (!coreStore) {
                    return [];
                }

                const records = [];

                selectedPostTypes.forEach((postType) => {
                    const items = coreStore.getEntityRecords('postType', postType, {
                        per_page: 100,
                        status: 'publish',
                        orderby: 'date',
                        order: 'desc',
                        _fields: 'id,title,slug,date',
                    });

                    if (!Array.isArray(items)) {
                        return;
                    }

                    items.forEach((item) => {
                        records.push({
                            id: Number.parseInt(item?.id, 10),
                            postType,
                            title: getRecordTitle(item),
                            date: typeof item?.date === 'string' ? item.date : '',
                        });
                    });
                });

                return records
                    .filter((item) => Number.isFinite(item.id) && item.id > 0)
                    .sort((left, right) => {
                        const leftDate = Date.parse(left.date || '');
                        const rightDate = Date.parse(right.date || '');
                        const leftScore = Number.isNaN(leftDate) ? 0 : leftDate;
                        const rightScore = Number.isNaN(rightDate) ? 0 : rightDate;
                        if (leftScore === rightScore) {
                            return right.id - left.id;
                        }

                        return rightScore - leftScore;
                    });
            },
            [selectedPostTypes.join(',')]
        );

        const {
            draggableRecords,
            handleDragStart,
            handleDragOver,
            handleDrop,
        } = useQueryPostManualOrder({
            candidateRecords: isManualOrder
                ? candidateRecords.filter((record) => isUpcomingForDayOrBeyond(record.date))
                : candidateRecords,
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
                            min={QUERY_BLOCK_MIN_ITEM_COUNT}
                            max={QUERY_BLOCK_MAX_ITEM_COUNT}
                            onChange={(value) => setAttributes({ listingCount: value ?? listingCount })}
                        />
                    </PanelBody>
                    {isManualOrder && (
                        <PanelBody title="Manual Item Order" initialOpen={true}>
                        {draggableRecords.length === 0 ? (
                            <p className="abcnorio-manual-order__empty">No matching items found</p>
                        ) : (
                            <div>
                                <p className="abcnorio-manual-order__hint">
                                    only shows upcoming entries for the day of and beyond
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
