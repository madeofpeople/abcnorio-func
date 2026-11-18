import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import ComponentShell from './componentShell';

const ALLOWED_BLOCKS = [
    'core/heading',
    'core/paragraph',
    'core/buttons',
    'core/button',
    'core/group',
    'core/list',
    'core/list-item',
    'core/image',
];

registerBlockType('abcnorio/announcement-tout', {
    edit({ attributes, setAttributes }) {
        const variantClass = attributes.variant === 'secondary' ? 'announcement-tout--secondary' : '';
        const blockProps = useBlockProps({ className: ['announcement-tout', variantClass].filter(Boolean).join(' ') });
        const headingLevel = Number.parseInt(String(attributes.toastHeadingLevel ?? 2), 10);
        const normalizedHeadingLevel = headingLevel >= 1 && headingLevel <= 6 ? headingLevel : 2;

        return (
            <>
                <InspectorControls>
                    <PanelBody title="Announcement Tout" initialOpen={true}>
                        <SelectControl
                            label="Variant"
                            value={attributes.variant || 'default'}
                            options={[
                                { label: 'Default', value: 'default' },
                                { label: 'Secondary', value: 'secondary' },
                            ]}
                            onChange={(value) => setAttributes({ variant: value })}
                        />
                        <TextControl
                            label="Toast"
                            value={attributes.toast || ''}
                            onChange={(value) => setAttributes({ toast: value })}
                        />
                        <SelectControl
                            label="Toast heading level"
                            value={String(normalizedHeadingLevel)}
                            options={[
                                { label: '1', value: '1' },
                                { label: '2', value: '2' },
                                { label: '3', value: '3' },
                                { label: '4', value: '4' },
                                { label: '5', value: '5' },
                                { label: '6', value: '6' },
                            ]}
                            onChange={(value) => setAttributes({ toastHeadingLevel: Number.parseInt(String(value), 10) || 2 })}
                        />
                        <TextControl
                            label="Title"
                            value={attributes.title || ''}
                            onChange={(value) => setAttributes({ title: value })}
                        />
                    </PanelBody>
                </InspectorControls>

                <ComponentShell
                    component="announcement-tout"
                    blockProps={blockProps}
                    bindings={{
                        toast: {
                            tag: `h${normalizedHeadingLevel}`,
                            hidden: !attributes.toast,
                            children: attributes.toast || '',
                        },
                        title: { hidden: !attributes.title, children: attributes.title || '' },
                    }}
                    slots={{ content: <InnerBlocks templateLock={false} allowedBlocks={ALLOWED_BLOCKS} /> }}
                />
            </>
        );
    },
    save() {
        return <InnerBlocks.Content />;
    },
});
