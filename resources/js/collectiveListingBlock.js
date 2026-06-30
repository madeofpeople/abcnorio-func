import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { FormTokenField, PanelBody, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';

const ORDER_OPTIONS = [
    { label: 'Descending', value: 'desc' },
    { label: 'Ascending', value: 'asc' },
];

function normalizeSlug(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '');
}

registerBlockType('abcnorio/collective-listing', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();
        const currentSortOrder = Array.isArray(attributes.sortOrderSlugs)
            ? attributes.sortOrderSlugs
            : [];

        const slugSuggestions = useSelect(
            (select) => {
                const coreStore = select('core');
                if (!coreStore) {
                    return [];
                }

                const collectives = coreStore.getEntityRecords('postType', 'collective', {
                    per_page: -1,
                    status: 'publish',
                    orderby: 'title',
                    order: 'asc',
                }) || [];

                return collectives
                    .map((collective) => normalizeSlug(collective?.slug || ''))
                    .filter(Boolean);
            },
            []
        );

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Collective Listing Options" initialOpen={true}>
                        <SelectControl
                            label="Sort Order"
                            value={attributes.order || 'desc'}
                            options={ORDER_OPTIONS}
                            onChange={(value) => setAttributes({ order: value })}
                        />
                        <FormTokenField
                            label="Manual Sort Order (collective slugs)"
                            value={currentSortOrder}
                            suggestions={slugSuggestions}
                            tokenizeOnBlur={true}
                            help="Optional. Slugs here render first, in this exact order."
                            onChange={(tokens) => {
                                const normalized = [];
                                const seen = new Set();

                                tokens.forEach((token) => {
                                    const slug = normalizeSlug(token);
                                    if (!slug || seen.has(slug)) {
                                        return;
                                    }

                                    seen.add(slug);
                                    normalized.push(slug);
                                });

                                setAttributes({ sortOrderSlugs: normalized });
                            }}
                        />
                    </PanelBody>
                </InspectorControls>
                <ServerSideRender block="abcnorio/collective-listing" attributes={attributes} />
            </div>
        );
    },
    save() {
        return null;
    },
});
