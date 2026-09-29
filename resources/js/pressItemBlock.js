import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { DatePicker, PanelBody, TextControl } from '@wordpress/components';

function formatPreviewDate(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
        return '';
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

registerBlockType('abcnorio/press-item', {
    edit({ attributes, setAttributes }) {
        const blockProps = useBlockProps({ className: 'press-item' });
        const date = String(attributes.date || '');
        const fallbackDate = String(attributes.fallbackDate || '');
        const title = String(attributes.title || '');
        const source = String(attributes.source || '');
        const sourceUrl = String(attributes.sourceUrl || '');
        const sourceValue = sourceUrl ? `<a href="${sourceUrl}">${source}</a>` : source;
        const displayDate = formatPreviewDate(date);

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
            <article {...blockProps}>
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

                {displayDate ? <time dateTime={date}>{displayDate}</time> : fallbackDate ? <time>{fallbackDate}</time> : null}
                <RichText
                    tagName="h3"
                    value={title}
                    allowedFormats={[]}
                    placeholder="Press item title"
                    onChange={(value) => setAttributes({ title: value })}
                />
                <p>
                    — <RichText
                        tagName="span"
                        className="source"
                        value={sourceValue}
                        allowedFormats={['core/link']}
                        placeholder="Source"
                        onChange={updateSource}
                    />
                </p>
            </article>
        );
    },
    save() {
        return null;
    },
});