import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { DatePicker, PanelBody, TextControl } from '@wordpress/components';
import { formatDate } from 'abcnorio-webcomponents/util/dates';
import ComponentShell from './componentShell';

registerBlockType('abcnorio/press-item', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps({ className: 'press-item' });
        const date = String(attributes.date || '');
        const fallbackDate = String(attributes.fallbackDate || '');
        const title = String(attributes.title || '');
        const source = String(attributes.source || '');
        const sourceUrl = String(attributes.sourceUrl || '');
        const sourceNode = document.createElement(sourceUrl ? 'a' : 'span');
        sourceNode.textContent = source;
        if (sourceUrl) sourceNode.setAttribute('href', sourceUrl);
        const sourceValue = sourceUrl ? sourceNode.outerHTML : sourceNode.innerHTML;
        const displayDate = formatDate(date);

        const updateSource = (value) => {
            const container = document.createElement('div');
            container.innerHTML = value;
            const link = container.querySelector('a');

            setAttributes({
                source: container.textContent || '',
                sourceUrl: link?.getAttribute('href') || '',
            });
        };

        return (
            <>
                <InspectorControls>
                    <PanelBody title="Press Item" initialOpen={true}>
                        <DatePicker
                            currentDate={date || undefined}
                            onChange={(value) => setAttributes({ date: value || '' })}
                        />
                        <TextControl
                            label="Date override string"
                            value={fallbackDate}
                            onChange={(value) => setAttributes({ fallbackDate: value })}
                        />
                    </PanelBody>
                </InspectorControls>

                <ComponentShell
                    component="press-item"
                    blockProps={blockProps}
                    bindings={{
                        date: {
                            hidden: !displayDate && !fallbackDate,
                            props: { dateTime: displayDate?.datetime },
                            children: displayDate?.label_date || fallbackDate,
                        },
                        title: { render: ({ tag, props: { key, ...props } }) => (
                            <RichText
                                key={key}
                                {...props}
                                tagName={tag}
                                value={title}
                                allowedFormats={[]}
                                placeholder="Press item title"
                                onChange={(value) => setAttributes({ title: value })}
                            />
                        ) },
                        source: { render: ({ tag, props: { key, ...props } }) => (
                            <RichText
                                key={key}
                                {...props}
                                tagName={tag}
                                value={sourceValue}
                                allowedFormats={['core/link']}
                                placeholder="Source"
                                onChange={updateSource}
                            />
                        ) },
                    }}
                />
            </>
        );
    },
    save() {
        return null;
    },
});