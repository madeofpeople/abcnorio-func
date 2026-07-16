import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, TextareaControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

registerBlockType('abcnorio/announcement-tout', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps();

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title="Announcement Tout" initialOpen={true}>
                        <TextControl
                            label="Toast"
                            value={attributes.toast || ''}
                            onChange={(value) => setAttributes({ toast: value })}
                        />
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
                    </PanelBody>
                </InspectorControls>
                <ServerSideRender block="abcnorio/announcement-tout" attributes={attributes} />
            </div>
        );
    },
    save() {
        return null;
    },
});
