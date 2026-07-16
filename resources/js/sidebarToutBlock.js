import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, TextareaControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

registerBlockType('abcnorio/sidebar-tout', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Sidebar Tout" initialOpen={true}>
                        <TextControl
                            label="Title"
                            value={attributes.title || ''}
                            onChange={(value) => setAttributes({ title: value })}
                        />
                        <TextareaControl
                            label="Details"
                            value={attributes.details || ''}
                            onChange={(value) => setAttributes({ details: value })}
                        />
                        <TextControl
                            label="Button Label"
                            value={attributes.buttonLabel || ''}
                            onChange={(value) => setAttributes({ buttonLabel: value })}
                        />
                        <TextControl
                            label="Button URL"
                            value={attributes.buttonUrl || ''}
                            onChange={(value) => setAttributes({ buttonUrl: value })}
                        />
                        <TextControl
                            label="Image URL"
                            value={attributes.imageUrl || ''}
                            onChange={(value) => setAttributes({ imageUrl: value })}
                        />
                        <TextControl
                            label="Image Alt"
                            value={attributes.imageAlt || ''}
                            onChange={(value) => setAttributes({ imageAlt: value })}
                        />
                    </PanelBody>
                </InspectorControls>
                <ServerSideRender block="abcnorio/sidebar-tout" attributes={attributes} />
            </div>
        );
    },
    save() {
        return null;
    },
});
