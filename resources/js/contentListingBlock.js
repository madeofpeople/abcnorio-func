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

const SLIDE_BREAKPOINTS = [
    { key: 'slidesPerPage', label: 'Base (under 450px)', defaultValue: 1 },
    { key: 'slidesPerPageXs', label: 'XS (450px and up)', defaultValue: 2 },
    { key: 'slidesPerPageSm', label: 'SM (720px and up)', defaultValue: 3 },
    { key: 'slidesPerPageMd', label: 'MD (1024px and up)', defaultValue: 3 },
    { key: 'slidesPerPageLg', label: 'LG (1250px and up)', defaultValue: 4 },
];

function normalizeSlidesPerPage(value, fallback) {
    const parsed = Number.parseFloat(value);
    const count = Number.isFinite(parsed) ? parsed : fallback;
    return Math.max(1, Math.min(6, count));
}

const SLIDE_OPTIONS = [
    { label: '1.333', value: '1.333' },
    { label: '2', value: '2' },
    { label: '2.333', value: '2.333' },
    { label: '3', value: '3' },
    { label: '3.333', value: '3.333' },
    { label: '4', value: '4' },
    { label: '4.333', value: '4.333' },
    { label: '5', value: '5' },
    { label: '6', value: '6' },
];

function getSlideOptions() {
    return SLIDE_OPTIONS;
}

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
        const blockProps = useBlockProps();
        const variant = attributes.variant === 'slider' ? 'slider' : 'grid';
        const gridColumns = attributes.gridColumns || (['grid-3', 'grid-4'].includes(attributes.variant) ? attributes.variant : '');
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
        const slidesPerPage = Object.fromEntries(
            SLIDE_BREAKPOINTS.map(({ key, defaultValue }) => [
                key,
                normalizeSlidesPerPage(attributes[key], defaultValue),
            ])
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
                            value={variant}
                            options={VARIANT_OPTIONS}
                            onChange={(value) => setAttributes({ variant: value, gridColumns })}
                        />
                        {variant === 'grid' && (
                            <SelectControl
                                label="Grid columns"
                                value={gridColumns === 'grid-4' ? 'grid-4' : ''}
                                options={[
                                    { label: '3 Col Grid', value: 'grid-3' },
                                    { label: '4 Col Grid', value: 'grid-4' },
                                ]}
                                onChange={(value) => setAttributes({ variant, gridColumns: value })}
                            />
                        )}
                        {attributes.variant === 'slider' && SLIDE_BREAKPOINTS.map(({ key, label, defaultValue }) => (
                            <SelectControl
                                key={key}
                                label={`Items per page — ${label}`}
                                value={String(slidesPerPage[key])}
                                options={getSlideOptions()}
                                onChange={(value) => setAttributes({
                                    [key]: normalizeSlidesPerPage(value, slidesPerPage[key]),
                                })}
                            />
                        ))}
                        <TextControl
                            label="Title"
                            value={attributes.title || ''}
                            onChange={(value) => setAttributes({ title: value })}
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
                        <fieldset style={{ display: 'flex', gap: '1rem', flexWrap: 'nowrap' }}>
                            <legend>Show…</legend>
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
                        </fieldset>
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
                            <p style={{ color: '#666', fontSize: '12px' }}>No matching items found</p>
                        ) : (
                            <div>
                                <p style={{ fontSize: '12px', margin: '0 0 8px 0', color: '#666' }}>
                                    only shows upcoming entries for the day of and beyond
                                </p>
                                <p style={{ fontSize: '12px', fontWeight: 600, margin: '0 0 8px 0', color: '#333' }}>
                                    Drag to reorder (showing first {draggableRecords.length} items)
                                </p>
                                <div style={{ border: '1px solid #ddd', borderRadius: '4px', backgroundColor: '#fafafa' }}>
                                    {draggableRecords.map((record, index) => (
                                        <div
                                            key={record.id}
                                            draggable
                                            onDragStart={(e) => handleDragStart(e, record.id)}
                                            onDragOver={handleDragOver}
                                            onDrop={(e) => handleDrop(e, index)}
                                            style={{
                                                padding: '8px 12px',
                                                borderBottom: index < draggableRecords.length - 1 ? '1px solid #eee' : 'none',
                                                cursor: 'move',
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: '8px',
                                                backgroundColor: '#fff',
                                            }}
                                        >
                                            <span style={{ color: '#999', fontSize: '18px', marginRight: '4px' }}>⋮⋮</span>
                                            <span style={{ flex: 1, fontSize: '13px' }}>
                                                {record.title}
                                                <span style={{ color: '#999', marginLeft: '4px' }}>
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
