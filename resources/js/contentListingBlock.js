import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const ORDER_OPTIONS = [
    { label: 'Descending', value: 'desc' },
    { label: 'Ascending', value: 'asc' },
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

registerBlockType('abcnorio/content-listing', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();
        const selectedPostTypes = Array.isArray(attributes.listingPostTypes)
            ? attributes.listingPostTypes
            : ['event', 'article'];

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Content Listing Options" initialOpen={true}>
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
                                    listingTagFilter: value
                                        .split(',')
                                        .map((entry) => entry.trim())
                                        .filter(Boolean),
                                })
                            }
                        />
                        <SelectControl
                            label="Sort Order"
                            value={attributes.order || 'desc'}
                            options={ORDER_OPTIONS}
                            onChange={(value) => setAttributes({ order: value })}
                        />
                        <RangeControl
                            label="Item Count"
                            value={attributes.listingCount || 5}
                            min={1}
                            max={50}
                            onChange={(value) => setAttributes({ listingCount: value ?? 5 })}
                        />
                    </PanelBody>
                </InspectorControls>
                <ServerSideRender block="abcnorio/content-listing" attributes={attributes} />
            </div>
        );
    },
    save() {
        return null;
    },
});
