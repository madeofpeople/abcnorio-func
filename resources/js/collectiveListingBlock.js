import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';
import { stripHtmlTags } from './blockHelpers';
import { normalizeUniqueValues, parseSlug, useQueryPostManualOrder } from './query-post-manual-order';

const ORDER_OPTIONS = [
    { label: 'Ascending', value: 'asc' },
    { label: 'Descending', value: 'desc' },
    { label: 'Manual', value: 'manual' },
];

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
    return slug !== '' ? slug : `Collective ${record?.id ?? ''}`;
}

registerBlockType('abcnorio/collective-listing', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();
        const orderMode = attributes.order === 'asc' || attributes.order === 'manual' ? attributes.order : 'desc';
        const isManualOrder = orderMode === 'manual';
        const orderSlugs = isManualOrder ? normalizeUniqueValues(attributes.sortOrderSlugs, parseSlug) : [];

        const candidateRecords = useSelect(
            (select) => {
                const coreStore = select('core');
                if (!coreStore) {
                    return [];
                }

                const collectives = coreStore.getEntityRecords('postType', 'collective', {
                    per_page: -1,
                    status: 'publish',
                    orderby: 'date',
                    order: 'desc',
                    _fields: 'id,title,slug,date',
                }) || [];

                return collectives
                    .map((collective) => ({
                        id: Number.parseInt(collective?.id, 10),
                        slug: String(collective?.slug || '').trim(),
                        title: getRecordTitle(collective),
                        date: typeof collective?.date === 'string' ? collective.date : '',
                    }))
                    .filter((collective) => collective.slug !== '' && Number.isFinite(collective.id) && collective.id > 0)
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
            []
        );

        const {
            draggableRecords,
            handleDragStart,
            handleDragOver,
            handleDrop,
        } = useQueryPostManualOrder({
            candidateRecords,
            orderedValues: orderSlugs,
            valueSelector: (record) => record.slug,
            onOrderChange: (nextOrder) => setAttributes({ sortOrderSlugs: nextOrder }),
        });

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Collective Listing Options" initialOpen={true}>
                        <SelectControl
                            label="Order By"
                            value={orderMode}
                            options={ORDER_OPTIONS}
                            onChange={(value) =>
                                setAttributes({
                                    order: value,
                                    sortOrderSlugs: value === 'manual'
                                        ? normalizeUniqueValues(attributes.sortOrderSlugs, parseSlug)
                                        : [],
                                })
                            }
                        />
                    </PanelBody>
                    {isManualOrder && (
                        <PanelBody title="Manual Item Order" initialOpen={true}>
                            {draggableRecords.length === 0 ? (
                                <p style={{ color: '#666', fontSize: '12px' }}>No matching items found</p>
                            ) : (
                                <div>
                                    <p style={{ fontSize: '12px', fontWeight: 600, margin: '0 0 8px 0', color: '#333' }}>
                                        Drag to reorder (showing {draggableRecords.length} items)
                                    </p>
                                    <div style={{ border: '1px solid #ddd', borderRadius: '4px', backgroundColor: '#fafafa' }}>
                                        {draggableRecords.map((record, index) => (
                                            <div
                                                key={record.slug}
                                                draggable
                                                onDragStart={(event) => handleDragStart(event, record.slug)}
                                                onDragOver={handleDragOver}
                                                onDrop={(event) => handleDrop(event, index)}
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
                                                        ({record.slug}) #{record.id}
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
                <ServerSideRender block="abcnorio/collective-listing" attributes={attributes} />
            </div>
        );
    },
    save() {
        return null;
    },
});
